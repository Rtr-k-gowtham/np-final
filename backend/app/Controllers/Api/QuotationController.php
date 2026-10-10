<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\QuotationModel;
use App\Models\QuotationItemModel;

class QuotationController extends BaseController
{
    private QuotationModel $qModel;
    private QuotationItemModel $qiModel;

    public function __construct()
    {
        $this->qModel  = new QuotationModel();
        $this->qiModel = new QuotationItemModel();
    }

    private function authUserId(): ?int
    {
        $u = $this->request->user ?? null;
        if (is_array($u) && isset($u['id'])) return (int)$u['id'];
        if (is_object($u) && isset($u->id)) return (int)$u->id;
        return null;
    }

    private function json(bool $success, string $message = '', $data = null, int $code = 200): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/quotations?client_id=&status=&q=&limit=&offset=
     */
    public function index(): ResponseInterface
    {
        $clientId = $this->request->getGet('client_id');
        $status   = $this->request->getGet('status');
        $q        = trim((string)$this->request->getGet('q'));
        $limit    = (int)($this->request->getGet('limit') ?? 25);
        $offset   = (int)($this->request->getGet('offset') ?? 0);

        $limit = max(1, min($limit, 200));

        $builder = $this->qModel->builder()->select('quotations.*');

        if ($clientId) $builder->where('client_id', (int)$clientId);
        if ($status)   $builder->where('status', strtolower($status));

        if ($q !== '') {
            $builder->groupStart()
                ->like('quotation_no', $q)
                ->orLike('subject', $q)
            ->groupEnd();
        }

        $total = (clone $builder)->countAllResults(false);

        $rows = $builder
            ->orderBy('id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return $this->json(true, '', [
            'rows'   => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * GET /api/quotations/{id}
     */
    public function show(int $id): ResponseInterface
    {
        $quotation = $this->qModel->find($id);
        if (!$quotation) return $this->json(false, 'Quotation not found', null, 404);

        $items = $this->qiModel
            ->where('quotation_id', $id)
            ->orderBy('sl_no', 'ASC')
            ->findAll();

        return $this->json(true, '', [
            'quotation' => $quotation,
            'items'     => $items,
        ]);
    }

    /**
     * POST /api/quotations
     */
    public function create(): ResponseInterface
    {
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $payload = $this->request->getJSON(true) ?? [];
        $items   = $payload['items'] ?? [];

        // Basic validation
        $required = ['quotation_no','quotation_date','client_id'];
        foreach ($required as $k) {
            if (empty($payload[$k])) return $this->json(false, "Missing: $k", null, 422);
        }
        if (!is_array($items) || count($items) === 0) {
            return $this->json(false, "Items required", null, 422);
        }

        // Compute totals from items, taking discount into account
        [$subtotal, $tax, $total] = $this->computeTotals($payload, $items);

        // Normalize status to lowercase to match DB enum constraints ('draft', 'sent', etc.)
        $status = isset($payload['status']) ? strtolower(trim((string)$payload['status'])) : 'draft';
        $validStatuses = ['draft', 'sent', 'approved', 'rejected', 'expired'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'draft';
        }

        $data = [
            'quotation_no'    => (string)$payload['quotation_no'],
            'quotation_date'  => (string)$payload['quotation_date'],
            'client_id'       => (int)$payload['client_id'],
            'lead_id'         => !empty($payload['lead_id']) ? (int)$payload['lead_id'] : null,
            'subject'         => $payload['subject'] ?? null,
            'notes'           => $payload['notes'] ?? null,
            'sections_config' => $payload['sections_config'] ?? null,
            'valid_till'      => $payload['valid_till'] ?? $payload['valid_until'] ?? null, // Fallback mapping
            'status'          => $status,
            'subtotal'        => $subtotal,
            'tax_amount'      => $tax,
            'total_amount'    => $total,
            'created_by'      => $userId,
        ];

        $db = db_connect();
        $db->transStart();

        try {
            $id = $this->qModel->insert($data, true);
            if (!$id) throw new \RuntimeException('Failed to create quotation');

            $itemRows = $this->normalizeItems((int)$id, $items);
            $this->qiModel->insertBatch($itemRows);

            $db->transComplete();
            if ($db->transStatus() === false) throw new \RuntimeException('Transaction failed');

            return $this->json(true, 'Quotation created', ['id' => (int)$id], 201);

        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->json(false, $e->getMessage(), null, 500);
        }
    }

    /**
     * PUT/PATCH /api/quotations/{id}
     */
    public function update(int $id): ResponseInterface
    {
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $existing = $this->qModel->find($id);
        if (!$existing) return $this->json(false, 'Quotation not found', null, 404);

        $payload = $this->request->getJSON(true) ?? [];
        $items   = $payload['items'] ?? null;

        $updateData = [];
        $allowed = ['quotation_no','quotation_date','client_id','lead_id','subject','notes','sections_config','valid_till','valid_until','status'];
        
        foreach ($allowed as $k) {
            if (array_key_exists($k, $payload)) {
                if ($k === 'valid_until') {
                    $updateData['valid_till'] = $payload['valid_until'];
                } elseif ($k === 'status') {
                    // Normalize status to lowercase to match DB enum constraints
                    $status = strtolower(trim((string)$payload['status']));
                    $validStatuses = ['draft', 'sent', 'approved', 'rejected', 'expired'];
                    $updateData['status'] = in_array($status, $validStatuses, true) ? $status : 'draft';
                } else {
                    $updateData[$k] = $payload[$k];
                }
            }
        }

        $db = db_connect();
        $db->transStart();

        try {
            // If items provided -> recompute totals and replace items
            if (is_array($items)) {
                if (count($items) === 0) return $this->json(false, 'Items cannot be empty', null, 422);

                // Merge payload with existing DB data to retain discount context during items recalculation
                $mergedHeader = array_merge($existing, $payload);
                [$subtotal, $tax, $total] = $this->computeTotals($mergedHeader, $items);

                $updateData['subtotal']     = $subtotal;
                $updateData['tax_amount']   = $tax;
                $updateData['total_amount'] = $total;

                // Replace items
                $this->qiModel->where('quotation_id', $id)->delete();
                $itemRows = $this->normalizeItems((int)$id, $items);
                $this->qiModel->insertBatch($itemRows);
            }

            if (!empty($updateData)) {
                // sanitize integers
                if (isset($updateData['client_id'])) $updateData['client_id'] = (int)$updateData['client_id'];
                if (array_key_exists('lead_id', $updateData)) {
                    $updateData['lead_id'] = !empty($updateData['lead_id']) ? (int)$updateData['lead_id'] : null;
                }

                $ok = $this->qModel->update($id, $updateData);
                if (!$ok) throw new \RuntimeException('Failed to update quotation');
            }

            $db->transComplete();
            if ($db->transStatus() === false) throw new \RuntimeException('Transaction failed');

            return $this->json(true, 'Quotation updated', ['id' => $id]);

        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->json(false, $e->getMessage(), null, 500);
        }
    }

    /**
     * DELETE /api/quotations/{id}
     */
    public function delete(int $id): ResponseInterface
    {
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $existing = $this->qModel->find($id);
        if (!$existing) return $this->json(false, 'Quotation not found', null, 404);

        try {
            $this->qiModel->where('quotation_id', $id)->delete();
            $this->qModel->delete($id);
            return $this->json(true, 'Quotation deleted');
        } catch (\Throwable $e) {
            return $this->json(false, $e->getMessage(), null, 500);
        }
    }

    // ---------- helpers ----------

    private function normalizeItems(int $quotationId, array $items): array
    {
        $rows = [];
        $sl = 1;

        foreach ($items as $it) {
            if (empty($it['description'])) continue;

            $qty   = isset($it['quantity']) ? (float)$it['quantity'] : 1.0;
            $rate  = isset($it['rate']) ? (float)$it['rate'] : 0.0;
            $amt   = isset($it['amount']) ? (float)$it['amount'] : ($qty * $rate);

            $rows[] = [
                'quotation_id' => $quotationId,
                'sl_no'        => isset($it['sl_no']) ? (int)$it['sl_no'] : $sl,
                'description'  => (string)$it['description'],
                'unit'         => $it['unit'] ?? null,
                'quantity'     => $qty,
                'rate'         => $rate,
                'amount'       => $amt,
                'remarks'      => $it['remarks'] ?? null,
            ];
            $sl++;
        }

        if (count($rows) === 0) {
            throw new \RuntimeException('No valid items found');
        }
        return $rows;
    }

    private function computeTotals(array $header, array $items): array
    {
        $subtotal = 0.0;
        foreach ($items as $it) {
            $qty  = isset($it['quantity']) ? (float)$it['quantity'] : 1.0;
            $rate = isset($it['rate']) ? (float)$it['rate'] : 0.0;
            $amt  = isset($it['amount']) ? (float)$it['amount'] : ($qty * $rate);
            $subtotal += $amt;
        }

        $taxPercent = isset($header['tax_percent']) ? (float)$header['tax_percent'] : 0.0;
        $tax = ($subtotal * $taxPercent) / 100.0;

        // Apply discount deduction so database total matches what the frontend calculated
        $discount = isset($header['discount']) ? (float)$header['discount'] : 0.0;
        $total = max(0.0, $subtotal + $tax - $discount);

        return [round($subtotal, 2), round($tax, 2), round($total, 2)];
    }
}
