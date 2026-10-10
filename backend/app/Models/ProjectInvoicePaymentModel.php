<?php namespace App\Models;

use CodeIgniter\Model;

class ProjectInvoicePaymentModel extends Model
{
    protected $table      = 'project_invoice_payments';
    protected $primaryKey = 'id';

    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'invoice_id',
        'payment_date',
        'amount',
        'method',
        'reference_no',
        'remarks',
        'created_by_user_id',
        'created_at',
    ];
}
