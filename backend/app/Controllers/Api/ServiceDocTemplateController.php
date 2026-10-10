<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ServiceDocTemplateModel;
use App\Models\ServiceModel;

class ServiceDocTemplateController extends BaseController
{
    public function index($serviceId)
    {
        $serviceId = (int) $serviceId;

        $serviceModel = new ServiceModel();
        $service = $serviceModel->find($serviceId);

        if (!$service) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Service not found',
            ]);
        }

        $model = new ServiceDocTemplateModel();

        $rows = $model->where('service_id', $serviceId)
            ->orderBy('is_active', 'DESC')
            ->orderBy('version', 'DESC')
            ->findAll();

        return $this->response->setJSON([
            'success' => true,
            'data'    => [
                'service'   => $service,
                'templates' => $rows,
            ],
        ]);
    }

  public function create($serviceId)
	{
		$serviceId = (int) $serviceId;

		$serviceModel = new \App\Models\ServiceModel();
		$service = $serviceModel->find($serviceId);

		if (!$service) {
			return $this->response->setStatusCode(404)->setJSON([
				'success' => false,
				'message' => 'Service not found',
			]);
		}

		$data = $this->request->getJSON(true) ?? [];
		$templateName = trim((string)($data['template_name'] ?? ''));

		if ($templateName === '') {
			return $this->response->setStatusCode(422)->setJSON([
				'success' => false,
				'message' => 'Validation failed',
				'errors'  => ['template_name' => 'Template name is required'],
			]);
		}

		$db    = \Config\Database::connect();
		$model = new \App\Models\ServiceDocTemplateModel();

		$db->transStart();

		// (A) get next version
		$maxRow = $model->selectMax('version', 'max_version')
			->where('service_id', $serviceId)
			->first();

		$nextVersion = (int)($maxRow['max_version'] ?? 0) + 1;

		// (B) deactivate all existing templates of this service
		$db->table('service_doc_templates')
			->where('service_id', $serviceId)
			->update(['is_active' => 0]);

		// (C) insert new template as active
		$payload = [
			'service_id'    => $serviceId,
			'template_name' => $templateName,
			'version'       => $nextVersion,
			'is_active'     => 1,
		];

		$ok = $model->insert($payload);

		if (!$ok) {
			$db->transRollback();
			return $this->response->setStatusCode(422)->setJSON([
				'success' => false,
				'message' => 'Validation failed',
				'errors'  => $model->errors(),
			]);
		}

		$newId = $model->getInsertID();

		$db->transComplete();
		if ($db->transStatus() === false) {
			return $this->response->setStatusCode(500)->setJSON([
				'success' => false,
				'message' => 'DB transaction failed while creating template',
			]);
		}

		return $this->response->setStatusCode(201)->setJSON([
			'success' => true,
			'message' => 'Document checklist template created and set as active',
			'data'    => $model->find($newId),
		]);
	}

	public function activate($templateId)
	{
		$templateId = (int) $templateId;

		$db    = \Config\Database::connect();
		$model = new \App\Models\ServiceDocTemplateModel();

		$tpl = $model->find($templateId);

		if (!$tpl) {
			return $this->response->setStatusCode(404)->setJSON([
				'success' => false,
				'message' => 'Template not found',
			]);
		}

		$serviceId = (int)$tpl['service_id'];

		$db->transStart();

		// (A) Deactivate all templates for this service
		$db->table('service_doc_templates')
			->where('service_id', $serviceId)
			->update(['is_active' => 0]);

		// (B) Activate selected template
		$model->update($templateId, ['is_active' => 1]);

		$db->transComplete();
		if ($db->transStatus() === false) {
			return $this->response->setStatusCode(500)->setJSON([
				'success' => false,
				'message' => 'DB transaction failed while activating template',
			]);
		}

		return $this->response->setJSON([
			'success' => true,
			'message' => 'Template activated',
			'data'    => $model->find($templateId),
		]);
	}

    // =========================================================
    // ADD THESE (FULL CRUD)
    // =========================================================

    /**
     * GET /api/doc-templates/{templateId}
     */
    public function show($templateId)
    {
        $templateId = (int) $templateId;

        $model = new ServiceDocTemplateModel();
        $tpl = $model->find($templateId);

        if (!$tpl) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Template not found',
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'data'    => $tpl,
        ]);
    }

    /**
     * PUT/PATCH /api/doc-templates/{templateId}
     * Rename template_name (does NOT create new version)
     */
    public function update($templateId)
    {
        $templateId = (int) $templateId;

        $model = new ServiceDocTemplateModel();
        $tpl = $model->find($templateId);

        if (!$tpl) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Template not found',
            ]);
        }

        $data = $this->request->getJSON(true) ?? [];
        $templateName = trim((string)($data['template_name'] ?? ''));

        if ($templateName === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => ['template_name' => 'Template name is required'],
            ]);
        }

        if (!$model->update($templateId, ['template_name' => $templateName])) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Update failed',
                'errors'  => $model->errors(),
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Template updated',
            'data'    => $model->find($templateId),
        ]);
    }

    /**
     * PUT /api/doc-templates/{templateId}/deactivate
     */
    public function deactivate($templateId)
    {
        $templateId = (int) $templateId;

        $model = new ServiceDocTemplateModel();
        $tpl = $model->find($templateId);

        if (!$tpl) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Template not found',
            ]);
        }

        $model->update($templateId, ['is_active' => 0]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Template deactivated',
            'data'    => $model->find($templateId),
        ]);
    }

    /**
     * DELETE /api/doc-templates/{templateId}
     * If deleted template was active, auto-activate the latest remaining version for that service.
     */
    public function delete($templateId)
    {
        $templateId = (int) $templateId;

        $model = new ServiceDocTemplateModel();
        $tpl = $model->find($templateId);

        if (!$tpl) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Template not found',
            ]);
        }

        $serviceId = (int) $tpl['service_id'];
        $wasActive = (int) $tpl['is_active'] === 1;

        $model->delete($templateId);

        // if active deleted => set latest version as active
        if ($wasActive) {
            $latest = $model->where('service_id', $serviceId)
                ->orderBy('version', 'DESC')
                ->first();

            if ($latest) {
                $model->where('service_id', $serviceId)->set(['is_active' => 0])->update();
                $model->update((int)$latest['id'], ['is_active' => 1]);
            }
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Template deleted',
        ]);
    }
}
