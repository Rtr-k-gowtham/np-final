<?php namespace App\Models;

use CodeIgniter\Model;

class ProjectInvoiceModel extends Model
{
    protected $table      = 'project_invoices';
    protected $primaryKey = 'id';

    protected $returnType = 'array';
    protected $useTimestamps = false; // you already store created_at/updated_at manually

    protected $allowedFields = [
        'company_id',
        'project_id',
        'invoice_no',
        'invoice_date',
        'due_date',
        'bill_to_name',
        'bill_to_address',
        'bill_to_gst',
        'currency',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'status',
        'notes',
        'terms',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];
}
