<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BugReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'description', 'screenshots', 'screenshot_count',
        'app_version', 'device_info', 'ip_address', 'user_agent', 'status',
    ];

    protected $casts = [
        'screenshots' => 'array',
        'screenshot_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
