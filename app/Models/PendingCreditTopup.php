<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PendingCreditTopup extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'package_id', 'credits', 'amount',
        'transaction_code', 'status', 'completed_at',
    ];

    protected $casts = [
        'credits' => 'integer',
        'amount' => 'integer',
        'completed_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_EXPIRED = 'expired';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public static function findByTransactionCode(string $code): ?self
    {
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $code);

        return static::where('status', self::STATUS_PENDING)
            ->get()
            ->first(function ($topup) use ($normalized) {
                $storedNormalized = preg_replace('/[^A-Za-z0-9]/', '', $topup->transaction_code);
                return $storedNormalized === $normalized;
            });
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }
}
