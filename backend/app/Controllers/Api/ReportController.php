<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class ReportController extends BaseController
{
    protected $db;

    public function __construct()
    {
        $this->db = db_connect();
    }

    private function jsonResponse(bool $success, string $message = '', $data = null, int $code = 200): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    private function getCompanyId(): int
    {
        return (int) ($this->request->getGet('company_id') ?: $this->request->getPost('company_id') ?: 1);
    }

    private function getMonthYear(): array
    {
        $month = (int) ($this->request->getGet('month') ?: date('m'));
        $year  = (int) ($this->request->getGet('year') ?: date('Y'));

        if ($month < 1 || $month > 12) {
            $month = (int) date('m');
        }

        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        return [$month, $year];
    }

    private function getDateRange(): array
    {
        $from = trim((string) $this->request->getGet('from'));
        $to   = trim((string) $this->request->getGet('to'));

        if ($from !== '' && $to !== '') {
            return [$from, $to];
        }

        [$month, $year] = $this->getMonthYear();

        $from = sprintf('%04d-%02d-01', $year, $month);
        $to   = date('Y-m-t', strtotime($from));

        return [$from, $to];
    }

    /**
     * GET /api/reports/attendance-monthly?month=3&year=2026&company_id=1
     */
    public function attendanceMonthly(): ResponseInterface
    {
        [$month, $year] = $this->getMonthYear();
        $companyId      = $this->getCompanyId();

        $fromDate   = sprintf('%04d-%02d-01', $year, $month);
        $toDate     = date('Y-m-t', strtotime($fromDate));
        $totalDays  = (int) date('t', strtotime($fromDate));

        // Active employees overlapping selected month
        $employees = $this->db->table('employees e')
            ->select('e.id, e.emp_code, e.name, e.salary, e.no_of_leave, e.no_of_ml, e.join_date, e.resigned_date')
            ->where('e.company_id', $companyId)
            ->where('e.status', 'active')
            ->where('e.join_date <=', $toDate)
            ->groupStart()
                ->where('e.resigned_date IS NULL', null, false)
                ->orWhere('e.resigned_date >=', $fromDate)
            ->groupEnd()
            ->orderBy('e.name', 'ASC')
            ->get()
            ->getResultArray();

        if (empty($employees)) {
            return $this->jsonResponse(true, 'No employees found for selected month', [
                'filters' => [
                    'company_id' => $companyId,
                    'month'      => $month,
                    'year'       => $year,
                    'from_date'  => $fromDate,
                    'to_date'    => $toDate,
                ],
                'summary' => [],
                'rows'    => [],
            ]);
        }

        // Attendance summary per employee
        $attendanceRows = $this->db->query("
            SELECT
                a.emp_id,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_days,
                SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) AS half_day_days,
                SUM(CASE WHEN a.status = 'medical_leave' THEN 1 ELSE 0 END) AS medical_leave_days,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
                SUM(CASE WHEN a.status = 'holiday' THEN 1 ELSE 0 END) AS holiday_days,
                SUM(CASE WHEN a.status = 'weekOff' THEN 1 ELSE 0 END) AS weekoff_days,
                SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) AS attendance_leave_days,
                MIN(a.time_in) AS first_time_in,
                MAX(a.time_out) AS last_time_out
            FROM attendance a
            WHERE a.company_id = ?
              AND a.att_date BETWEEN ? AND ?
            GROUP BY a.emp_id
        ", [$companyId, $fromDate, $toDate])->getResultArray();

        $attendanceMap = [];
        foreach ($attendanceRows as $row) {
            $attendanceMap[(int) $row['emp_id']] = $row;
        }

        // Approved leave overlap inside selected month
        $leaveRows = $this->db->query("
            SELECT
                lr.emp_id,
                SUM(CASE
                    WHEN lr.status = 'approved' AND lr.leave_type = 'CL'
                    THEN DATEDIFF(LEAST(lr.to_date, ?), GREATEST(lr.from_date, ?)) + 1
                    ELSE 0
                END) AS cl_days,
                SUM(CASE
                    WHEN lr.status = 'approved' AND lr.leave_type = 'ML'
                    THEN DATEDIFF(LEAST(lr.to_date, ?), GREATEST(lr.from_date, ?)) + 1
                    ELSE 0
                END) AS ml_days,
                SUM(CASE
                    WHEN lr.status = 'approved' AND lr.leave_type = 'UP'
                    THEN DATEDIFF(LEAST(lr.to_date, ?), GREATEST(lr.from_date, ?)) + 1
                    ELSE 0
                END) AS up_days
            FROM leave_requests lr
            WHERE lr.company_id = ?
              AND lr.status = 'approved'
              AND lr.from_date <= ?
              AND lr.to_date >= ?
            GROUP BY lr.emp_id
        ", [
            $toDate, $fromDate,
            $toDate, $fromDate,
            $toDate, $fromDate,
            $companyId, $toDate, $fromDate
        ])->getResultArray();

        $leaveMap = [];
        foreach ($leaveRows as $row) {
            $leaveMap[(int) $row['emp_id']] = $row;
        }

        // Holiday count for reference
        $holidayCount = (int) $this->db->table('holidays')
            ->where('company_id', $companyId)
            ->where('holiday_date >=', $fromDate)
            ->where('holiday_date <=', $toDate)
            ->countAllResults();

        $rows = [];
        $grandGross     = 0;
        $grandDeduction = 0;
        $grandNet       = 0;
        $grandPayable   = 0;
        $grandLop       = 0;

        foreach ($employees as $emp) {
            $empId = (int) $emp['id'];

            $att = $attendanceMap[$empId] ?? [
                'present_days'          => 0,
                'half_day_days'         => 0,
                'medical_leave_days'    => 0,
                'absent_days'           => 0,
                'holiday_days'          => 0,
                'weekoff_days'          => 0,
                'attendance_leave_days' => 0,
                'first_time_in'         => null,
                'last_time_out'         => null,
            ];

            $lv = $leaveMap[$empId] ?? [
                'cl_days' => 0,
                'ml_days' => 0,
                'up_days' => 0,
            ];

            $salary = (float) $emp['salary'];
            $perDaySalary = $totalDays > 0 ? round($salary / $totalDays, 2) : 0;

            $presentDays = (float) $att['present_days'];
            $holidayDays = (float) $att['holiday_days'];
            $weekoffDays = (float) $att['weekoff_days'];
            $halfDayDays      = (float) $att['half_day_days'];
            $medicalLeaveDays = (float) $att['medical_leave_days'];

            // --- CALCULATE PAID SUNDAYS (CORRECTED & TIMEZONE SAFE) ---
            $joinDateClean = !empty($emp['join_date']) ? date('Y-m-d', strtotime($emp['join_date'])) : $fromDate;
            $resignedDateClean = !empty($emp['resigned_date']) ? date('Y-m-d', strtotime($emp['resigned_date'])) : $toDate;

            $startStr = max($fromDate, $joinDateClean);
            $endStr = min($toDate, $resignedDateClean);

            $sundaysInActivePeriod = 0;
            try {
                $currDate = new \DateTime($startStr);
                $endDate  = new \DateTime($endStr);
                
                while ($currDate <= $endDate) {
                    if ($currDate->format('N') == 7) { // 7 = Sunday
                        $sundaysInActivePeriod++;
                    }
                    $currDate->modify('+1 day');
                }
            } catch (\Exception $e) {
                $sundaysInActivePeriod = 0;
            }

            // Ensure Sundays are treated as paid Week Offs even if they aren't explicitly registered in the DB
            $weekoffDays = max($weekoffDays, $sundaysInActivePeriod);
            // ----------------------------------------------------------

            $clDays = max(0, (float) $lv['cl_days']);
            $mlDays = max(0, (float) $lv['ml_days']);
            $upDays = max(0, (float) $lv['up_days']);

            $paidLeaveDays = $clDays + $mlDays;

            // Half day salary logic:
            // 1 half_day = 0.5 payable day
            $halfDayPayable = $halfDayDays * 0.5;

            // Salary logic:
            // payable = present + holiday + weekoff + approved paid leave + medical leave + half day payable
            // lop     = total month days - payable
            $payableDays = $presentDays
                + $holidayDays
                + $weekoffDays
                + $paidLeaveDays
                + $medicalLeaveDays
                + $halfDayPayable;
                
            if ($payableDays > $totalDays) {
                $payableDays = $totalDays;
            }

            $lopDays = max(0, $totalDays - $payableDays);
            $deductionAmount = round($lopDays * $perDaySalary, 2);
            $netSalary       = round($salary - $deductionAmount, 2);

            $grandGross     += $salary;
            $grandDeduction += $deductionAmount;
            $grandNet       += $netSalary;
            $grandPayable   += $payableDays;
            $grandLop       += $lopDays;

            $rows[] = [
                'employee_id'            => $empId,
                'emp_code'               => $emp['emp_code'],
                'employee_name'          => $emp['name'],
                'month'                  => $month,
                'year'                   => $year,
                'from_date'              => $fromDate,
                'to_date'                => $toDate,
                'total_days'             => $totalDays,
                'present_days'           => $presentDays,
                'half_day_days'          => $halfDayDays,
                'half_day_payable_days'  => $halfDayPayable,
                'medical_leave_days'     => $medicalLeaveDays,
                'absent_days'            => (float) $att['absent_days'],
                'holiday_days'           => $holidayDays,
                'weekoff_days'           => $weekoffDays,
                'approved_cl_days'       => $clDays,
                'approved_ml_days'       => $mlDays,
                'approved_up_days'       => $upDays,
                'paid_leave_days'        => $paidLeaveDays,
                'payable_days'           => $payableDays,
                'lop_days'               => $lopDays,
                'gross_salary'           => round($salary, 2),
                'per_day_salary'         => $perDaySalary,
                'deduction_amount'       => $deductionAmount,
                'net_salary'             => $netSalary,
                'first_time_in'          => $att['first_time_in'],
                'last_time_out'          => $att['last_time_out'],
            ];
        }

        return $this->jsonResponse(true, 'Monthly attendance report fetched successfully', [
            'filters' => [
                'company_id'    => $companyId,
                'month'         => $month,
                'year'          => $year,
                'from_date'     => $fromDate,
                'to_date'       => $toDate,
                'holiday_count' => $holidayCount,
            ],
            'summary' => [
                'employee_count'      => count($rows),
                'total_gross_salary'  => round($grandGross, 2),
                'total_deduction'     => round($grandDeduction, 2),
                'total_net_salary'    => round($grandNet, 2),
                'total_payable_days'  => round($grandPayable, 2),
                'total_lop_days'      => round($grandLop, 2),
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * GET /api/reports/project-productivity?from=2026-03-01&to=2026-03-31
     * or  /api/reports/project-productivity?month=3&year=2026
     */
    public function projectProductivity(): ResponseInterface
    {
        [$fromDate, $toDate] = $this->getDateRange();

        $projects = $this->db->query("
            SELECT
                p.id,
                p.project_code,
                p.project_title,
                p.status AS project_status,
                s.name AS service_name,
                c.name AS client_name,
                COUNT(pt.id) AS total_tasks,
                SUM(CASE WHEN pt.status = 'done' THEN 1 ELSE 0 END) AS completed_tasks,
                SUM(CASE WHEN pt.status = 'pending' THEN 1 ELSE 0 END) AS pending_tasks,
                SUM(CASE WHEN pt.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
                SUM(CASE WHEN pt.status = 'canceled' THEN 1 ELSE 0 END) AS canceled_tasks,
                SUM(
                    CASE
                        WHEN pt.due_date IS NOT NULL
                         AND pt.due_date < ?
                         AND (pt.status IS NULL OR pt.status <> 'done')
                        THEN 1 ELSE 0
                    END
                ) AS overdue_tasks,
                SUM(CASE WHEN pt.is_milestone = 1 THEN 1 ELSE 0 END) AS milestone_tasks,
                SUM(CASE WHEN pt.is_milestone = 1 AND pt.status = 'done' THEN 1 ELSE 0 END) AS completed_milestones
            FROM projects p
            LEFT JOIN services s ON s.id = p.service_id
            LEFT JOIN clients c ON c.id = p.client_id
            LEFT JOIN project_tasks pt
                   ON pt.project_id = p.id
                  AND (
                      (pt.status = 'done' AND DATE(pt.completed_at) BETWEEN ? AND ?)
                      OR
                      (pt.status <> 'done' AND DATE(pt.created_at) BETWEEN ? AND ?)
                  )
            GROUP BY p.id, p.project_code, p.project_title, p.status, s.name, c.name
            ORDER BY p.id DESC
        ", [$toDate, $fromDate, $toDate, $fromDate, $toDate])->getResultArray();

        $rows = [];
        $summary = [
            'project_count'        => 0,
            'total_tasks'          => 0,
            'completed_tasks'      => 0,
            'pending_tasks'        => 0,
            'in_progress_tasks'    => 0,
            'canceled_tasks'       => 0,
            'overdue_tasks'        => 0,
            'completed_projects'   => 0,
            'active_projects'      => 0,
        ];

        foreach ($projects as $row) {
            $totalTasks      = (int) $row['total_tasks'];
            $completedTasks  = (int) $row['completed_tasks'];
            $pendingTasks    = (int) $row['pending_tasks'];
            $inProgressTasks = (int) $row['in_progress_tasks'];
            $canceledTasks   = (int) $row['canceled_tasks'];
            $overdueTasks    = (int) $row['overdue_tasks'];

            $completionPct = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 2) : 0;

            $rows[] = [
                'project_id'             => (int) $row['id'],
                'project_code'           => $row['project_code'],
                'project_title'          => $row['project_title'],
                'project_status'         => $row['project_status'],
                'service_name'           => $row['service_name'],
                'client_name'            => $row['client_name'],
                'total_tasks'            => $totalTasks,
                'completed_tasks'        => $completedTasks,
                'pending_tasks'          => $pendingTasks,
                'in_progress_tasks'      => $inProgressTasks,
                'canceled_tasks'         => $canceledTasks,
                'overdue_tasks'          => $overdueTasks,
                'milestone_tasks'        => (int) $row['milestone_tasks'],
                'completed_milestones'   => (int) $row['completed_milestones'],
                'completion_percentage'  => $completionPct,
            ];

            $summary['project_count']++;
            $summary['total_tasks']       += $totalTasks;
            $summary['completed_tasks']   += $completedTasks;
            $summary['pending_tasks']     += $pendingTasks;
            $summary['in_progress_tasks'] += $inProgressTasks;
            $summary['canceled_tasks']    += $canceledTasks;
            $summary['overdue_tasks']     += $overdueTasks;

            if (strtolower((string) $row['project_status']) === 'completed') {
                $summary['completed_projects']++;
            } else {
                $summary['active_projects']++;
            }
        }

        $summary['overall_completion_percentage'] =
            $summary['total_tasks'] > 0
                ? round(($summary['completed_tasks'] / $summary['total_tasks']) * 100, 2)
                : 0;

        return $this->jsonResponse(true, 'Project productivity report fetched successfully', [
            'filters' => [
                'from_date' => $fromDate,
                'to_date'   => $toDate,
            ],
            'summary' => $summary,
            'rows'    => $rows,
        ]);
    }

    /**
     * GET /api/reports/employee-performance?from=2026-03-01&to=2026-03-31
     * or  /api/reports/employee-performance?month=3&year=2026
     */
    public function employeePerformance(): ResponseInterface
    {
        [$fromDate, $toDate] = $this->getDateRange();

        $rows = $this->db->query("
            SELECT
                u.id AS user_id,
                u.name AS user_name,
                u.email,
                r.name AS role_name,
                COUNT(pt.id) AS total_assigned_tasks,
                COUNT(DISTINCT pt.project_id) AS participated_projects,
                SUM(CASE WHEN pt.status = 'done' THEN 1 ELSE 0 END) AS completed_tasks,
                SUM(CASE WHEN pt.status = 'pending' THEN 1 ELSE 0 END) AS pending_tasks,
                SUM(CASE WHEN pt.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
                SUM(
                    CASE
                        WHEN pt.due_date IS NOT NULL
                         AND pt.due_date < ?
                         AND pt.status <> 'done'
                        THEN 1 ELSE 0
                    END
                ) AS overdue_tasks,
                SUM(CASE WHEN pt.is_milestone = 1 AND pt.status = 'done' THEN 1 ELSE 0 END) AS milestone_completed
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            LEFT JOIN project_tasks pt
                   ON pt.assigned_to = u.id
                  AND (
                      (pt.status = 'done' AND DATE(pt.completed_at) BETWEEN ? AND ?)
                      OR
                      (pt.status <> 'done' AND DATE(pt.created_at) BETWEEN ? AND ?)
                  )
            WHERE u.status = 1
            GROUP BY u.id, u.name, u.email, r.name
            ORDER BY u.name ASC
        ", [$toDate, $fromDate, $toDate, $fromDate, $toDate])->getResultArray();

        $rankedRows = [];
        foreach ($rows as $row) {
            $totalAssigned   = (int) $row['total_assigned_tasks'];
            $completed       = (int) $row['completed_tasks'];
            $pending         = (int) $row['pending_tasks'];
            $inProgress      = (int) $row['in_progress_tasks'];
            $overdue         = (int) $row['overdue_tasks'];
            $milestoneDone   = (int) $row['milestone_completed'];
            $projectsCount   = (int) $row['participated_projects'];

            $completionRate = $totalAssigned > 0 ? round(($completed / $totalAssigned) * 100, 2) : 0;

            // Simple operational score for current schema
            $performanceScore = round(
                ($completed * 5) +
                ($milestoneDone * 3) +
                ($projectsCount * 2) -
                ($overdue * 2),
                2
            );

            $rankedRows[] = [
                'user_id'                => (int) $row['user_id'],
                'user_name'              => $row['user_name'],
                'email'                  => $row['email'],
                'role_name'              => $row['role_name'],
                'total_assigned_tasks'   => $totalAssigned,
                'participated_projects'  => $projectsCount,
                'completed_tasks'        => $completed,
                'pending_tasks'          => $pending,
                'in_progress_tasks'      => $inProgress,
                'overdue_tasks'          => $overdue,
                'milestone_completed'    => $milestoneDone,
                'completion_rate'        => $completionRate,
                'performance_score'      => $performanceScore,
            ];
        }

        usort($rankedRows, static function ($a, $b) {
            if ($a['performance_score'] == $b['performance_score']) {
                if ($a['completion_rate'] == $b['completion_rate']) {
                    return $b['completed_tasks'] <=> $a['completed_tasks'];
                }
                return $b['completion_rate'] <=> $a['completion_rate'];
            }
            return $b['performance_score'] <=> $a['performance_score'];
        });

        foreach ($rankedRows as $index => &$row) {
            $row['rank'] = $index + 1;
            $row['employee_of_the_month'] = ($index === 0 && $row['total_assigned_tasks'] > 0);
        }
        unset($row);

        $employeeOfMonth = $rankedRows[0] ?? null;

        return $this->jsonResponse(true, 'Employee performance report fetched successfully', [
            'filters' => [
                'from_date' => $fromDate,
                'to_date'   => $toDate,
            ],
            'summary' => [
                'employee_count'      => count($rankedRows),
                'employee_of_month'   => $employeeOfMonth,
            ],
            'rows' => $rankedRows,
        ]);
    }
    
    /**
     * GET /api/reports/staff-task-summary?from=2026-04-01&to=2026-04-30
     * GET /api/reports/staff-task-summary?month=4&year=2026
     */
    public function staffTaskSummary(): ResponseInterface
    {
        [$fromDate, $toDate] = $this->getDateRange();

        $rows = $this->db->query("
            SELECT
                u.id AS user_id,
                u.name AS staff_name,
                u.email,
                r.name AS role_name,
                e.emp_code,
                e.name AS employee_name,

                COUNT(pt.id) AS total_tasks,
                SUM(CASE WHEN pt.status = 'pending' THEN 1 ELSE 0 END) AS pending_tasks,
                SUM(CASE WHEN pt.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
                SUM(CASE WHEN pt.status = 'done' THEN 1 ELSE 0 END) AS completed_tasks,
                SUM(CASE WHEN pt.status = 'canceled' THEN 1 ELSE 0 END) AS canceled_tasks,
                SUM(
                    CASE
                        WHEN pt.due_date IS NOT NULL
                         AND pt.due_date < ?
                         AND pt.status <> 'done'
                        THEN 1 ELSE 0
                    END
                ) AS overdue_tasks,
                COUNT(DISTINCT pt.project_id) AS project_count

            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            LEFT JOIN employees e ON e.id = u.employee_id
            LEFT JOIN project_tasks pt
                   ON pt.assigned_to = u.id
                  AND (
                      (pt.status = 'done' AND DATE(pt.completed_at) BETWEEN ? AND ?)
                      OR
                      (pt.status <> 'done' AND DATE(pt.created_at) BETWEEN ? AND ?)
                  )
            WHERE u.status = 1
            GROUP BY u.id, u.name, u.email, r.name, e.emp_code, e.name
            ORDER BY u.name ASC
        ", [$toDate, $fromDate, $toDate, $fromDate, $toDate])->getResultArray();

        $data = [];
        $summary = [
            'staff_count'       => 0,
            'total_tasks'       => 0,
            'pending_tasks'     => 0,
            'in_progress_tasks' => 0,
            'completed_tasks'   => 0,
            'canceled_tasks'    => 0,
            'overdue_tasks'     => 0,
        ];

        foreach ($rows as $row) {
            $total     = (int) $row['total_tasks'];
            $completed = (int) $row['completed_tasks'];

            $completionPercentage = $total > 0
                ? round(($completed / $total) * 100, 2)
                : 0;

            $data[] = [
                'user_id'               => (int) $row['user_id'],
                'staff_name'            => $row['staff_name'],
                'employee_name'         => $row['employee_name'],
                'emp_code'              => $row['emp_code'],
                'email'                 => $row['email'],
                'role_name'             => $row['role_name'],
                'project_count'         => (int) $row['project_count'],
                'total_tasks'           => $total,
                'pending_tasks'         => (int) $row['pending_tasks'],
                'in_progress_tasks'     => (int) $row['in_progress_tasks'],
                'completed_tasks'       => $completed,
                'canceled_tasks'        => (int) $row['canceled_tasks'],
                'overdue_tasks'         => (int) $row['overdue_tasks'],
                'completion_percentage' => $completionPercentage,
            ];

            $summary['staff_count']++;
            $summary['total_tasks']       += (int) $row['total_tasks'];
            $summary['pending_tasks']     += (int) $row['pending_tasks'];
            $summary['in_progress_tasks'] += (int) $row['in_progress_tasks'];
            $summary['completed_tasks']   += (int) $row['completed_tasks'];
            $summary['canceled_tasks']    += (int) $row['canceled_tasks'];
            $summary['overdue_tasks']     += (int) $row['overdue_tasks'];
        }

        $summary['overall_completion_percentage'] =
            $summary['total_tasks'] > 0
                ? round(($summary['completed_tasks'] / $summary['total_tasks']) * 100, 2)
                : 0;

        return $this->jsonResponse(true, 'Staff task summary fetched successfully', [
            'filters' => [
                'from_date' => $fromDate,
                'to_date'   => $toDate,
            ],
            'summary' => $summary,
            'rows'    => $data,
        ]);
    }

    /**
     * GET /api/reports/staff-tasks/25?from=2026-04-01&to=2026-04-30
     * GET /api/reports/staff-tasks/25?status=pending
     */
    public function staffTasks(int $userId): ResponseInterface
    {
        [$fromDate, $toDate] = $this->getDateRange();

        $status = trim((string) $this->request->getGet('status'));

        $builder = $this->db->table('project_tasks pt')
            ->select("
                pt.id AS task_id,
                pt.project_id,
                pt.task_title,
                pt.task_description,
                pt.status,
                pt.priority,
                pt.start_date,
                pt.due_date,
                pt.completed_at,
                pt.is_milestone,
                pt.is_mandatory,
                pt.created_at,
                pt.updated_at,
                p.project_code,
                p.project_title,
                p.status AS project_status,
                c.name AS client_name,
                s.name AS service_name,
                u.name AS assigned_staff_name
            ")
            ->join('projects p', 'p.id = pt.project_id', 'left')
            ->join('clients c', 'c.id = p.client_id', 'left')
            ->join('services s', 's.id = p.service_id', 'left')
            ->join('users u', 'u.id = pt.assigned_to', 'left')
            ->where('pt.assigned_to', $userId)
            ->groupStart()
                ->groupStart()
                    ->where('pt.status', 'done')
                    ->where('DATE(pt.completed_at) >=', $fromDate)
                    ->where('DATE(pt.completed_at) <=', $toDate)
                ->groupEnd()
                ->orGroupStart()
                    ->where('pt.status !=', 'done')
                    ->where('DATE(pt.created_at) >=', $fromDate)
                    ->where('DATE(pt.created_at) <=', $toDate)
                ->groupEnd()
            ->groupEnd();

        if ($status !== '') {
            $builder->where('pt.status', $status);
        }

        $tasks = $builder
            ->orderBy('pt.due_date', 'ASC')
            ->orderBy('pt.id', 'DESC')
            ->get()
            ->getResultArray();

        $summary = [
            'total_tasks'       => 0,
            'pending_tasks'     => 0,
            'in_progress_tasks' => 0,
            'completed_tasks'   => 0,
            'canceled_tasks'    => 0,
            'overdue_tasks'     => 0,
        ];

        $today = date('Y-m-d');

        foreach ($tasks as &$task) {
            $task['task_id']      = (int) $task['task_id'];
            $task['project_id']   = (int) $task['project_id'];
            $task['is_milestone'] = (int) $task['is_milestone'];
            $task['is_mandatory'] = (int) $task['is_mandatory'];

            $task['is_overdue'] = (
                !empty($task['due_date'])
                && $task['due_date'] < $today
                && $task['status'] !== 'done'
            );

            $summary['total_tasks']++;

            if ($task['status'] === 'pending') {
                $summary['pending_tasks']++;
            } elseif ($task['status'] === 'in_progress') {
                $summary['in_progress_tasks']++;
            } elseif ($task['status'] === 'done') {
                $summary['completed_tasks']++;
            } elseif ($task['status'] === 'canceled') {
                $summary['canceled_tasks']++;
            }

            if ($task['is_overdue']) {
                $summary['overdue_tasks']++;
            }
        }
        unset($task);

        return $this->jsonResponse(true, 'Staff task list fetched successfully', [
            'filters' => [
                'user_id'   => $userId,
                'from_date' => $fromDate,
                'to_date'   => $toDate,
                'status'    => $status,
            ],
            'summary' => $summary,
            'rows'    => $tasks,
        ]);
    }

    public function myTasks(): ResponseInterface
    {
        // Support method_exists and fallback cleanly to X-User-Id, user request property, and session
        $userId = method_exists($this, 'authUserId')
            ? (int) $this->authUserId()
            : (int) ($_SERVER['HTTP_X_USER_ID'] ?? $this->request->user['id'] ?? session('user_id') ?? 0);

        if ($userId <= 0) {
            return $this->jsonResponse(false, 'Login required', null, 401);
        }

        return $this->staffTasks($userId);
    }

    /**
     * GET /api/reports/client-report
     * GET /api/reports/client-report?from=2026-01-01&to=2026-12-31
     * GET /api/reports/client-report?month=5&year=2026
     */
    public function clientReport(): ResponseInterface
    {
        [$fromDate, $toDate] = $this->getDateRange();

        $rows = $this->db->query("
            SELECT
                c.id AS client_id,
                c.name AS client_name,
                COUNT(DISTINCT p.id) AS project_count,
                COALESCE(SUM(
                    CASE
                        WHEN pip.payment_date BETWEEN ? AND ?
                        THEN pip.amount
                        ELSE 0
                    END
                ), 0) AS total_income
            FROM clients c
            LEFT JOIN projects p 
                   ON p.client_id = c.id
            LEFT JOIN project_invoices pi 
                   ON pi.project_id = p.id
            LEFT JOIN project_invoice_payments pip 
                   ON pip.invoice_id = pi.id
            GROUP BY c.id, c.name
            ORDER BY total_income DESC, project_count DESC, c.name ASC
        ", [$fromDate, $toDate])->getResultArray();

        $data = [];

        $summary = [
            'client_count'       => 0,
            'total_project_count'=> 0,
            'total_income'       => 0,
            'top_client'         => null,
        ];

        foreach ($rows as $index => $row) {
            $projectCount = (int) $row['project_count'];
            $totalIncome  = round((float) $row['total_income'], 2);

            $item = [
                's_no'                     => $index + 1,
                'client_id'                => (int) $row['client_id'],
                'client_name'              => $row['client_name'],
                'project_count'            => $projectCount,
                'total_income_from_client' => $totalIncome,
                'rank'                     => $index + 1,
            ];

            $data[] = $item;

            $summary['client_count']++;
            $summary['total_project_count'] += $projectCount;
            $summary['total_income'] += $totalIncome;
        }

        if (!empty($data)) {
            $summary['top_client'] = $data[0];
        }

        $summary['total_income'] = round((float) $summary['total_income'], 2);

        return $this->jsonResponse(true, 'Client report fetched successfully', [
            'filters' => [
                'from_date' => $fromDate,
                'to_date'   => $toDate,
            ],
            'summary' => $summary,
            'rows'    => $data,
        ]);
    }
}