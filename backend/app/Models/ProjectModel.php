<?php

namespace App\Models;

use CodeIgniter\Model;

class ProjectModel extends Model
{
    protected $table            = 'projects';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'project_code',
        'service_id',
        'created_by_user_id',
        'client_id',
        'applicant_id',
        'project_title',
        'description',
        'status',
        'assigned_agent_id',
        'start_date',
        'opening_date',
        'deadline',
        'due_date',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'service_id'    => 'required|is_natural_no_zero',
        'project_title' => 'required|min_length[3]|max_length[255]',
        'status'        => 'permit_empty|in_list[open,in_progress,completed,closed]',
        'client_id'     => 'permit_empty|is_natural_no_zero',
        'applicant_id'  => 'permit_empty|is_natural_no_zero',
        'assigned_agent_id' => 'permit_empty|is_natural_no_zero',
        'project_code'  => 'permit_empty|max_length[60]',
        'description'   => 'permit_empty',
    ];

    protected $validationMessages = [
        'service_id' => [
            'required' => 'service_id is required',
        ],
        'project_title' => [
            'required' => 'project_title is required',
        ],
    ];
}
