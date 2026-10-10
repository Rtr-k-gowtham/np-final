<?php

namespace App\Models;

use CodeIgniter\Model;

class ClientModel extends Model
{
    protected $table            = 'clients';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    // Allowed fields strictly using 'reference_source' with an underscore
    protected $allowedFields = [
        'name', 'email', 'phone', 'address', 'city', 'state', 'country', 'reference_source'
    ];

    protected $useTimestamps = true; // uses created_at, updated_at
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // Validation (used on insert/update)
    protected $validationRules = [
        'name'             => 'required|min_length[2]|max_length[200]',
        'email'            => 'permit_empty|valid_email|max_length[150]',
        'phone'            => 'permit_empty|max_length[30]',
        'address'          => 'permit_empty',
        'city'             => 'permit_empty|max_length[80]',
        'state'            => 'permit_empty|max_length[80]',
        'country'          => 'permit_empty|max_length[80]',
        'reference_source' => 'permit_empty|max_length[255]',
    ];

    protected $validationMessages = [
        'name' => [
            'required' => 'Client name is required.'
        ],
        'email' => [
            'valid_email' => 'Email format is invalid.'
        ]
    ];
}
