<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ProjectWorkflowModel;
use App\Models\ProjectWorkflowStepModel;
use App\Models\ServiceWorkflowTemplateModel;
use App\Models\ServiceWorkflowStepModel;

class ProjectWorkflowController extends BaseController
{
    /**
     * POST /api/projects/{projectId}/start-workflow
     * Body: { "workflow_template_id": 1 }
     */
    public function start($projectId)
    {
        $projectId = (int)$projectId;
        $data = $this->request->getJSON(true) ?? [];

        if ($projectId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Invalid projectId'
            ]);
        }

        if (empty($data['workflow_template_id'])) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'workflow_template_id is required'
            ]);
        }

        $templateId = (int)$data['workflow_template_id'];

        // ✅ Validate template exists
        $tplModel  = new ServiceWorkflowTemplateModel();
        $template  = $tplModel->find($templateId);

        if (!$template) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Workflow template not found'
            ]);
        }

        // ✅ Validate project exists (FK safety)
        $db = \Config\Database::connect();
        $project = $db->table('projects')->where('id', $projectId)->get()->getRowArray();

        if (!$project) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Project not found'
            ]);
        }

        $pwModel          = new ProjectWorkflowModel();
        $projectStepModel = new ProjectWorkflowStepModel();
        $stepTplModel     = new ServiceWorkflowStepModel();

        $db->transBegin();

        try {

            // ✅ Insert workflow row
            $ok = $pwModel->insert([
                'project_id'           => $projectId,
                'workflow_template_id' => $templateId,
                'template_version'     => (int)($template['version'] ?? 1),
                'started_at'           => date('Y-m-d H:i:s'),
            ]);

            if (!$ok) {
                $db->transRollback();
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => 'Failed to start workflow (project_workflows insert failed)',
                    'errors'  => $pwModel->errors(),
                ]);
            }

            // ✅ VERY IMPORTANT: get insert id
            $projectWorkflowId = (int)$pwModel->getInsertID();

            if ($projectWorkflowId <= 0) {
                $db->transRollback();
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'Workflow created but insert id not returned. Check model allowedFields / DB.'
                ]);
            }

            // ✅ Copy steps from template
            $steps = $stepTplModel
                ->where('workflow_template_id', $templateId)
                ->orderBy('step_no', 'ASC')
                ->findAll();

            foreach ($steps as $step) {
                $ok2 = $projectStepModel->insert([
                    'project_workflow_id'       => $projectWorkflowId,
                    'service_workflow_step_id'  => (int)$step['id'],
                    'step_no'                   => (int)$step['step_no'],
                    'step_title'                => (string)$step['step_title'],
                    'step_description'          => $step['step_description'] ?? null,
                    'follow_by_role_id'         => $step['follow_by_role_id'] ?? null,
                    'follow_by_user_id'         => $step['follow_by_user_id'] ?? null,
                    'approval_stage'            => (int)($step['approval_stage'] ?? 0),
                    'approval_role_id'          => $step['approval_role_id'] ?? null,
                    'approval_user_id'          => $step['approval_user_id'] ?? null,
                    'status'                    => 'not_started',
                ]);

                if (!$ok2) {
                    $db->transRollback();
                    return $this->response->setStatusCode(422)->setJSON([
                        'success' => false,
                        'message' => 'Failed to copy workflow steps',
                        'errors'  => $projectStepModel->errors(),
                    ]);
                }
            }

            // ✅ Start first step automatically (PASTE HERE – correct place)
            $firstStep = $projectStepModel
                ->where('project_workflow_id', $projectWorkflowId)
                ->orderBy('step_no', 'ASC')
                ->first();

            if ($firstStep) {
                $projectStepModel->update((int)$firstStep['id'], [
                    'status'     => 'in_progress',
                    'started_at' => date('Y-m-d H:i:s'),
                    'updated_by' => (int)($_SERVER['HTTP_X_USER_ID'] ?? 0),
                ]);
            }

            $db->transCommit();

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Workflow started for project',
                'data' => [
                    'project_workflow_id' => $projectWorkflowId,
                    'steps_created'       => count($steps),
                ]
            ]);

        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/projects/{projectId}/workflow
     */
    public function showByProject($projectId)
    {
        $projectId = (int)$projectId;

        $workflowModel = new ProjectWorkflowModel();
        $stepModel     = new ProjectWorkflowStepModel();

        $workflow = $workflowModel
            ->where('project_id', $projectId)
            ->orderBy('id', 'DESC')
            ->first();

        if (!$workflow) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'No workflow started for this project'
            ]);
        }

        $steps = $stepModel
            ->where('project_workflow_id', (int)$workflow['id'])
            ->orderBy('step_no', 'ASC')
            ->findAll();

        return $this->response->setJSON([
            'success' => true,
            'data' => [
                'workflow' => $workflow,
                'steps'    => $steps
            ]
        ]);
    }

    /**
     * OPTIONAL: GET /api/projects/{projectId}/workflow-summary
     */
    public function summary($projectId)
    {
        // Your existing summary is fine.
        // Keep it, but make sure route exists if you want to call it.
        return $this->response->setStatusCode(501)->setJSON([
            'success' => false,
            'message' => 'summary() route not added yet'
        ]);
    }
}
