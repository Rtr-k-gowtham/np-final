<?php

namespace App\Models;

use CodeIgniter\Model;

class ServiceDocTemplateItemModel extends Model
{
    protected $table      = 'service_doc_template_items';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'doc_template_id',
        'item_no',
        'document_title',
        'description',
        'copies_required',
        'is_mandatory',
		'provided_by', 
        'allowed_file_types',
        'sort_order',
    ];

    // Your items table doesn't have created_at/updated_at
    protected $useTimestamps = false;

    protected $validationRules = [
        'doc_template_id' => 'required|is_natural_no_zero',
        'document_title'  => 'required|min_length[2]|max_length[255]',
        'copies_required' => 'permit_empty|is_natural',
        'is_mandatory'    => 'permit_empty|in_list[0,1]',
        'sort_order'      => 'permit_empty|is_natural',
        'item_no'         => 'permit_empty|is_natural',
    ];
}
