<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ProjectWorkflowStepModel;
use App\Models\ProjectWorkflowStepFileModel;

class ProjectWorkflowFileController extends BaseController
{
    /**
     * GET /api/project-workflow-steps/{stepId}/files
     */
    public function index($stepId)
    {
        $stepId = (int) $stepId;

        $stepModel = new ProjectWorkflowStepModel();
        $step = $stepModel->find($stepId);

        if (!$step) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Workflow step not found'
            ]);
        }

        $fileModel = new ProjectWorkflowStepFileModel();
        $files = $fileModel->where('project_workflow_step_id', $stepId)
            ->orderBy('id', 'DESC')
            ->findAll();

        return $this->response->setJSON([
            'success' => true,
            'data' => [
                'step'  => $step,
                'files' => $files,
            ]
        ]);
    }

    /**
     * POST /api/project-workflow-steps/{stepId}/files
     * Form-data: files[] (multiple) OR file (single)
     */
    public function upload($stepId)
    {
        $stepId = (int) $stepId;

        $stepModel = new ProjectWorkflowStepModel();
        $step = $stepModel->find($stepId);

        if (!$step) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Workflow step not found'
            ]);
        }

        // accept both "file" and "files[]"
        $files = $this->request->getFiles();

        $incoming = [];
        if (!empty($files['files'])) {
            // files[] multiple
            $incoming = is_array($files['files']) ? $files['files'] : [$files['files']];
        } elseif (!empty($files['file'])) {
            // file single
            $incoming = [$files['file']];
        }

        if (empty($incoming)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'No file uploaded. Use form-data key "file" or "files[]"'
            ]);
        }

        $allowedExt = ['pdf','jpg','jpeg','png','doc','docx','xls','xlsx','csv'];

        $uploadRoot = WRITEPATH . 'uploads/project_workflow_steps/' . $stepId;
        if (!is_dir($uploadRoot)) {
            mkdir($uploadRoot, 0777, true);
        }

        $fileModel = new ProjectWorkflowStepFileModel();
        $saved = [];
        $userId = (int)($_SERVER['HTTP_X_USER_ID'] ?? 0);

        foreach ($incoming as $f) {
            if (!$f || !$f->isValid()) {
                continue;
            }

            $ext = strtolower($f->getClientExtension() ?? '');
            if (!in_array($ext, $allowedExt)) {
                continue;
            }

            // safe name
            $originalName = $f->getClientName();
            $newName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

            $f->move($uploadRoot, $newName);

            $absolutePath = $uploadRoot . DIRECTORY_SEPARATOR . $newName;
            $sizeKb = file_exists($absolutePath) ? (int)ceil(filesize($absolutePath) / 1024) : null;

            // store relative path (clean)
            $relativePath = 'uploads/project_workflow_steps/' . $stepId . '/' . $newName;

            $id = $fileModel->insert([
                'project_workflow_step_id' => $stepId,
                'file_path'   => $relativePath,
                'file_name'   => $originalName,
                'file_ext'    => $ext,
                'file_size_kb'=> $sizeKb,
                'uploaded_by' => $userId,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ]);

            if ($id) {
                $saved[] = $fileModel->find($id);
            }
        }

        if (empty($saved)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'No valid files saved (check extension/valid upload)',
            ]);
        }

        // OPTIONAL: mark step as in_progress when first file uploaded
        if (($step['status'] ?? '') === 'not_started') {
            $stepModel->update($stepId, [
                'status'     => 'in_progress',
                'started_at' => date('Y-m-d H:i:s'),
                'updated_by' => $userId,
            ]);
        }

        return $this->response->setStatusCode(201)->setJSON([
            'success' => true,
            'message' => 'Files uploaded',
            'data'    => [
                'step_id' => $stepId,
                'files'   => $saved
            ]
        ]);
    }

    /**
     * DELETE /api/project-workflow-files/{id}
     */
    public function delete($id)
    {
        $id = (int) $id;

        $fileModel = new ProjectWorkflowStepFileModel();
        $row = $fileModel->find($id);

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'File not found'
            ]);
        }

        // delete physical file (if exists)
        $relative = $row['file_path'] ?? '';
        $absolute = WRITEPATH . ltrim(str_replace('uploads/', 'uploads/', $relative), '/');

        // NOTE: we stored relative as "uploads/..." inside WRITEPATH, so build properly:
        // WRITEPATH . 'uploads/...'
        if (str_starts_with($relative, 'uploads/')) {
            $absolute = WRITEPATH . $relative;
        }

        if (is_file($absolute)) {
            @unlink($absolute);
        }

        $fileModel->delete($id);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'File deleted'
        ]);
    }
}
