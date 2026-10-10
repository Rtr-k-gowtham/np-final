<?php

namespace App\Models;

use CodeIgniter\Model;

class ProjectExpenseModel extends Model
{
    protected $table         = 'project_expenses';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'project_id','category_id','expense_date','title','description',
        'amount','tax_amount','total_amount',
        'status','payment_status',
        'submitted_by','approved_by','approved_at'
    ];

    protected $validationRules = [
        'project_id'   => 'required|is_natural_no_zero',
        'category_id'  => 'required|is_natural_no_zero',
        'expense_date' => 'required|valid_date[Y-m-d]',
        'title'        => 'required|min_length[3]|max_length[255]',
        'amount'       => 'required|decimal',
        'tax_amount'   => 'permit_empty|decimal',
        'total_amount' => 'required|decimal',
    ];
}
