<?php

namespace App\Models;

use CodeIgniter\Model;

class ServiceDocTemplateModel extends Model
{
    protected $table      = 'service_doc_templates';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'service_id',
        'template_name',
        'version',
        'is_active',
    ];

    protected $useTimestamps = true; // uses created_at, updated_at

    protected $validationRules = [
        'service_id'    => 'required|is_natural_no_zero',
        'template_name' => 'required|min_length[3]|max_length[200]',
        'version'       => 'required|is_natural_no_zero',
        'is_active'     => 'permit_empty|in_list[0,1]',
    ];

    protected $validationMessages = [
        'service_id' => [
            'required' => 'Service is required',
        ],
        'template_name' => [
            'required' => 'Template name is required',
        ],
    ];
}
