<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ServiceDocTemplateItemModel;
use App\Models\ServiceDocTemplateModel;

class ServiceDocTemplateItemController extends BaseController
{
    /**
     * GET /api/doc-templates/{templateId}/items
     */
    public function index($templateId)
    {
        $templateId = (int)$templateId;

        $tplModel = new ServiceDocTemplateModel();
        $tpl = $tplModel->find($templateId);

        if (!$tpl) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Template not found'
            ]);
        }

        $model = new ServiceDocTemplateItemModel();

        $rows = $model->where('doc_template_id', $templateId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('item_no', 'ASC')
            ->findAll();

        return $this->response->setJSON([
            'success' => true,
            'data'    => [
                'template' => $tpl,
                'items'    => $rows
            ]
        ]);
    }

    /**
     * POST /api/doc-templates/{templateId}/items
     */
    public function create($templateId)
    {
        $templateId = (int)$templateId;

        $tplModel = new ServiceDocTemplateModel();
        $tpl = $tplModel->find($templateId);

        if (!$tpl) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Template not found'
            ]);
        }

        $data = $this->request->getJSON(true) ?? [];

        // ✅ provided_by validation
        $providedBy = strtolower(trim((string)($data['provided_by'] ?? 'client')));
        if (!in_array($providedBy, ['client', 'northpoint'], true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => ['provided_by' => 'Invalid value. Allowed: client, northpoint']
            ]);
        }

        $payload = [
            'doc_template_id'    => $templateId,
            'item_no'            => (int)($data['item_no'] ?? 0),
            'document_title'     => trim((string)($data['document_title'] ?? '')),
            'description'        => $data['description'] ?? null,
            'copies_required'    => (int)($data['copies_required'] ?? 1),
            'is_mandatory'       => (int)($data['is_mandatory'] ?? 1),

            // ✅ NEW
            'provided_by'        => $providedBy,

            'allowed_file_types' => trim((string)($data['allowed_file_types'] ?? 'pdf,jpg,jpeg,png')),
            'sort_order'         => (int)($data['sort_order'] ?? 0),
        ];

        // Auto item_no & sort_order if not sent
        $model = new ServiceDocTemplateItemModel();

        if ($payload['item_no'] <= 0 || $payload['sort_order'] <= 0) {
            $max = $model->selectMax('sort_order', 'max_sort')
                ->where('doc_template_id', $templateId)
                ->first();
            $next = (int)($max['max_sort'] ?? 0) + 1;

            if ($payload['sort_order'] <= 0) $payload['sort_order'] = $next;
            if ($payload['item_no'] <= 0)    $payload['item_no']    = $next;
        }

        if (!$model->insert($payload)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $model->errors()
            ]);
        }

        return $this->response->setStatusCode(201)->setJSON([
            'success' => true,
            'message' => 'Checklist item created',
            'data'    => $model->find($model->getInsertID())
        ]);
    }

    /**
     * PUT /api/doc-template-items/{id}
     */
    public function update($id)
    {
        $id = (int)$id;

        $model = new ServiceDocTemplateItemModel();
        $row = $model->find($id);

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Item not found'
            ]);
        }

        $data = $this->request->getJSON(true) ?? [];

        $payload = [];
        foreach ($model->allowedFields as $f) {
            if (array_key_exists($f, $data)) {
                $payload[$f] = $data[$f];
            }
        }

        if (isset($payload['document_title'])) {
            $payload['document_title'] = trim((string)$payload['document_title']);
        }
        if (isset($payload['allowed_file_types'])) {
            $payload['allowed_file_types'] = trim((string)$payload['allowed_file_types']);
        }

        // ✅ provided_by validation (only if sent)
        if (isset($payload['provided_by'])) {
            $payload['provided_by'] = strtolower(trim((string)$payload['provided_by']));
            if (!in_array($payload['provided_by'], ['client', 'northpoint'], true)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => ['provided_by' => 'Invalid value. Allowed: client, northpoint']
                ]);
            }
        }

        if (!$model->update($id, $payload)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Update failed',
                'errors'  => $model->errors()
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Checklist item updated',
            'data'    => $model->find($id)
        ]);
    }

    /**
     * DELETE /api/doc-template-items/{id}
     */
    public function delete($id)
    {
        $id = (int)$id;

        $model = new ServiceDocTemplateItemModel();
        $row = $model->find($id);

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Item not found'
            ]);
        }

        $model->delete($id);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Checklist item deleted'
        ]);
    }
}
