<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

class EmployeeController extends ResourceController
{
    protected $format = 'json';

    private function json(bool $ok, string $msg, $data = null, int $code = 200, $errors = null): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => $ok,
            'message' => $msg,
            'data'    => $data,
            'errors'  => $errors
        ]);
    }

    private function companyIdFromReq(): int
    {
        // prefer session company_id, else allow company_id in request for API usage
        $cid = (int)(session()->get('company_id') ?? 0);
        if ($cid > 0) return $cid;

        $req = $this->request->getJSON(true);
        if (is_array($req) && isset($req['company_id'])) return (int)$req['company_id'];

        return (int)$this->request->getGet('company_id');
    }

    private function isValidDate(?string $d): bool
    {
        if ($d === null) return false;
        $d = trim($d);
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    }

    private function toMoney($v): float
    {
        if ($v === null || $v === '') return 0.0;
        return (float) $v;
    }

    private function normTime($t): ?string
    {
        $t = trim((string)$t);
        if ($t === '') return null;
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $t)) return null;
        if (strlen($t) === 5) $t .= ':00';
        return $t;
    }

    /**
     * GET /api/employees?status=active&search=mahe&page=1&limit=50
     */
    public function index(): ResponseInterface
    {
        $db = db_connect();
        $companyId = $this->companyIdFromReq();
        if ($companyId <= 0) return $this->json(false, 'company_id missing', null, 422);

        $status = trim((string)$this->request->getGet('status')); // active/inactive
        $search = trim((string)$this->request->getGet('search'));

        $page  = max(1, (int)$this->request->getGet('page'));
        $limit = (int)$this->request->getGet('limit');
        if ($limit <= 0 || $limit > 200) $limit = 50;
        $offset = ($page - 1) * $limit;

        $b = $db->table('employees e')
            ->select('e.id, e.company_id, e.emp_code, e.name, e.salary, e.no_of_leave, e.no_of_ml, e.status, e.work_mode, e.shift_start, e.shift_end, e.join_date, e.resigned_date, e.created_at, e.updated_at')
            ->where('e.company_id', $companyId);

        if ($status !== '') {
            if (!in_array($status, ['active','inactive'], true)) {
                return $this->json(false, 'Invalid status', null, 422, ['status' => ['active','inactive']]);
            }
            $b->where('e.status', $status);
        }

        if ($search !== '') {
            $b->groupStart()
                ->like('e.name', $search)
                ->orLike('e.emp_code', $search)
            ->groupEnd();
        }

        // total count (safe)
        $countBuilder = clone $b;
        $totalRow = $countBuilder->select('COUNT(*) AS c', false)->get()->getRowArray();
        $total = (int)($totalRow['c'] ?? 0);

        $rows = $b->orderBy('e.id', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();

        return $this->json(true, 'OK', [
            'items' => $rows,
            'page'  => $page,
            'limit' => $limit,
            'total' => $total
        ]);
    }

    /**
     * GET /api/employees/{id}
     */
    public function show($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $db = db_connect();
        $companyId = $this->companyIdFromReq();
        if ($companyId <= 0) return $this->json(false, 'company_id missing', null, 422);

        $row = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()->getRowArray();

        if (!$row) return $this->json(false, 'Employee not found', null, 404);
        return $this->json(true, 'OK', $row);
    }

    /**
     * POST /api/employees
     */
    public function create(): ResponseInterface
    {
        $db = db_connect();
        $companyId = $this->companyIdFromReq();
        if ($companyId <= 0) return $this->json(false, 'company_id missing', null, 422);

        $p = $this->request->getJSON(true);
        if (!is_array($p) || empty($p)) $p = $this->request->getPost();
        if (!is_array($p)) $p = [];

        $empCode = trim((string)($p['emp_code'] ?? ''));
        $name    = trim((string)($p['name'] ?? ''));
        $status  = trim((string)($p['status'] ?? 'active'));

        $joinDate = trim((string)($p['join_date'] ?? ''));
        $resignedDate = isset($p['resigned_date']) ? trim((string)$p['resigned_date']) : '';

        $salary = $this->toMoney($p['salary'] ?? 0);
        $noLeave = (float)($p['no_of_leave'] ?? 0);
        $noMl    = (float)($p['no_of_ml'] ?? 0);

        $errors = [];

        if ($empCode === '') $errors['emp_code'] = 'emp_code is required';
        if ($name === '')    $errors['name'] = 'name is required';
        if (!$this->isValidDate($joinDate)) $errors['join_date'] = 'join_date must be YYYY-MM-DD';
        if (!in_array($status, ['active','inactive'], true)) $errors['status'] = 'status must be active/inactive';

        if ($resignedDate !== '' && !$this->isValidDate($resignedDate)) {
            $errors['resigned_date'] = 'resigned_date must be YYYY-MM-DD or empty';
        }

        if (!empty($errors)) return $this->json(false, 'Validation failed', null, 422, $errors);

        // unique emp_code in same company
        $dup = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('emp_code', $empCode)
            ->get()->getRowArray();

        if ($dup) return $this->json(false, 'emp_code already exists', null, 409);

        $workMode = strtolower(trim((string)($p['work_mode'] ?? 'company')));
        if (!in_array($workMode, ['company', 'site', 'hybrid'], true)) {
            $workMode = 'company';
        }

        $shiftStart = $this->normTime($p['shift_start'] ?? '09:00:00') ?? '09:00:00';
        $shiftEnd   = $this->normTime($p['shift_end'] ?? '18:00:00') ?? '18:00:00';

        $data = [
            'company_id'   => $companyId,
            'emp_code'     => $empCode,
            'name'         => $name,
            'salary'       => $salary,
            'no_of_leave'  => $noLeave,
            'no_of_ml'     => $noMl,
            'status'       => $status,
            'work_mode'    => $workMode,
            'shift_start'  => $shiftStart,
            'shift_end'    => $shiftEnd,
            'join_date'    => $joinDate,
            'resigned_date'=> ($resignedDate === '' ? null : $resignedDate),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        $db->table('employees')->insert($data);
        $id = (int)$db->insertID();

        return $this->json(true, 'Employee created', ['id' => $id], 201);
    }

    /**
     * PUT/PATCH /api/employees/{id}
     */
    public function update($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $db = db_connect();
        $companyId = $this->companyIdFromReq();
        if ($companyId <= 0) return $this->json(false, 'company_id missing', null, 422);

        $row = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()->getRowArray();
        if (!$row) return $this->json(false, 'Employee not found', null, 404);

        $p = $this->request->getJSON(true);
        if (!is_array($p) || empty($p)) $p = $this->request->getRawInput();
        if (!is_array($p)) $p = [];

        $data = [];
        $errors = [];

        if (array_key_exists('emp_code', $p)) {
            $empCode = trim((string)$p['emp_code']);
            if ($empCode === '') $errors['emp_code'] = 'emp_code cannot be empty';

            // unique check
            if ($empCode !== '') {
                $dup = $db->table('employees')
                    ->where('company_id', $companyId)
                    ->where('emp_code', $empCode)
                    ->where('id !=', $id)
                    ->get()->getRowArray();
                if ($dup) $errors['emp_code'] = 'emp_code already exists';
            }

            $data['emp_code'] = $empCode;
        }

        if (array_key_exists('name', $p)) {
            $name = trim((string)$p['name']);
            if ($name === '') $errors['name'] = 'name cannot be empty';
            $data['name'] = $name;
        }

        if (array_key_exists('salary', $p)) $data['salary'] = $this->toMoney($p['salary']);

        if (array_key_exists('no_of_leave', $p)) $data['no_of_leave'] = (float)$p['no_of_leave'];
        if (array_key_exists('no_of_ml', $p))    $data['no_of_ml'] = (float)$p['no_of_ml'];

        if (array_key_exists('status', $p)) {
            $status = trim((string)$p['status']);
            if (!in_array($status, ['active','inactive'], true)) {
                $errors['status'] = 'status must be active/inactive';
            } else {
                $data['status'] = $status;
            }
        }

        if (array_key_exists('work_mode', $p)) {
            $wm = strtolower(trim((string)$p['work_mode']));
            if (!in_array($wm, ['company', 'site', 'hybrid'], true)) {
                $errors['work_mode'] = 'work_mode must be company, site, or hybrid';
            } else {
                $data['work_mode'] = $wm;
            }
        }

        if (array_key_exists('shift_start', $p)) {
            $st = $this->normTime($p['shift_start']);
            if ($st) $data['shift_start'] = $st;
        }

        if (array_key_exists('shift_end', $p)) {
            $se = $this->normTime($p['shift_end']);
            if ($se) $data['shift_end'] = $se;
        }

        if (array_key_exists('join_date', $p)) {
            $jd = trim((string)$p['join_date']);
            if (!$this->isValidDate($jd)) $errors['join_date'] = 'join_date must be YYYY-MM-DD';
            else $data['join_date'] = $jd;
        }

        if (array_key_exists('resigned_date', $p)) {
            $rd = trim((string)$p['resigned_date']);
            if ($rd === '') $data['resigned_date'] = null;
            else {
                if (!$this->isValidDate($rd)) $errors['resigned_date'] = 'resigned_date must be YYYY-MM-DD or empty';
                else $data['resigned_date'] = $rd;
            }
        }

        if (!empty($errors)) return $this->json(false, 'Validation failed', null, 422, $errors);
        if (empty($data)) return $this->json(false, 'No data to update', null, 422);

        $data['updated_at'] = date('Y-m-d H:i:s');
        $db->table('employees')->where('id', $id)->update($data);

        return $this->json(true, 'Employee updated', ['id' => $id]);
    }

    /**
     * DELETE /api/employees/{id}
     */
    public function delete($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $db = db_connect();
        $companyId = $this->companyIdFromReq();
        if ($companyId <= 0) return $this->json(false, 'company_id missing', null, 422);

        $row = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()->getRowArray();

        if (!$row) return $this->json(false, 'Employee not found', null, 404);

        // optional: block delete if attendance exists
        $hasAtt = $db->table('attendance')
            ->where('company_id', $companyId)
            ->where('emp_id', $id)
            ->limit(1)->get()->getRowArray();

        if ($hasAtt) {
            return $this->json(false, 'Cannot delete. Attendance exists for this employee.', null, 409);
        }

        $db->table('employees')->where('id', $id)->delete();
        return $this->json(true, 'Employee deleted', ['id' => $id]);
    }
}