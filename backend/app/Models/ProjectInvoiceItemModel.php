<?php namespace App\Models;

use CodeIgniter\Model;

class ProjectInvoiceItemModel extends Model
{
    protected $table      = 'project_invoice_items';
    protected $primaryKey = 'id';

    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'invoice_id',
        'line_no',
        'item_title',
        'description',
        'qty',
        'unit_price',
        'discount',
        'tax_rate',
        'tax_amount',
        'line_total',
    ];
}
