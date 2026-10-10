<?php

namespace App\Models;

use CodeIgniter\Model;

class ProjectWorkflowModel extends Model
{
    protected $table            = 'project_workflows';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;

    protected $returnType       = 'array';
    protected $protectFields    = true;

    protected $allowedFields = [
        'project_id',
        'workflow_template_id',
        'template_version',
        'started_at',
        'completed_at',
    ];

    // your table already has datetime columns, but we are manually setting started_at
    protected $useTimestamps = false;
}
