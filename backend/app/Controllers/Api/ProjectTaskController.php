<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ProjectModel;
use App\Libraries\WhatsAppService;
use CodeIgniter\HTTP\ResponseInterface;

class ProjectTaskController extends BaseController
{
    // =========================
    // Helpers
    // =========================
    private function authUserId(): ?int
    {
        $u = $this->request->user ?? null;
        if (is_array($u) && isset($u['id'])) return (int)$u['id'];
        if (is_object($u) && isset($u->id)) return (int)$u->id;
        return null;
    }

    private function json(bool $success, string $message = '', $data = null, int $code = 200): ResponseInterface
    {
        $payload = ['success' => $success, 'message' => $message];
        if ($data !== null) $payload['data'] = $data;
        return $this->response->setStatusCode($code)->setJSON($payload);
    }

    private function normalizeStatus(?string $status): string
    {
        $status = trim((string)$status);
        $allowed = ['pending', 'in_progress', 'done', 'canceled'];
        return in_array($status, $allowed, true) ? $status : 'pending';
    }

    private function normalizePriority(?string $priority): string
    {
        $priority = trim((string)$priority);
        $allowed = ['low', 'normal', 'high', 'urgent'];
        return in_array($priority, $allowed, true) ? $priority : 'normal';
    }

    private function ensureWhatsAppColumns(): void
    {
        try {
            $db = db_connect();
            $fields = $db->getFieldNames('project_tasks');
            if (!in_array('enable_whatsapp_msg', $fields, true)) {
                $db->query("ALTER TABLE `project_tasks` ADD COLUMN `enable_whatsapp_msg` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_mandatory`");
            }
            if (!in_array('whatsapp_message', $fields, true)) {
                $db->query("ALTER TABLE `project_tasks` ADD COLUMN `whatsapp_message` TEXT NULL DEFAULT NULL AFTER `enable_whatsapp_msg`");
            }
        } catch (\Throwable $e) {
            log_message('error', 'ensureWhatsAppColumns error: ' . $e->getMessage());
        }
    }

    private function notifyClientTaskUpdate(int $taskId): void
    {
        try {
            $this->ensureWhatsAppColumns();
            $db = db_connect();

            $row = $db->table('project_tasks pt')
                ->select('
                    pt.id,
                    pt.project_id,
                    pt.task_title,
                    pt.status,
                    pt.enable_whatsapp_msg,
                    pt.whatsapp_message,
                    p.project_code,
                    p.project_title,
                    p.client_id,
                    p.applicant_id,
                    c.name AS client_name,
                    c.phone AS client_phone,
                    a.name AS applicant_name,
                    a.phone AS applicant_phone
                ')
                ->join('projects p', 'p.id = pt.project_id', 'left')
                ->join('clients c', 'c.id = p.client_id', 'left')
                ->join('clients a', 'a.id = p.applicant_id', 'left')
                ->where('pt.id', $taskId)
                ->get()
                ->getRowArray();

            if (!$row) {
                return;
            }

            // Only send WhatsApp when enabled for this task and status is 'done'
            if (empty($row['enable_whatsapp_msg']) || (int)$row['enable_whatsapp_msg'] !== 1) {
                return;
            }

            if ($row['status'] !== 'done') {
                return;
            }

            $phone = trim((string)($row['client_phone'] ?? ''));
            if ($phone === '' && !empty($row['applicant_phone'])) {
                $phone = trim((string)$row['applicant_phone']);
            }

            if ($phone === '') {
                log_message('info', "WhatsApp skipped for task #{$taskId}: No client phone found.");
                return;
            }

            $clientName = trim((string)($row['client_name'] ?? ''));
            if ($clientName === '' && !empty($row['applicant_name'])) {
                $clientName = trim((string)$row['applicant_name']);
            }
            if ($clientName === '') {
                $clientName = 'Customer';
            }

            $projectTitle = trim((string)($row['project_title'] ?? ''));
            $projectCode  = trim((string)($row['project_code'] ?? ''));

            if ($projectTitle !== '' && $projectCode !== '') {
                $projectName = $projectTitle . ' (' . $projectCode . ')';
            } elseif ($projectTitle !== '') {
                $projectName = $projectTitle;
            } elseif ($projectCode !== '') {
                $projectName = $projectCode;
            } else {
                $projectName = 'Project #' . (int)($row['project_id'] ?? 0);
            }

            $taskTitle = trim((string)($row['task_title'] ?? ''));
            if ($taskTitle === '') {
                $taskTitle = 'Task #' . $taskId;
            }

            $wa = new WhatsAppService();
            $result = $wa->sendTaskCompletionMessage(
                $phone,
                $clientName,
                $projectName,
                $taskTitle,
                $row['whatsapp_message'] ?? null
            );

            $message = $result['sent_message'] ?? ("Hello {$clientName}, Your project {$projectName} task {$taskTitle} has been completed.");

            // Safe insert into whatsapp_logs
            try {
                $db->query("CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `module` VARCHAR(50) DEFAULT 'project_task',
                    `module_id` INT DEFAULT NULL,
                    `phone` VARCHAR(30) DEFAULT NULL,
                    `template_name` VARCHAR(100) DEFAULT NULL,
                    `message` TEXT DEFAULT NULL,
                    `success` TINYINT(1) DEFAULT 0,
                    `http_code` INT DEFAULT NULL,
                    `response_json` LONGTEXT DEFAULT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $db->table('whatsapp_logs')->insert([
                    'module'        => 'project_task',
                    'module_id'     => $taskId,
                    'phone'         => $phone,
                    'template_name' => !empty($row['whatsapp_message']) ? 'custom_text' : (env('WHATSAPP_TASK_TEMPLATE_NAME') ?: 'project_task_update'),
                    'message'       => $message,
                    'success'       => !empty($result['success']) ? 1 : 0,
                    'http_code'     => $result['http_code'] ?? null,
                    'response_json' => json_encode($result),
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $logEx) {
                log_message('error', 'Failed to log WhatsApp send: ' . $logEx->getMessage());
            }

        } catch (\Throwable $e) {
            log_message('error', 'WhatsApp task notification failed: ' . $e->getMessage());
        }
    }
    // ============================================================
    // GET /api/projects/{projectId}/tasks
    // ============================================================
    public function index($projectId): ResponseInterface
    {
        $projectId = (int)$projectId;
        $db = db_connect();

        // Check if team columns exist
        $fields = $db->getFieldNames('project_tasks');
        $hasTeamCols = in_array('assignment_type', $fields, true);

        $selectCols = 'pt.*, u.name as assigned_user_name, u.email as assigned_user_email';
        if ($hasTeamCols) {
            $selectCols .= ', t.team_name, t.team_code, t.team_leader_id,
                           (SELECT COUNT(*) FROM task_subtasks ts WHERE ts.task_id = pt.id) AS subtask_count,
                           (SELECT COUNT(*) FROM task_subtasks ts WHERE ts.task_id = pt.id AND ts.status = "completed") AS completed_subtask_count';
        }

        $builder = $db->table('project_tasks pt')
            ->select($selectCols)
            ->join('users u', 'u.id = pt.assigned_to', 'left');

        if ($hasTeamCols) {
            $builder->join('teams t', 't.id = pt.assigned_team_id', 'left');
        }

        $tasks = $builder
            ->where('pt.project_id', $projectId)
            ->whereIn('pt.status', ['pending', 'in_progress', 'done', 'canceled'])
            ->orderBy('pt.sort_order', 'ASC')
            ->orderBy('pt.id', 'ASC')
            ->get()
            ->getResultArray();

        return $this->json(true, 'OK', [
            'project_id' => $projectId,
            'tasks' => $tasks,
        ]);
    }

    // ============================================================
    // GET /api/project-tasks/{id}
    // ============================================================
    public function show($id): ResponseInterface
    {
        $id = (int)$id;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $db = db_connect();
        $task = $db->table('project_tasks')->where('id', $id)->get()->getRowArray();
        if (!$task) return $this->json(false, 'Task not found', null, 404);

        return $this->json(true, 'OK', ['task' => $task]);
    }

    // ============================================================
    // POST /api/projects/{projectId}/tasks
    // Create manual task
    // ============================================================
    public function create($projectId): ResponseInterface
    {
        $projectId = (int)$projectId;
        $createdBy = $this->authUserId();
        if (!$createdBy) return $this->json(false, 'Unauthorized', null, 401);

        $data = $this->request->getJSON(true);
        if (!is_array($data) || empty($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $title = trim((string)($data['task_title'] ?? ''));
        if ($title === '') return $this->json(false, 'Task title required', null, 422);

        $this->ensureWhatsAppColumns();
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $status   = $this->normalizeStatus($data['status'] ?? 'pending');
        $priority = $this->normalizePriority($data['priority'] ?? 'normal');

        $completedAt = ($status === 'done') ? $now : null;

        $enableWhatsApp = !empty($data['enable_whatsapp_msg']) ? 1 : 0;
        $whatsAppMessage = array_key_exists('whatsapp_message', $data) ? $data['whatsapp_message'] : null;
        $whatsAppMessage = ($whatsAppMessage === '' || $whatsAppMessage === null) ? null : trim((string)$whatsAppMessage);

        $insert = [
            'project_id' => $projectId,
            'template_item_id' => null,
            'generated_from_template' => 0,

            'task_title' => $title,
            'task_description' => $data['task_description'] ?? null,

            'status' => $status,
            'priority' => $priority,
            'sort_order' => (int)($data['sort_order'] ?? 1),

            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'completed_at' => $completedAt,

            'assigned_to' => !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null,
            'created_by' => $createdBy,

            'is_milestone' => (int)($data['is_milestone'] ?? 0),
            'is_mandatory' => (int)($data['is_mandatory'] ?? 1),
            'enable_whatsapp_msg' => $enableWhatsApp,
            'whatsapp_message' => $whatsAppMessage,

            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Team assignment fields
        $fields = $db->getFieldNames('project_tasks');
        if (in_array('assignment_type', $fields, true)) {
            $assignmentType = ($data['assignment_type'] ?? 'individual');
            $assignmentType = in_array($assignmentType, ['individual', 'team']) ? $assignmentType : 'individual';
            $insert['assignment_type'] = $assignmentType;

            if ($assignmentType === 'team' && !empty($data['assigned_team_id'])) {
                // Validate team is active
                $team = $db->table('teams')->where('id', (int)$data['assigned_team_id'])->where('status', 1)->get()->getRowArray();
                if (!$team) {
                    return $this->json(false, 'Selected team is inactive or not found', null, 422);
                }
                $insert['assigned_team_id'] = (int)$data['assigned_team_id'];
                // Auto-set assigned_to to team leader
                if (empty($insert['assigned_to']) && !empty($team['team_leader_id'])) {
                    $insert['assigned_to'] = (int)$team['team_leader_id'];
                }
            } else {
                $insert['assigned_team_id'] = null;
            }
        }

        $db->table('project_tasks')->insert($insert);
        $newTaskId = (int)$db->insertID();

        if ($status === 'done') {
            $this->notifyClientTaskUpdate($newTaskId);
        }

        return $this->json(true, 'Task created', [
            'task_id' => $newTaskId
        ], 201);
    }

    // ============================================================
    // PUT/PATCH /api/project-tasks/{id}
    // Update task
    // ============================================================
    public function update($id): ResponseInterface
    {
        $id = (int)$id;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $data = $this->request->getJSON(true);
        if (!is_array($data) || empty($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $this->ensureWhatsAppColumns();
        $db = db_connect();

        $existing = $db->table('project_tasks')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Task not found', null, 404);

        $allowed = [
            'task_title', 'task_description',
            'status', 'priority',
            'sort_order', 'start_date', 'due_date',
            'assigned_to', 'is_milestone', 'is_mandatory',
            'enable_whatsapp_msg', 'whatsapp_message',
            'assignment_type', 'assigned_team_id'
        ];

        $update = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        // normalize status/priority if provided
        if (isset($update['status']))   $update['status'] = $this->normalizeStatus($update['status']);
        if (isset($update['priority'])) $update['priority'] = $this->normalizePriority($update['priority']);

        if (array_key_exists('enable_whatsapp_msg', $update)) {
            $update['enable_whatsapp_msg'] = !empty($update['enable_whatsapp_msg']) ? 1 : 0;
        }
        if (array_key_exists('whatsapp_message', $update)) {
            $update['whatsapp_message'] = ($update['whatsapp_message'] === '' || $update['whatsapp_message'] === null) ? null : trim((string)$update['whatsapp_message']);
        }

        $now = date('Y-m-d H:i:s');

        // completed_at rules:
        // - status -> done: set completed_at (if not already)
        // - status away from done: clear completed_at
        if (isset($update['status'])) {
            $newStatus = (string)$update['status'];
            $oldStatus = (string)$existing['status'];

            // Prevent manual completion of team tasks with incomplete subtasks
            if ($newStatus === 'done' && $oldStatus !== 'done') {
                try {
                    $fields = $db->getFieldNames('project_tasks');
                    if (in_array('assignment_type', $fields, true) && ($existing['assignment_type'] ?? '') === 'team') {
                        $totalSubs = $db->table('task_subtasks')->where('task_id', $id)->countAllResults();
                        if ($totalSubs > 0) {
                            $completedSubs = $db->table('task_subtasks')->where('task_id', $id)->where('status', 'completed')->countAllResults();
                            if ($completedSubs < $totalSubs) {
                                return $this->json(false, 'Cannot mark task as done while subtasks are incomplete (' . $completedSubs . '/' . $totalSubs . ' completed)', null, 422);
                            }
                        }
                    }
                } catch (\Throwable $e) {}
                $update['completed_at'] = $now;
            } elseif ($newStatus !== 'done' && $oldStatus === 'done') {
                $update['completed_at'] = null;
            }
        }

        // if assigned_to is empty string, set null
        if (array_key_exists('assigned_to', $update)) {
            $update['assigned_to'] = ($update['assigned_to'] === '' || $update['assigned_to'] === null)
                ? null
                : (int)$update['assigned_to'];
        }

        // Validate team assignment
        if (isset($update['assignment_type'])) {
            $update['assignment_type'] = in_array($update['assignment_type'], ['individual', 'team']) ? $update['assignment_type'] : 'individual';
            if ($update['assignment_type'] === 'individual') {
                $update['assigned_team_id'] = null;
            }
        }
        if (isset($update['assigned_team_id'])) {
            $update['assigned_team_id'] = ($update['assigned_team_id'] === '' || $update['assigned_team_id'] === null)
                ? null
                : (int)$update['assigned_team_id'];
        }

        $update['updated_at'] = $now;

        $db->table('project_tasks')->where('id', $id)->update($update);
        $this->notifyClientTaskUpdate($id);

        return $this->json(true, 'Task updated', [
            'task_id' => $id
        ]);
    }

    // ============================================================
    // POST /api/project-tasks/{id}/status
    // Body: { status: "in_progress" | "done" | "pending" | "canceled" }
    // ============================================================
    public function updateStatus($id): ResponseInterface
    {
        $id = (int)$id;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $data = $this->request->getJSON(true);
        if (!is_array($data) || empty($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $status = $this->normalizeStatus($data['status'] ?? '');
        if (!in_array($status, ['pending','in_progress','done','canceled'], true)) {
            return $this->json(false, 'Invalid status', null, 422);
        }

        $db = db_connect();
        $existing = $db->table('project_tasks')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Task not found', null, 404);

        $now = date('Y-m-d H:i:s');

        // Prevent manual completion of team tasks with incomplete subtasks
        if ($status === 'done' && $existing['status'] !== 'done') {
            try {
                $fields = $db->getFieldNames('project_tasks');
                if (in_array('assignment_type', $fields, true) && ($existing['assignment_type'] ?? '') === 'team') {
                    $totalSubs = $db->table('task_subtasks')->where('task_id', $id)->countAllResults();
                    if ($totalSubs > 0) {
                        $completedSubs = $db->table('task_subtasks')->where('task_id', $id)->where('status', 'completed')->countAllResults();
                        if ($completedSubs < $totalSubs) {
                            return $this->json(false, 'Cannot mark task as done while subtasks are incomplete (' . $completedSubs . '/' . $totalSubs . ' completed)', null, 422);
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        $update = [
            'status' => $status,
            'updated_at' => $now,
        ];

        if ($status === 'done') {
            $update['completed_at'] = $existing['completed_at'] ?: $now;
        } else {
            if ($existing['status'] === 'done') $update['completed_at'] = null;
        }

        $db->table('project_tasks')->where('id', $id)->update($update);
		$this->notifyClientTaskUpdate($id);

        return $this->json(true, 'Status updated', [
            'task_id' => $id,
            'status' => $status,
            'completed_at' => $update['completed_at'] ?? $existing['completed_at'],
        ]);
    }

    // ============================================================
    // DELETE /api/project-tasks/{id}
    // Logical delete -> status=canceled
    // ============================================================
    public function delete($id): ResponseInterface
    {
        $id = (int)$id;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $db = db_connect();

        $existing = $db->table('project_tasks')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Task not found', null, 404);

        $db->table('project_tasks')->where('id', $id)->update([
            'status' => 'canceled',
            'completed_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->json(true, 'Task canceled');
    }

    // ============================================================
    // GET /api/projects/{projectId}/task-assignees?q=
    // Assignees dropdown from users table
    // ============================================================
    public function assignees($projectId): ResponseInterface
    {
        $projectId = (int)$projectId;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $q = trim((string)$this->request->getGet('q'));

        $db = db_connect();

        $builder = $db->table('users')
            ->select('id, name, email, phone, role_id')
            ->where('status', 1);

        if ($q !== '') {
            $builder->groupStart()
                ->like('name', $q)
                ->orLike('email', $q)
                ->orLike('phone', $q)
            ->groupEnd();
        }

        $rows = $builder->orderBy('name', 'ASC')->limit(50)->get()->getResultArray();

        // Select2-friendly
        $assignees = array_map(function ($r) {
            $text = $r['name'];
            if (!empty($r['email'])) $text .= ' (' . $r['email'] . ')';
            return [
                'id'      => (int)$r['id'],
                'text'    => $text,
                'name'    => $r['name'],
                'email'   => $r['email'],
                'phone'   => $r['phone'],
                'role_id' => (int)$r['role_id'],
            ];
        }, $rows);

        return $this->json(true, 'OK', [
            'project_id' => $projectId,
            'assignees'  => $assignees
        ]);
    }

    // ============================================================
    // POST /api/projects/{projectId}/generate-tasks
    // Generate default tasks from service task template
    // ============================================================
    public function generateFromTemplate($projectId): ResponseInterface
    {
        $projectId = (int)$projectId;
        $createdBy = $this->authUserId();
        if (!$createdBy) return $this->json(false, 'Unauthorized', null, 401);

        $project = (new ProjectModel())->find($projectId);
        if (!$project) return $this->json(false, 'Project not found', null, 404);

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        // Prevent duplicate generation
        $exists = $db->table('project_tasks')
            ->where('project_id', $projectId)
            ->where('generated_from_template', 1)
            ->countAllResults();

        if ($exists > 0) {
            return $this->json(true, 'Tasks already generated');
        }

        // Default template
        $tpl = $db->table('service_task_templates')
            ->where('service_id', $project['service_id'])
            ->where('status', 1)
            ->orderBy('is_default', 'DESC')
            ->get()
            ->getRowArray();

        if (!$tpl) return $this->json(false, 'No task template found', null, 404);

        $items = $db->table('service_task_template_items')
            ->where('template_id', $tpl['id'])
            ->where('status', 1)
            ->orderBy('sort_order', 'ASC')
            ->get()
            ->getResultArray();

        $baseDate = substr($project['created_at'] ?? $now, 0, 10);

        $this->ensureWhatsAppColumns();
        $tasksCreated = 0;
        $subtasksCreated = 0;
        foreach ($items as $it) {
            $due = null;
            if ($it['default_due_days'] !== null) {
                $due = date('Y-m-d', strtotime($baseDate . ' +' . (int)$it['default_due_days'] . ' days'));
            }

            $taskData = [
                'project_id' => $projectId,
                'template_item_id' => $it['id'],
                'generated_from_template' => 1,

                'task_title' => $it['task_name'],
                'task_description' => $it['task_description'],

                'status' => 'pending',
                'priority' => 'normal',
                'sort_order' => $it['sort_order'] ?? 1,

                'start_date' => null,
                'due_date' => $due,
                'completed_at' => null,

                'assignment_type' => 'individual',
                'assigned_to' => !empty($it['default_assigned_user_id']) 
                    ? (int)$it['default_assigned_user_id'] 
                    : (!empty($tpl['default_assigned_user_id']) ? (int)$tpl['default_assigned_user_id'] : null),
                'created_by' => $createdBy,

                'is_milestone' => $it['is_milestone'] ?? 0,
                'is_mandatory' => $it['is_mandatory'] ?? 1,
                'enable_whatsapp_msg' => !empty($it['enable_whatsapp_msg']) ? 1 : 0,
                'whatsapp_message' => $it['whatsapp_message'] ?? null,

                'created_at' => $now,
                'updated_at' => $now,
            ];

            $db->table('project_tasks')->insert($taskData);
            $newTaskId = $db->insertID();
            $tasksCreated++;

            if (!empty($it['subtasks_json'])) {
                $subtasks = json_decode($it['subtasks_json'], true);
                if (is_array($subtasks)) {
                    $subBatch = [];
                    foreach ($subtasks as $st) {
                        if (empty($st['title'])) continue;
                        $subBatch[] = [
                            'task_id' => $newTaskId,
                            'title' => $st['title'],
                            'description' => $st['description'] ?? null,
                            'status' => 'pending',
                            'progress_percentage' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if (!empty($subBatch)) {
                        $db->table('task_subtasks')->insertBatch($subBatch);
                        $subtasksCreated += count($subBatch);
                    }
                }
            }
        }

        return $this->json(true, 'Tasks generated', [
            'tasks_created' => $tasksCreated,
            'subtasks_created' => $subtasksCreated
        ], 201);
    }
	
	
}
