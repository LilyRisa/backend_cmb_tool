<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureUsage extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'feature_name', 'usage_count', 'last_used_at'];

    protected $casts = [
        'usage_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
