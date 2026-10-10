<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class ServiceQuotationTemplateItemController extends BaseController
{
    protected $db;

    public function __construct()
    {
        $this->db = db_connect();
    }

    private function jsonResponse(bool $success, string $message = '', $data = null, int $code = 200): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/quotation-templates/{templateId}/items
     */
    public function list(int $templateId): ResponseInterface
    {
        $items = $this->db->table('service_quotation_template_items')
            ->where('template_id', $templateId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        return $this->jsonResponse(true, 'Items fetched', $items);
    }

    /**
     * POST /api/quotation-templates/{templateId}/items
     */
    public function create(int $templateId): ResponseInterface
    {
        // Verify template exists
        $template = $this->db->table('service_quotation_templates')
            ->where('id', $templateId)
            ->where('deleted_at IS NULL', null, false)
            ->get()
            ->getRowArray();

        if (!$template) {
            return $this->jsonResponse(false, 'Template not found', null, 404);
        }

        $data = $this->request->getJSON(true) ?: $this->request->getPost();

        $paymentDetails = trim($data['payment_details'] ?? '');
        if ($paymentDetails === '') {
            return $this->jsonResponse(false, 'Payment details is required', null, 422);
        }

        // Auto sort_order: next available
        $maxSort = $this->db->table('service_quotation_template_items')
            ->selectMax('sort_order')
            ->where('template_id', $templateId)
            ->get()
            ->getRowArray();

        $sortOrder = isset($data['sort_order'])
            ? (int) $data['sort_order']
            : (int) ($maxSort['sort_order'] ?? 0) + 1;

        $insertData = [
            'template_id'     => $templateId,
            'sort_order'      => $sortOrder,
            'payment_details' => $paymentDetails,
            'fees'            => (float) ($data['fees'] ?? 0),
            'remarks'         => $data['remarks'] ?? null,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        $this->db->table('service_quotation_template_items')->insert($insertData);
        $newId = $this->db->insertID();

        $item = $this->db->table('service_quotation_template_items')
            ->where('id', $newId)
            ->get()
            ->getRowArray();

        return $this->jsonResponse(true, 'Item created', $item, 201);
    }

    /**
     * PUT /api/quotation-template-items/{id}
     */
    public function update(int $id): ResponseInterface
    {
        $item = $this->db->table('service_quotation_template_items')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$item) {
            return $this->jsonResponse(false, 'Item not found', null, 404);
        }

        $data = $this->request->getJSON(true) ?: $this->request->getPost();

        $updateData = [
            'payment_details' => $data['payment_details'] ?? $item['payment_details'],
            'fees'            => isset($data['fees']) ? (float) $data['fees'] : (float) $item['fees'],
            'remarks'         => array_key_exists('remarks', $data) ? $data['remarks'] : $item['remarks'],
            'sort_order'      => isset($data['sort_order']) ? (int) $data['sort_order'] : (int) $item['sort_order'],
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        $this->db->table('service_quotation_template_items')
            ->where('id', $id)
            ->update($updateData);

        $updated = $this->db->table('service_quotation_template_items')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        return $this->jsonResponse(true, 'Item updated', $updated);
    }

    /**
     * DELETE /api/quotation-template-items/{id}
     */
    public function delete(int $id): ResponseInterface
    {
        $item = $this->db->table('service_quotation_template_items')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$item) {
            return $this->jsonResponse(false, 'Item not found', null, 404);
        }

        $this->db->table('service_quotation_template_items')
            ->where('id', $id)
            ->delete();

        return $this->jsonResponse(true, 'Item deleted');
    }
}

