<?php namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ProjectInvoiceModel;
use App\Models\ProjectInvoiceItemModel;

class ProjectInvoiceController extends BaseController
{
    protected ProjectInvoiceModel $invModel;
    protected ProjectInvoiceItemModel $itemModel;

    public function __construct()
    {
        $db = db_connect(); 
		$this->invModel  = new ProjectInvoiceModel();
        $this->itemModel = new ProjectInvoiceItemModel();
    }

    private function json($success, $message = '', $data = null, int $code = 200)
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => (bool)$success,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/projects/{projectId}/invoices
     * (DataTables list)
     */
    public function index($projectId)
    {
        $projectId = (int)$projectId;
        if ($projectId <= 0) return $this->json(false, 'Invalid project', null, 400);

        $db = db_connect();

        $rows = $db->table('project_invoices pi')
            ->select('pi.id, pi.invoice_no, pi.invoice_date, pi.due_date, pi.total, pi.status, u.name as created_by, pi.created_at')
            ->join('users u', 'u.id = pi.created_by_user_id', 'left')
            ->where('pi.project_id', $projectId)
            ->orderBy('pi.id', 'DESC')
            ->get()->getResultArray();

        // Optional: add paid_total + balance to list (useful)
        foreach ($rows as &$r) {
            $paid = $this->getPaymentSummary((int)$r['id'])['paid_total'];
            $total = (float)($r['total'] ?? 0);
            $r['paid_total'] = $paid;
            $r['balance'] = max(0, $total - $paid);
        }
        unset($r);

        return $this->json(true, 'OK', $rows);
    }

    /**
     * POST /api/projects/{projectId}/invoices
     */

	public function create($projectId)
{
    $projectId = (int)$projectId;
    if ($projectId <= 0) {
        return $this->json(false, 'Invalid project', null, 400);
    }

    // ============================
    // Read payload
    // ============================
    $payload = $this->request->getJSON(true);
    if (!is_array($payload)) {
        $payload = $this->request->getPost();
    }
    if (!is_array($payload)) {
        return $this->json(false, 'Invalid request payload', null, 400);
    }

    // ============================
    // Normalize header fields
    // ============================
    $invoiceNo = trim((string)(
        $payload['invoice_no']
        ?? $payload['invoice_number']
        ?? ''
    ));

    $invoiceDate = (string)(
        $payload['invoice_date']
        ?? $payload['date']
        ?? ''
    );

    $dueDate = $payload['due_date'] ?? null;

    if ($invoiceNo === '' || !preg_match('/^\d{4}\-\d{2}\-\d{2}$/', (string)$invoiceDate)) {
        return $this->json(false, 'invoice_no and invoice_date(YYYY-MM-DD) required', null, 422);
    }

    // ============================
    // Normalize items
    // ============================
    $items = $payload['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        return $this->json(false, 'At least 1 invoice item required', null, 422);
    }

    $normalizedItems = [];
    foreach ($items as $row) {
        if (!is_array($row)) continue;

        $description = trim((string)($row['description'] ?? ''));

        $itemTitle = trim((string)(
            $row['item_title']
            ?? $row['title']
            ?? ($description !== '' ? $description : 'Item')
        ));

        if ($itemTitle === '') {
            return $this->json(false, 'Item title required', null, 422);
        }

        $qty = (float)($row['qty'] ?? $row['quantity'] ?? 1);
        if ($qty <= 0) $qty = 1;

        $unitPrice = (float)($row['unit_price'] ?? $row['price'] ?? 0);
        $discount  = (float)($row['discount'] ?? 0);
        $taxRate   = (float)($row['tax_rate'] ?? 0);

        $normalizedItems[] = [
            'item_title'  => $itemTitle,
            'description' => $description !== '' ? $description : null,
            'qty'         => $qty,
            'unit_price'  => $unitPrice,
            'discount'    => $discount,
            'tax_rate'    => $taxRate,
        ];
    }

    // ============================
    // Compute totals
    // ============================
    [$calc, $calcItems] = $this->calcInvoiceTotals($normalizedItems);
    if (!$calc['success']) {
        return $this->json(false, $calc['message'], null, 422);
    }

    // ============================
    // Company + Created-by (single company)
    // ============================
    $companyId = 1; // ✅ single-company project (your requirement)

    // 1) try session
    $session = session();
    $userId = (int)($session->get('user_id') ?? 0);

    // 2) if your auth filter attaches user to request (optional)
    if ($userId <= 0 && property_exists($this->request, 'user')) {
        $u = $this->request->user;
        if (is_array($u) && isset($u['id'])) $userId = (int)$u['id'];
        if (is_object($u) && isset($u->id)) $userId = (int)$u->id;
    }

    // 3) final fallback to known admin id from your /me screenshot
    if ($userId <= 0) $userId = 3;

    // ============================
    // Other optional fields
    // ============================
    $billToName    = $payload['bill_to_name'] ?? null;
    $billToAddress = $payload['bill_to_address'] ?? null;
    $billToGst     = $payload['bill_to_gst'] ?? null;
    $currency      = $payload['currency'] ?? 'INR';
    $notes         = $payload['notes'] ?? null;
    $terms         = $payload['terms'] ?? null;
    $status        = $payload['status'] ?? 'draft';

    // ============================
    // IMPORTANT: same DB connection for both models
    // ============================
    $db = db_connect();
    $invModel  = new ProjectInvoiceModel($db);
    $itemModel = new ProjectInvoiceItemModel($db);

    $db->transBegin();

    try {

        // Insert invoice header
        $invData = [
            'company_id'          => $companyId,
            'project_id'          => $projectId,
            'invoice_no'          => $invoiceNo,
            'invoice_date'        => $invoiceDate,
            'due_date'            => $dueDate ?: null,

            'bill_to_name'        => $billToName,
            'bill_to_address'     => $billToAddress,
            'bill_to_gst'         => $billToGst,
            'currency'            => $currency,

            'subtotal'            => $calc['subtotal'],
            'discount_total'      => $calc['discount_total'],
            'tax_total'           => $calc['tax_total'],
            'total'               => $calc['total'],

            'status'              => $status,
            'notes'               => $notes,
            'terms'               => $terms,

            'created_by_user_id'  => $userId,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ];

        $invoiceId = (int)$invModel->insert($invData, true);
        if ($invoiceId <= 0) {
            throw new \Exception('Invoice insert failed');
        }

        // Insert items (include company_id!)
        $lineNo = 1;
        foreach ($calcItems as $it) {
            $itemModel->insert([
                'company_id'   => $companyId,
                'invoice_id'   => $invoiceId,
                'line_no'      => $lineNo++,
                'item_title'   => $it['item_title'],
                'description'  => $it['description'],
                'qty'          => $it['qty'],
                'unit_price'   => $it['unit_price'],
                'discount'     => $it['discount'],
                'tax_rate'     => $it['tax_rate'],
                'tax_amount'   => $it['tax_amount'],
                'line_total'   => $it['line_total'],
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        if ($db->transStatus() === false) {
            throw new \Exception('Transaction failed');
        }

        $db->transCommit();

        // Refresh status (paid/partial/draft)
        $this->updateInvoicePaymentStatus($invoiceId);

        return $this->json(true, 'Invoice created', [
            'invoice_id' => $invoiceId
        ]);

    } catch (\Throwable $e) {

        $db->transRollback();

        return $this->json(false, 'Error: ' . $e->getMessage(), null, 500);
    }
}

    /**
     * GET /api/project-invoices/{invoiceId}
     */
    public function show($invoiceId)
    {
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0) return $this->json(false, 'Invalid invoice', null, 400);

        $inv = $this->invModel->find($invoiceId);
        if (!$inv) return $this->json(false, 'Invoice not found', null, 404);

        $items = $this->itemModel
            ->where('invoice_id', $invoiceId)
            ->orderBy('line_no', 'ASC')
            ->findAll();

        // ✅ Payment summary included
        $pay = $this->getPaymentSummary($invoiceId);
        $invTotal = (float)($inv['total'] ?? 0);

        return $this->json(true, 'OK', [
            'invoice' => $inv,
            'items'   => $items,
            'payments' => [
                'paid_total' => $pay['paid_total'],
                'balance'    => max(0, $invTotal - $pay['paid_total']),
            ],
        ]);
    }

    /**
     * PUT/PATCH /api/project-invoices/{invoiceId}
     * Replaces items
     */
    public function update($invoiceId)
    {
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0) return $this->json(false, 'Invalid invoice', null, 400);

        $existing = $this->invModel->find($invoiceId);
        if (!$existing) return $this->json(false, 'Invoice not found', null, 404);

        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) $payload = $this->request->getRawInput();

        $items = $payload['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            return $this->json(false, 'At least 1 invoice item required', null, 422);
        }

        [$calc, $calcItems] = $this->calcInvoiceTotals($items);
        if (!$calc['success']) return $this->json(false, $calc['message'], null, 422);

        $update = [];
        foreach (['invoice_no','invoice_date','due_date','bill_to_name','bill_to_address','bill_to_gst','currency','notes','terms','status'] as $f) {
            if (array_key_exists($f, $payload)) $update[$f] = $payload[$f];
        }

        $update['subtotal'] = $calc['subtotal'];
        $update['discount_total'] = $calc['discount_total'];
        $update['tax_total'] = $calc['tax_total'];
        $update['total'] = $calc['total'];
        $update['updated_at'] = date('Y-m-d H:i:s');

        if (isset($update['invoice_date']) && !preg_match('/^\d{4}\-\d{2}\-\d{2}$/', (string)$update['invoice_date'])) {
            return $this->json(false, 'invoice_date must be YYYY-MM-DD', null, 422);
        }

        $db = db_connect();
        $db->transStart();

        try {
            $this->invModel->update($invoiceId, $update);

            $db->table('project_invoice_items')->where('invoice_id', $invoiceId)->delete();

            $lineNo = 1;
            foreach ($calcItems as $it) {
                $this->itemModel->insert([
                    'invoice_id' => $invoiceId,
                    'line_no' => $lineNo++,
                    'item_title' => $it['item_title'],
                    'description' => $it['description'],
                    'qty' => $it['qty'],
                    'unit_price' => $it['unit_price'],
                    'discount' => $it['discount'],
                    'tax_rate' => $it['tax_rate'],
                    'tax_amount' => $it['tax_amount'],
                    'line_total' => $it['line_total'],
                ]);
            }

            $db->transComplete();
            if (!$db->transStatus()) return $this->json(false, 'Failed to update invoice', null, 500);

            // ✅ Status refresh after totals change (payments may exist)
            $this->updateInvoicePaymentStatus($invoiceId);

            return $this->json(true, 'Invoice updated', ['invoice_id' => $invoiceId]);
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->json(false, 'Error: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * DELETE /api/project-invoices/{invoiceId}
     */
    public function delete($invoiceId)
    {
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0) return $this->json(false, 'Invalid invoice', null, 400);

        $db = db_connect();
        $db->transStart();

        try {
            // delete payments first (safe even if FK not set)
            $db->table('project_invoice_payments')->where('invoice_id', $invoiceId)->delete();

            // delete items
            $db->table('project_invoice_items')->where('invoice_id', $invoiceId)->delete();

            // delete invoice
            $this->invModel->delete($invoiceId);

            $db->transComplete();
            if (!$db->transStatus()) return $this->json(false, 'Failed to delete invoice', null, 500);

            return $this->json(true, 'Invoice deleted', null);
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->json(false, 'Error: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * POST /api/project-invoices/{invoiceId}/status
     * Body: { status: "draft|sent|paid|partial_paid|void" }
     */
    public function updateStatus($invoiceId)
    {
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0) return $this->json(false, 'Invalid invoice', null, 400);

        $inv = $this->invModel->find($invoiceId);
        if (!$inv) return $this->json(false, 'Invoice not found', null, 404);

        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) $payload = $this->request->getPost();

        $status = trim((string)($payload['status'] ?? ''));
        if ($status === '') return $this->json(false, 'status required', null, 422);

        $allowed = ['draft','sent','partial_paid','paid','void'];
        if (!in_array($status, $allowed, true)) {
            return $this->json(false, 'Invalid status', ['allowed' => $allowed], 422);
        }

        // Optional: protect from setting paid if payments not enough
        if ($status === 'paid') {
            $paid = $this->getPaymentSummary($invoiceId)['paid_total'];
            $total = (float)($inv['total'] ?? 0);
            if ($total > 0 && $paid < $total) {
                return $this->json(false, 'Cannot set paid: payments not enough', [
                    'paid_total' => $paid,
                    'invoice_total' => $total
                ], 422);
            }
        }

        $this->invModel->update($invoiceId, [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->json(true, 'Status updated', ['invoice_id' => $invoiceId, 'status' => $status]);
    }

    /**
     * POST /api/project-invoices/{invoiceId}/void
     * (optional route if you use it)
     */
    public function void($invoiceId)
    {
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0) return $this->json(false, 'Invalid invoice', null, 400);

        $inv = $this->invModel->find($invoiceId);
        if (!$inv) return $this->json(false, 'Invoice not found', null, 404);

        $this->invModel->update($invoiceId, [
            'status' => 'void',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->json(true, 'Invoice voided', ['invoice_id' => $invoiceId]);
    }

    /**
     * Server-side calculation
     */
    private function calcInvoiceTotals(array $items): array
    {
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $total = 0.0;

        $outItems = [];

        foreach ($items as $row) {
            $title = trim((string)($row['item_title'] ?? ''));
            if ($title === '') {
                return [['success' => false, 'message' => 'Item title required'], []];
            }

            $qty = (float)($row['qty'] ?? 1);
            $unit = (float)($row['unit_price'] ?? 0);
            $disc = (float)($row['discount'] ?? 0);
            $taxRate = (float)($row['tax_rate'] ?? 0);

            if ($qty <= 0) $qty = 1;

            $lineBase = $qty * $unit;
            $lineAfterDisc = max(0, $lineBase - $disc);
            $taxAmt = ($taxRate > 0) ? ($lineAfterDisc * ($taxRate / 100)) : 0;
            $lineTotal = $lineAfterDisc + $taxAmt;

            $subtotal += $lineBase;
            $discountTotal += $disc;
            $taxTotal += $taxAmt;
            $total += $lineTotal;

            $outItems[] = [
                'item_title' => $title,
                'description' => $row['description'] ?? null,
                'qty' => $qty,
                'unit_price' => $unit,
                'discount' => $disc,
                'tax_rate' => $taxRate,
                'tax_amount' => round($taxAmt, 2),
                'line_total' => round($lineTotal, 2),
            ];
        }

        return [[
            'success' => true,
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'tax_total' => round($taxTotal, 2),
            'total' => round($total, 2),
        ], $outItems];
    }

    // ========================
    // Payments helpers
    // ========================
    private function getPaymentSummary(int $invoiceId): array
    {
        $db = db_connect();

        $paid = (float)($db->table('project_invoice_payments')
            ->selectSum('amount', 'paid')
            ->where('invoice_id', $invoiceId)
            ->get()->getRow()->paid ?? 0);

        return [
            'paid_total' => round($paid, 2),
        ];
    }

    private function updateInvoicePaymentStatus(int $invoiceId): void
    {
        $invoice = $this->invModel->find($invoiceId);
        if (!$invoice) return;

        $total  = (float)($invoice['total'] ?? 0);
        $status = (string)($invoice['status'] ?? 'draft');

        if ($status === 'void') return;

        $paid = $this->getPaymentSummary($invoiceId)['paid_total'];

        if ($total > 0 && $paid >= $total) $status = 'paid';
        else if ($paid > 0) $status = 'partial_paid';
        else if ($status === 'draft') $status = 'draft';
        else $status = 'sent';

        $this->invModel->update($invoiceId, [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
