<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'type', 'amount', 'balance_after',
        'description', 'reference_type', 'reference_id',
    ];

    const TYPE_DEDUCT = 'deduct';
    const TYPE_TOPUP = 'topup';
    const TYPE_BONUS = 'bonus';
    const TYPE_REFUND = 'refund';
    const TYPE_REFERRAL = 'referral';
    const TYPE_REFERRAL_COMMISSION = 'referral_commission';
    const TYPE_SUBSCRIPTION = 'subscription';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
