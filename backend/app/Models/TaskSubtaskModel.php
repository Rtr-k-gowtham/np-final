<?php

namespace App\Models;

use CodeIgniter\Model;

class TaskSubtaskModel extends Model
{
    protected $table            = 'task_subtasks';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'task_id',
        'title',
        'description',
        'assigned_employee_id',
        'start_date',
        'due_date',
        'priority',
        'status',
        'progress_percentage',
        'remarks',
        'completed_by',
        'completed_at',
        'created_by',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = false;
}
