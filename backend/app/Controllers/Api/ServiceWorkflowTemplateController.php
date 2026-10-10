<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ServiceWorkflowTemplateModel;
use App\Models\ServiceModel;

class ServiceWorkflowTemplateController extends BaseController
{
    /**
     * GET /api/services/{serviceId}/workflow-templates
     */
    public function index($serviceId)
    {
        $serviceId = (int)$serviceId;

        $serviceModel = new ServiceModel();
        $service = $serviceModel->find($serviceId);

        if (!$service) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Service not found'
            ]);
        }

        $model = new ServiceWorkflowTemplateModel();

        $rows = $model->where('service_id', $serviceId)
            ->orderBy('is_active', 'DESC')
            ->orderBy('version', 'DESC')
            ->findAll();

        return $this->response->setJSON([
            'success' => true,
            'data' => [
                'service'   => $service,
                'templates' => $rows
            ]
        ]);
    }

    /**
     * POST /api/services/{serviceId}/workflow-templates
     * Body:
     * { "template_name": "Panchayat Approval - Workflow" }
     *
     * Creates next version and sets active, deactivating old ones.
     */
    public function create($serviceId)
    {
        $serviceId = (int)$serviceId;

        $serviceModel = new ServiceModel();
        $service = $serviceModel->find($serviceId);

        if (!$service) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Service not found'
            ]);
        }

        $data = $this->request->getJSON(true) ?? [];
        $templateName = trim((string)($data['template_name'] ?? ''));

        if ($templateName === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => ['template_name' => 'Template name is required']
            ]);
        }

        $model = new ServiceWorkflowTemplateModel();

        // Next version = max(version)+1
        $maxRow = $model->selectMax('version', 'max_version')
            ->where('service_id', $serviceId)
            ->first();

        $nextVersion = (int)($maxRow['max_version'] ?? 0) + 1;

        // Deactivate previous templates for this service
        $model->where('service_id', $serviceId)->set(['is_active' => 0])->update();

        $payload = [
            'service_id'    => $serviceId,
            'template_name' => $templateName,
            'version'       => $nextVersion,
            'is_active'     => 1,
        ];

        if (!$model->insert($payload)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $model->errors()
            ]);
        }

        return $this->response->setStatusCode(201)->setJSON([
            'success' => true,
            'message' => 'Workflow template created and set as active',
            'data'    => $model->find($model->getInsertID())
        ]);
    }

    /**
     * PUT /api/workflow-templates/{templateId}/activate
     * Activates an old template and deactivates others in same service.
     */
    public function activate($templateId)
    {
        $templateId = (int)$templateId;

        $model = new ServiceWorkflowTemplateModel();
        $tpl = $model->find($templateId);

        if (!$tpl) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Workflow template not found'
            ]);
        }

        $serviceId = (int)$tpl['service_id'];

        $model->where('service_id', $serviceId)->set(['is_active' => 0])->update();
        $model->update($templateId, ['is_active' => 1]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Workflow template activated',
            'data'    => $model->find($templateId)
        ]);
    }
}
