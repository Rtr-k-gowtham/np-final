<?php

namespace App\Models;

use CodeIgniter\Model;

class ServiceModel extends Model
{
    protected $table      = 'services';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'code','name','description','default_sla_days','status'
    ];

    protected $useTimestamps = true;

    protected $validationRules = [
        'code' => 'required|min_length[3]|max_length[60]',
        'name' => 'required|min_length[3]|max_length[200]',
    ];
}
