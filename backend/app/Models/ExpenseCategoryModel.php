<?php

namespace App\Models;

use CodeIgniter\Model;

class ExpenseCategoryModel extends Model
{
    protected $table            = 'expense_categories';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = true;           // if your table has created_at/updated_at
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields    = ['category_name'];

    // Basic validation
    protected $validationRules = [
        'category_name' => 'required|min_length[2]|max_length[120]',
    ];

    protected $validationMessages = [
        'category_name' => [
            'required'   => 'Category name is required',
            'min_length' => 'Category name is too short',
            'max_length' => 'Category name is too long',
        ],
    ];
}
