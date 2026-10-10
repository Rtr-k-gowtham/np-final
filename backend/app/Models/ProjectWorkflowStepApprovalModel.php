<?php

namespace App\Models;

use CodeIgniter\Model;

class ProjectWorkflowStepApprovalModel extends Model
{
    protected $table         = 'project_workflow_step_approvals';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'project_workflow_step_id',
        'decision',
        'decision_by',
        'decision_at',
        'remarks',
    ];
}
