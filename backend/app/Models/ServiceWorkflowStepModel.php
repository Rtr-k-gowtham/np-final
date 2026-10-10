<?php

namespace App\Models;

use CodeIgniter\Model;

class ServiceWorkflowStepModel extends Model
{
    protected $table      = 'service_workflow_steps';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'workflow_template_id',
        'step_no',
        'step_title',
        'step_description',
        'approval_stage',
        'approval_role_id',
        'approval_user_id',
        'follow_by_role_id',
        'follow_by_user_id',
        'sla_days',
        'depends_on_step_no',
        'sort_order',
    ];

    protected $useTimestamps = false; // your table has no created_at/updated_at

    protected $validationRules = [
        'workflow_template_id' => 'required|is_natural_no_zero',
        'step_title'           => 'required|min_length[2]|max_length[200]',
        'step_no'              => 'permit_empty|is_natural',
        'sort_order'           => 'permit_empty|is_natural',
        'approval_stage'       => 'permit_empty|in_list[0,1]',
        'sla_days'             => 'permit_empty|is_natural',
        'depends_on_step_no'   => 'permit_empty|is_natural',
    ];
}
