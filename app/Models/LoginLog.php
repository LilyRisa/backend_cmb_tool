<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginLog extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'ip_address', 'action', 'user_agent', 'source'];

    const ACTION_LOGIN = 'login';
    const ACTION_REGISTER = 'register';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(int $userId, string $action, string $ip, ?string $userAgent = null, string $source = 'api'): static
    {
        return static::create([
            'user_id' => $userId,
            'ip_address' => $ip,
            'action' => $action,
            'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
            'source' => $source,
        ]);
    }
}
