<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SrtGenerateJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'original_filename', 'language', 'srt_content',
        'duration_seconds', 'status', 'stage', 'error',
        'characters_used', 'credits_deducted',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'characters_used' => 'integer',
        'credits_deducted' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
