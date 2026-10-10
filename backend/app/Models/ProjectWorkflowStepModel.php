<?php

namespace App\Models;

use CodeIgniter\Model;

class ProjectWorkflowStepModel extends Model
{
    protected $table            = 'project_workflow_steps';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;

    protected $returnType       = 'array';
    protected $protectFields    = true;

    protected $allowedFields = [
        'project_workflow_id',
        'service_workflow_step_id',
        'step_no',
        'step_title',
        'step_description',
        'follow_by_role_id',
        'follow_by_user_id',
        'approval_stage',
        'approval_role_id',
        'approval_user_id',
        'status',
        'started_at',
        'due_date',
        'completed_at',
        'updated_by',
        'notes',
    ];

    protected $useTimestamps = false;
}
