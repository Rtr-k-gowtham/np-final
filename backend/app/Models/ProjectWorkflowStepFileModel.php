<?php

namespace App\Models;

use CodeIgniter\Model;

class ProjectWorkflowStepFileModel extends Model
{
    protected $table            = 'project_workflow_step_files';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'project_workflow_step_id',
        'file_path',
        'file_name',
        'file_ext',
        'file_size_kb',
        'uploaded_by',
        'uploaded_at',
    ];
}
