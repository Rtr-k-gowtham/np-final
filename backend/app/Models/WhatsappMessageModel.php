<?php

namespace App\Models;

use CodeIgniter\Model;

class WhatsappMessageModel extends Model
{
    protected $table            = 'whatsapp_messages';
    protected $primaryKey       = 'id';
    protected $allowedFields    = [
        'message_id',
        'sender_phone',
        'sender_name',
        'message_type',
        'message_content',
        'raw_payload',
        'created_at'
    ];
    
    // Set to false as we are storing our own custom datetime from Meta webhook,
    // but you can change this to true if you want automated CI4 timestamps.
    protected $useTimestamps    = false;
}
