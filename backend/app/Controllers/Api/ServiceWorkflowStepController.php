<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ServiceWorkflowStepModel;
use App\Models\ServiceWorkflowTemplateModel;

class ServiceWorkflowStepController extends BaseController
{
    /**
     * GET /api/workflow-templates/{templateId}/steps
     */
    public function index($templateId)
    {
        $templateId = (int) $templateId;

        $tplModel = new ServiceWorkflowTemplateModel();
        $tpl      = $tplModel->find($templateId);

        if (!$tpl) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Workflow template not found'
            ]);
        }

        $model = new ServiceWorkflowStepModel();
        $rows  = $model->where('workflow_template_id', $templateId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('step_no', 'ASC')
            ->findAll();

        return $this->response->setJSON([
            'success' => true,
            'data'    => [
                'template' => $tpl,
                'steps'    => $rows
            ]
        ]);
    }

    /**
     * POST /api/workflow-templates/{templateId}/steps
     */
    public function create($templateId)
    {
        $templateId = (int) $templateId;

        $tplModel = new ServiceWorkflowTemplateModel();
        $tpl      = $tplModel->find($templateId);

        if (!$tpl) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Workflow template not found'
            ]);
        }

        $data = $this->request->getJSON(true) ?? [];

        // ✅ THESE 4 LINES WERE MISSING IN YOUR FILE
        $approvalRoleId = isset($data['approval_role_id']) ? (int) $data['approval_role_id'] : null;
        $approvalUserId = isset($data['approval_user_id']) ? (int) $data['approval_user_id'] : null;
        $followByRoleId = isset($data['follow_by_role_id']) ? (int) $data['follow_by_role_id'] : null;
        $followByUserId = isset($data['follow_by_user_id']) ? (int) $data['follow_by_user_id'] : null;

        $model = new ServiceWorkflowStepModel();

        // auto step_no and sort_order if not provided
        $max  = $model->selectMax('sort_order', 'max_sort')
            ->where('workflow_template_id', $templateId)
            ->first();
        $next = (int) ($max['max_sort'] ?? 0) + 1;

        $payload = [
            'workflow_template_id' => $templateId,
            'step_no'              => (int) ($data['step_no'] ?? 0),
            'step_title'           => trim((string) ($data['step_title'] ?? '')),
            'step_description'     => $data['step_description'] ?? null,
            'approval_stage'       => (int) ($data['approval_stage'] ?? 0),

            // ✅ NEVER SEND 0 when FK exists -> send NULL
            'approval_role_id'     => ($approvalRoleId && $approvalRoleId > 0) ? $approvalRoleId : null,
            'approval_user_id'     => ($approvalUserId && $approvalUserId > 0) ? $approvalUserId : null,

            'follow_by_role_id'    => ($followByRoleId && $followByRoleId > 0) ? $followByRoleId : null,
            'follow_by_user_id'    => ($followByUserId && $followByUserId > 0) ? $followByUserId : null,

            'sla_days'             => (int) ($data['sla_days'] ?? 0),
            'depends_on_step_no'   => (int) ($data['depends_on_step_no'] ?? 0),

            'sort_order'           => (int) ($data['sort_order'] ?? 0),
        ];

        if ($payload['sort_order'] <= 0) {
            $payload['sort_order'] = $next;
        }
        if ($payload['step_no'] <= 0) {
            $payload['step_no'] = $next;
        }

        // ✅ If approval_stage = 0, FK fields MUST be NULL (not 0)
        if ((int) $payload['approval_stage'] === 0) {
            $payload['approval_role_id'] = null;
            $payload['approval_user_id'] = null;
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
            'message' => 'Workflow step created',
            'data'    => $model->find($model->getInsertID())
        ]);
    }

    /**
     * PUT /api/workflow-steps/{id}
     */
    public function update($id)
    {
        $id = (int) $id;

        $model = new ServiceWorkflowStepModel();
        $row   = $model->find($id);

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Step not found'
            ]);
        }

        $data = $this->request->getJSON(true) ?? [];

        $payload = [];
        foreach ($model->allowedFields as $f) {
            if (array_key_exists($f, $data)) {
                $payload[$f] = $data[$f];
            }
        }

        if (isset($payload['step_title'])) {
            $payload['step_title'] = trim((string) $payload['step_title']);
        }

        // ✅ If approval_stage set to 0, FK fields MUST be NULL (not 0)
        if (isset($payload['approval_stage']) && (int) $payload['approval_stage'] === 0) {
            $payload['approval_role_id'] = null;
            $payload['approval_user_id'] = null;
        }

        // ✅ Normalize numeric FK fields: if 0 provided, make it NULL
        foreach (['approval_role_id','approval_user_id','follow_by_role_id','follow_by_user_id'] as $fk) {
            if (array_key_exists($fk, $payload)) {
                $v = (int) $payload[$fk];
                $payload[$fk] = ($v > 0) ? $v : null;
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
            'message' => 'Workflow step updated',
            'data'    => $model->find($id)
        ]);
    }

    /**
     * DELETE /api/workflow-steps/{id}
     */
    public function delete($id)
    {
        $id = (int) $id;

        $model = new ServiceWorkflowStepModel();
        $row   = $model->find($id);

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Step not found'
            ]);
        }

        $model->delete($id);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Workflow step deleted'
        ]);
    }
}
