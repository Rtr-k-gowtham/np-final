<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class TaskSubtaskController extends BaseController
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

    private function normalizeSubtaskStatus(?string $status): string
    {
        $status = trim((string)$status);
        $allowed = ['pending', 'in_progress', 'review', 'completed', 'blocked'];
        return in_array($status, $allowed, true) ? $status : 'pending';
    }

    private function normalizePriority(?string $priority): string
    {
        $priority = trim((string)$priority);
        $allowed = ['low', 'normal', 'high', 'urgent'];
        return in_array($priority, $allowed, true) ? $priority : 'normal';
    }

    // ============================================================
    // Ensure table exists
    // ============================================================
    private function ensureTable(): void
    {
        try {
            $db = db_connect();
            $tables = $db->listTables();

            if (!in_array('task_subtasks', $tables, true)) {
                $db->query("CREATE TABLE IF NOT EXISTS `task_subtasks` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `task_id` INT UNSIGNED NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `description` TEXT NULL,
                    `assigned_employee_id` INT UNSIGNED NULL,
                    `start_date` DATE NULL,
                    `due_date` DATE NULL,
                    `priority` VARCHAR(20) DEFAULT 'normal',
                    `status` VARCHAR(20) DEFAULT 'pending',
                    `progress_percentage` TINYINT UNSIGNED DEFAULT 0,
                    `remarks` TEXT NULL,
                    `completed_by` INT UNSIGNED NULL,
                    `completed_at` DATETIME NULL,
                    `created_by` INT UNSIGNED NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY `idx_task` (`task_id`),
                    KEY `idx_assigned` (`assigned_employee_id`),
                    KEY `idx_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        } catch (\Throwable $e) {
            log_message('error', 'TaskSubtaskController::ensureTable — ' . $e->getMessage());
        }
    }

    // ============================================================
    // Recalculate parent task progress from subtasks
    // ============================================================
    private function recalcTaskProgress(int $taskId): void
    {
        $db = db_connect();

        $subtasks = $db->table('task_subtasks')
            ->where('task_id', $taskId)
            ->get()
            ->getResultArray();

        $total     = count($subtasks);
        $completed = 0;
        $hasInProgress = false;
        $hasReview     = false;
        $hasBlocked    = false;
        $hasOverdue    = false;
        $allCompleted  = true;
        $noneStarted   = true;
        $now = date('Y-m-d');

        foreach ($subtasks as $st) {
            $s = $st['status'];
            if ($s === 'completed') {
                $completed++;
            } else {
                $allCompleted = false;
            }

            if ($s !== 'pending') $noneStarted = false;
            if ($s === 'in_progress') $hasInProgress = true;
            if ($s === 'review') $hasReview = true;
            if ($s === 'blocked') $hasBlocked = true;

            if ($s !== 'completed' && !empty($st['due_date']) && $st['due_date'] < $now) {
                $hasOverdue = true;
            }
        }

        if ($total === 0) return;

        $progress = round(($completed / $total) * 100);

        // Determine parent task status
        $taskUpdate = [
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Only auto-set status for team tasks with subtasks
        $task = $db->table('project_tasks')->where('id', $taskId)->get()->getRowArray();
        if (!$task) return;

        // Check if this is a team task
        $isTeamTask = false;
        try {
            $fields = $db->getFieldNames('project_tasks');
            if (in_array('assignment_type', $fields, true)) {
                $isTeamTask = ($task['assignment_type'] ?? '') === 'team';
            }
        } catch (\Throwable $e) {}

        if ($isTeamTask) {
            if ($allCompleted) {
                $taskUpdate['status'] = 'done';
                $taskUpdate['completed_at'] = date('Y-m-d H:i:s');
            } elseif ($noneStarted) {
                $taskUpdate['status'] = 'pending';
                $taskUpdate['completed_at'] = null;
            } else {
                $taskUpdate['status'] = 'in_progress';
                $taskUpdate['completed_at'] = null;
            }
        }

        $db->table('project_tasks')->where('id', $taskId)->update($taskUpdate);
    }

    // ============================================================
    // GET /api/project-tasks/{taskId}/subtasks
    // List subtasks for a task
    // ============================================================
    public function index($taskId): ResponseInterface
    {
        $taskId = (int)$taskId;
        $this->ensureTable();
        $db = db_connect();

        // Verify task exists
        $task = $db->table('project_tasks')->where('id', $taskId)->get()->getRowArray();
        if (!$task) return $this->json(false, 'Task not found', null, 404);

        $subtasks = $db->table('task_subtasks ts')
            ->select('ts.*, u.name AS assigned_name, u.email AS assigned_email,
                      cb.name AS completed_by_name')
            ->join('users u', 'u.id = ts.assigned_employee_id', 'left')
            ->join('users cb', 'cb.id = ts.completed_by', 'left')
            ->where('ts.task_id', $taskId)
            ->orderBy('ts.id', 'ASC')
            ->get()
            ->getResultArray();

        // Compute summary
        $total     = count($subtasks);
        $completed = count(array_filter($subtasks, fn($s) => $s['status'] === 'completed'));
        $progress  = $total > 0 ? round(($completed / $total) * 100) : 0;

        return $this->json(true, 'OK', [
            'task_id'       => $taskId,
            'subtasks'      => $subtasks,
            'total'         => $total,
            'completed'     => $completed,
            'progress'      => $progress,
        ]);
    }

    // ============================================================
    // POST /api/project-tasks/{taskId}/subtasks
    // Create a subtask
    // ============================================================
    public function create($taskId): ResponseInterface
    {
        $taskId = (int)$taskId;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $data = $this->request->getJSON(true);
        if (!is_array($data) || empty($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') return $this->json(false, 'Subtask title is required', null, 422);

        $this->ensureTable();
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        // Verify task exists
        $task = $db->table('project_tasks')->where('id', $taskId)->get()->getRowArray();
        if (!$task) return $this->json(false, 'Parent task not found', null, 404);

        // If team assignment, validate assignee is a team member
        $assigneeId = !empty($data['assigned_employee_id']) ? (int)$data['assigned_employee_id'] : null;
        if ($assigneeId) {
            try {
                $fields = $db->getFieldNames('project_tasks');
                if (in_array('assigned_team_id', $fields, true) && !empty($task['assigned_team_id'])) {
                    $isMember = $db->table('team_members')
                        ->where('team_id', (int)$task['assigned_team_id'])
                        ->where('employee_id', $assigneeId)
                        ->where('status', 1)
                        ->countAllResults();

                    if ($isMember === 0) {
                        // Check if user is admin (role_code check from JWT)
                        $roleCode = $_SERVER['HTTP_X_ROLE_CODE'] ?? '';
                        if (strtolower($roleCode) !== 'admin') {
                            return $this->json(false, 'Assigned employee must be a member of the team', null, 422);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // column may not exist yet
            }
        }

        $status = $this->normalizeSubtaskStatus($data['status'] ?? 'pending');

        $insert = [
            'task_id'              => $taskId,
            'title'                => $title,
            'description'          => $data['description'] ?? null,
            'assigned_employee_id' => $assigneeId,
            'start_date'           => $data['start_date'] ?? null,
            'due_date'             => $data['due_date'] ?? null,
            'priority'             => $this->normalizePriority($data['priority'] ?? 'normal'),
            'status'               => $status,
            'progress_percentage'  => (int)($data['progress_percentage'] ?? 0),
            'remarks'              => $data['remarks'] ?? null,
            'completed_by'         => ($status === 'completed') ? $userId : null,
            'completed_at'         => ($status === 'completed') ? $now : null,
            'created_by'           => $userId,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];

        $db->table('task_subtasks')->insert($insert);
        $subtaskId = (int)$db->insertID();

        // Recalculate parent task progress
        $this->recalcTaskProgress($taskId);

        return $this->json(true, 'Subtask created', ['subtask_id' => $subtaskId], 201);
    }

    // ============================================================
    // GET /api/subtasks/{id}
    // Show single subtask
    // ============================================================
    public function show($id): ResponseInterface
    {
        $id = (int)$id;
        $this->ensureTable();
        $db = db_connect();

        $subtask = $db->table('task_subtasks ts')
            ->select('ts.*, u.name AS assigned_name, u.email AS assigned_email,
                      cb.name AS completed_by_name')
            ->join('users u', 'u.id = ts.assigned_employee_id', 'left')
            ->join('users cb', 'cb.id = ts.completed_by', 'left')
            ->where('ts.id', $id)
            ->get()
            ->getRowArray();

        if (!$subtask) return $this->json(false, 'Subtask not found', null, 404);

        return $this->json(true, 'OK', ['subtask' => $subtask]);
    }

    // ============================================================
    // PUT /api/subtasks/{id}
    // Update a subtask
    // ============================================================
    public function update($id): ResponseInterface
    {
        $id = (int)$id;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $data = $this->request->getJSON(true);
        if (!is_array($data) || empty($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $this->ensureTable();
        $db = db_connect();

        $existing = $db->table('task_subtasks')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Subtask not found', null, 404);

        $now = date('Y-m-d H:i:s');
        $allowed = [
            'title', 'description', 'assigned_employee_id',
            'start_date', 'due_date', 'priority', 'status',
            'progress_percentage', 'remarks'
        ];

        $update = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if (isset($update['status'])) {
            $update['status'] = $this->normalizeSubtaskStatus($update['status']);
        }
        if (isset($update['priority'])) {
            $update['priority'] = $this->normalizePriority($update['priority']);
        }

        // Completion logic
        if (isset($update['status'])) {
            $newStatus = $update['status'];
            $oldStatus = $existing['status'];

            if ($newStatus === 'completed' && $oldStatus !== 'completed') {
                $update['completed_by'] = $userId;
                $update['completed_at'] = $now;
                $update['progress_percentage'] = 100;
            } elseif ($newStatus !== 'completed' && $oldStatus === 'completed') {
                $update['completed_by'] = null;
                $update['completed_at'] = null;
            }
        }

        // Assignee validation for team tasks
        if (array_key_exists('assigned_employee_id', $update) && $update['assigned_employee_id']) {
            $task = $db->table('project_tasks')->where('id', $existing['task_id'])->get()->getRowArray();
            if ($task) {
                try {
                    $fields = $db->getFieldNames('project_tasks');
                    if (in_array('assigned_team_id', $fields, true) && !empty($task['assigned_team_id'])) {
                        $isMember = $db->table('team_members')
                            ->where('team_id', (int)$task['assigned_team_id'])
                            ->where('employee_id', (int)$update['assigned_employee_id'])
                            ->where('status', 1)
                            ->countAllResults();

                        if ($isMember === 0) {
                            $roleCode = $_SERVER['HTTP_X_ROLE_CODE'] ?? '';
                            if (strtolower($roleCode) !== 'admin') {
                                return $this->json(false, 'Assigned employee must be a member of the team', null, 422);
                            }
                        }
                    }
                } catch (\Throwable $e) {}
            }
        }

        if (array_key_exists('assigned_employee_id', $update)) {
            $update['assigned_employee_id'] = ($update['assigned_employee_id'] === '' || $update['assigned_employee_id'] === null)
                ? null
                : (int)$update['assigned_employee_id'];
        }

        $update['updated_at'] = $now;

        $db->table('task_subtasks')->where('id', $id)->update($update);

        // Recalculate parent task progress
        $this->recalcTaskProgress((int)$existing['task_id']);

        return $this->json(true, 'Subtask updated', ['subtask_id' => $id]);
    }

    // ============================================================
    // DELETE /api/subtasks/{id}
    // Hard-delete a subtask
    // ============================================================
    public function delete($id): ResponseInterface
    {
        $id = (int)$id;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $this->ensureTable();
        $db = db_connect();

        $existing = $db->table('task_subtasks')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Subtask not found', null, 404);

        $taskId = (int)$existing['task_id'];
        $db->table('task_subtasks')->where('id', $id)->delete();

        // Recalculate parent task progress
        $this->recalcTaskProgress($taskId);

        return $this->json(true, 'Subtask deleted');
    }

    // ============================================================
    // POST /api/project-tasks/{taskId}/subtasks/bulk
    // Batch create subtasks
    // ============================================================
    public function bulkCreate($taskId): ResponseInterface
    {
        $taskId = (int)$taskId;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $subtasks = $data['subtasks'] ?? $data;
        if (!is_array($subtasks) || empty($subtasks)) {
            return $this->json(false, 'Subtasks array is required', null, 422);
        }

        $this->ensureTable();
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $task = $db->table('project_tasks')->where('id', $taskId)->get()->getRowArray();
        if (!$task) return $this->json(false, 'Parent task not found', null, 404);

        $created = 0;
        foreach ($subtasks as $st) {
            $title = trim((string)($st['title'] ?? ''));
            if ($title === '') continue;

            $status = $this->normalizeSubtaskStatus($st['status'] ?? 'pending');

            $db->table('task_subtasks')->insert([
                'task_id'              => $taskId,
                'title'                => $title,
                'description'          => $st['description'] ?? null,
                'assigned_employee_id' => !empty($st['assigned_employee_id']) ? (int)$st['assigned_employee_id'] : null,
                'start_date'           => $st['start_date'] ?? null,
                'due_date'             => $st['due_date'] ?? null,
                'priority'             => $this->normalizePriority($st['priority'] ?? 'normal'),
                'status'               => $status,
                'progress_percentage'  => (int)($st['progress_percentage'] ?? 0),
                'remarks'              => $st['remarks'] ?? null,
                'completed_by'         => ($status === 'completed') ? $userId : null,
                'completed_at'         => ($status === 'completed') ? $now : null,
                'created_by'           => $userId,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
            $created++;
        }

        $this->recalcTaskProgress($taskId);

        return $this->json(true, "Created {$created} subtask(s)", ['count' => $created], 201);
    }
}
