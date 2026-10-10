<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class RolePermissionControllerApi extends BaseController
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

    private function roleExists(int $roleId): bool
    {
        return $roleId > 0 && $this->db->table('roles')
            ->where('id', $roleId)
            ->countAllResults() > 0;
    }

    private function permissionExists(int $permissionId): bool
    {
        return $permissionId > 0 && $this->db->table('permissions')
            ->where('id', $permissionId)
            ->countAllResults() > 0;
    }

    // GET /api/permissions?search=&module=
    public function permissionList(): ResponseInterface
    {
        $search = trim((string)($this->request->getGet('search') ?? ''));
        $module = trim((string)($this->request->getGet('module') ?? ''));

        $builder = $this->db->table('permissions')
            ->select('id, code, module, action, description')
            ->orderBy('module', 'ASC')
            ->orderBy('code', 'ASC');

        if ($module !== '') {
            $builder->where('module', $module);
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('code', $search)
                ->orLike('module', $search)
                ->orLike('action', $search)
                ->orLike('description', $search)
                ->groupEnd();
        }

        $rows = $builder->get()->getResultArray();

        return $this->json(true, 'Permissions fetched successfully', $rows);
    }

    // GET /api/permissions/grouped
    public function permissionGrouped(): ResponseInterface
    {
        $rows = $this->db->table('permissions')
            ->select('id, code, module, action, description')
            ->orderBy('module', 'ASC')
            ->orderBy('code', 'ASC')
            ->get()
            ->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $module = $row['module'] ?: 'others';
            if (!isset($grouped[$module])) {
                $grouped[$module] = [];
            }
            $grouped[$module][] = $row;
        }

        return $this->json(true, 'Grouped permissions fetched successfully', $grouped);
    }

    // GET /api/roles/permissions?role_id=1
    // or GET /api/roles/{roleId}/permissions
    public function rolePermissions($roleId = null): ResponseInterface
    {
        $roleId = $roleId !== null ? (int)$roleId : (int)($this->request->getGet('role_id') ?? 0);

        if ($roleId <= 0) {
            return $this->json(false, 'Role id is required', null, 422);
        }

        if (!$this->roleExists($roleId)) {
            return $this->json(false, 'Role not found', null, 404);
        }

        $rows = $this->db->table('role_permissions rp')
            ->select('
                rp.id,
                rp.role_id,
                rp.permission_id,
                r.name AS role_name,
                p.code,
                p.module,
                p.action,
                p.description
            ')
            ->join('roles r', 'r.id = rp.role_id', 'left')
            ->join('permissions p', 'p.id = rp.permission_id', 'left')
            ->where('rp.role_id', $roleId)
            ->orderBy('p.module', 'ASC')
            ->orderBy('p.code', 'ASC')
            ->get()
            ->getResultArray();

        $permissionIds = array_map(static fn($r) => (int)$r['permission_id'], $rows);

        return $this->json(true, 'Role permissions fetched successfully', [
            'role_id'        => $roleId,
            'permission_ids' => $permissionIds,
            'items'          => $rows,
        ]);
    }

    // POST /api/roles/{roleId}/permissions
    // payload: { "permission_ids": [27,28,30] }
    public function syncRolePermissions($roleId = null): ResponseInterface
    {
        $roleId = (int)$roleId;

        if ($roleId <= 0) {
            return $this->json(false, 'Invalid role id', null, 422);
        }

        if (!$this->roleExists($roleId)) {
            return $this->json(false, 'Role not found', null, 404);
        }

        $payload = $this->getPayload();
        $permissionIds = $payload['permission_ids'] ?? $payload['permissions'] ?? [];

        if (!is_array($permissionIds)) {
            return $this->json(false, 'permission_ids must be an array', null, 422);
        }

        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));
        $permissionIds = array_values(array_filter($permissionIds, fn($v) => $v > 0));

        if (!empty($permissionIds)) {
            $validCount = $this->db->table('permissions')
                ->whereIn('id', $permissionIds)
                ->countAllResults();

            if ($validCount !== count($permissionIds)) {
                return $this->json(false, 'One or more permission ids are invalid', null, 422);
            }
        }

        $this->db->transStart();

        // remove existing mappings
        $this->db->table('role_permissions')->where('role_id', $roleId)->delete();

        // insert new mappings
        if (!empty($permissionIds)) {
            $insertRows = [];
            foreach ($permissionIds as $pid) {
                $insertRows[] = [
                    'role_id'       => $roleId,
                    'permission_id' => $pid,
                ];
            }
            $this->db->table('role_permissions')->insertBatch($insertRows);
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return $this->json(false, 'Failed to save role permissions', null, 500);
        }

        return $this->rolePermissions($roleId);
    }

    // POST /api/role-permissions
    // payload: { "role_id": 1, "permission_id": 27 }
    public function create(): ResponseInterface
    {
        $payload = $this->getPayload();

        $roleId       = (int)($payload['role_id'] ?? 0);
        $permissionId = (int)($payload['permission_id'] ?? 0);

        $errors = [];

        if ($roleId <= 0) {
            $errors['role_id'] = 'Role is required';
        } elseif (!$this->roleExists($roleId)) {
            $errors['role_id'] = 'Invalid role';
        }

        if ($permissionId <= 0) {
            $errors['permission_id'] = 'Permission is required';
        } elseif (!$this->permissionExists($permissionId)) {
            $errors['permission_id'] = 'Invalid permission';
        }

        if (empty($errors)) {
            $exists = $this->db->table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->countAllResults();

            if ($exists > 0) {
                $errors['duplicate'] = 'This permission is already assigned to the role';
            }
        }

        if (!empty($errors)) {
            return $this->json(false, 'Validation failed', null, 422, $errors);
        }

        $this->db->table('role_permissions')->insert([
            'role_id'       => $roleId,
            'permission_id' => $permissionId,
        ]);

        $newId = (int)$this->db->insertID();

        $row = $this->db->table('role_permissions rp')
            ->select('
                rp.id,
                rp.role_id,
                rp.permission_id,
                r.name AS role_name,
                p.code,
                p.module,
                p.action,
                p.description
            ')
            ->join('roles r', 'r.id = rp.role_id', 'left')
            ->join('permissions p', 'p.id = rp.permission_id', 'left')
            ->where('rp.id', $newId)
            ->get()
            ->getRowArray();

        return $this->json(true, 'Role permission created successfully', $row, 201);
    }

    // PUT /api/role-permissions/{id}
    // payload: { "permission_id": 30 }
    public function update($id = null): ResponseInterface
    {
        $id = (int)$id;

        if ($id <= 0) {
            return $this->json(false, 'Invalid mapping id', null, 422);
        }

        $existing = $this->db->table('role_permissions')->where('id', $id)->get()->getRowArray();
        if (!$existing) {
            return $this->json(false, 'Role permission mapping not found', null, 404);
        }

        $payload = $this->getPayload();

        $roleId       = isset($payload['role_id']) ? (int)$payload['role_id'] : (int)$existing['role_id'];
        $permissionId = isset($payload['permission_id']) ? (int)$payload['permission_id'] : (int)$existing['permission_id'];

        $errors = [];

        if ($roleId <= 0 || !$this->roleExists($roleId)) {
            $errors['role_id'] = 'Invalid role';
        }

        if ($permissionId <= 0 || !$this->permissionExists($permissionId)) {
            $errors['permission_id'] = 'Invalid permission';
        }

        if (empty($errors)) {
            $duplicate = $this->db->table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->where('id !=', $id)
                ->countAllResults();

            if ($duplicate > 0) {
                $errors['duplicate'] = 'This role-permission mapping already exists';
            }
        }

        if (!empty($errors)) {
            return $this->json(false, 'Validation failed', null, 422, $errors);
        }

        $this->db->table('role_permissions')
            ->where('id', $id)
            ->update([
                'role_id'       => $roleId,
                'permission_id' => $permissionId,
            ]);

        $row = $this->db->table('role_permissions rp')
            ->select('
                rp.id,
                rp.role_id,
                rp.permission_id,
                r.name AS role_name,
                p.code,
                p.module,
                p.action,
                p.description
            ')
            ->join('roles r', 'r.id = rp.role_id', 'left')
            ->join('permissions p', 'p.id = rp.permission_id', 'left')
            ->where('rp.id', $id)
            ->get()
            ->getRowArray();

        return $this->json(true, 'Role permission updated successfully', $row);
    }

    // DELETE /api/role-permissions/{id}
    public function delete($id = null): ResponseInterface
    {
        $id = (int)$id;

        if ($id <= 0) {
            return $this->json(false, 'Invalid mapping id', null, 422);
        }

        $existing = $this->db->table('role_permissions')->where('id', $id)->get()->getRowArray();
        if (!$existing) {
            return $this->json(false, 'Role permission mapping not found', null, 404);
        }

        $this->db->table('role_permissions')->where('id', $id)->delete();

        return $this->json(true, 'Role permission deleted successfully');
    }
}