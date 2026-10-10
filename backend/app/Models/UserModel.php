<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'name','email','phone','password_hash','role_id','status'
    ];

    protected $useTimestamps = true;

    public function findActiveByEmail(string $email): ?array
    {
        return $this->select('users.*, roles.code as role_code, roles.name as role_name')
            ->join('roles', 'roles.id = users.role_id')
            ->where('users.email', $email)
            ->where('users.status', 1)
            ->first();
    }

    public function findWithRole(int $userId): ?array
    {
        return $this->select('users.*, roles.code as role_code, roles.name as role_name')
            ->join('roles', 'roles.id = users.role_id')
            ->where('users.id', $userId)
            ->first();
    }
}
