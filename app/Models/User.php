<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable {
        HasApiTokens::createToken as sanctumCreateToken;
    }

    protected $fillable = [
        'name', 'email', 'password',
        'token_version', 'avatar', 'is_admin',
        'credits', 'monthly_credits', 'purchased_credits', 'credits_reset_at',
        'package_type', 'package_expires_at',
        'referral_code', 'referred_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_admin' => 'boolean',
        'credits' => 'integer',
        'monthly_credits' => 'integer',
        'purchased_credits' => 'integer',
        'credits_reset_at' => 'datetime',
        'package_expires_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->referral_code)) {
                $user->referral_code = self::generateUniqueReferralCode();
            }
        });
    }

    public static function generateUniqueReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    public function creditTransactions()
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function loginLogs()
    {
        return $this->hasMany(LoginLog::class);
    }

    /**
     * Create a new personal access token, stamping it with the user's current
     * token_version so CheckTokenVersion can later detect that it was minted
     * under a version that has since been superseded (e.g. by a password
     * reset). Sanctum's own createToken() has no concept of token_version, so
     * we let it do its normal work and then patch the version onto the row.
     */
    public function createToken(string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null)
    {
        $newAccessToken = $this->sanctumCreateToken($name, $abilities, $expiresAt);

        $newAccessToken->accessToken->forceFill([
            'token_version' => $this->token_version,
        ])->save();

        return $newAccessToken;
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    public function getTotalCreditsAttribute(): int
    {
        return ($this->monthly_credits ?? 0) + ($this->purchased_credits ?? 0);
    }

    public function getReferralLinkAttribute(): string
    {
        return url('/register?ref=' . $this->referral_code);
    }

    public function isPremium(): bool
    {
        if ($this->package_type === 'free') return false;
        if (!$this->package_expires_at) return true;

        return Carbon::parse($this->package_expires_at)->isFuture();
    }

    public function deductCredits(int $amount, string $description = '', ?string $refType = null, ?int $refId = null): bool
    {
        return DB::transaction(function () use ($amount, $description, $refType, $refId) {
            $user = User::where('id', $this->id)->lockForUpdate()->first();

            $totalAvailable = $user->monthly_credits + $user->purchased_credits;
            if ($totalAvailable < $amount) {
                return false;
            }

            $fromMonthly = min($user->monthly_credits, $amount);
            $fromPurchased = $amount - $fromMonthly;

            if ($fromMonthly > 0) $user->decrement('monthly_credits', $fromMonthly);
            if ($fromPurchased > 0) $user->decrement('purchased_credits', $fromPurchased);
            $user->decrement('credits', $amount);

            $this->monthly_credits = $user->monthly_credits;
            $this->purchased_credits = $user->purchased_credits;
            $this->credits = $user->credits;

            $balanceAfter = $user->monthly_credits + $user->purchased_credits;

            CreditTransaction::create([
                'user_id' => $this->id,
                'type' => CreditTransaction::TYPE_DEDUCT,
                'amount' => -$amount,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'reference_type' => $refType,
                'reference_id' => $refId,
            ]);

            return true;
        });
    }

    public function addCredits(int $amount, string $type = 'topup', string $description = '', ?string $refType = null, ?int $refId = null, string $creditType = 'purchased'): void
    {
        DB::transaction(function () use ($amount, $type, $description, $refType, $refId, $creditType) {
            $column = $creditType === 'monthly' ? 'monthly_credits' : 'purchased_credits';
            $this->increment($column, $amount);
            $this->increment('credits', $amount);

            $fresh = $this->fresh();
            $balanceAfter = $fresh->monthly_credits + $fresh->purchased_credits;

            CreditTransaction::create([
                'user_id' => $this->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'reference_type' => $refType,
                'reference_id' => $refId,
            ]);
        });
    }
}
