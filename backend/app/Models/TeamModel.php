<?php

namespace App\Models;

use CodeIgniter\Model;

class TeamModel extends Model
{
    protected $table            = 'teams';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'team_code',
        'team_name',
        'description',
        'team_leader_id',
        'department_id',
        'status',
        'created_by',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = false;
}
