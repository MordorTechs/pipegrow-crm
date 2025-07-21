<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappSession extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'whatsapp_sessions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'phone_number',
        'conversation_history',
        'current_stage',
        'qualification_data',
        'lead_id',
    ];

    /**
     * The attributes that should be cast to native types.
     * This is crucial for handling JSON columns.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'conversation_history' => 'array',
        'qualification_data'   => 'array',
    ];

    /**
     * Get the lead associated with the WhatsApp session.
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
