<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ProjectModel;

class ProjectServiceDataController extends BaseController
{
    /**
     * GET /api/projects/{projectId}/service-data
     */
    public function index($projectId)
    {
        $projectId = (int)$projectId;

        $projectModel = new ProjectModel();
        $project = $projectModel->find($projectId);

        if (!$project) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Project not found'
            ]);
        }

        $db = db_connect();

        $rows = $db->table('project_service_data')
            ->where('project_id', $projectId)
            ->orderBy('field_key', 'ASC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'success' => true,
            'data' => [
                'project_id' => $projectId,
                'service_id' => (int)$project['service_id'],
                'fields'     => $rows
            ]
        ]);
    }

    /**
     * POST /api/projects/{projectId}/service-data
     *
     * Saves service-specific data
     * AND generates default tasks (only once)
     */
    public function save($projectId)
    {
        $projectId = (int)$projectId;
        $data = $this->request->getJSON(true) ?? [];

        if (!isset($data['fields']) || !is_array($data['fields'])) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Invalid fields payload'
            ]);
        }

        $createdBy = (int)($_SERVER['HTTP_X_USER_ID'] ?? 0);
        if ($createdBy <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'Unauthorized'
            ]);
        }

        $projectModel = new ProjectModel();
        $project = $projectModel->find($projectId);

        if (!$project) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Project not found'
            ]);
        }

        $serviceId = (int)$project['service_id'];
        $db = db_connect();

        $db->transBegin();

        /* =====================================================
         * 1) SAVE / UPDATE SERVICE DATA
         * ===================================================== */
        foreach ($data['fields'] as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') continue;

            $existing = $db->table('project_service_data')
                ->where([
                    'project_id' => $projectId,
                    'service_id' => $serviceId,
                    'field_key'  => $key
                ])
                ->get()
                ->getRowArray();

            if ($existing) {
                $db->table('project_service_data')
                    ->where('id', $existing['id'])
                    ->update([
                        'field_value' => (string)$value
                    ]);
            } else {
                $db->table('project_service_data')->insert([
                    'project_id'  => $projectId,
                    'service_id'  => $serviceId,
                    'field_key'   => $key,
                    'field_value' => (string)$value
                ]);
            }
        }

        /* =====================================================
         * 2) GENERATE DEFAULT TASKS (ONLY ONCE)
         * ===================================================== */
        $already = $db->table('project_tasks')
            ->where('project_id', $projectId)
            ->where('generated_from_template', 1)
            ->countAllResults();

        $tasksCreated = 0;
        $tasksAlreadyGenerated = ($already > 0);

        if (!$tasksAlreadyGenerated) {

            $tpl = $db->table('service_task_templates')
                ->where('service_id', $serviceId)
                ->where('status', 1)
                ->orderBy('is_default', 'DESC')
                ->orderBy('id', 'DESC')
                ->get()
                ->getRowArray();

            if ($tpl) {
                $items = $db->table('service_task_template_items')
                    ->where('template_id', (int)$tpl['id'])
                    ->where('status', 1)
                    ->orderBy('sort_order', 'ASC')
                    ->get()
                    ->getResultArray();

                if (!empty($items)) {
                    $now = date('Y-m-d H:i:s');
                    $projectDate = substr($project['created_at'] ?? $now, 0, 10);

                    $batch = [];
                    foreach ($items as $it) {
                        $dueDate = null;
                        if ($it['default_due_days'] !== null && $it['default_due_days'] !== '') {
                            $dueDate = date(
                                'Y-m-d',
                                strtotime($projectDate . ' +' . (int)$it['default_due_days'] . ' days')
                            );
                        }

                        $batch[] = [
                            'project_id'              => $projectId,
                            'template_item_id'        => (int)$it['id'],
                            'generated_from_template' => 1,

                            'task_title'       => $it['task_name'],
                            'task_description' => $it['task_description'] ?? null,

                            'status'     => 'pending',
                            'priority'   => 'normal',
                            'sort_order' => (int)$it['sort_order'],

                            'start_date'   => null,
                            'due_date'     => $dueDate,
                            'completed_at' => null,

                            'assigned_to' => !empty($it['default_assigned_user_id']) 
                                ? (int)$it['default_assigned_user_id'] 
                                : (!empty($tpl['default_assigned_user_id']) ? (int)$tpl['default_assigned_user_id'] : null),
                            'created_by'  => $createdBy,

                            'is_milestone' => (int)($it['is_milestone'] ?? 0),
                            'is_mandatory' => (int)($it['is_mandatory'] ?? 1),
                            'enable_whatsapp_msg' => !empty($it['enable_whatsapp_msg']) ? 1 : 0,
                            'whatsapp_message' => $it['whatsapp_message'] ?? null,

                            'created_at' => $now,
                            'updated_at' => $now
                        ];
                    }

                    // ensure columns exist
                    try {
                        $ptFields = $db->getFieldNames('project_tasks');
                        if (!in_array('enable_whatsapp_msg', $ptFields, true)) {
                            $db->query("ALTER TABLE `project_tasks` ADD COLUMN `enable_whatsapp_msg` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_mandatory`");
                        }
                        if (!in_array('whatsapp_message', $ptFields, true)) {
                            $db->query("ALTER TABLE `project_tasks` ADD COLUMN `whatsapp_message` TEXT NULL DEFAULT NULL AFTER `enable_whatsapp_msg`");
                        }
                    } catch (\Throwable $e) {}

                    $db->table('project_tasks')->insertBatch($batch);
                    $tasksCreated = count($batch);
                }
            }
        }

        if ($db->transStatus() === false) {
            $db->transRollback();
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Failed to save service data'
            ]);
        }

        $db->transCommit();

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Service data saved successfully',
            'data' => [
                'project_id' => $projectId,
                'service_id' => $serviceId,
                'tasks_created' => $tasksCreated,
                'tasks_already_generated' => $tasksAlreadyGenerated
            ]
        ]);
    }
}
