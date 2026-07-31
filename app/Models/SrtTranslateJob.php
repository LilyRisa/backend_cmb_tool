<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SrtTranslateJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'target_language', 'source_language',
        'srt_original', 'srt_translated', 'status', 'stage', 'error',
        'characters_used', 'credits_deducted',
    ];

    protected $casts = [
        'characters_used' => 'integer',
        'credits_deducted' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
