<?php

namespace App\Models;

use CodeIgniter\Model;

class QuotationModel extends Model
{
    protected $table            = 'quotations';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'quotation_no',
        'quotation_date',
        'client_id',
        'lead_id',
        'subject',
        'notes',
        'subtotal',
        'tax_amount',
        'total_amount',
        'status',
        'sections_config', // 👈 ADDED THIS COLUMN TO ALLOW SAVING
        'valid_till',
        'created_by',
        'created_at',
        'updated_at'
    ];
}
