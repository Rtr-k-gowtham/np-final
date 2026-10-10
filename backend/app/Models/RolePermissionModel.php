<?php

namespace App\Models;

use CodeIgniter\Model;

class RolePermissionModel extends Model
{
    protected $table      = 'role_permissions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = ['role_id','permission_id'];
    public $useTimestamps = false;

    public function getPermissionCodesByRoleId(int $roleId): array
    {
        $rows = $this->db->table('role_permissions rp')
            ->select('p.code')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->get()->getResultArray();

        return array_map(fn($r) => $r['code'], $rows);
    }
}
