<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ProjectWorkflowModel;
use App\Models\ProjectWorkflowStepModel;
use App\Models\ProjectWorkflowStepApprovalModel;

class ProjectWorkflowApprovalController extends BaseController
{
    /**
     * POST /api/project-workflow-steps/{stepId}/decision
     * Body:
     * {
     *   "decision": "approved" | "rejected",
     *   "remarks": "optional text"
     * }
     */
    public function decision($stepId)
    {
        $stepId = (int)$stepId;
        $data   = $this->request->getJSON(true) ?? [];

        $decision = strtolower(trim((string)($data['decision'] ?? '')));
        $remarks  = $data['remarks'] ?? null;

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'decision must be "approved" or "rejected"',
            ]);
        }

        // Logged-in user info (AuthFilter injects these)
        $userId = (int)($_SERVER['HTTP_X_USER_ID'] ?? 0);
        $roleId = (int)($_SERVER['HTTP_X_ROLE_ID'] ?? 0);

        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'Unauthenticated',
            ]);
        }

        $stepModel = new ProjectWorkflowStepModel();
        $wfModel   = new ProjectWorkflowModel();
        $appModel  = new ProjectWorkflowStepApprovalModel();

        $step = $stepModel->find($stepId);

        if (!$step) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Workflow step not found',
            ]);
        }

        // Only approval steps can be decided
        if ((int)($step['approval_stage'] ?? 0) !== 1) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'This step is not an approval stage',
            ]);
        }

        // Block re-decision
        $currentStatus = (string)($step['status'] ?? 'not_started');
        if (in_array($currentStatus, ['approved', 'rejected'], true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'This step is already decided',
            ]);
        }

        // Authorization at step-level (role/user)
        $approvalRoleId = (int)($step['approval_role_id'] ?? 0);
        $approvalUserId = (int)($step['approval_user_id'] ?? 0);

        // If specific user is set, only that user can decide
        if ($approvalUserId > 0 && $approvalUserId !== $userId) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => 'Access denied (not the assigned approval user)',
            ]);
        }

        // Else if role is set, user must match role
        if ($approvalUserId <= 0 && $approvalRoleId > 0 && $approvalRoleId !== $roleId) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => 'Access denied (role mismatch for approval)',
            ]);
        }

        $db = \Config\Database::connect();
        $db->transStart();

        // 1) Update this step
        $now = date('Y-m-d H:i:s');

        $stepModel->update($stepId, [
            'status'      => $decision,     // 'approved' or 'rejected'
            'completed_at'=> $now,
            'updated_by'  => $userId,
            // keep started_at as-is
        ]);

        // 2) Insert decision log
        $appModel->insert([
            'project_workflow_step_id' => $stepId,
            'decision'                 => $decision,
            'decision_by'              => $userId,
            'decision_at'              => $now,
            'remarks'                  => $remarks,
        ]);

        $projectWorkflowId = (int)$step['project_workflow_id'];

        // 3) If rejected: stop workflow (no next step auto-start)
        if ($decision === 'rejected') {
            $wfModel->update($projectWorkflowId, [
                'completed_at' => $now,
            ]);

            $db->transComplete();

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Step rejected. Workflow marked completed.',
                'data' => [
                    'step_id' => $stepId,
                    'decision'=> $decision,
                ]
            ]);
        }

        // 4) If approved: start next step OR complete workflow if last step
        $nextStep = $stepModel
            ->where('project_workflow_id', $projectWorkflowId)
            ->where('step_no >', (int)$step['step_no'])
            ->orderBy('step_no', 'ASC')
            ->first();

        if ($nextStep) {
            // Start next step (only if not started yet)
            if (in_array((string)$nextStep['status'], ['not_started', ''], true)) {
                $stepModel->update((int)$nextStep['id'], [
                    'status'     => 'in_progress',
                    'started_at' => $now,
                    'updated_by' => $userId,
                ]);
            }

            $db->transComplete();

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Step approved. Next step moved to in_progress.',
                'data' => [
                    'step_id'        => $stepId,
                    'decision'       => $decision,
                    'next_step_id'   => (int)$nextStep['id'],
                    'next_step_no'   => (int)$nextStep['step_no'],
                ]
            ]);
        }

        // No next step => workflow completed
        $wfModel->update($projectWorkflowId, [
            'completed_at' => $now,
        ]);

        $db->transComplete();

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Step approved. Workflow completed.',
            'data' => [
                'step_id'  => $stepId,
                'decision' => $decision,
            ]
        ]);
    }
}
