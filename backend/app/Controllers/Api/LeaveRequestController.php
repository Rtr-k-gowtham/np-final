<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

class LeaveRequestController extends ResourceController
{
    protected $format = 'json';

    private array $allowedType   = ['CL', 'ML', 'UP'];
    private array $allowedStatus = ['pending', 'approved', 'rejected', 'cancelled'];

    private function json(bool $ok, string $msg, $data = null, int $code = 200, $errors = null): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => $ok,
            'message' => $msg,
            'data'    => $data,
            'errors'  => $errors,
        ]);
    }

    private function companyId(): int
    {
        $cid = (int) (session()->get('company_id') ?? 0);
        if ($cid > 0) {
            return $cid;
        }

        $req = $this->request->getJSON(true);
        if (is_array($req) && isset($req['company_id'])) {
            return (int) $req['company_id'];
        }

        return (int) $this->request->getGet('company_id');
    }

   private function userId(): int
    {
        return (int)($_SERVER['HTTP_X_USER_ID'] ?? 0);
    }
    
    private function employeeId(): int
	{
		$userId = $this->userId();

		if ($userId <= 0) {
			return 0;
		}

		$row = db_connect()->table('users u')
			->select('u.employee_id')
			->where('u.id', $userId)
			->get()
			->getRowArray();

		return (int) ($row['employee_id'] ?? 0);
	}

    private function isValidDate(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    }

    private function calcDays(string $from, string $to): float
    {
        $a = strtotime($from);
        $b = strtotime($to);

        if ($a === false || $b === false) {
            return 0.0;
        }

        if ($b < $a) {
            return 0.0;
        }

        return (float) (((int) floor(($b - $a) / 86400)) + 1);
    }

    private function getPayload(): array
    {
        $p = $this->request->getJSON(true);

        if (!is_array($p) || empty($p)) {
            $p = $this->request->getRawInput();
        }

        if (!is_array($p) || empty($p)) {
            $p = $this->request->getPost();
        }

        return is_array($p) ? $p : [];
    }

    private function getLeaveById(int $id, int $companyId): ?array
    {
        $row = db_connect()->table('leave_requests lr')
            ->select('lr.*, e.emp_code, e.name')
            ->join('employees e', 'e.id = lr.emp_id', 'left')
            ->where('lr.company_id', $companyId)
            ->where('lr.id', $id)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    private function employeeExists(int $companyId, int $empId): bool
    {
        $row = db_connect()->table('employees')
            ->select('id')
            ->where('company_id', $companyId)
            ->where('id', $empId)
            ->get()
            ->getRowArray();

        return !empty($row);
    }

    private function hasOverlap(int $companyId, int $empId, string $from, string $to, ?int $excludeId = null): bool
    {
        $b = db_connect()->table('leave_requests')
            ->where('company_id', $companyId)
            ->where('emp_id', $empId)
            ->whereIn('status', ['pending', 'approved'])
            ->groupStart()
                ->where('from_date <=', $to)
                ->where('to_date >=', $from)
            ->groupEnd();

        if ($excludeId !== null && $excludeId > 0) {
            $b->where('id !=', $excludeId);
        }

        $row = $b->get()->getRowArray();
        return !empty($row);
    }

    /**
     * ADMIN / HR
     * GET /api/leave-requests
     */
    public function index(): ResponseInterface
    {
        $db = db_connect();
        $companyId = $this->companyId();

        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        $status = trim((string) $this->request->getGet('status'));
        $empId  = (int) $this->request->getGet('emp_id');
        $from   = trim((string) $this->request->getGet('from'));
        $to     = trim((string) $this->request->getGet('to'));

        $page  = max(1, (int) $this->request->getGet('page'));
        $limit = (int) $this->request->getGet('limit');
        if ($limit <= 0 || $limit > 200) {
            $limit = 50;
        }

        $offset = ($page - 1) * $limit;

        $b = $db->table('leave_requests lr')
            ->select('lr.*, e.emp_code, e.name')
            ->join('employees e', 'e.id = lr.emp_id', 'left')
            ->where('lr.company_id', $companyId);

        if ($empId > 0) {
            $b->where('lr.emp_id', $empId);
        }

        if ($status !== '') {
            if (!in_array($status, $this->allowedStatus, true)) {
                return $this->json(false, 'Invalid status', null, 422, [
                    'status' => $this->allowedStatus
                ]);
            }
            $b->where('lr.status', $status);
        }

        if ($from !== '') {
            if (!$this->isValidDate($from)) {
                return $this->json(false, 'Invalid from date', null, 422);
            }
            $b->where('lr.from_date >=', $from);
        }

        if ($to !== '') {
            if (!$this->isValidDate($to)) {
                return $this->json(false, 'Invalid to date', null, 422);
            }
            $b->where('lr.to_date <=', $to);
        }

        $countBuilder = clone $b;
        $totalRow = $countBuilder->select('COUNT(*) AS c', false)->get()->getRowArray();
        $total = (int) ($totalRow['c'] ?? 0);

        $rows = $b->orderBy('lr.id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return $this->json(true, 'OK', [
            'items' => $rows,
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    /**
     * ADMIN / HR
     * GET /api/leave-requests/{id}
     */
    public function show($id = null): ResponseInterface
    {
        $id = (int) $id;
        if ($id <= 0) {
            return $this->json(false, 'Invalid id', null, 422);
        }

        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        $row = $this->getLeaveById($id, $companyId);
        if (!$row) {
            return $this->json(false, 'Leave request not found', null, 404);
        }

        return $this->json(true, 'OK', $row);
    }

    /**
     * EMPLOYEE SELF
     * GET /api/leave-requests/my
     */
    public function myLeaves(): ResponseInterface
    {
        $db = db_connect();
        $companyId = $this->companyId();
        $empId     = $this->employeeId();

        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        if ($empId <= 0) {
            return $this->json(false, 'Employee mapping not found', null, 403);
        }

        $status = trim((string) $this->request->getGet('status'));
        $from   = trim((string) $this->request->getGet('from'));
        $to     = trim((string) $this->request->getGet('to'));

        $page  = max(1, (int) $this->request->getGet('page'));
        $limit = (int) $this->request->getGet('limit');
        if ($limit <= 0 || $limit > 200) {
            $limit = 50;
        }

        $offset = ($page - 1) * $limit;

        $b = $db->table('leave_requests lr')
            ->select('lr.*, e.emp_code, e.name')
            ->join('employees e', 'e.id = lr.emp_id', 'left')
            ->where('lr.company_id', $companyId)
            ->where('lr.emp_id', $empId);

        if ($status !== '') {
            if (!in_array($status, $this->allowedStatus, true)) {
                return $this->json(false, 'Invalid status', null, 422, [
                    'status' => $this->allowedStatus
                ]);
            }
            $b->where('lr.status', $status);
        }

        if ($from !== '') {
            if (!$this->isValidDate($from)) {
                return $this->json(false, 'Invalid from date', null, 422);
            }
            $b->where('lr.from_date >=', $from);
        }

        if ($to !== '') {
            if (!$this->isValidDate($to)) {
                return $this->json(false, 'Invalid to date', null, 422);
            }
            $b->where('lr.to_date <=', $to);
        }

        $countBuilder = clone $b;
        $totalRow = $countBuilder->select('COUNT(*) AS c', false)->get()->getRowArray();
        $total = (int) ($totalRow['c'] ?? 0);

        $rows = $b->orderBy('lr.id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return $this->json(true, 'OK', [
            'items' => $rows,
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    /**
     * EMPLOYEE SELF
     * POST /api/leave-requests
     * Body: leave_type, from_date, to_date, reason(optional), days(optional)
     */
    public function create(): ResponseInterface
    {
        $db = db_connect();
        $companyId = $this->companyId();
        $empId     = $this->employeeId();

        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        if ($empId <= 0) {
            return $this->json(false, 'Employee mapping not found', null, 403);
        }

        if (!$this->employeeExists($companyId, $empId)) {
            return $this->json(false, 'Employee not found for this company', null, 404);
        }

        $p = $this->getPayload();

        $type   = trim((string) ($p['leave_type'] ?? ''));
        $from   = trim((string) ($p['from_date'] ?? ''));
        $to     = trim((string) ($p['to_date'] ?? ''));
        $reason = trim((string) ($p['reason'] ?? ''));

        $errors = [];

        if (!in_array($type, $this->allowedType, true)) {
            $errors['leave_type'] = 'leave_type must be CL/ML/UP';
        }

        if (!$this->isValidDate($from)) {
            $errors['from_date'] = 'from_date must be YYYY-MM-DD';
        }

        if (!$this->isValidDate($to)) {
            $errors['to_date'] = 'to_date must be YYYY-MM-DD';
        }

        if (!empty($errors)) {
            return $this->json(false, 'Validation failed', null, 422, $errors);
        }

        if (strtotime($to) < strtotime($from)) {
            return $this->json(false, 'to_date must be >= from_date', null, 422);
        }

        $days = (isset($p['days']) && $p['days'] !== '')
            ? (float) $p['days']
            : $this->calcDays($from, $to);

        if ($days <= 0) {
            return $this->json(false, 'Invalid days', null, 422);
        }

        if ($this->hasOverlap($companyId, $empId, $from, $to)) {
            return $this->json(false, 'Leave already exists for this date range', null, 409);
        }

        $data = [
            'company_id'  => $companyId,
            'emp_id'      => $empId,
            'leave_type'  => $type,
            'from_date'   => $from,
            'to_date'     => $to,
            'days'        => $days,
            'reason'      => ($reason === '' ? null : $reason),
            'status'      => 'pending',
            'applied_at'  => date('Y-m-d H:i:s'),
            'approved_by' => null,
            'approved_at' => null,
        ];

        $db->table('leave_requests')->insert($data);
        $id = (int) $db->insertID();

        return $this->json(true, 'Leave request created', ['id' => $id], 201);
    }

    /**
     * EMPLOYEE SELF
     * PUT/PATCH /api/leave-requests/{id}
     * Only pending + only own leave
     */
    public function update($id = null): ResponseInterface
    {
        $id = (int) $id;
        if ($id <= 0) {
            return $this->json(false, 'Invalid id', null, 422);
        }

        $db = db_connect();
        $companyId = $this->companyId();
        $empId     = $this->employeeId();

        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        if ($empId <= 0) {
            return $this->json(false, 'Employee mapping not found', null, 403);
        }

        $row = $db->table('leave_requests')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->json(false, 'Leave request not found', null, 404);
        }

        if ((int) $row['emp_id'] !== $empId) {
            return $this->json(false, 'You can only edit your own leave', null, 403);
        }

        if (($row['status'] ?? '') !== 'pending') {
            return $this->json(false, 'Only pending leave can be updated', null, 409);
        }

        $p = $this->getPayload();

        $data = [];
        $errors = [];

        $newFrom = (string) ($row['from_date'] ?? '');
        $newTo   = (string) ($row['to_date'] ?? '');

        if (array_key_exists('leave_type', $p)) {
            $t = trim((string) $p['leave_type']);
            if (!in_array($t, $this->allowedType, true)) {
                $errors['leave_type'] = 'leave_type must be CL/ML/UP';
            } else {
                $data['leave_type'] = $t;
            }
        }

        if (array_key_exists('from_date', $p)) {
            $fd = trim((string) $p['from_date']);
            if (!$this->isValidDate($fd)) {
                $errors['from_date'] = 'from_date must be YYYY-MM-DD';
            } else {
                $data['from_date'] = $fd;
                $newFrom = $fd;
            }
        }

        if (array_key_exists('to_date', $p)) {
            $td = trim((string) $p['to_date']);
            if (!$this->isValidDate($td)) {
                $errors['to_date'] = 'to_date must be YYYY-MM-DD';
            } else {
                $data['to_date'] = $td;
                $newTo = $td;
            }
        }

        if (array_key_exists('reason', $p)) {
            $reason = trim((string) $p['reason']);
            $data['reason'] = ($reason === '' ? null : $reason);
        }

        if ($newFrom !== '' && $newTo !== '' && strtotime($newTo) < strtotime($newFrom)) {
            $errors['date_range'] = 'to_date must be >= from_date';
        }

        if (array_key_exists('days', $p)) {
            $d = (float) $p['days'];
            if ($d <= 0) {
                $errors['days'] = 'days must be > 0';
            } else {
                $data['days'] = $d;
            }
        } else {
            if ($newFrom !== '' && $newTo !== '') {
                $days = $this->calcDays($newFrom, $newTo);
                if ($days <= 0) {
                    $errors['days'] = 'Invalid days';
                } else {
                    $data['days'] = $days;
                }
            }
        }

        if (!empty($errors)) {
            return $this->json(false, 'Validation failed', null, 422, $errors);
        }

        if (empty($data)) {
            return $this->json(false, 'No data to update', null, 422);
        }

        if ($this->hasOverlap($companyId, $empId, $newFrom, $newTo, $id)) {
            return $this->json(false, 'Leave already exists for this date range', null, 409);
        }

        $db->table('leave_requests')
            ->where('id', $id)
            ->update($data);

        return $this->json(true, 'Leave request updated', ['id' => $id]);
    }

    /**
     * EMPLOYEE SELF
     * DELETE /api/leave-requests/{id}
     * Only pending + only own leave
     */
    public function cancel($id = null): ResponseInterface
    {
        $id = (int) $id;
        if ($id <= 0) {
            return $this->json(false, 'Invalid id', null, 422);
        }

        $db = db_connect();
        $companyId = $this->companyId();
        $empId     = $this->employeeId();

        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        if ($empId <= 0) {
            return $this->json(false, 'Employee mapping not found', null, 403);
        }

        $row = $db->table('leave_requests')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->json(false, 'Leave request not found', null, 404);
        }

        if ((int) $row['emp_id'] !== $empId) {
            return $this->json(false, 'You can only cancel your own leave', null, 403);
        }

        if (($row['status'] ?? '') !== 'pending') {
            return $this->json(false, 'Only pending leave can be cancelled', null, 409);
        }

        $db->table('leave_requests')
            ->where('id', $id)
            ->update([
                'status'      => 'cancelled',
                'approved_by' => null,
                'approved_at' => null,
            ]);

        return $this->json(true, 'Leave request cancelled', ['id' => $id]);
    }

    /**
     * ADMIN / HR
     * POST /api/leave-requests/{id}/approve
     */
    public function approve($id = null): ResponseInterface
    {
        $id = (int) $id;
        if ($id <= 0) {
            return $this->json(false, 'Invalid id', null, 422);
        }

        $db = db_connect();
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        $row = $db->table('leave_requests')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->json(false, 'Leave request not found', null, 404);
        }

        if (($row['status'] ?? '') !== 'pending') {
            return $this->json(false, 'Only pending leave can be approved', null, 409);
        }

        $uid = $this->userId();

        $db->table('leave_requests')
            ->where('id', $id)
            ->update([
                'status'      => 'approved',
                'approved_by' => ($uid > 0 ? $uid : null),
                'approved_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->json(true, 'Leave approved', ['id' => $id]);
    }

    /**
     * ADMIN / HR
     * POST /api/leave-requests/{id}/reject
     */
    public function reject($id = null): ResponseInterface
    {
        $id = (int) $id;
        if ($id <= 0) {
            return $this->json(false, 'Invalid id', null, 422);
        }

        $db = db_connect();
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        $row = $db->table('leave_requests')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->json(false, 'Leave request not found', null, 404);
        }

        if (($row['status'] ?? '') !== 'pending') {
            return $this->json(false, 'Only pending leave can be rejected', null, 409);
        }

        $uid = $this->userId();

        $db->table('leave_requests')
            ->where('id', $id)
            ->update([
                'status'      => 'rejected',
                'approved_by' => ($uid > 0 ? $uid : null),
                'approved_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->json(true, 'Leave rejected', ['id' => $id]);
    }
}