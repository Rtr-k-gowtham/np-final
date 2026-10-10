<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class ServiceQuotationTemplateController extends BaseController
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

    private function getCompanyId(): int
    {
        return (int) ($this->request->getGet('company_id') ?: $this->request->getPost('company_id') ?: 1);
    }

    /**
     * GET /api/services/{serviceId}/quotation-templates
     */
    public function listByService(int $serviceId): ResponseInterface
    {
        $companyId = $this->getCompanyId();

        $templates = $this->db->table('service_quotation_templates')
            ->where('service_id', $serviceId)
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL', null, false)
            ->orderBy('is_default', 'DESC')
            ->orderBy('template_name', 'ASC')
            ->get()
            ->getResultArray();

        // Attach item count for each template
        foreach ($templates as &$t) {
            $t['item_count'] = (int) $this->db->table('service_quotation_template_items')
                ->where('template_id', $t['id'])
                ->countAllResults();
        }
        unset($t);

        return $this->jsonResponse(true, 'Quotation templates fetched', $templates);
    }

    /**
     * GET /api/quotation-templates/{id}
     */
    public function get(int $id): ResponseInterface
    {
        $template = $this->db->table('service_quotation_templates')
            ->where('id', $id)
            ->where('deleted_at IS NULL', null, false)
            ->get()
            ->getRowArray();

        if (!$template) {
            return $this->jsonResponse(false, 'Template not found', null, 404);
        }

        // Fetch items
        $items = $this->db->table('service_quotation_template_items')
            ->where('template_id', $id)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $template['items'] = $items;

        return $this->jsonResponse(true, 'Template fetched', $template);
    }

    /**
     * POST /api/services/{serviceId}/quotation-templates
     */
    public function create(int $serviceId): ResponseInterface
    {
        $companyId = $this->getCompanyId();
        $data = $this->request->getJSON(true) ?: $this->request->getPost();

        $templateName = trim($data['template_name'] ?? '');
        if ($templateName === '') {
            return $this->jsonResponse(false, 'Template name is required', null, 422);
        }

        // If this is set as default, unset others
        $isDefault = (int) ($data['is_default'] ?? 0);
        if ($isDefault) {
            $this->db->table('service_quotation_templates')
                ->where('service_id', $serviceId)
                ->where('company_id', $companyId)
                ->update(['is_default' => 0]);
        }

        $insertData = [
            'service_id'           => $serviceId,
            'company_id'           => $companyId,
            'template_name'        => $templateName,
            'is_default'           => $isDefault,
            'status'               => (int) ($data['status'] ?? 1),
            'company_name'         => $data['company_name'] ?? null,
            'engineer_name'        => $data['engineer_name'] ?? null,
            'recipient_name'       => $data['recipient_name'] ?? null,
            'recipient_location'   => $data['recipient_location'] ?? null,
            'salutation'           => $data['salutation'] ?? 'Dear Sir,',
            'proposal_description' => $data['proposal_description'] ?? null,
            'notes'                => $data['notes'] ?? null,
            'payment_terms'        => $data['payment_terms'] ?? null,
            'work_duration'        => $data['work_duration'] ?? null,
            'additional_notes'     => $data['additional_notes'] ?? null,
            'mobile_number'        => $data['mobile_number'] ?? null,
            'office_number'        => $data['office_number'] ?? null,
            'email_address'        => $data['email_address'] ?? null,
            'office_address'       => $data['office_address'] ?? null,
            'signature_text'       => $data['signature_text'] ?? 'With Regards',
            'signatory_name'       => $data['signatory_name'] ?? null,
            'sections_config'      => $data['sections_config'] ?? null,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ];

        $this->db->table('service_quotation_templates')->insert($insertData);
        $newId = $this->db->insertID();

        // Insert items if provided
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $index => $item) {
                $this->db->table('service_quotation_template_items')->insert([
                    'template_id'     => $newId,
                    'sort_order'      => (int) ($item['sort_order'] ?? $index + 1),
                    'payment_details' => $item['payment_details'] ?? '',
                    'fees'            => (float) ($item['fees'] ?? 0),
                    'remarks'         => $item['remarks'] ?? null,
                    'created_at'      => date('Y-m-d H:i:s'),
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $template = $this->db->table('service_quotation_templates')
            ->where('id', $newId)
            ->get()
            ->getRowArray();

        return $this->jsonResponse(true, 'Quotation template created', $template, 201);
    }

    /**
     * PUT /api/quotation-templates/{id}
     */
    public function update(int $id): ResponseInterface
    {
        $template = $this->db->table('service_quotation_templates')
            ->where('id', $id)
            ->where('deleted_at IS NULL', null, false)
            ->get()
            ->getRowArray();

        if (!$template) {
            return $this->jsonResponse(false, 'Template not found', null, 404);
        }

        $data = $this->request->getJSON(true) ?: $this->request->getPost();

        // If setting as default, unset others
        $isDefault = isset($data['is_default']) ? (int) $data['is_default'] : (int) $template['is_default'];
        if ($isDefault && !(int) $template['is_default']) {
            $this->db->table('service_quotation_templates')
                ->where('service_id', $template['service_id'])
                ->where('company_id', $template['company_id'])
                ->where('id !=', $id)
                ->update(['is_default' => 0]);
        }

        $updateData = [
            'template_name'        => $data['template_name'] ?? $template['template_name'],
            'is_default'           => $isDefault,
            'status'               => isset($data['status']) ? (int) $data['status'] : (int) $template['status'],
            'company_name'         => $data['company_name'] ?? $template['company_name'],
            'engineer_name'        => $data['engineer_name'] ?? $template['engineer_name'],
            'recipient_name'       => $data['recipient_name'] ?? $template['recipient_name'],
            'recipient_location'   => $data['recipient_location'] ?? $template['recipient_location'],
            'salutation'           => $data['salutation'] ?? $template['salutation'],
            'proposal_description' => $data['proposal_description'] ?? $template['proposal_description'],
            'notes'                => $data['notes'] ?? $template['notes'],
            'payment_terms'        => $data['payment_terms'] ?? $template['payment_terms'],
            'work_duration'        => $data['work_duration'] ?? $template['work_duration'],
            'additional_notes'     => $data['additional_notes'] ?? $template['additional_notes'],
            'mobile_number'        => $data['mobile_number'] ?? $template['mobile_number'],
            'office_number'        => $data['office_number'] ?? $template['office_number'],
            'email_address'        => $data['email_address'] ?? $template['email_address'],
            'office_address'       => $data['office_address'] ?? $template['office_address'],
            'signature_text'       => $data['signature_text'] ?? $template['signature_text'],
            'signatory_name'       => $data['signatory_name'] ?? $template['signatory_name'],
            'sections_config'      => $data['sections_config'] ?? $template['sections_config'],
            'updated_at'           => date('Y-m-d H:i:s'),
        ];

        $this->db->table('service_quotation_templates')
            ->where('id', $id)
            ->update($updateData);

        // Sync items if provided (delete + re-insert strategy)
        if (isset($data['items']) && is_array($data['items'])) {
            $this->db->table('service_quotation_template_items')
                ->where('template_id', $id)
                ->delete();

            foreach ($data['items'] as $index => $item) {
                $this->db->table('service_quotation_template_items')->insert([
                    'template_id'     => $id,
                    'sort_order'      => (int) ($item['sort_order'] ?? $index + 1),
                    'payment_details' => $item['payment_details'] ?? '',
                    'fees'            => (float) ($item['fees'] ?? 0),
                    'remarks'         => $item['remarks'] ?? null,
                    'created_at'      => date('Y-m-d H:i:s'),
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $updated = $this->db->table('service_quotation_templates')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        return $this->jsonResponse(true, 'Quotation template updated', $updated);
    }

    /**
     * DELETE /api/quotation-templates/{id}
     */
    public function delete(int $id): ResponseInterface
    {
        $template = $this->db->table('service_quotation_templates')
            ->where('id', $id)
            ->where('deleted_at IS NULL', null, false)
            ->get()
            ->getRowArray();

        if (!$template) {
            return $this->jsonResponse(false, 'Template not found', null, 404);
        }

        // Soft delete
        $this->db->table('service_quotation_templates')
            ->where('id', $id)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        return $this->jsonResponse(true, 'Quotation template deleted');
    }

    /**
     * POST /api/quotation-templates/{id}/upload-signature
     */
    public function uploadSignature(int $id): ResponseInterface
    {
        $template = $this->db->table('service_quotation_templates')
            ->where('id', $id)
            ->where('deleted_at IS NULL', null, false)
            ->get()
            ->getRowArray();

        if (!$template) {
            return $this->jsonResponse(false, 'Template not found', null, 404);
        }

        $file = $this->request->getFile('signature_image');

        if (!$file || !$file->isValid()) {
            return $this->jsonResponse(false, 'No valid file uploaded', null, 422);
        }

        // Validate: only images
        $validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $validTypes)) {
            return $this->jsonResponse(false, 'Only image files (JPG, PNG, GIF, WEBP) are allowed', null, 422);
        }

        // Max 2MB
        if ($file->getSize() > 2 * 1024 * 1024) {
            return $this->jsonResponse(false, 'File size must be under 2MB', null, 422);
        }

        $uploadPath = FCPATH . 'uploads/signatures/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        // Delete old signature if exists
        if (!empty($template['signature_image'])) {
            $oldPath = FCPATH . $template['signature_image'];
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        $newName = 'sig_' . $id . '_' . time() . '.' . $file->getExtension();
        $file->move($uploadPath, $newName);

        $relativePath = 'uploads/signatures/' . $newName;

        $this->db->table('service_quotation_templates')
            ->where('id', $id)
            ->update([
                'signature_image' => $relativePath,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

        return $this->jsonResponse(true, 'Signature uploaded successfully', [
            'signature_image' => $relativePath,
        ]);
    }
}
