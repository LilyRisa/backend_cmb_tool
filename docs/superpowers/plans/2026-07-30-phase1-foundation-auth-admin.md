# Phase 1: Project Foundation, Auth & Admin Shell — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a fresh Laravel 10 project at `D:\cmbcoremkt_backend` with its own database, and ship a fully working authentication system (register/login/logout/me/password-reset/email-verification/profile update), a desktop-app OAuth handoff endpoint, and a minimal admin login shell — all ported from `G:\esp\ESP32_FULL\laravel` with every ESP32/device/audio reference removed.

**Architecture:** Standard Laravel 10 MVC. Sanctum personal-access-tokens for the API (mobile/desktop clients), session-based `web` guard for the Admin Panel (AdminLTE). `App\Models\User` is the single source of identity for both. Credit/subscription fields live on `users` from day one (read by later phases) but the business logic for spending them (Phase 2+) is out of scope here — this phase only needs `CreditService::creditsToMinutes()` for the `/me` and `/register` responses.

**Tech Stack:** PHP 8.1, Laravel 10, `laravel/sanctum`, `jeroennoten/laravel-adminlte`, PHPUnit 10 (matches the source project's stack), SQLite for tests, MySQL for dev/prod (`.env`).

## Global Constraints

- New project lives at `D:\cmbcoremkt_backend`, fully independent DB/`.env`/`APP_KEY` from the ESP32 project — no shared connections, no cross-project imports.
- Do not port anything related to `Device`, `Dsp`, `Audio`, `Playlist`, `StreamSession`, MQTT, or WebSockets — this project never depends on those.
- Every ported file must be adapted: remove references to models/relations that don't exist in this project (e.g. `User::devices()`, `storage_limit`).
- Follow existing source-project conventions where they don't conflict with the above (naming, response shapes, validation messages in Vietnamese where the source used Vietnamese).
- All work committed to git in small, working increments — one commit per task minimum.

---

### Task 1: Scaffold the Laravel 10 project

**Files:**
- Create: entire fresh Laravel 10 skeleton under `D:\cmbcoremkt_backend` (composer.json, artisan, app/, bootstrap/, config/, routes/, etc.) — merged alongside the existing `docs/` folder already committed there.
- Modify: `D:\cmbcoremkt_backend\.env`, `D:\cmbcoremkt_backend\phpunit.xml`, `D:\cmbcoremkt_backend\.gitignore`

**Interfaces:**
- Produces: a runnable `php artisan serve` app, `composer.json` with `laravel/framework:^10.0`, `laravel/sanctum`, `jeroennoten/laravel-adminlte` required. Every later task assumes this skeleton exists.

- [ ] **Step 1: Scaffold into a temp sibling folder (target dir already has `docs/` + `.git`, which trips Composer's "directory not empty" check)**

```bash
cd "D:/"
composer create-project laravel/laravel:^10.0 cmbcoremkt_backend_scaffold --prefer-dist --no-interaction
```

- [ ] **Step 2: Merge the scaffold into the real project directory, keep the existing `docs/` and `.git/`**

```bash
cd "D:/cmbcoremkt_backend_scaffold"
# Move everything except .git (scaffold's own, which we discard) into the real project
rsync -a --exclude='.git' ./ "D:/cmbcoremkt_backend/"
cd "D:/"
rm -rf "D:/cmbcoremkt_backend_scaffold"
```

(If `rsync` is unavailable on this Windows box, use `robocopy "D:\cmbcoremkt_backend_scaffold" "D:\cmbcoremkt_backend" /E /XD .git` instead, then remove the scaffold folder.)

- [ ] **Step 3: Require the extra packages**

```bash
cd "D:/cmbcoremkt_backend"
composer require laravel/sanctum jeroennoten/laravel-adminlte
```

- [ ] **Step 4: Publish Sanctum and AdminLTE assets**

```bash
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan adminlte:install
```

- [ ] **Step 5: Configure `.env` (dev DB — adjust credentials to your local MySQL)**

Edit `D:\cmbcoremkt_backend\.env`:

```env
APP_NAME="CMB Core Marketing"
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cmbcoremkt
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS="hello@cmbcoremkt.local"
MAIL_FROM_NAME="${APP_NAME}"
```

Run `php artisan key:generate` if `APP_KEY` is empty.

- [ ] **Step 6: Force tests onto in-memory SQLite (fast, isolated from the dev DB)**

Replace the `<php>` block in `D:\cmbcoremkt_backend\phpunit.xml` with:

```xml
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
    </php>
```

- [ ] **Step 7: Verify the skeleton boots and the test runner works**

Run: `php artisan about` — expect no fatal errors, shows `Laravel 10.x`.
Run: `php artisan test` — expect the default `ExampleTest` in `tests/Feature` and `tests/Unit` to PASS (2 passed).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Scaffold Laravel 10 project with Sanctum and AdminLTE"
```

---

### Task 2: `users` table + `User` model

**Files:**
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php` (leave as generated by scaffold — do not edit)
- Create: `database/migrations/2026_07_30_000001_add_marketing_fields_to_users_table.php`
- Modify: `app/Models/User.php`
- Create: `database/factories/UserFactory.php` (overwrite scaffold's default with credit/package fields)
- Test: `tests/Unit/UserModelTest.php`

**Interfaces:**
- Produces: `User` with fillable `name, email, password, token_version, avatar, is_admin, credits, monthly_credits, purchased_credits, credits_reset_at, package_type, package_expires_at, referral_code, referred_by`; methods `isPremium(): bool`, `deductCredits(int $amount, string $description = '', ?string $refType = null, ?int $refId = null): bool`, `addCredits(int $amount, string $type = 'topup', string $description = '', ?string $refType = null, ?int $refId = null, string $creditType = 'purchased'): void`, `generateUniqueReferralCode(): string` (static), relations `creditTransactions()`, `loginLogs()`, `referrer()`, `referrals()`.
- Consumed by every later task in this plan and by every subsequent phase.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/UserModelTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_gets_a_unique_referral_code_on_creation(): void
    {
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $this->assertNotEmpty($user->referral_code);
        $this->assertEquals(8, strlen($user->referral_code));
    }

    public function test_is_premium_returns_false_for_free_package(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $this->assertFalse($user->isPremium());
    }

    public function test_is_premium_returns_true_for_unexpired_premium_package(): void
    {
        $user = User::factory()->create([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(10),
        ]);

        $this->assertTrue($user->isPremium());
    }

    public function test_deduct_credits_fails_when_insufficient(): void
    {
        $user = User::factory()->create([
            'monthly_credits' => 5,
            'purchased_credits' => 0,
            'credits' => 5,
        ]);

        $result = $user->deductCredits(10, 'test deduction');

        $this->assertFalse($result);
        $this->assertEquals(5, $user->fresh()->monthly_credits);
    }

    public function test_deduct_credits_succeeds_and_records_transaction(): void
    {
        $user = User::factory()->create([
            'monthly_credits' => 20,
            'purchased_credits' => 5,
            'credits' => 25,
        ]);

        $result = $user->deductCredits(22, 'test deduction');

        $this->assertTrue($result);
        $fresh = $user->fresh();
        $this->assertEquals(0, $fresh->monthly_credits);
        $this->assertEquals(3, $fresh->purchased_credits);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'amount' => -22,
            'balance_after' => 3,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserModelTest`
Expected: FAIL — `Class "App\Models\CreditTransaction" not found` and/or missing columns (migration not written yet).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_30_000001_add_marketing_fields_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('token_version')->default(1);
            $table->string('avatar')->nullable();
            $table->boolean('is_admin')->default(false);

            $table->integer('credits')->default(0);
            $table->integer('monthly_credits')->default(0);
            $table->integer('purchased_credits')->default(0);
            $table->timestamp('credits_reset_at')->nullable();

            $table->string('package_type')->default('free');
            $table->timestamp('package_expires_at')->nullable();

            $table->string('referral_code', 8)->unique()->nullable();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by');
            $table->dropColumn([
                'token_version', 'avatar', 'is_admin',
                'credits', 'monthly_credits', 'purchased_credits', 'credits_reset_at',
                'package_type', 'package_expires_at', 'referral_code',
            ]);
        });
    }
};
```

- [ ] **Step 4: Write the `User` model**

Replace `app/Models/User.php`:

```php
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
    use HasApiTokens, HasFactory, Notifiable;

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
```

- [ ] **Step 5: Write the factory**

Replace `database/factories/UserFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'token_version' => 1,
            'package_type' => 'free',
            'credits' => 0,
            'monthly_credits' => 0,
            'purchased_credits' => 0,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }
}
```

(This depends on `CreditTransaction` — written in Task 3. The test will still fail until Task 3 lands; that's expected and covered below.)

- [ ] **Step 6: Run migrations and re-run the test**

Run: `php artisan migrate:fresh`
Run: `php artisan test --filter=UserModelTest`
Expected: FAIL — `Class "App\Models\CreditTransaction" not found` (Task 3 not done yet). This confirms Task 2's own code (migration + model shape) is otherwise correct; proceed to Task 3 before expecting green.

- [ ] **Step 7: Commit**

```bash
git add app/Models/User.php database/migrations database/factories/UserFactory.php tests/Unit/UserModelTest.php
git commit -m "Add marketing fields to users table and port User model"
```

---

### Task 3: `SystemSetting`, `CreditTransaction`, `LoginLog` models + `CreditService`

**Files:**
- Create: `database/migrations/2026_07_30_000002_create_system_settings_table.php`
- Create: `database/migrations/2026_07_30_000003_create_credit_transactions_table.php`
- Create: `database/migrations/2026_07_30_000004_create_login_logs_table.php`
- Create: `app/Models/SystemSetting.php`
- Create: `app/Models/CreditTransaction.php`
- Create: `app/Models/LoginLog.php`
- Create: `app/Services/CreditService.php`
- Test: `tests/Unit/CreditServiceTest.php`
- Test: `tests/Unit/SystemSettingTest.php`

**Interfaces:**
- Produces: `SystemSetting::getValue(string $key, $default = null)`, `::setValue(string $key, $value, bool $encrypted = false, ?string $description = null)`, `::getCharsPerMinute(): int`, `::getPremiumMonthlyCredits(): int`; `CreditTransaction::TYPE_DEDUCT|TYPE_TOPUP|TYPE_BONUS|TYPE_REFUND|TYPE_REFERRAL|TYPE_REFERRAL_COMMISSION|TYPE_SUBSCRIPTION`; `LoginLog::record(int $userId, string $action, string $ip, ?string $userAgent, string $source): static`, `LoginLog::ACTION_LOGIN|ACTION_REGISTER`; `CreditService::creditsToMinutes(int $credits, ?int $charsPerMinute = null): float`, `::calculateCredits(string $text): int`.
- Consumes: `User` model from Task 2 (via `credit_transactions.user_id` FK and `login_logs.user_id` FK).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/SystemSettingTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_and_get_plain_value(): void
    {
        SystemSetting::setValue('chars_per_minute', 800);

        $this->assertEquals(800, SystemSetting::getValue('chars_per_minute'));
    }

    public function test_get_returns_default_when_missing(): void
    {
        $this->assertEquals(42, SystemSetting::getValue('nonexistent_key', 42));
    }

    public function test_encrypted_value_is_decrypted_on_read(): void
    {
        SystemSetting::setValue('secret_api_key', 'super-secret', true);

        $this->assertEquals('super-secret', SystemSetting::getValue('secret_api_key'));
        $this->assertDatabaseMissing('system_settings', ['value' => 'super-secret']);
    }

    public function test_get_premium_monthly_credits_defaults_to_5000(): void
    {
        $this->assertEquals(5000, SystemSetting::getValue('premium_monthly_credits', 5000));
    }
}
```

Create `tests/Unit/CreditServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_credits_rounds_up_by_ten_chars_per_credit(): void
    {
        $this->assertEquals(1, CreditService::calculateCredits('short'));
        $this->assertEquals(2, CreditService::calculateCredits('exactly ten')); // 11 chars -> ceil(11/10)=2
        $this->assertEquals(0, CreditService::calculateCredits(''));
    }

    public function test_credits_to_minutes_uses_default_chars_per_minute(): void
    {
        // 100 credits * 10 chars/credit = 1000 chars / 800 chars-per-min = 1.25
        $this->assertEquals(1.25, CreditService::creditsToMinutes(100, 800));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SystemSettingTest`
Run: `php artisan test --filter=CreditServiceTest`
Expected: FAIL — classes don't exist yet.

- [ ] **Step 3: Write the migrations**

Create `database/migrations/2026_07_30_000002_create_system_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
```

Create `database/migrations/2026_07_30_000003_create_credit_transactions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->integer('amount');
            $table->integer('balance_after');
            $table->string('description')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
```

Create `database/migrations/2026_07_30_000004_create_login_logs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address')->nullable();
            $table->string('action');
            $table->string('user_agent')->nullable();
            $table->string('source')->default('api');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('ip_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
    }
};
```

- [ ] **Step 4: Write the models**

Create `app/Models/SystemSetting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'is_encrypted', 'description'];

    protected $casts = ['is_encrypted' => 'boolean'];

    public static function getValue(string $key, $default = null)
    {
        return Cache::remember("system_setting.{$key}", 300, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();
            if (!$setting) return $default;

            return $setting->is_encrypted ? decrypt($setting->value) : $setting->value;
        });
    }

    public static function setValue(string $key, $value, bool $encrypted = false, ?string $description = null): static
    {
        Cache::forget("system_setting.{$key}");

        return static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $encrypted ? encrypt($value) : $value,
                'is_encrypted' => $encrypted,
                'description' => $description,
            ]
        );
    }

    public static function getCharsPerMinute(): int
    {
        return (int) static::getValue('chars_per_minute', 800);
    }

    public static function getPremiumMonthlyCredits(): int
    {
        return (int) static::getValue('premium_monthly_credits', 5000);
    }
}
```

Create `app/Models/CreditTransaction.php`:

```php
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
```

Create `app/Models/LoginLog.php`:

```php
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
```

- [ ] **Step 5: Write `CreditService`**

Create `app/Services/CreditService.php`:

```php
<?php

namespace App\Services;

use App\Models\SystemSetting;

class CreditService
{
    const CHARS_PER_CREDIT = 10;
    const SRT_TRANSLATE_CHARS_PER_CREDIT = 50;

    public static function calculateCredits(string $text): int
    {
        $charCount = mb_strlen($text);
        if ($charCount <= 0) return 0;

        return (int) ceil($charCount / self::CHARS_PER_CREDIT);
    }

    public static function calculateSrtTranslateCredits(int $characterCount): int
    {
        if ($characterCount <= 0) return 0;

        return (int) ceil($characterCount / self::SRT_TRANSLATE_CHARS_PER_CREDIT);
    }

    public static function characterCount(string $text): int
    {
        return mb_strlen($text);
    }

    public static function estimate(string $text): array
    {
        $characters = mb_strlen($text);

        return [
            'characters' => $characters,
            'credits' => $characters <= 0 ? 0 : (int) ceil($characters / self::CHARS_PER_CREDIT),
        ];
    }

    public static function creditsToMinutes(int $credits, ?int $charsPerMinute = null): float
    {
        $cpm = max($charsPerMinute ?? SystemSetting::getCharsPerMinute(), 1);

        return round(($credits * self::CHARS_PER_CREDIT) / $cpm, 2);
    }

    public static function charactersToMinutes(int $characters, ?int $charsPerMinute = null): float
    {
        $cpm = max($charsPerMinute ?? SystemSetting::getCharsPerMinute(), 1);

        return round($characters / $cpm, 2);
    }

    public static function charactersToCredits(int $charactersUsed): int
    {
        if ($charactersUsed <= 0) return 0;

        return (int) ceil($charactersUsed / self::CHARS_PER_CREDIT);
    }
}
```

(Feature-based pricing (`FEATURE_PRICING`, `calculateFeatureCredits`) is added in Phase 2 alongside `ToolFeatureCreditController` — not needed until then.)

- [ ] **Step 6: Run migrations and all tests from Tasks 2–3**

Run: `php artisan migrate:fresh`
Run: `php artisan test --filter=SystemSettingTest`
Run: `php artisan test --filter=CreditServiceTest`
Run: `php artisan test --filter=UserModelTest`
Expected: all PASS (5 + 2 + 5 = 12 tests green).

- [ ] **Step 7: Commit**

```bash
git add app/Models/SystemSetting.php app/Models/CreditTransaction.php app/Models/LoginLog.php app/Services/CreditService.php database/migrations tests/Unit
git commit -m "Add SystemSetting, CreditTransaction, LoginLog models and CreditService"
```

---

### Task 4: Sanctum wiring + `token.version` / `email.verified` middleware

**Files:**
- Create: `app/Http/Middleware/CheckTokenVersion.php`
- Create: `app/Http/Middleware/EnsureEmailIsVerified.php`
- Modify: `app/Http/Kernel.php`
- Test: `tests/Feature/MiddlewareTest.php`

**Interfaces:**
- Produces: route middleware aliases `token.version` and `email.verified`, usable by any route group from here on.
- Consumes: `$request->user()->currentAccessToken()`, `$user->token_version` (Task 2), Sanctum's `auth:sanctum` guard (Task 1).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MiddlewareTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ad-hoc routes registered directly on the already-booted router —
        // this is a plain Laravel app TestCase, not Orchestra Testbench,
        // so there is no defineRoutes() hook to override.
        Route::middleware(['auth:sanctum', 'token.version'])->get('/__test/token-version', function () {
            return response()->json(['ok' => true]);
        });

        Route::middleware(['auth:sanctum', 'email.verified'])->get('/__test/email-verified', function () {
            return response()->json(['ok' => true]);
        });
    }

    public function test_stale_token_version_is_rejected(): void
    {
        $user = User::factory()->create(['token_version' => 2]);
        $token = $user->createToken('test')->plainTextToken;

        // Simulate the token being issued when token_version was 1 (e.g. password reset happened after)
        $user->update(['token_version' => 3]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/__test/token-version');

        $response->assertStatus(401)->assertJson(['error' => 'Token expired']);
    }

    public function test_current_token_version_is_accepted(): void
    {
        $user = User::factory()->create(['token_version' => 1]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/__test/token-version');

        $response->assertOk()->assertJson(['ok' => true]);
    }

    public function test_unverified_email_is_blocked(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/__test/email-verified');

        $response->assertStatus(403)->assertJsonPath('code', 'email_not_verified');
    }

    public function test_verified_email_passes(): void
    {
        $user = User::factory()->create(); // factory sets email_verified_at = now()
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/__test/email-verified');

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MiddlewareTest`
Expected: FAIL — `Target class [token.version] does not exist.`

- [ ] **Step 3: Write the middleware**

Create `app/Http/Middleware/CheckTokenVersion.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTokenVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->currentAccessToken()->tokenable->token_version !== $user->token_version) {
            return response()->json(['error' => 'Token expired'], 401);
        }

        return $next($request);
    }
}
```

Create `app/Http/Middleware/EnsureEmailIsVerified.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasVerifiedEmail()) {
            return response()->json([
                'error' => 'Vui lòng xác minh email trước khi sử dụng dịch vụ này.',
                'code' => 'email_not_verified',
            ], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the aliases**

In `app/Http/Kernel.php`, add to the `$middlewareAliases` array (create the array if the scaffold uses a different name — Laravel 10 default property is `$middlewareAliases`):

```php
    protected $middlewareAliases = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'token.version' => \App\Http\Middleware\CheckTokenVersion::class,
        'email.verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
    ];
```

(Keep whatever aliases the scaffold already generated — just add the last two lines if the array already exists.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=MiddlewareTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/CheckTokenVersion.php app/Http/Middleware/EnsureEmailIsVerified.php app/Http/Kernel.php tests/Feature/MiddlewareTest.php
git commit -m "Add token-version and email-verified middleware"
```

---

### Task 5: Register + Login endpoints

**Files:**
- Create: `app/Http/Controllers/API/UserController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Auth/RegisterLoginTest.php`

**Interfaces:**
- Produces: `POST /api/user/register`, `POST /api/user/login` (and duplicated at `POST /api/auth/register`, `POST /api/auth/login` per the source project's routing convention), returning `{ token, token_version, user, email_verified, minutes_remaining }` on register and `{ token, token_version, email_verified }` on login.
- Consumes: `User` (Task 2), `SystemSetting::getPremiumMonthlyCredits()` / `getCharsPerMinute()` (Task 3), `CreditService::creditsToMinutes()` (Task 3), `LoginLog::record()` (Task 3), Sanctum `createToken()` (Task 1/4).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/RegisterLoginTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_with_premium_trial_and_returns_token(): void
    {
        $response = $this->postJson('/api/user/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'token', 'user', 'email_verified', 'minutes_remaining']);

        $this->assertDatabaseHas('users', [
            'email' => 'bob@example.com',
            'package_type' => 'premium',
        ]);

        $user = User::where('email', 'bob@example.com')->first();
        $this->assertEquals(5000, $user->monthly_credits);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'bonus',
            'amount' => 5000,
        ]);
        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'action' => 'register',
        ]);
    }

    public function test_register_grants_referral_bonus_to_referrer(): void
    {
        $referrer = User::factory()->create(['purchased_credits' => 0, 'credits' => 0]);

        $this->postJson('/api/user/register', [
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => 'secret123',
            'ref' => $referrer->referral_code,
        ])->assertOk();

        $this->assertEquals(800, $referrer->fresh()->purchased_credits);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $this->postJson('/api/user/register', [
            'name' => 'Dup',
            'email' => 'dup@example.com',
            'password' => 'secret123',
        ])->assertStatus(422);
    }

    public function test_login_returns_token_for_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/user/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'token_version', 'email_verified']);
        $this->assertDatabaseHas('login_logs', ['user_id' => $user->id, 'action' => 'login']);
    }

    public function test_login_rejects_invalid_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $this->postJson('/api/user/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RegisterLoginTest`
Expected: FAIL — route `/api/user/register` not defined (404).

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/API/UserController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\LoginLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function login(Request $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        LoginLog::record($user->id, LoginLog::ACTION_LOGIN, $request->ip(), $request->userAgent(), 'api');

        return response()->json([
            'token' => $token,
            'token_version' => $user->token_version,
            'email_verified' => $user->hasVerifiedEmail(),
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
        ]);

        $referrer = null;
        if ($request->filled('ref')) {
            $referrer = User::where('referral_code', $request->ref)->first();
        }

        $monthlyCredits = SystemSetting::getPremiumMonthlyCredits();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'token_version' => 1,
            'package_type' => 'premium',
            'package_expires_at' => now()->addMonth(),
            'monthly_credits' => $monthlyCredits,
            'purchased_credits' => 0,
            'credits' => $monthlyCredits,
            'credits_reset_at' => now(),
            'referred_by' => $referrer?->id,
        ]);

        CreditTransaction::create([
            'user_id' => $user->id,
            'type' => 'bonus',
            'amount' => $monthlyCredits,
            'balance_after' => $monthlyCredits,
            'description' => "Welcome bonus - 1 tháng Premium miễn phí ({$monthlyCredits} monthly credits)",
            'reference_type' => 'registration',
        ]);

        if ($referrer) {
            $referrer->addCredits(
                800,
                CreditTransaction::TYPE_REFERRAL,
                "Giới thiệu thành công: {$user->name} ({$user->email})",
                User::class,
                $user->id,
                'purchased'
            );
        }

        $token = $user->createToken('mobile')->plainTextToken;

        LoginLog::record($user->id, LoginLog::ACTION_REGISTER, $request->ip(), $request->userAgent(), 'api');

        $charsPerMinute = max(SystemSetting::getCharsPerMinute(), 1);

        return response()->json([
            'message' => 'Register success',
            'token' => $token,
            'user' => $user,
            'email_verified' => false,
            'minutes_remaining' => CreditService::creditsToMinutes($user->credits, $charsPerMinute),
        ]);
    }
}
```

(Turnstile captcha verification and email-sending are intentionally omitted here — added in Task 7. `$request->validate()` returning 422 on failure is Laravel's default behavior, matching the test's expectation.)

- [ ] **Step 4: Wire the routes**

In `routes/api.php`:

```php
<?php

use App\Http\Controllers\API\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/user/login', [UserController::class, 'login']);
Route::post('/user/register', [UserController::class, 'register']);

Route::prefix('auth')->group(function () {
    Route::post('/login', [UserController::class, 'login']);
    Route::post('/register', [UserController::class, 'register'])->middleware('throttle:3,60');
});
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=RegisterLoginTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/API/UserController.php routes/api.php tests/Feature/Auth/RegisterLoginTest.php
git commit -m "Add register and login endpoints"
```

---

### Task 6: `/me` + `/logout` endpoints

**Files:**
- Modify: `app/Http/Controllers/API/UserController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Auth/MeLogoutTest.php`

**Interfaces:**
- Produces: `GET /api/me` (authenticated) returning full user JSON with `package_current`, `package_expired`, `package_time_end`, `package_message`, `minutes_remaining`, `avatar_url`, `email_verified`; `POST /api/logout` revoking the current token.
- Consumes: `token.version` middleware (Task 4).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/MeLogoutTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_current_user_with_package_info(): void
    {
        $user = User::factory()->create([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(5),
            'monthly_credits' => 100,
            'purchased_credits' => 20,
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('package_current', 'premium')
            ->assertJsonPath('package_expired', false)
            ->assertJsonPath('monthly_credits', 100)
            ->assertJsonPath('purchased_credits', 20)
            ->assertJsonStructure(['avatar_url', 'minutes_remaining', 'email_verified']);
    }

    public function test_me_marks_expired_package_as_free(): void
    {
        $user = User::factory()->create([
            'package_type' => 'premium',
            'package_expires_at' => now()->subDay(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('package_current', 'free')
            ->assertJsonPath('package_expired', true)
            ->assertJsonPath('package_last', 'premium');
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MeLogoutTest`
Expected: FAIL — 404 on `/api/me`.

- [ ] **Step 3: Add `me` and `logout` to `UserController`**

Add these methods to `app/Http/Controllers/API/UserController.php` (add `use Illuminate\Support\Facades\Storage;` and `use Carbon\Carbon;` to the imports):

```php
    public function me(Request $request)
    {
        $user = $request->user();

        $avatarUrl = $user->avatar
            ? Storage::disk('public')->url($user->avatar)
            : url('/images/defaultavatar.png');

        $userData = $user->toArray();
        $userData['avatar_url'] = $avatarUrl;

        $packageType = $user->package_type ?? 'free';
        $packageExpiresAt = $user->package_expires_at ?? null;

        $isExpired = false;
        $expiresDate = null;
        if ($packageExpiresAt) {
            $expiresDate = Carbon::parse($packageExpiresAt);
            $isExpired = $expiresDate->isPast();
        }

        if ($isExpired) {
            $userData['package_current'] = 'free';
            $userData['package_last'] = $packageType;
            $userData['package_expired'] = true;
            $userData['package_time_end'] = $expiresDate->format('d/m/Y');
            $userData['package_message'] = 'Gói ' . ucfirst($packageType) . ' của bạn đã hết hạn. Vui lòng gia hạn để tiếp tục sử dụng đầy đủ tính năng.';
        } else {
            $userData['package_current'] = $packageType;
            $userData['package_last'] = $packageType;
            $userData['package_expired'] = false;
            $userData['package_time_end'] = $packageExpiresAt ? Carbon::parse($packageExpiresAt)->format('d/m/Y') : null;
            $userData['package_message'] = null;
        }

        $charsPerMinute = max(SystemSetting::getCharsPerMinute(), 1);
        $totalCredits = ($user->monthly_credits ?? 0) + ($user->purchased_credits ?? 0);
        $userData['minutes_remaining'] = CreditService::creditsToMinutes($totalCredits, $charsPerMinute);
        $userData['monthly_credits'] = $user->monthly_credits ?? 0;
        $userData['purchased_credits'] = $user->purchased_credits ?? 0;
        $userData['credits_reset_at'] = $user->credits_reset_at ? Carbon::parse($user->credits_reset_at)->toIso8601String() : null;
        $userData['email_verified'] = $user->hasVerifiedEmail();

        return response()->json($userData);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Đăng xuất thành công'], 200);
    }
```

- [ ] **Step 4: Wire the routes**

Add to `routes/api.php`:

```php
Route::middleware(['auth:sanctum', 'token.version'])->group(function () {
    Route::get('/me', [UserController::class, 'me']);
    Route::post('/logout', [UserController::class, 'logout']);
});
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=MeLogoutTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/API/UserController.php routes/api.php tests/Feature/Auth/MeLogoutTest.php
git commit -m "Add /me and /logout endpoints"
```

---

### Task 7: Email verification on register

**Files:**
- Create: `database/migrations/2026_07_30_000005_create_email_verification_tokens_table.php`
- Create: `app/Mail/EmailVerificationMail.php`
- Create: `resources/views/emails/email-verification.blade.php`
- Create: `resources/views/auth/email-verified.blade.php`
- Modify: `app/Http/Controllers/API/UserController.php`
- Modify: `routes/api.php`, `routes/web.php`
- Test: `tests/Feature/Auth/EmailVerificationTest.php`

**Interfaces:**
- Produces: `GET /email/verify/{token}` (web), `POST /api/auth/resend-verification` (authenticated), and register now sends a verification email.
- Consumes: `email.verified` middleware (Task 4, applied to other endpoints from Phase 2+, not here).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/EmailVerificationTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_sends_verification_email(): void
    {
        Mail::fake();

        $this->postJson('/api/user/register', [
            'name' => 'Dave',
            'email' => 'dave@example.com',
            'password' => 'secret123',
        ])->assertOk();

        Mail::assertSent(\App\Mail\EmailVerificationMail::class);
    }

    public function test_verify_email_with_valid_token_marks_user_verified(): void
    {
        $user = User::factory()->unverified()->create();
        $plainToken = Str::random(64);

        DB::table('email_verification_tokens')->insert([
            'user_id' => $user->id,
            'token' => Hash::make($plainToken),
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get("/email/verify/{$plainToken}");

        $response->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verify_email_with_invalid_token_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get('/email/verify/not-a-real-token')->assertOk();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_verification_is_rate_limited_within_two_minutes(): void
    {
        Mail::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/resend-verification')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/resend-verification')
            ->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EmailVerificationTest`
Expected: FAIL — table `email_verification_tokens` doesn't exist / route 404.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_30_000005_create_email_verification_tokens_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 255);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');
    }
};
```

- [ ] **Step 4: Write the Mailable and views**

Create `app/Mail/EmailVerificationMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $verificationUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Xác minh email - CMB Core Marketing');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.email-verification');
    }
}
```

Create `resources/views/emails/email-verification.blade.php`:

```blade
<!DOCTYPE html>
<html>
<body>
    <p>Xin chào {{ $user->name }},</p>
    <p>Vui lòng nhấn vào liên kết dưới đây để xác minh email của bạn (hết hạn sau 24 giờ):</p>
    <p><a href="{{ $verificationUrl }}">{{ $verificationUrl }}</a></p>
</body>
</html>
```

Create `resources/views/auth/email-verified.blade.php`:

```blade
<!DOCTYPE html>
<html>
<body>
    @if($success)
        <h1>Email đã được xác minh thành công!</h1>
    @else
        <h1>Liên kết xác minh không hợp lệ hoặc đã hết hạn.</h1>
    @endif
</body>
</html>
```

- [ ] **Step 5: Add verification logic to `UserController`**

Add these methods (add `use App\Mail\EmailVerificationMail;`, `use Illuminate\Support\Facades\DB;`, `use Illuminate\Support\Facades\Mail;`, `use Illuminate\Support\Facades\Log;`, `use Illuminate\Support\Str;` to the imports), and call `$this->sendVerificationEmail($user);` right before the `return response()->json([...` line in `register()`:

```php
    public function verifyEmail(string $token)
    {
        $records = DB::table('email_verification_tokens')->get();

        $matched = null;
        foreach ($records as $record) {
            if (Hash::check($token, $record->token)) {
                $matched = $record;
                break;
            }
        }

        if (!$matched || \Carbon\Carbon::parse($matched->expires_at)->isPast()) {
            if ($matched) DB::table('email_verification_tokens')->where('id', $matched->id)->delete();
            return view('auth.email-verified', ['success' => false]);
        }

        $user = User::find($matched->user_id);
        if (!$user) {
            DB::table('email_verification_tokens')->where('id', $matched->id)->delete();
            return view('auth.email-verified', ['success' => false]);
        }

        if (!$user->hasVerifiedEmail()) {
            $user->email_verified_at = now();
            $user->save();
        }

        DB::table('email_verification_tokens')->where('user_id', $matched->user_id)->delete();

        return view('auth.email-verified', ['success' => true]);
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email đã được xác minh.', 'email_verified' => true]);
        }

        $recentToken = DB::table('email_verification_tokens')
            ->where('user_id', $user->id)
            ->where('created_at', '>', now()->subMinutes(2))
            ->exists();

        if ($recentToken) {
            return response()->json(['error' => 'Vui lòng đợi 2 phút trước khi gửi lại.', 'code' => 'rate_limited'], 429);
        }

        $this->sendVerificationEmail($user);

        return response()->json(['message' => 'Email xác minh đã được gửi lại.']);
    }

    private function sendVerificationEmail(User $user): void
    {
        DB::table('email_verification_tokens')->where('user_id', $user->id)->delete();

        $token = Str::random(64);
        DB::table('email_verification_tokens')->insert([
            'user_id' => $user->id,
            'token' => Hash::make($token),
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $verificationUrl = url('/email/verify/' . $token);

        try {
            Mail::to($user->email)->send(new EmailVerificationMail($user, $verificationUrl));
        } catch (\Exception $e) {
            Log::error('Failed to send verification email: ' . $e->getMessage());
        }
    }
```

- [ ] **Step 6: Wire the routes**

Add to `routes/web.php`:

```php
use App\Http\Controllers\API\UserController;

Route::get('/email/verify/{token}', [UserController::class, 'verifyEmail']);
```

Add inside the existing `auth` prefix group in `routes/api.php`:

```php
    Route::middleware(['auth:sanctum', 'token.version'])->group(function () {
        Route::post('/resend-verification', [UserController::class, 'resendVerification'])->middleware('throttle:3,10');
    });
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=EmailVerificationTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Mail/EmailVerificationMail.php resources/views/emails resources/views/auth/email-verified.blade.php app/Http/Controllers/API/UserController.php database/migrations routes tests/Feature/Auth/EmailVerificationTest.php
git commit -m "Add email verification flow"
```

---

### Task 8: Forgot / reset password

**Files:**
- Create: `app/Mail/ForgotPasswordMail.php`
- Create: `resources/views/emails/forgot-password.blade.php`
- Create: `resources/views/auth/reset-password.blade.php`
- Modify: `app/Http/Controllers/API/UserController.php`
- Modify: `routes/api.php`, `routes/web.php`
- Test: `tests/Feature/Auth/PasswordResetTest.php`

**Interfaces:**
- Produces: `POST /api/auth/forgot-password`, `GET /password/reset/{token}` (web form), `POST /password/reset` (web submit). Uses Laravel's default `password_reset_tokens` table (already created by the Task 1 scaffold's stock users migration).
- Consumes: nothing new beyond `User` (Task 2).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/PasswordResetTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_email_for_existing_user(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        Mail::assertSent(\App\Mail\ForgotPasswordMail::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_does_not_reveal_unknown_email(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk();

        Mail::assertNothingSent();
    }

    public function test_reset_password_with_valid_token_updates_password_and_invalidates_tokens(): void
    {
        $user = User::factory()->create(['token_version' => 1]);
        $plainToken = Str::random(64);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($plainToken),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/password/reset', [
            'token' => $plainToken,
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('newpassword123', $fresh->password));
        $this->assertEquals(2, $fresh->token_version);
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $user = User::factory()->create();

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('the-real-token'),
            'created_at' => now(),
        ]);

        $this->postJson('/password/reset', [
            'token' => 'wrong-token',
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PasswordResetTest`
Expected: FAIL — 404 on `/api/auth/forgot-password`.

- [ ] **Step 3: Write the Mailable and views**

Create `app/Mail/ForgotPasswordMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ForgotPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $resetUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Đặt lại mật khẩu - CMB Core Marketing');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.forgot-password');
    }
}
```

Create `resources/views/emails/forgot-password.blade.php`:

```blade
<!DOCTYPE html>
<html>
<body>
    <p>Xin chào {{ $user->name }},</p>
    <p>Nhấn vào liên kết dưới đây để đặt lại mật khẩu (hết hạn sau 60 phút):</p>
    <p><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
</body>
</html>
```

Create `resources/views/auth/reset-password.blade.php`:

```blade
<!DOCTYPE html>
<html>
<body>
    @if($expired ?? false)
        <h1>Liên kết đặt lại mật khẩu đã hết hạn hoặc không hợp lệ.</h1>
    @else
        <form method="POST" action="/password/reset">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">
            <input type="password" name="password" placeholder="Mật khẩu mới" required>
            <input type="password" name="password_confirmation" placeholder="Xác nhận mật khẩu" required>
            <button type="submit">Đặt lại mật khẩu</button>
        </form>
    @endif
</body>
</html>
```

- [ ] **Step 4: Add password-reset logic to `UserController`**

Add these methods (add `use App\Mail\ForgotPasswordMail;` to imports):

```php
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Nếu email tồn tại, chúng tôi đã gửi hướng dẫn đặt lại mật khẩu.'], 200);
        }

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        $token = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $resetUrl = url('/password/reset/' . $token . '?email=' . urlencode($user->email));

        try {
            Mail::to($user->email)->send(new ForgotPasswordMail($user, $resetUrl));
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Kiểm tra email và làm theo hướng dẫn để đặt lại mật khẩu.'], 200);
    }

    public function showResetForm(Request $request, string $token)
    {
        $email = $request->query('email', '');

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (!$record || !Hash::check($token, $record->token) || \Carbon\Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            return view('auth.reset-password', ['expired' => true]);
        }

        return view('auth.reset-password', ['token' => $token, 'email' => $email, 'expired' => false]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            return response()->json(['error' => 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.'], 422);
        }

        if (\Carbon\Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            return response()->json(['error' => 'Liên kết đặt lại mật khẩu đã hết hạn. Vui lòng yêu cầu lại.'], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['error' => 'Không tìm thấy tài khoản.'], 404);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        $user->tokens()->delete();
        $user->increment('token_version');

        return response()->json(['message' => 'Mật khẩu đã được đặt lại thành công. Vui lòng đăng nhập lại.']);
    }
```

- [ ] **Step 5: Wire the routes**

Add inside the `auth` prefix group in `routes/api.php` (alongside `login`/`register`):

```php
    Route::post('/forgot-password', [UserController::class, 'forgotPassword'])->middleware('throttle:3,10');
```

Add to `routes/web.php`:

```php
Route::get('/password/reset/{token}', [UserController::class, 'showResetForm']);
Route::post('/password/reset', [UserController::class, 'resetPassword']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=PasswordResetTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Mail/ForgotPasswordMail.php resources/views/emails/forgot-password.blade.php resources/views/auth/reset-password.blade.php app/Http/Controllers/API/UserController.php routes tests/Feature/Auth/PasswordResetTest.php
git commit -m "Add forgot/reset password flow"
```

---

### Task 9: Profile update endpoints

**Files:**
- Modify: `app/Http/Controllers/API/UserController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Auth/ProfileUpdateTest.php`

**Interfaces:**
- Produces: `PUT /api/account/change-password`, `PUT /api/account/change-name`, `PUT|POST /api/account/profile` — all authenticated, all return JSON (fixes the source project's broken wiring where these routes pointed at a session-based, non-JSON controller — see design spec §3 note).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/ProfileUpdateTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_change_password_updates_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);

        $this->withHeaders($this->authHeader($user))
            ->putJson('/api/account/change-password', [
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])->assertOk();

        $this->assertTrue(Hash::check('new-password123', $user->fresh()->password));
    }

    public function test_change_name_updates_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->withHeaders($this->authHeader($user))
            ->putJson('/api/account/change-name', ['name' => 'New Name'])
            ->assertOk();

        $this->assertEquals('New Name', $user->fresh()->name);
    }

    public function test_update_profile_uploads_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/account/profile', [
                'name' => 'Updated Name',
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            ]);

        $response->assertOk()->assertJsonStructure(['message', 'user']);

        $fresh = $user->fresh();
        $this->assertEquals('Updated Name', $fresh->name);
        $this->assertNotNull($fresh->avatar);
        Storage::disk('public')->assertExists($fresh->avatar);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProfileUpdateTest`
Expected: FAIL — 404 on `/api/account/change-password`.

- [ ] **Step 3: Add the methods to `UserController`**

```php
    public function updatePassword(Request $request)
    {
        $request->validate(['password' => 'required|min:6|confirmed']);

        $user = $request->user();
        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Đổi mật khẩu thành công!']);
    }

    public function updateName(Request $request)
    {
        $request->validate(['name' => 'required|min:3']);

        $user = $request->user();
        $user->name = $request->name;
        $user->save();

        return response()->json(['message' => 'Cập nhật tên hiển thị thành công!', 'user' => $user->fresh()]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'nullable|string|max:255',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        $updateData = [];

        if ($request->filled('name')) {
            $updateData['name'] = $request->name;
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            $filename = $user->id . '_' . time() . '.' . $request->file('avatar')->getClientOriginalExtension();
            $updateData['avatar'] = $request->file('avatar')->storeAs('avatars', $filename, 'public');
        }

        if (!empty($updateData)) {
            $user->update($updateData);
            $user = $user->fresh();
        }

        $avatarUrl = $user->avatar ? Storage::disk('public')->url($user->avatar) : null;

        $userData = $user->toArray();
        $userData['avatar_url'] = $avatarUrl;

        return response()->json(['message' => 'Cập nhật thông tin thành công', 'user' => $userData]);
    }
```

- [ ] **Step 4: Wire the routes**

Add inside the `auth:sanctum` + `token.version` group already created in Task 6, in `routes/api.php`:

```php
    Route::put('/account/change-password', [UserController::class, 'updatePassword']);
    Route::put('/account/change-name', [UserController::class, 'updateName']);
    Route::match(['put', 'post'], '/account/profile', [UserController::class, 'updateProfile']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ProfileUpdateTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/API/UserController.php routes/api.php tests/Feature/Auth/ProfileUpdateTest.php
git commit -m "Add JSON profile/password/name update endpoints"
```

---

### Task 10: Desktop app OAuth handoff

**Files:**
- Create: `config/oauth_clients.php`
- Create: `database/migrations/2026_07_30_000006_create_oauth_codes_table.php`
- Create: `app/Http/Controllers/API/OAuthController.php`
- Create: `resources/views/oauth/error.blade.php`
- Modify: `routes/api.php`, `routes/web.php`
- Test: `tests/Feature/Auth/OAuthTest.php`

**Interfaces:**
- Produces: `POST /api/auth/oauth/authorize` (authenticated) returning a one-time `code`; `GET /oauth/callback?code=&client=` (web, unauthenticated) that redirects to `cmbcoremkt://callback?token=...&token_version=...`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/OAuthTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorize_issues_a_code_for_verified_user(): void
    {
        $user = User::factory()->create(); // verified by factory default
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/oauth/authorize', ['client_id' => 'cmb-core-mkt']);

        $response->assertOk()->assertJsonStructure(['code']);
        $this->assertDatabaseHas('oauth_codes', ['user_id' => $user->id, 'client_id' => 'cmb-core-mkt']);
    }

    public function test_authorize_rejects_unverified_email(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/oauth/authorize', ['client_id' => 'cmb-core-mkt'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'email_not_verified');
    }

    public function test_authorize_rejects_unknown_client(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/oauth/authorize', ['client_id' => 'not-a-real-client'])
            ->assertStatus(400);
    }

    public function test_callback_redirects_to_desktop_protocol_with_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $code = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/oauth/authorize', ['client_id' => 'cmb-core-mkt'])
            ->json('code');

        $response = $this->get("/oauth/callback?code={$code}&client=cmb-core-mkt&state=xyz");

        $response->assertRedirect();
        $this->assertStringStartsWith('cmbcoremkt://callback?token=', $response->headers->get('Location'));
        $this->assertStringContainsString('state=xyz', $response->headers->get('Location'));
    }

    public function test_callback_rejects_expired_or_missing_code(): void
    {
        $this->get('/oauth/callback?code=bogus&client=cmb-core-mkt')->assertStatus(400);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OAuthTest`
Expected: FAIL — 404 on `/api/auth/oauth/authorize`.

- [ ] **Step 3: Write the config and migration**

Create `config/oauth_clients.php`:

```php
<?php

return [
    'clients' => [
        'cmb-core-mkt' => [
            'name' => 'CMB Core Marketing',
            'protocol' => 'cmbcoremkt',
            'callback_path' => '/callback',
        ],
    ],
];
```

Create `database/migrations/2026_07_30_000006_create_oauth_codes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 128);
            $table->string('client_id', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_codes');
    }
};
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/API/OAuthController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public function authorize(Request $request)
    {
        $request->validate(['client_id' => 'required|string|max:64']);

        $clientId = $request->input('client_id');
        $user = $request->user();

        $client = config("oauth_clients.clients.{$clientId}");
        if (!$client) {
            return response()->json(['error' => 'Invalid client_id'], 400);
        }

        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'error' => 'email_not_verified',
                'message' => 'Vui lòng xác minh email trước khi đăng nhập vào ứng dụng.',
            ], 403);
        }

        DB::table('oauth_codes')->where('user_id', $user->id)->where('client_id', $clientId)->delete();

        $plainCode = Str::random(64);

        DB::table('oauth_codes')->insert([
            'user_id' => $user->id,
            'code' => Hash::make($plainCode),
            'client_id' => $clientId,
            'expires_at' => now()->addSeconds(60),
            'created_at' => now(),
        ]);

        return response()->json(['code' => $plainCode]);
    }

    public function callback(Request $request)
    {
        $code = $request->query('code');
        $clientId = $request->query('client');
        $state = $request->query('state');

        if (!$code || !$clientId) {
            return $this->errorPage('Thiếu thông tin xác thực. Vui lòng thử đăng nhập lại.');
        }

        $client = config("oauth_clients.clients.{$clientId}");
        if (!$client) {
            return $this->errorPage('Ứng dụng không hợp lệ.');
        }

        $records = DB::table('oauth_codes')
            ->where('client_id', $clientId)
            ->where('expires_at', '>', now())
            ->get();

        $matched = null;
        foreach ($records as $record) {
            if (Hash::check($code, $record->code)) {
                $matched = $record;
                break;
            }
        }

        if (!$matched) {
            DB::table('oauth_codes')->where('expires_at', '<=', now())->delete();
            return $this->errorPage('Mã xác thực không hợp lệ hoặc đã hết hạn. Vui lòng đăng nhập lại.');
        }

        DB::table('oauth_codes')->where('id', $matched->id)->delete();

        $user = User::find($matched->user_id);
        if (!$user || !$user->hasVerifiedEmail()) {
            return $this->errorPage('Không tìm thấy tài khoản hoặc email chưa xác minh.');
        }

        $token = $user->createToken('desktop-' . $clientId)->plainTextToken;

        LoginLog::record($user->id, LoginLog::ACTION_LOGIN, $request->ip(), $request->userAgent(), 'oauth-' . $clientId);

        $redirectUrl = $client['protocol'] . '://' . ltrim($client['callback_path'], '/')
            . '?token=' . urlencode($token)
            . '&token_version=' . $user->token_version;

        if ($state !== null && $state !== '') {
            $redirectUrl .= '&state=' . urlencode($state);
        }

        return redirect()->away($redirectUrl);
    }

    private function errorPage(string $message)
    {
        return response()->view('oauth.error', ['message' => $message], 400);
    }
}
```

Create `resources/views/oauth/error.blade.php`:

```blade
<!DOCTYPE html>
<html>
<body>
    <h1>Đăng nhập thất bại</h1>
    <p>{{ $message }}</p>
</body>
</html>
```

- [ ] **Step 5: Wire the routes**

Add inside the `auth` prefix group's `auth:sanctum` sub-group in `routes/api.php`:

```php
use App\Http\Controllers\API\OAuthController;

// ...inside Route::prefix('auth')->group(...) -> Route::middleware(['auth:sanctum','token.version'])->group(...)
    Route::post('/oauth/authorize', [OAuthController::class, 'authorize'])->middleware('throttle:5,1');
```

Add to `routes/web.php`:

```php
use App\Http\Controllers\API\OAuthController;

Route::get('/oauth/callback', [OAuthController::class, 'callback']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=OAuthTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
git add config/oauth_clients.php database/migrations app/Http/Controllers/API/OAuthController.php resources/views/oauth routes tests/Feature/Auth/OAuthTest.php
git commit -m "Add desktop app OAuth handoff endpoint"
```

---

### Task 11: Admin login shell

**Files:**
- Create: `app/Http/Middleware/IsAdmin.php`
- Create: `app/Http/Controllers/Admin/AdminController.php`
- Create: `resources/views/admin/login.blade.php`
- Create: `resources/views/admin/dashboard.blade.php`
- Modify: `app/Http/Kernel.php`, `routes/web.php`
- Test: `tests/Feature/Admin/AdminLoginTest.php`

**Interfaces:**
- Produces: `GET/POST admin.login`, `POST admin.logout`, `GET admin.dashboard` (name-routed), gated by `is_admin` boolean on `User` (Task 2) and the `web` session guard. `IsAdmin::class` registered as the `admin` middleware alias.
- Note: `dashboard()` here only reports `total_users` / `premium_users` / `new_users_today` — Phase 5 expands it with TTS/VideoDub/FeatureUsage stats once those models exist.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/AdminLoginTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_reach_dashboard(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertStatus(403);
    }

    public function test_admin_login_with_valid_credentials_redirects_to_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'password' => bcrypt('adminpass123')]);

        $response = $this->post(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'adminpass123',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_login_rejects_non_admin_user(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'password' => bcrypt('userpass123')]);

        $response = $this->post(route('admin.login.submit'), [
            'email' => $user->email,
            'password' => 'userpass123',
        ]);

        $response->assertRedirect();
        $this->assertGuest();
    }

    public function test_admin_can_view_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->count(3)->create(['is_admin' => false]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk()->assertViewHas('totalUsers', 4);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminLoginTest`
Expected: FAIL — route `admin.dashboard` not defined.

- [ ] **Step 3: Write the middleware**

Create `app/Http/Middleware/IsAdmin.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('admin.login')->with('error', 'Please login to access admin panel.');
        }

        if (!auth()->user()->is_admin) {
            abort(403, 'Unauthorized access. Admin privileges required.');
        }

        return $next($request);
    }
}
```

Register the alias in `app/Http/Kernel.php`'s `$middlewareAliases` array (added alongside `token.version`/`email.verified` from Task 4):

```php
        'admin' => \App\Http\Middleware\IsAdmin::class,
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/Admin/AdminController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AdminController extends Controller
{
    public function showLogin()
    {
        if (auth()->check() && auth()->user()->is_admin) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $user = Auth::user();

            if (!$user->is_admin) {
                Auth::logout();
                return back()->with('error', 'Access denied. Admin privileges required.')->withInput();
            }

            $request->session()->regenerate();

            return redirect()->intended(route('admin.dashboard'))->with('success', 'Welcome back, ' . $user->name . '!');
        }

        return back()->with('error', 'Invalid email or password.')->withInput();
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'Logged out successfully.');
    }

    public function dashboard()
    {
        $today = Carbon::today();

        $totalUsers = User::count();
        $premiumUsers = User::where('package_type', '!=', 'free')->count();
        $newUsersToday = User::whereDate('created_at', $today)->count();

        return view('admin.dashboard', compact('totalUsers', 'premiumUsers', 'newUsersToday'));
    }
}
```

- [ ] **Step 5: Write minimal views**

Create `resources/views/admin/login.blade.php`:

```blade
<!DOCTYPE html>
<html>
<body>
    <h1>Admin Login</h1>
    @if(session('error')) <p style="color:red">{{ session('error') }}</p> @endif
    <form method="POST" action="{{ route('admin.login.submit') }}">
        @csrf
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <label><input type="checkbox" name="remember"> Remember me</label>
        <button type="submit">Login</button>
    </form>
</body>
</html>
```

Create `resources/views/admin/dashboard.blade.php`:

```blade
<!DOCTYPE html>
<html>
<body>
    <h1>Admin Dashboard</h1>
    <ul>
        <li>Total users: {{ $totalUsers }}</li>
        <li>Premium users: {{ $premiumUsers }}</li>
        <li>New users today: {{ $newUsersToday }}</li>
    </ul>
    <form method="POST" action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit">Logout</button>
    </form>
</body>
</html>
```

(Phase 5 will replace these with full AdminLTE-themed layouts once the management controllers exist — this task only needs a working, testable login gate.)

- [ ] **Step 6: Wire the routes**

Add to `routes/web.php`:

```php
use App\Http\Controllers\Admin\AdminController;

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminController::class, 'login'])->name('login.submit');

    Route::middleware('admin')->group(function () {
        Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    });
});
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=AdminLoginTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Middleware/IsAdmin.php app/Http/Controllers/Admin/AdminController.php app/Http/Kernel.php resources/views/admin routes/web.php tests/Feature/Admin/AdminLoginTest.php
git commit -m "Add admin login shell with IsAdmin middleware"
```

---

### Task 12: Full-suite check and route sanity pass

**Files:**
- Modify: `routes/api.php` (add `use` import cleanup only — no new endpoints)
- Test: none new — this task runs everything written so far

**Interfaces:**
- Verifies: every test file created in Tasks 2–11 passes together (catches cross-test DB pollution, route-name collisions, and middleware alias typos that only surface once everything loads at once).

- [ ] **Step 1: Run the entire suite**

Run: `php artisan test`
Expected: PASS — every test from Tasks 1–11 green (roughly 40+ tests), zero failures, zero errors.

- [ ] **Step 2: Confirm the route list matches the plan**

Run: `php artisan route:list --path=api`
Expected output includes (order may vary): `POST api/user/login`, `POST api/user/register`, `GET api/me`, `POST api/logout`, `PUT api/account/change-password`, `PUT api/account/change-name`, `PUT|POST api/account/profile`, `POST api/auth/login`, `POST api/auth/register`, `POST api/auth/forgot-password`, `POST api/auth/resend-verification`, `POST api/auth/oauth/authorize`.

Run: `php artisan route:list --path=admin`
Expected output includes: `GET admin/login`, `POST admin/login`, `POST admin/logout`, `GET admin/dashboard`.

- [ ] **Step 3: If anything is red, fix it now — do not proceed to Phase 2 with a red suite**

Common culprits at this point: a missing `use` import in `routes/api.php`/`routes/web.php`, a middleware alias typo in `Kernel.php`, or a migration ordering issue (all `2026_07_30_0000XX_*` files must run in numeric order — verify with `php artisan migrate:status`).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "Phase 1 complete: foundation, auth, and admin shell all green"
```

---

## What's next

Phase 1 ships a working, independently testable Laravel API + admin login. Phase 2 (Credit & Subscription system — `Subscription`, `PendingSubscriptionPayment`, `PendingCreditTopup`, `SePayService`, `ToolCreditController`, `ToolSubscriptionController`, `CreditTopupController`, `ToolFeatureCreditController`, extending `CreditService` with `FEATURE_PRICING`) gets its own plan document once this one is executed and verified.
