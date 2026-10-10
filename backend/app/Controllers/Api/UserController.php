<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class UserController extends BaseController
{
    protected $db;

    public function __construct()
    {
        $this->db = db_connect();
    }

    private function json(bool $success, string $message = '', $data = null, int $code = 200, $errors = null): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
            'errors'  => $errors,
        ]);
    }

    private function getPayload(): array
    {
        $payload = $this->request->getJSON(true);

        if (!is_array($payload) || empty($payload)) {
            $payload = $this->request->getRawInput();
        }

        if (!is_array($payload) || empty($payload)) {
            $payload = $this->request->getPost();
        }

        return is_array($payload) ? $payload : [];
    }

private function getUserById(int $id): ?array
{
    $row = $this->db->table('users u')
        ->select("
            u.id,
            u.company_id,
            u.employee_id,
            u.name,
            u.email,
            u.phone,
            u.role_id,
            u.status,
            u.created_at,
            u.updated_at,
            r.name AS role_name,
            e.emp_code,
            e.name AS employee_name
        ")
        ->join('roles r', 'r.id = u.role_id', 'left')
        ->join('employees e', 'e.id = u.employee_id AND e.company_id = u.company_id', 'left')
        ->where('u.id', $id)
        ->get()
        ->getRowArray();

    return $row ?: null;
} 
  private function roleExists(int $roleId): bool
    {
        if ($roleId <= 0) {
            return false;
        }

        return $this->db->table('roles')
            ->where('id', $roleId)
            ->where('status', 1)
            ->countAllResults() > 0;
    }

    // GET /api/users?search=&status=&role_id=&page=1&per_page=20
    public function index(): ResponseInterface
    {
        $search  = trim((string)($this->request->getGet('search') ?? ''));
        $status  = $this->request->getGet('status');
        $roleId  = (int)($this->request->getGet('role_id') ?? 0);
        $page    = max(1, (int)($this->request->getGet('page') ?? 1));
        $perPage = max(1, min(100, (int)($this->request->getGet('per_page') ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $builder = $this->db->table('users u');
        $builder->select("
            u.id,
            u.name,
            u.email,
            u.phone,
            u.role_id,
            u.status,
            u.created_at,
            u.updated_at,
            r.name AS role_name
        ");
        $builder->join('roles r', 'r.id = u.role_id', 'left');

        if ($status !== null && $status !== '') {
            $builder->where('u.status', (int)$status);
        }

        if ($roleId > 0) {
            $builder->where('u.role_id', $roleId);
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('u.name', $search)
                ->orLike('u.email', $search)
                ->orLike('u.phone', $search)
                ->groupEnd();
        }

        $countBuilder = clone $builder;
        $total = $countBuilder->countAllResults();

        $rows = $builder->orderBy('u.id', 'DESC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        return $this->json(true, 'Users fetched successfully', [
            'items' => $rows,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'has_more'  => ($offset + $perPage) < $total,
            ]
        ]);
    }

    // GET /api/users/{id}
    public function show($id = null): ResponseInterface
    {
        $id = (int)$id;

        if ($id <= 0) {
            return $this->json(false, 'Invalid user id', null, 422);
        }

        $user = $this->getUserById($id);

        if (!$user) {
            return $this->json(false, 'User not found', null, 404);
        }

        return $this->json(true, 'User fetched successfully', $user);
    }

    // POST /api/users
public function create(): ResponseInterface
{
    $payload = $this->getPayload();

    $companyId  = (int)($payload['company_id'] ?? 0);
    $employeeId = (int)($payload['employee_id'] ?? 0);
    $name       = trim((string)($payload['name'] ?? ''));
    $email      = trim((string)($payload['email'] ?? ''));
    $phone      = trim((string)($payload['phone'] ?? ''));
    $password   = trim((string)($payload['password'] ?? ''));
    $roleId     = (int)($payload['role_id'] ?? 0);
    $status     = isset($payload['status']) ? (int)$payload['status'] : 1;

    $errors = [];

    if ($companyId <= 0) {
        $errors['company_id'] = 'Company is required';
    }

    if ($employeeId <= 0) {
        $errors['employee_id'] = 'Employee is required';
    }

    if ($name === '') {
        $errors['name'] = 'Name is required';
    }

    if ($email === '') {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Valid email is required';
    } else {
        $exists = $this->db->table('users')
            ->where('email', $email)
            ->countAllResults();

        if ($exists > 0) {
            $errors['email'] = 'Email already exists';
        }
    }

    

    if ($password === '') {
        $errors['password'] = 'Password is required';
    } elseif (strlen($password) < 6) {
        $errors['password'] = 'Password must be minimum 6 characters';
    }

    if ($roleId <= 0) {
        $errors['role_id'] = 'Role is required';
    } elseif (!$this->roleExists($roleId)) {
        $errors['role_id'] = 'Selected role is invalid or inactive';
    }

    if (!in_array($status, [0, 1], true)) {
        $errors['status'] = 'Status must be 0 or 1';
    }

    if ($companyId > 0 && $employeeId > 0) {
        $employee = $this->db->table('employees')
            ->where('id', $employeeId)
            ->where('company_id', $companyId)
            ->get()
            ->getRowArray();

        if (!$employee) {
            $errors['employee_id'] = 'Selected employee is invalid for this company';
        } else {
            if ($name === '') {
                $name = (string)$employee['name'];
            }
        }

        $employeeUserExists = $this->db->table('users')
            ->where('employee_id', $employeeId)
            ->countAllResults();

        if ($employeeUserExists > 0) {
            $errors['employee_id'] = 'This employee is already linked to another user';
        }
    }

    if (!empty($errors)) {
        return $this->json(false, 'Validation failed', null, 422, $errors);
    }

    $insert = [
        'company_id'    => $companyId,
        'employee_id'   => $employeeId,
        'name'          => $name,
        'email'         => $email,
        'phone'         => $phone,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role_id'       => $roleId,
        'status'        => $status,
        'created_at'    => date('Y-m-d H:i:s'),
        'updated_at'    => date('Y-m-d H:i:s'),
    ];

    $this->db->table('users')->insert($insert);
    $newId = (int)$this->db->insertID();

    return $this->json(true, 'User created successfully', $this->getUserById($newId), 201);
}

    // PUT /api/users/{id}
    // PATCH /api/users/{id}
public function update($id = null): ResponseInterface
{
    $id = (int)$id;

    if ($id <= 0) {
        return $this->json(false, 'Invalid user id', null, 422);
    }

    $existing = $this->db->table('users')->where('id', $id)->get()->getRowArray();

    if (!$existing) {
        return $this->json(false, 'User not found', null, 404);
    }

    $payload = $this->getPayload();

    $companyId  = isset($payload['company_id']) ? (int)$payload['company_id'] : (int)$existing['company_id'];
    $employeeId = isset($payload['employee_id']) ? (int)$payload['employee_id'] : (int)$existing['employee_id'];
    $name       = trim((string)($payload['name'] ?? $existing['name']));
    $email      = trim((string)($payload['email'] ?? $existing['email']));
    $phone      = trim((string)($payload['phone'] ?? $existing['phone']));
    $password   = trim((string)($payload['password'] ?? ''));
    $roleId     = isset($payload['role_id']) ? (int)$payload['role_id'] : (int)$existing['role_id'];
    $status     = isset($payload['status']) ? (int)$payload['status'] : (int)$existing['status'];

    $errors = [];

    if ($companyId <= 0) {
        $errors['company_id'] = 'Company is required';
    }

    if ($employeeId <= 0) {
        $errors['employee_id'] = 'Employee is required';
    }

    if ($name === '') {
        $errors['name'] = 'Name is required';
    }

    if ($email === '') {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Valid email is required';
    } else {
        $exists = $this->db->table('users')
            ->where('email', $email)
            ->where('id !=', $id)
            ->countAllResults();

        if ($exists > 0) {
            $errors['email'] = 'Email already exists';
        }
    }

    
    if ($roleId <= 0) {
        $errors['role_id'] = 'Role is required';
    } elseif (!$this->roleExists($roleId)) {
        $errors['role_id'] = 'Selected role is invalid or inactive';
    }

    if ($password !== '' && strlen($password) < 6) {
        $errors['password'] = 'Password must be minimum 6 characters';
    }

    if (!in_array($status, [0, 1], true)) {
        $errors['status'] = 'Status must be 0 or 1';
    }

    if ($companyId > 0 && $employeeId > 0) {
        $employee = $this->db->table('employees')
            ->where('id', $employeeId)
            ->where('company_id', $companyId)
            ->get()
            ->getRowArray();

        if (!$employee) {
            $errors['employee_id'] = 'Selected employee is invalid for this company';
        }

        $employeeUserExists = $this->db->table('users')
            ->where('employee_id', $employeeId)
            ->where('id !=', $id)
            ->countAllResults();

        if ($employeeUserExists > 0) {
            $errors['employee_id'] = 'This employee is already linked to another user';
        }
    }

    if (!empty($errors)) {
        return $this->json(false, 'Validation failed', null, 422, $errors);
    }

    $update = [
        'company_id'  => $companyId,
        'employee_id' => $employeeId,
        'name'        => $name,
        'email'       => $email,
        'phone'       => $phone,
        'role_id'     => $roleId,
        'status'      => $status,
        'updated_at'  => date('Y-m-d H:i:s'),
    ];

    if ($password !== '') {
        $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }

    $this->db->table('users')->where('id', $id)->update($update);

    return $this->json(true, 'User updated successfully', $this->getUserById($id));
}

    // DELETE /api/users/{id}
    public function delete($id = null): ResponseInterface
    {
        $id = (int)$id;

        if ($id <= 0) {
            return $this->json(false, 'Invalid user id', null, 422);
        }

        $existing = $this->db->table('users')->where('id', $id)->get()->getRowArray();

        if (!$existing) {
            return $this->json(false, 'User not found', null, 404);
        }

        $this->db->table('users')->where('id', $id)->delete();

        return $this->json(true, 'User deleted successfully');
    }

    // GET /api/users/dropdown?search=&status=1
    public function dropdown(): ResponseInterface
    {
        $search = trim((string)($this->request->getGet('search') ?? ''));
        $status = $this->request->getGet('status');

        $builder = $this->db->table('users')
            ->select('id, name, email')
            ->orderBy('name', 'ASC');

        if ($status !== null && $status !== '') {
            $builder->where('status', (int)$status);
        } else {
            $builder->where('status', 1);
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('name', $search)
                ->orLike('email', $search)
                ->groupEnd();
        }

        $rows = $builder->limit(50)->get()->getResultArray();

        $data = array_map(static function ($r) {
            return [
                'id'    => (int)$r['id'],
                'text'  => $r['name'] . (!empty($r['email']) ? ' (' . $r['email'] . ')' : ''),
                'name'  => $r['name'],
                'email' => $r['email'],
            ];
        }, $rows);

        return $this->json(true, 'Users loaded', $data);
    }

    // GET /api/users/roles-dropdown?search=&status=1
    public function rolesDropdown(): ResponseInterface
    {
        $search = trim((string)($this->request->getGet('search') ?? ''));
        $status = $this->request->getGet('status');

        $builder = $this->db->table('roles')
            ->select('id, name, code, status')
            ->orderBy('name', 'ASC');

        if ($status !== null && $status !== '') {
            $builder->where('status', (int)$status);
        } else {
            $builder->where('status', 1);
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('name', $search)
                ->orLike('code', $search)
                ->groupEnd();
        }

        $rows = $builder->limit(50)->get()->getResultArray();

        $data = array_map(static function ($r) {
            return [
                'id'     => (int)$r['id'],
                'text'   => $r['name'],
                'name'   => $r['name'],
                'code'   => $r['code'],
                'status' => (int)$r['status'],
            ];
        }, $rows);

        return $this->json(true, 'Roles loaded', $data);
    }
}