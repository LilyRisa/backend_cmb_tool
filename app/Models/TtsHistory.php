<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TtsHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'genmax_task_id', 'provider', 'voice_id', 'model_id',
        'text', 'language_code', 'voice_settings', 'status', 'progress',
        'characters_used', 'credits_deducted_provider', 'credits_deducted_user',
        'audio_url', 'error', 'is_credit_deducted',
    ];

    protected $casts = [
        'voice_settings' => 'array',
        'is_credit_deducted' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creditTransactions()
    {
        return $this->morphMany(CreditTransaction::class, 'reference');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
