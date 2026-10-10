<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\TeamModel;
use App\Models\TeamMemberModel;
use CodeIgniter\HTTP\ResponseInterface;

class TeamController extends BaseController
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

    // ============================================================
    // Ensure tables exist (safe bootstrap)
    // ============================================================
    private function ensureTables(): void
    {
        try {
            $db = db_connect();
            $tables = $db->listTables();

            if (!in_array('teams', $tables, true)) {
                $db->query("CREATE TABLE IF NOT EXISTS `teams` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `team_code` VARCHAR(50) NOT NULL,
                    `team_name` VARCHAR(150) NOT NULL,
                    `description` TEXT NULL,
                    `team_leader_id` INT UNSIGNED NULL,
                    `department_id` INT UNSIGNED NULL,
                    `status` TINYINT(1) DEFAULT 1,
                    `created_by` INT UNSIGNED NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_team_code` (`team_code`),
                    KEY `idx_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }

            if (!in_array('team_members', $tables, true)) {
                $db->query("CREATE TABLE IF NOT EXISTS `team_members` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `team_id` INT UNSIGNED NOT NULL,
                    `employee_id` INT UNSIGNED NOT NULL,
                    `joined_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `status` TINYINT(1) DEFAULT 1,
                    KEY `idx_team` (`team_id`),
                    KEY `idx_employee` (`employee_id`),
                    UNIQUE KEY `uk_team_employee` (`team_id`, `employee_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        } catch (\Throwable $e) {
            log_message('error', 'TeamController::ensureTables — ' . $e->getMessage());
        }
    }

    // ============================================================
    // GET /api/teams
    // List all teams with leader name & member count
    // ============================================================
    public function index(): ResponseInterface
    {
        $this->ensureTables();
        $db = db_connect();

        $status = $this->request->getGet('status');
        $search = trim((string)$this->request->getGet('search'));

        $builder = $db->table('teams t')
            ->select('t.*, u.name AS leader_name, u.email AS leader_email,
                      (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id AND tm.status = 1) AS member_count')
            ->join('users u', 'u.id = t.team_leader_id', 'left');

        if ($status !== null && $status !== '' && $status !== 'all') {
            $builder->where('t.status', (int)$status);
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('t.team_name', $search)
                ->orLike('t.team_code', $search)
                ->orLike('t.description', $search)
            ->groupEnd();
        }

        $teams = $builder->orderBy('t.team_name', 'ASC')->get()->getResultArray();

        return $this->json(true, 'OK', ['teams' => $teams]);
    }

    // ============================================================
    // GET /api/teams/{id}
    // Show team with members
    // ============================================================
    public function show($id): ResponseInterface
    {
        $id = (int)$id;
        $this->ensureTables();
        $db = db_connect();

        $team = $db->table('teams t')
            ->select('t.*, u.name AS leader_name, u.email AS leader_email')
            ->join('users u', 'u.id = t.team_leader_id', 'left')
            ->where('t.id', $id)
            ->get()
            ->getRowArray();

        if (!$team) return $this->json(false, 'Team not found', null, 404);

        // Fetch members
        $members = $db->table('team_members tm')
            ->select('tm.*, u.name AS employee_name, u.email AS employee_email, u.phone AS employee_phone, u.role_id')
            ->join('users u', 'u.id = tm.employee_id', 'left')
            ->where('tm.team_id', $id)
            ->where('tm.status', 1)
            ->orderBy('u.name', 'ASC')
            ->get()
            ->getResultArray();

        $team['members'] = $members;

        return $this->json(true, 'OK', ['team' => $team]);
    }

    // ============================================================
    // GET /api/teams/dropdown
    // Active teams for select dropdowns
    // ============================================================
    public function dropdown(): ResponseInterface
    {
        $this->ensureTables();
        $db = db_connect();

        $teams = $db->table('teams')
            ->select('id, team_code, team_name, team_leader_id, status')
            ->where('status', 1)
            ->orderBy('team_name', 'ASC')
            ->get()
            ->getResultArray();

        $result = array_map(function ($t) {
            return [
                'id'   => (int)$t['id'],
                'text' => $t['team_name'] . ' (' . $t['team_code'] . ')',
                'team_name'  => $t['team_name'],
                'team_code'  => $t['team_code'],
                'team_leader_id' => $t['team_leader_id'] ? (int)$t['team_leader_id'] : null,
            ];
        }, $teams);

        return $this->json(true, 'OK', ['teams' => $result]);
    }

    // ============================================================
    // POST /api/teams
    // Create a new team
    // ============================================================
    public function create(): ResponseInterface
    {
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $data = $this->request->getJSON(true);
        if (!is_array($data) || empty($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $teamName = trim((string)($data['team_name'] ?? ''));
        $teamCode = trim((string)($data['team_code'] ?? ''));

        if ($teamName === '') return $this->json(false, 'Team name is required', null, 422);
        if ($teamCode === '') return $this->json(false, 'Team code is required', null, 422);

        $this->ensureTables();
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        // Check duplicate team_code
        $existing = $db->table('teams')->where('team_code', $teamCode)->get()->getRowArray();
        if ($existing) {
            return $this->json(false, 'Team code already exists', null, 422);
        }

        $insert = [
            'team_code'      => $teamCode,
            'team_name'      => $teamName,
            'description'    => $data['description'] ?? null,
            'team_leader_id' => !empty($data['team_leader_id']) ? (int)$data['team_leader_id'] : null,
            'department_id'  => !empty($data['department_id']) ? (int)$data['department_id'] : null,
            'status'         => isset($data['status']) ? (int)$data['status'] : 1,
            'created_by'     => $userId,
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        $db->table('teams')->insert($insert);
        $teamId = (int)$db->insertID();

        // Auto-add leader as member
        if (!empty($insert['team_leader_id'])) {
            $db->table('team_members')->insert([
                'team_id'     => $teamId,
                'employee_id' => $insert['team_leader_id'],
                'joined_at'   => $now,
                'status'      => 1,
            ]);
        }

        // Add members if provided
        if (!empty($data['members']) && is_array($data['members'])) {
            foreach ($data['members'] as $empId) {
                $empId = (int)$empId;
                if ($empId <= 0 || $empId === $insert['team_leader_id']) continue;

                $exists = $db->table('team_members')
                    ->where('team_id', $teamId)
                    ->where('employee_id', $empId)
                    ->countAllResults();

                if ($exists === 0) {
                    $db->table('team_members')->insert([
                        'team_id'     => $teamId,
                        'employee_id' => $empId,
                        'joined_at'   => $now,
                        'status'      => 1,
                    ]);
                }
            }
        }

        return $this->json(true, 'Team created', ['team_id' => $teamId], 201);
    }

    // ============================================================
    // PUT /api/teams/{id}
    // Update team details
    // ============================================================
    public function update($id): ResponseInterface
    {
        $id = (int)$id;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $data = $this->request->getJSON(true);
        if (!is_array($data) || empty($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $this->ensureTables();
        $db = db_connect();

        $existing = $db->table('teams')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Team not found', null, 404);

        $now = date('Y-m-d H:i:s');
        $update = ['updated_at' => $now];

        if (array_key_exists('team_name', $data)) {
            $name = trim((string)$data['team_name']);
            if ($name === '') return $this->json(false, 'Team name cannot be empty', null, 422);
            $update['team_name'] = $name;
        }

        if (array_key_exists('team_code', $data)) {
            $code = trim((string)$data['team_code']);
            if ($code === '') return $this->json(false, 'Team code cannot be empty', null, 422);

            // Check duplicate
            $dup = $db->table('teams')
                ->where('team_code', $code)
                ->where('id !=', $id)
                ->get()
                ->getRowArray();
            if ($dup) return $this->json(false, 'Team code already exists', null, 422);
            $update['team_code'] = $code;
        }

        if (array_key_exists('description', $data))    $update['description'] = $data['description'];
        if (array_key_exists('team_leader_id', $data))  $update['team_leader_id'] = !empty($data['team_leader_id']) ? (int)$data['team_leader_id'] : null;
        if (array_key_exists('department_id', $data))   $update['department_id'] = !empty($data['department_id']) ? (int)$data['department_id'] : null;
        if (array_key_exists('status', $data))          $update['status'] = (int)$data['status'];

        $db->table('teams')->where('id', $id)->update($update);

        // Sync members if provided
        if (array_key_exists('members', $data) && is_array($data['members'])) {
            // Deactivate all current members
            $db->table('team_members')->where('team_id', $id)->update(['status' => 0]);

            $memberIds = $data['members'];

            // Ensure leader is in members
            $leaderId = $update['team_leader_id'] ?? $existing['team_leader_id'] ?? null;
            if ($leaderId && !in_array((int)$leaderId, array_map('intval', $memberIds))) {
                $memberIds[] = (int)$leaderId;
            }

            foreach ($memberIds as $empId) {
                $empId = (int)$empId;
                if ($empId <= 0) continue;

                $row = $db->table('team_members')
                    ->where('team_id', $id)
                    ->where('employee_id', $empId)
                    ->get()
                    ->getRowArray();

                if ($row) {
                    $db->table('team_members')
                        ->where('id', $row['id'])
                        ->update(['status' => 1, 'joined_at' => $row['joined_at'] ?: $now]);
                } else {
                    $db->table('team_members')->insert([
                        'team_id'     => $id,
                        'employee_id' => $empId,
                        'joined_at'   => $now,
                        'status'      => 1,
                    ]);
                }
            }
        }

        return $this->json(true, 'Team updated', ['team_id' => $id]);
    }

    // ============================================================
    // DELETE /api/teams/{id}
    // Soft-delete: set status = 0
    // ============================================================
    public function delete($id): ResponseInterface
    {
        $id = (int)$id;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $this->ensureTables();
        $db = db_connect();

        $existing = $db->table('teams')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Team not found', null, 404);

        $db->table('teams')->where('id', $id)->update([
            'status'     => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->json(true, 'Team deactivated');
    }

    // ============================================================
    // PATCH /api/teams/{id}/toggle-status
    // Toggle active/inactive
    // ============================================================
    public function toggleStatus($id): ResponseInterface
    {
        $id = (int)$id;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $this->ensureTables();
        $db = db_connect();

        $existing = $db->table('teams')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Team not found', null, 404);

        $newStatus = (int)$existing['status'] === 1 ? 0 : 1;

        $db->table('teams')->where('id', $id)->update([
            'status'     => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->json(true, $newStatus ? 'Team activated' : 'Team deactivated', [
            'team_id' => $id,
            'status'  => $newStatus,
        ]);
    }

    // ============================================================
    // GET /api/teams/{id}/members
    // List team members
    // ============================================================
    public function members($id): ResponseInterface
    {
        $id = (int)$id;
        $this->ensureTables();
        $db = db_connect();

        $members = $db->table('team_members tm')
            ->select('tm.*, u.name AS employee_name, u.email AS employee_email, u.phone AS employee_phone, u.role_id')
            ->join('users u', 'u.id = tm.employee_id', 'left')
            ->where('tm.team_id', $id)
            ->where('tm.status', 1)
            ->orderBy('u.name', 'ASC')
            ->get()
            ->getResultArray();

        return $this->json(true, 'OK', ['team_id' => $id, 'members' => $members]);
    }

    // ============================================================
    // POST /api/teams/{id}/members
    // Add member to team
    // ============================================================
    public function addMember($id): ResponseInterface
    {
        $id = (int)$id;
        $userId = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $empId = (int)($data['employee_id'] ?? 0);
        if ($empId <= 0) return $this->json(false, 'Employee ID is required', null, 422);

        $this->ensureTables();
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        // Check team exists
        $team = $db->table('teams')->where('id', $id)->get()->getRowArray();
        if (!$team) return $this->json(false, 'Team not found', null, 404);

        // Check if already a member
        $existing = $db->table('team_members')
            ->where('team_id', $id)
            ->where('employee_id', $empId)
            ->get()
            ->getRowArray();

        if ($existing) {
            if ((int)$existing['status'] === 1) {
                return $this->json(false, 'Employee is already a team member', null, 422);
            }
            // Re-activate
            $db->table('team_members')->where('id', $existing['id'])->update([
                'status'    => 1,
                'joined_at' => $now,
            ]);
        } else {
            $db->table('team_members')->insert([
                'team_id'     => $id,
                'employee_id' => $empId,
                'joined_at'   => $now,
                'status'      => 1,
            ]);
        }

        return $this->json(true, 'Member added', ['team_id' => $id, 'employee_id' => $empId], 201);
    }

    // ============================================================
    // DELETE /api/teams/{teamId}/members/{employeeId}
    // Remove member from team
    // ============================================================
    public function removeMember($teamId, $employeeId): ResponseInterface
    {
        $teamId    = (int)$teamId;
        $employeeId = (int)$employeeId;
        $userId     = $this->authUserId();
        if (!$userId) return $this->json(false, 'Unauthorized', null, 401);

        $this->ensureTables();
        $db = db_connect();

        $db->table('team_members')
            ->where('team_id', $teamId)
            ->where('employee_id', $employeeId)
            ->update(['status' => 0]);

        return $this->json(true, 'Member removed');
    }

    // ============================================================
    // GET /api/teams/dashboard
    // Team Dashboard summary
    // ============================================================
    public function dashboard(): ResponseInterface
    {
        $this->ensureTables();
        $db = db_connect();

        $teamId   = $this->request->getGet('team_id');
        $leaderId = $this->request->getGet('leader_id');
        $memberId = $this->request->getGet('member_id');
        $status   = $this->request->getGet('task_status');
        $priority = $this->request->getGet('priority');
        $dateFrom = $this->request->getGet('date_from');
        $dateTo   = $this->request->getGet('date_to');

        // Ensure project_tasks has team columns
        try {
            $fields = $db->getFieldNames('project_tasks');
            $hasTeamCols = in_array('assignment_type', $fields, true);
        } catch (\Throwable $e) {
            $hasTeamCols = false;
        }

        if (!$hasTeamCols) {
            return $this->json(true, 'OK', [
                'total_team_tasks'    => 0,
                'pending_tasks'       => 0,
                'in_progress_tasks'   => 0,
                'completed_tasks'     => 0,
                'overdue_tasks'       => 0,
                'total_subtasks'      => 0,
                'completed_subtasks'  => 0,
                'completion_percent'  => 0,
                'tasks'               => [],
            ]);
        }

        // Build task query
        $builder = $db->table('project_tasks pt')
            ->select('pt.*, p.project_title, p.project_code, t.team_name, t.team_code,
                      u.name AS assigned_user_name,
                      (SELECT COUNT(*) FROM task_subtasks ts WHERE ts.task_id = pt.id) AS subtask_count,
                      (SELECT COUNT(*) FROM task_subtasks ts WHERE ts.task_id = pt.id AND ts.status = "completed") AS completed_subtask_count')
            ->join('projects p', 'p.id = pt.project_id', 'left')
            ->join('teams t', 't.id = pt.assigned_team_id', 'left')
            ->join('users u', 'u.id = pt.assigned_to', 'left')
            ->where('pt.assignment_type', 'team')
            ->whereIn('pt.status', ['pending', 'in_progress', 'done', 'canceled']);

        if ($teamId) $builder->where('pt.assigned_team_id', (int)$teamId);
        if ($leaderId) $builder->where('t.team_leader_id', (int)$leaderId);
        if ($status && $status !== 'all') $builder->where('pt.status', $status);
        if ($priority && $priority !== 'all') $builder->where('pt.priority', $priority);
        if ($dateFrom) $builder->where('pt.created_at >=', $dateFrom . ' 00:00:00');
        if ($dateTo) $builder->where('pt.created_at <=', $dateTo . ' 23:59:59');

        // If member filter, join through subtasks
        if ($memberId) {
            $builder->where("pt.id IN (SELECT DISTINCT ts2.task_id FROM task_subtasks ts2 WHERE ts2.assigned_employee_id = " . (int)$memberId . ")");
        }

        $tasks = $builder->orderBy('pt.created_at', 'DESC')->get()->getResultArray();

        // Calculate summaries
        $total      = count($tasks);
        $pending    = count(array_filter($tasks, fn($t) => $t['status'] === 'pending'));
        $inProgress = count(array_filter($tasks, fn($t) => $t['status'] === 'in_progress'));
        $completed  = count(array_filter($tasks, fn($t) => $t['status'] === 'done'));
        $overdue    = count(array_filter($tasks, fn($t) =>
            $t['status'] !== 'done' && $t['status'] !== 'canceled' &&
            !empty($t['due_date']) && $t['due_date'] < date('Y-m-d')
        ));

        $totalSubtasks     = array_sum(array_column($tasks, 'subtask_count'));
        $completedSubtasks = array_sum(array_column($tasks, 'completed_subtask_count'));
        $completionPercent = $totalSubtasks > 0 ? round(($completedSubtasks / $totalSubtasks) * 100) : 0;

        return $this->json(true, 'OK', [
            'total_team_tasks'    => $total,
            'pending_tasks'       => $pending,
            'in_progress_tasks'   => $inProgress,
            'completed_tasks'     => $completed,
            'overdue_tasks'       => $overdue,
            'total_subtasks'      => $totalSubtasks,
            'completed_subtasks'  => $completedSubtasks,
            'completion_percent'  => $completionPercent,
            'tasks'               => $tasks,
        ]);
    }

    // ============================================================
    // GET /api/teams/performance
    // ============================================================
    public function performance(): ResponseInterface
    {
        $db = db_connect();
        
        $teamId = $this->request->getGet('team_id');
        $dateFrom = $this->request->getGet('date_from');
        $dateTo = $this->request->getGet('date_to');

        // Get all members for the team
        $membersQuery = $db->table('team_members tm')
            ->select('tm.employee_id, u.name as employee_name, t.team_name')
            ->join('users u', 'u.id = tm.employee_id', 'left')
            ->join('teams t', 't.id = tm.team_id', 'left')
            ->where('tm.status', 1);

        if ($teamId) {
            $membersQuery->where('tm.team_id', (int)$teamId);
        }

        $members = $membersQuery->get()->getResultArray();
        $performance = [];

        foreach ($members as $member) {
            $empId = (int)$member['employee_id'];
            
            // Subtasks completed by this member
            $b1 = $db->table('task_subtasks ts')->where('ts.completed_by_id', $empId)->where('ts.status', 'completed');
            if ($dateFrom) $b1->where('ts.completed_at >=', $dateFrom . ' 00:00:00');
            if ($dateTo) $b1->where('ts.completed_at <=', $dateTo . ' 23:59:59');
            $completedCount = $b1->countAllResults();

            // Subtasks in progress by this member (assigned directly or they are the one working on it)
            // Assuming assigned_employee_id means they are working on it
            $inProgressCount = $db->table('task_subtasks ts')
                ->where('ts.assigned_employee_id', $empId)
                ->where('ts.status', 'in_progress')
                ->countAllResults();

            // Overdue subtasks assigned to them
            $overdueCount = $db->table('task_subtasks ts')
                ->where('ts.assigned_employee_id', $empId)
                ->whereIn('ts.status', ['pending', 'in_progress'])
                ->where('ts.due_date <', date('Y-m-d'))
                ->countAllResults();

            // Total participated (assigned or completed)
            $participatedCount = $db->table('task_subtasks ts')
                ->groupStart()
                    ->where('ts.assigned_employee_id', $empId)
                    ->orWhere('ts.completed_by_id', $empId)
                ->groupEnd()
                ->countAllResults();

            // Calculate percentage based on assigned vs completed
            $assignedTotal = $db->table('task_subtasks ts')
                ->groupStart()
                    ->where('ts.assigned_employee_id', $empId)
                    ->orWhere('ts.completed_by_id', $empId)
                ->groupEnd()
                ->countAllResults();

            $percentage = $assignedTotal > 0 ? round(($completedCount / $assignedTotal) * 100) : 0;

            $performance[] = [
                'employee_id'        => $empId,
                'employee_name'      => $member['employee_name'],
                'team_name'          => $member['team_name'],
                'total_participated' => $participatedCount,
                'completed_count'    => $completedCount,
                'in_progress_count'  => $inProgressCount,
                'overdue_count'      => $overdueCount,
                'completion_percent' => $percentage,
            ];
        }

        return $this->json(true, 'OK', ['performance' => $performance]);
    }
}
