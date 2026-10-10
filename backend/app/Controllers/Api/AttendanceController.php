<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use App\Libraries\JwtService;

class AttendanceController extends ResourceController
{
    protected $format = 'json';
    protected $db;
    protected $jwtService;

    private array $allowedStatus = ['present', 'absent', 'holiday', 'weekOff', 'leave', 'medical_leave', 'half_day'];

    public function __construct()
    {
        $this->db = db_connect();
        $this->jwtService = new JwtService();
    }

    private function json(bool $ok, string $msg, $data = null, int $code = 200, $errors = null): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => $ok,
            'message' => $msg,
            'data'    => $data,
            'errors'  => $errors
        ]);
    }

    private function isValidDate(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    }

    private function normTime($t): ?string
    {
        $t = trim((string)$t);
        if ($t === '') return null;

        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) return null;

        if (strlen($t) === 5) $t .= ':00';
        return $t;
    }

    private function getBearerToken(): string
{
    // 1) Cookie-based auth (your actual login flow)
    $cookieToken = $this->request->getCookie('npcrm_token');
    if (!empty($cookieToken)) {
        return trim($cookieToken);
    }

    // 2) Standard Authorization header
    $authHeader = $this->request->getHeaderLine('Authorization');
    if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        return trim($matches[1]);
    }

    // 3) Legacy custom header
    $xToken = $this->request->getHeaderLine('X-Auth-Token');
    if (!empty($xToken)) {
        return trim(preg_replace('/^Bearer\s+/i', '', $xToken));
    }

    return '';
}

private function authUser(): ?array
{
    /*
     * IMPORTANT:
     * Auth filter may only provide:
     * id, role_id, role_code
     *
     * But attendance needs:
     * users.employee_id, users.company_id
     *
     * So always fetch full user row from DB when we have user id.
     */

    $filterUser = $this->request->user ?? null;
    $userId = 0;

    if (is_array($filterUser) && !empty($filterUser['id'])) {
        $userId = (int) $filterUser['id'];
    } elseif (is_object($filterUser) && !empty($filterUser->id)) {
        $userId = (int) $filterUser->id;
    }

    if ($userId <= 0) {
        $token = $this->getBearerToken();

        if ($token === '') {
            return null;
        }

        try {
            $payload = $this->jwtService->validateToken($token);

            if (!is_array($payload) || empty($payload)) {
                return null;
            }

            if (!empty($payload['uid'])) {
                $userId = (int) $payload['uid'];
            } elseif (!empty($payload['user_id'])) {
                $userId = (int) $payload['user_id'];
            } elseif (!empty($payload['id'])) {
                $userId = (int) $payload['id'];
            } elseif (!empty($payload['sub'])) {
                $userId = (int) $payload['sub'];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Attendance auth token error: ' . $e->getMessage());
            return null;
        }
    }

    if ($userId <= 0) {
        return null;
    }

    $user = $this->db->table('users')
        ->select('id, company_id, employee_id, role_id, name, email, phone, status')
        ->where('id', $userId)
        ->where('status', 1)
        ->get()
        ->getRowArray();

    return $user ?: null;
}

    private function authUserId(): int
    {
        $user = $this->authUser();
        return (int)($user['id'] ?? 0);
    }

    private function companyId(): int
    {
        $user = $this->authUser();
        if (!empty($user['company_id'])) {
            return (int)$user['company_id'];
        }

        $req = $this->request->getJSON(true);
        if (is_array($req) && isset($req['company_id'])) {
            return (int)$req['company_id'];
        }

        return (int)$this->request->getGet('company_id');
    }

private function currentEmployee(): ?array
{
    $authUser = $this->authUser();
    log_message('error', 'SELF ATTENDANCE AUTH USER = ' . json_encode($authUser));
    if (!$authUser) {
        return null;
    }

    $userId     = (int)($authUser['id'] ?? 0);
    $employeeId = (int)($authUser['employee_id'] ?? 0);

    if ($employeeId <= 0) {
        return null;
    }

    $emp = $this->db->table('employees')
        ->select('id as emp_id, company_id, emp_code, name')
        ->where('id', $employeeId)
        ->get()
        ->getRowArray();

    if (!$emp) {
        return null;
    }

    $companyId = (int)($emp['company_id'] ?? 0);
    if ($companyId <= 0) {
        return null;
    }

    return [
        'user_id'     => $userId,
        'company_id'  => $companyId,
        'employee_id' => $employeeId,
        'emp_id'      => (int)$emp['emp_id'],
        'emp_code'    => $emp['emp_code'] ?? null,
        'name'        => $emp['name'] ?? null,
    ];
}

    private function attendanceRow(int $companyId, int $empId, string $attDate): ?array
    {
        $row = $this->db->table('attendance')
            ->where('company_id', $companyId)
            ->where('emp_id', $empId)
            ->where('att_date', $attDate)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * GET /api/attendance?from=YYYY-MM-DD&to=YYYY-MM-DD&emp_id=&status=&page=&limit=
     */
    public function index(): ResponseInterface
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        $from   = trim((string)($this->request->getGet('from') ?: $this->request->getGet('date_from')));
        $to     = trim((string)($this->request->getGet('to') ?: $this->request->getGet('date_to')));
        $empId  = (int)$this->request->getGet('emp_id');
        $status = trim((string)$this->request->getGet('status'));

        $page  = max(1, (int)$this->request->getGet('page'));
        $limit = (int)$this->request->getGet('limit');
        if ($limit <= 0) $limit = 50;
        if ($limit > 2000) $limit = 2000;
        $offset = ($page - 1) * $limit;

        $b = $this->db->table('attendance a')
            ->select('a.*, e.emp_code, e.name, e.shift_start, e.shift_end, e.work_mode')
            ->join('employees e', 'e.id = a.emp_id', 'left')
            ->where('a.company_id', $companyId);

        if ($from !== '') {
            if (!$this->isValidDate($from)) {
                return $this->json(false, 'Invalid from date', null, 422);
            }
            $b->where('a.att_date >=', $from);
        }

        if ($to !== '') {
            if (!$this->isValidDate($to)) {
                return $this->json(false, 'Invalid to date', null, 422);
            }
            $b->where('a.att_date <=', $to);
        }

        if ($empId > 0) {
            $b->where('a.emp_id', $empId);
        }

        if ($status !== '') {
            if (!in_array($status, $this->allowedStatus, true)) {
                return $this->json(false, 'Invalid status', null, 422, ['status' => $this->allowedStatus]);
            }
            $b->where('a.status', $status);
        }

        $countBuilder = clone $b;
        $totalRow = $countBuilder->select('COUNT(*) AS c', false)->get()->getRowArray();
        $total = (int)($totalRow['c'] ?? 0);

        $rows = $b->orderBy('a.att_date', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return $this->json(true, 'OK', [
            'items' => $rows,
            'page'  => $page,
            'limit' => $limit,
            'total' => $total
        ]);
    }

    /**
     * GET /api/attendance/{id}
     */
    public function show($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) {
            return $this->json(false, 'Invalid id', null, 422);
        }

        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        $row = $this->db->table('attendance a')
            ->select('a.*, e.emp_code, e.name, e.shift_start, e.shift_end, e.work_mode')
            ->join('employees e', 'e.id = a.emp_id', 'left')
            ->where('a.company_id', $companyId)
            ->where('a.id', $id)
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->json(false, 'Attendance not found', null, 404);
        }

        return $this->json(true, 'OK', $row);
    }

    /**
     * POST /api/attendance
     * Body: att_date, emp_id, time_in, time_out, status, remark(optional)
     * Upsert by (company_id, emp_id, att_date)
     */
    public function create(): ResponseInterface
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        $p = $this->request->getJSON(true);
        if (!is_array($p) || empty($p)) $p = $this->request->getPost();
        if (!is_array($p)) $p = [];

        $attDate = trim((string)($p['att_date'] ?? ''));
        $empId   = (int)($p['emp_id'] ?? 0);
        $status  = trim((string)($p['status'] ?? ''));
        $remark  = trim((string)($p['remark'] ?? ''));

        if (!$this->isValidDate($attDate)) {
            return $this->json(false, 'Invalid att_date', null, 422);
        }

        if ($empId <= 0) {
            return $this->json(false, 'Invalid emp_id', null, 422);
        }

        if (!in_array($status, $this->allowedStatus, true)) {
            return $this->json(false, 'Invalid status', null, 422, ['status' => $this->allowedStatus]);
        }

        $timeIn  = $this->normTime($p['time_in'] ?? null);
        $timeOut = $this->normTime($p['time_out'] ?? null);

        if (($p['time_in'] ?? '') !== '' && $timeIn === null) {
            return $this->json(false, 'Invalid time_in', null, 422);
        }

        if (($p['time_out'] ?? '') !== '' && $timeOut === null) {
            return $this->json(false, 'Invalid time_out', null, 422);
        }

        $emp = $this->db->table('employees')
            ->where('company_id', $companyId)
            ->where('id', $empId)
            ->get()
            ->getRowArray();

        if (!$emp) {
            return $this->json(false, 'Employee not found for this company', null, 404);
        }

        $existing = $this->attendanceRow($companyId, $empId, $attDate);

        $data = [
            'company_id' => $companyId,
            'att_date'   => $attDate,
            'emp_id'     => $empId,
            'time_in'    => $timeIn,
            'time_out'   => $timeOut,
            'status'     => $status,
            'remark'     => ($remark === '' ? null : $remark),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $this->db->table('attendance')->where('id', (int)$existing['id'])->update($data);
            return $this->json(true, 'Attendance updated (upsert)', ['id' => (int)$existing['id']]);
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->table('attendance')->insert($data);
        $id = (int)$this->db->insertID();

        return $this->json(true, 'Attendance created', ['id' => $id], 201);
    }

    /**
     * PUT/PATCH /api/attendance/{id}
     */
    public function update($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) {
            return $this->json(false, 'Invalid id', null, 422);
        }

        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        $row = $this->db->table('attendance')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->json(false, 'Attendance not found', null, 404);
        }

        $p = $this->request->getJSON(true);
        if (!is_array($p) || empty($p)) $p = $this->request->getRawInput();
        if (!is_array($p)) $p = [];

        $data = [];

        if (array_key_exists('att_date', $p)) {
            $attDate = trim((string)$p['att_date']);
            if (!$this->isValidDate($attDate)) {
                return $this->json(false, 'Invalid att_date', null, 422);
            }
            $data['att_date'] = $attDate;
        }

        if (array_key_exists('emp_id', $p)) {
            $empId = (int)$p['emp_id'];
            if ($empId <= 0) {
                return $this->json(false, 'Invalid emp_id', null, 422);
            }

            $emp = $this->db->table('employees')
                ->where('company_id', $companyId)
                ->where('id', $empId)
                ->get()
                ->getRowArray();

            if (!$emp) {
                return $this->json(false, 'Employee not found for this company', null, 404);
            }

            $data['emp_id'] = $empId;
        }

        if (array_key_exists('time_in', $p)) {
            $t = $this->normTime($p['time_in']);
            if (($p['time_in'] ?? '') !== '' && $t === null) {
                return $this->json(false, 'Invalid time_in', null, 422);
            }
            $data['time_in'] = $t;
        }

        if (array_key_exists('time_out', $p)) {
            $t = $this->normTime($p['time_out']);
            if (($p['time_out'] ?? '') !== '' && $t === null) {
                return $this->json(false, 'Invalid time_out', null, 422);
            }
            $data['time_out'] = $t;
        }

        if (array_key_exists('status', $p)) {
            $status = trim((string)$p['status']);
            if (!in_array($status, $this->allowedStatus, true)) {
                return $this->json(false, 'Invalid status', null, 422, ['status' => $this->allowedStatus]);
            }
            $data['status'] = $status;
        }

        if (array_key_exists('remark', $p)) {
            $remark = trim((string)$p['remark']);
            $data['remark'] = ($remark === '' ? null : $remark);
        }

        if (empty($data)) {
            return $this->json(false, 'No data to update', null, 422);
        }

        $newEmpId   = (int)($data['emp_id'] ?? $row['emp_id']);
        $newAttDate = (string)($data['att_date'] ?? $row['att_date']);

        $dup = $this->db->table('attendance')
            ->where('company_id', $companyId)
            ->where('emp_id', $newEmpId)
            ->where('att_date', $newAttDate)
            ->where('id !=', $id)
            ->get()
            ->getRowArray();

        if ($dup) {
            return $this->json(false, 'Duplicate attendance exists for same emp_id and date', null, 409);
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('attendance')->where('id', $id)->update($data);

        return $this->json(true, 'Attendance updated', ['id' => $id]);
    }

    /**
     * DELETE /api/attendance/{id}
     */
    public function delete($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) {
            return $this->json(false, 'Invalid id', null, 422);
        }

        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->json(false, 'company_id missing', null, 422);
        }

        $row = $this->db->table('attendance')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->json(false, 'Attendance not found', null, 404);
        }

        $this->db->table('attendance')->where('id', $id)->delete();
        return $this->json(true, 'Attendance deleted', ['id' => $id]);
    }

    /**
     * POST /api/attendance/self/login-capture
     */
    public function selfLoginCapture(): ResponseInterface
    {
        $emp = $this->currentEmployee();
        if (!$emp) {
            return $this->json(false, 'Logged-in employee mapping not found', null, 404);
        }

        $companyId = (int)($emp['company_id'] ?? 1);
        $empId     = (int)$emp['emp_id'];
        $today     = date('Y-m-d');
        $nowTime   = date('H:i:s');

        $reqData = $this->request->getJSON(true) ?? $this->request->getPost() ?? [];
        $lat = isset($reqData['lat']) ? (float)$reqData['lat'] : (isset($reqData['latitude']) ? (float)$reqData['latitude'] : null);
        $lng = isset($reqData['lng']) ? (float)$reqData['lng'] : (isset($reqData['longitude']) ? (float)$reqData['longitude'] : null);

        // Fetch employee details for work_mode
        $empRow = $this->db->table('employees')->where('id', $empId)->get()->getRowArray();
        $workMode = strtolower(trim((string)($empRow['work_mode'] ?? 'company')));

        // Check office geofence for 'company' work mode
        if ($workMode === 'company') {
            $office = $this->db->table('company_locations')
                ->where('company_id', $companyId)
                ->orderBy('id', 'DESC')
                ->get()
                ->getRowArray();

            if ($office) {
                $allowedRadius = (int)($office['radius_meters'] ?? 100);

                if ($lat === null || $lng === null) {
                    return $this->json(false, 'GPS location is required for company employees to check in.', null, 403);
                }

                $distance = CompanyLocationController::haversineDistance($lat, $lng, (float)$office['latitude'], (float)$office['longitude']);

                if ($distance > $allowedRadius) {
                    return $this->json(false, 'Attendance check-in blocked: You are ' . round($distance) . 'm away from the office. You must be within ' . $allowedRadius . 'm.', [
                        'distance'       => round($distance, 1),
                        'allowed_radius' => $allowedRadius,
                        'work_mode'      => 'company'
                    ], 403);
                }
            }
        }

        $existing = $this->attendanceRow($companyId, $empId, $today);

        if ($existing) {
            $updateData = [
                'status'     => 'present',
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if (empty($existing['time_in'])) {
                $updateData['time_in'] = $nowTime;
            }

            if (empty($existing['remark'])) {
                $updateData['remark'] = 'Auto captured on login (' . ucfirst($workMode) . ')';
            }

            if ($lat !== null) {
                $updateData['latitude'] = $lat;
                $updateData['longitude'] = $lng;
            }

            $this->db->table('attendance')->where('id', (int)$existing['id'])->update($updateData);

            $fresh = $this->db->table('attendance')
                ->where('id', (int)$existing['id'])
                ->get()
                ->getRowArray();

            return $this->json(true, 'Login attendance captured', [
                'attendance_id' => (int)$existing['id'],
                'emp_id'        => $empId,
                'emp_code'      => $emp['emp_code'] ?? null,
                'name'          => $emp['name'] ?? null,
                'att_date'      => $fresh['att_date'] ?? $today,
                'time_in'       => $fresh['time_in'] ?? null,
                'time_out'      => $fresh['time_out'] ?? null,
                'status'        => $fresh['status'] ?? 'present',
                'action'        => 'updated'
            ]);
        }

        $insertData = [
            'company_id' => $companyId,
            'att_date'   => $today,
            'emp_id'     => $empId,
            'time_in'    => $nowTime,
            'time_out'   => null,
            'status'     => 'present',
            'remark'     => 'Auto captured on login (' . ucfirst($workMode) . ')',
            'latitude'   => $lat,
            'longitude'  => $lng,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->table('attendance')->insert($insertData);
        $id = (int)$this->db->insertID();

        return $this->json(true, 'Login attendance captured', [
            'attendance_id' => $id,
            'emp_id'        => $empId,
            'emp_code'      => $emp['emp_code'] ?? null,
            'name'          => $emp['name'] ?? null,
            'att_date'      => $today,
            'time_in'       => $nowTime,
            'time_out'      => null,
            'status'        => 'present',
            'action'        => 'created'
        ], 201);
    }

    /**
     * POST /api/attendance/self/logout-capture
     */
    public function selfLogoutCapture(): ResponseInterface
    {
        $emp = $this->currentEmployee();
        if (!$emp) {
            return $this->json(false, 'Logged-in employee mapping not found', null, 404);
        }

        $companyId = (int)$emp['company_id'];
        $empId     = (int)$emp['emp_id'];
        $today     = date('Y-m-d');
        $nowTime   = date('H:i:s');

        $existing = $this->attendanceRow($companyId, $empId, $today);

        if (!$existing) {
            $insertData = [
                'company_id' => $companyId,
                'att_date'   => $today,
                'emp_id'     => $empId,
                'time_in'    => null,
                'time_out'   => $nowTime,
                'status'     => 'present',
                'remark'     => 'Auto captured on logout',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $this->db->table('attendance')->insert($insertData);
            $id = (int)$this->db->insertID();

            return $this->json(true, 'Logout attendance captured', [
                'attendance_id' => $id,
                'emp_id'        => $empId,
                'emp_code'      => $emp['emp_code'] ?? null,
                'name'          => $emp['name'] ?? null,
                'att_date'      => $today,
                'time_in'       => null,
                'time_out'      => $nowTime,
                'status'        => 'present',
                'action'        => 'created'
            ], 201);
        }

        $updateData = [
            'time_out'   => $nowTime,
            'status'     => 'present',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (empty($existing['remark'])) {
            $updateData['remark'] = 'Auto captured on logout';
        }

        $this->db->table('attendance')->where('id', (int)$existing['id'])->update($updateData);

        return $this->json(true, 'Logout attendance captured', [
            'attendance_id' => (int)$existing['id'],
            'emp_id'        => $empId,
            'emp_code'      => $emp['emp_code'] ?? null,
            'name'          => $emp['name'] ?? null,
            'att_date'      => $today,
            'time_in'       => $existing['time_in'],
            'time_out'      => $nowTime,
            'status'        => 'present',
            'action'        => 'updated'
        ]);
    }

    /**
     * GET /api/attendance/self/today
     */
    public function selfToday(): ResponseInterface
    {
        $emp = $this->currentEmployee();
        if (!$emp) {
            return $this->json(false, 'Logged-in employee mapping not found', null, 404);
        }

        $row = $this->db->table('attendance a')
            ->select('a.*, e.emp_code, e.name, e.shift_start, e.shift_end, e.work_mode')
            ->join('employees e', 'e.id = a.emp_id', 'left')
            ->where('a.company_id', (int)$emp['company_id'])
            ->where('a.emp_id', (int)$emp['emp_id'])
            ->where('a.att_date', date('Y-m-d'))
            ->get()
            ->getRowArray();

        return $this->json(true, 'OK', $row);
    }

    /**
     * GET /api/attendance/self/history?from=YYYY-MM-DD&to=YYYY-MM-DD&page=&limit=
     */
    public function selfHistory(): ResponseInterface
    {
        $emp = $this->currentEmployee();
        if (!$emp) {
            return $this->json(false, 'Logged-in employee mapping not found', null, 404);
        }

        $from = trim((string)$this->request->getGet('from'));
        $to   = trim((string)$this->request->getGet('to'));

        $page  = max(1, (int)$this->request->getGet('page'));
        $limit = (int)$this->request->getGet('limit');
        if ($limit <= 0 || $limit > 200) $limit = 31;
        $offset = ($page - 1) * $limit;

        $b = $this->db->table('attendance a')
            ->select('a.*, e.emp_code, e.name, e.shift_start, e.shift_end, e.work_mode')
            ->join('employees e', 'e.id = a.emp_id', 'left')
            ->where('a.company_id', (int)$emp['company_id'])
            ->where('a.emp_id', (int)$emp['emp_id']);

        if ($from !== '') {
            if (!$this->isValidDate($from)) {
                return $this->json(false, 'Invalid from date', null, 422);
            }
            $b->where('a.att_date >=', $from);
        }

        if ($to !== '') {
            if (!$this->isValidDate($to)) {
                return $this->json(false, 'Invalid to date', null, 422);
            }
            $b->where('a.att_date <=', $to);
        }

        $countBuilder = clone $b;
        $totalRow = $countBuilder->select('COUNT(*) AS c', false)->get()->getRowArray();
        $total = (int)($totalRow['c'] ?? 0);

        $rows = $b->orderBy('a.att_date', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return $this->json(true, 'OK', [
            'employee' => [
                'emp_id'   => (int)$emp['emp_id'],
                'emp_code' => $emp['emp_code'] ?? null,
                'name'     => $emp['name'] ?? null,
            ],
            'items' => $rows,
            'page'  => $page,
            'limit' => $limit,
            'total' => $total
        ]);
    }
}