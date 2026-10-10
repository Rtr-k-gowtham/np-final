<?php

namespace App\Models;

use CodeIgniter\Model;

class ProjectExpenseApprovalModel extends Model
{
    protected $table         = 'project_expense_approvals';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'expense_id','action','remarks','action_by','action_at'
    ];
}
