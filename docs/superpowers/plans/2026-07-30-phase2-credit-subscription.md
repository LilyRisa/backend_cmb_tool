# Phase 2: Credit & Subscription System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the full credit-topup and Premium-subscription system on top of Phase 1's foundation: SePay bank-transfer payment integration, credit packages, Premium plans, feature-based credit pricing, and the webhook listeners that actually complete payments.

**Architecture:** Same Laravel 10 app from Phase 1. Users buy credits or a Premium subscription by generating a bank-transfer "pending" record with a unique transaction code; SePay (a Vietnamese payment gateway) sends a webhook when the transfer lands, which is matched by transaction code and completes the pending record — crediting the user or activating their subscription. A separate 2-phase "feature credit" flow (already scaffolded by `ToolFeatureCreditController`, ported here) lets the desktop client reserve credits before running an AI feature and confirm/release them after.

**Tech Stack:** Laravel 10 (from Phase 1), `sepayvn/laravel-sepay` package (Vietnamese bank-transfer webhook integration), Laravel's event/listener system.

## Global Constraints

- Builds directly on Phase 1's `D:\cmbcoremkt_backend` — same DB, same `User`/`CreditTransaction`/`SystemSetting` models, same `auth:sanctum` + `token.version` + `email.verified` middleware stack. Do not modify Phase 1's already-tested behavior except where a task explicitly says to extend an existing file (e.g. adding a relation to `User`, adding entries to `CreditService`).
- `SEPAY_WEBHOOK_TOKEN` MUST be set to a real secret in production `.env` — the `sepayvn/laravel-sepay` package's own webhook controller (`vendor/sepayvn/laravel-sepay/src/Http/Controllers/SePayController.php`) only enforces the bearer-token check `if (config('sepay.webhook_token') && ...)` — if the config value is empty, the webhook accepts unauthenticated requests from anyone. This is a package-level behavior, not something this plan's code can fix; document it as a required deploy-time config value (Task 3 adds it to `.env.example` with a placeholder, not a real secret).
- All work committed to git in small, working increments — one commit per task minimum.
- Continue committing directly to `master` (approved by the human partner for this project).
- Docker is this project's real dev/deploy environment; tests run against in-memory SQLite per Phase 1's `phpunit.xml` — no local MySQL needed.

---

### Task 1: Subscription, PendingSubscriptionPayment, PendingCreditTopup, FeatureCreditUsage models + migrations + `User::subscriptions()`/`activeSubscription()`

**Files:**
- Create: `database/migrations/2026_07_30_000008_create_subscriptions_table.php`
- Create: `database/migrations/2026_07_30_000009_create_pending_subscription_payments_table.php`
- Create: `database/migrations/2026_07_30_000010_create_pending_credit_topups_table.php`
- Create: `database/migrations/2026_07_30_000011_create_feature_credit_usages_table.php`
- Create: `app/Models/Subscription.php`
- Create: `app/Models/PendingSubscriptionPayment.php`
- Create: `app/Models/PendingCreditTopup.php`
- Create: `app/Models/FeatureCreditUsage.php`
- Modify: `app/Models/User.php` (add `subscriptions()` relation + `activeSubscription()` method)
- Test: `tests/Unit/SubscriptionModelTest.php`

**Interfaces:**
- Produces: `Subscription::PLAN_MONTHLY|PLAN_YEARLY`, `::STATUS_PENDING|STATUS_ACTIVE|STATUS_EXPIRED|STATUS_CANCELLED`, `->isActive(): bool`, `::scopeActive()`; `PendingSubscriptionPayment::STATUS_PENDING|STATUS_COMPLETED|STATUS_EXPIRED`, `::findByTransactionCode(string $code): ?self`, `->markCompleted(): void`; `PendingCreditTopup` — same shape as `PendingSubscriptionPayment`; `FeatureCreditUsage::STATUS_PENDING|STATUS_COMPLETED|STATUS_FAILED`; `User::subscriptions(): HasMany`, `User::activeSubscription(): ?Subscription`.
- Consumes: `User` model from Phase 1 (FK `user_id` on all four tables).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/SubscriptionModelTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\PendingCreditTopup;
use App\Models\PendingSubscriptionPayment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_is_active_when_status_active_and_not_expired(): void
    {
        $sub = Subscription::factory()->create([
            'status' => Subscription::STATUS_ACTIVE,
            'expires_at' => now()->addDays(10),
        ]);

        $this->assertTrue($sub->isActive());
    }

    public function test_subscription_is_not_active_when_expired(): void
    {
        $sub = Subscription::factory()->create([
            'status' => Subscription::STATUS_ACTIVE,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($sub->isActive());
    }

    public function test_user_active_subscription_returns_latest_active(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->create([
            'user_id' => $user->id,
            'status' => Subscription::STATUS_EXPIRED,
            'expires_at' => now()->subDay(),
        ]);
        $active = Subscription::factory()->create([
            'user_id' => $user->id,
            'status' => Subscription::STATUS_ACTIVE,
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertEquals($active->id, $user->activeSubscription()->id);
    }

    public function test_pending_subscription_payment_found_by_normalized_transaction_code(): void
    {
        PendingSubscriptionPayment::factory()->create([
            'transaction_code' => 'CMBSUB121234567890',
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
        ]);

        $found = PendingSubscriptionPayment::findByTransactionCode('CMB SUB1 2123-4567890');

        $this->assertNotNull($found);
    }

    public function test_pending_subscription_payment_mark_completed(): void
    {
        $payment = PendingSubscriptionPayment::factory()->create([
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
        ]);

        $payment->markCompleted();

        $this->assertEquals(PendingSubscriptionPayment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->completed_at);
    }

    public function test_pending_credit_topup_found_by_normalized_transaction_code(): void
    {
        PendingCreditTopup::factory()->create([
            'transaction_code' => 'CMB121234567890',
            'status' => PendingCreditTopup::STATUS_PENDING,
        ]);

        $found = PendingCreditTopup::findByTransactionCode('CMB 121-2345 67890');

        $this->assertNotNull($found);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SubscriptionModelTest`
Expected: FAIL — classes/factories don't exist yet.

- [ ] **Step 3: Write the migrations**

Create `database/migrations/2026_07_30_000008_create_subscriptions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('plan');
            $table->string('status')->default('pending');
            $table->integer('amount');
            $table->string('payment_method')->default('sepay');
            $table->string('transaction_id')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
```

Create `database/migrations/2026_07_30_000009_create_pending_subscription_payments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('duration_days');
            $table->unsignedInteger('monthly_credits');
            $table->string('transaction_code')->unique();
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'transaction_code']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_subscription_payments');
    }
};
```

Create `database/migrations/2026_07_30_000010_create_pending_credit_topups_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_credit_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('package_id', 32);
            $table->unsignedInteger('credits');
            $table->unsignedInteger('amount');
            $table->string('transaction_code', 64)->unique();
            $table->enum('status', ['pending', 'completed', 'expired'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'transaction_code']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_credit_topups');
    }
};
```

Create `database/migrations/2026_07_30_000011_create_feature_credit_usages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_credit_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('feature', 50)->index();
            $table->unsignedInteger('duration_seconds');
            $table->unsignedInteger('credits');
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_credit_usages');
    }
};
```

- [ ] **Step 4: Write the models**

Create `app/Models/Subscription.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'plan', 'status', 'amount',
        'payment_method', 'transaction_id', 'starts_at', 'expires_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    const PLAN_MONTHLY = 'monthly';
    const PLAN_YEARLY = 'yearly';

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }
}
```

Create `app/Models/PendingSubscriptionPayment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PendingSubscriptionPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'plan', 'amount', 'duration_days',
        'monthly_credits', 'transaction_code', 'status', 'completed_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'duration_days' => 'integer',
        'monthly_credits' => 'integer',
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
            ->first(function ($payment) use ($normalized) {
                $stored = preg_replace('/[^A-Za-z0-9]/', '', $payment->transaction_code);
                return $stored === $normalized;
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
```

Create `app/Models/PendingCreditTopup.php`:

```php
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
```

Create `app/Models/FeatureCreditUsage.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeatureCreditUsage extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'feature', 'duration_seconds', 'credits', 'status'];

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
```

- [ ] **Step 5: Add factories for the new models**

Create `database/factories/SubscriptionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan' => Subscription::PLAN_MONTHLY,
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 99000,
            'payment_method' => 'sepay',
            'transaction_id' => null,
            'starts_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }
}
```

Create `database/factories/PendingSubscriptionPaymentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\PendingSubscriptionPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PendingSubscriptionPaymentFactory extends Factory
{
    protected $model = PendingSubscriptionPayment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan' => 'monthly',
            'amount' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
            'transaction_code' => 'CMBSUB' . Str::random(10),
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
        ];
    }
}
```

Create `database/factories/PendingCreditTopupFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\PendingCreditTopup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PendingCreditTopupFactory extends Factory
{
    protected $model = PendingCreditTopup::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'package_id' => 'starter',
            'credits' => 5000,
            'amount' => 30000,
            'transaction_code' => 'CMB' . Str::random(10),
            'status' => PendingCreditTopup::STATUS_PENDING,
        ];
    }
}
```

- [ ] **Step 6: Add `subscriptions()` and `activeSubscription()` to `User`**

Add to `app/Models/User.php` (alongside the other relation methods like `creditTransactions()`):

```php
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->subscriptions()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest()
            ->first();
    }
```

Add `use App\Models\Subscription;` is not needed since `Subscription` is in the same `App\Models` namespace — reference it as `Subscription::class` directly.

- [ ] **Step 7: Run migrations and the test**

Run: `php artisan migrate:fresh`
Run: `php artisan test --filter=SubscriptionModelTest`
Expected: PASS (6 tests).

- [ ] **Step 8: Run the full suite to confirm no Phase 1 regressions**

Run: `php artisan test`
Expected: PASS (59 tests — Phase 1's 53 + this task's 6).

- [ ] **Step 9: Commit**

```bash
git add app/Models/Subscription.php app/Models/PendingSubscriptionPayment.php app/Models/PendingCreditTopup.php app/Models/FeatureCreditUsage.php app/Models/User.php database/migrations database/factories tests/Unit/SubscriptionModelTest.php
git commit -m "Add Subscription, PendingSubscriptionPayment, PendingCreditTopup, FeatureCreditUsage models"
```

---

### Task 2: `CreditService` feature-based pricing

**Files:**
- Modify: `app/Services/CreditService.php` (add `FEATURE_PRICING` const + `calculateFeatureCredits()` + `getFeaturePricing()`)
- Test: `tests/Unit/CreditServiceTest.php` (add cases to the existing file from Phase 1)

**Interfaces:**
- Produces: `CreditService::FEATURE_PRICING` (array), `CreditService::calculateFeatureCredits(string $feature, int $durationSeconds): ?array` (returns `['feature' => ..., 'duration_seconds' => ..., 'credits' => ...]` or `null` if unknown feature; throws `\InvalidArgumentException` if duration out of range), `CreditService::getFeaturePricing(): array`.
- Consumed by: `ToolFeatureCreditController` (Task 8).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/CreditServiceTest.php` (append these methods to the existing test class from Phase 1 — do not remove the existing `test_calculate_credits_rounds_up_by_ten_chars_per_credit` / `test_credits_to_minutes_uses_default_chars_per_minute` methods):

```php
    public function test_calculate_feature_credits_for_known_feature(): void
    {
        $result = CreditService::calculateFeatureCredits('create_video_script', 300);

        $this->assertEquals([
            'feature' => 'create_video_script',
            'duration_seconds' => 300,
            'credits' => 700,
        ], $result);
    }

    public function test_calculate_feature_credits_returns_null_for_unknown_feature(): void
    {
        $this->assertNull(CreditService::calculateFeatureCredits('not_a_real_feature', 60));
    }

    public function test_calculate_feature_credits_throws_when_duration_exceeds_max(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CreditService::calculateFeatureCredits('create_video_script', 99999);
    }

    public function test_get_feature_pricing_returns_the_full_table(): void
    {
        $pricing = CreditService::getFeaturePricing();

        $this->assertArrayHasKey('create_video_script', $pricing);
        $this->assertEquals(140, $pricing['create_video_script']['credits_per_minute']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CreditServiceTest`
Expected: FAIL — `CreditService::calculateFeatureCredits` does not exist.

- [ ] **Step 3: Add feature pricing to `CreditService`**

Add to `app/Services/CreditService.php` (append inside the class, after `charactersToCredits()`):

```php
    const FEATURE_PRICING = [
        'create_video_script' => [
            'credits_per_minute' => 140,
            'max_duration_seconds' => 1200,
        ],
    ];

    public static function calculateFeatureCredits(string $feature, int $durationSeconds): ?array
    {
        if (!isset(self::FEATURE_PRICING[$feature])) {
            return null;
        }

        $pricing = self::FEATURE_PRICING[$feature];
        $maxDuration = $pricing['max_duration_seconds'];

        if ($durationSeconds < 1 || $durationSeconds > $maxDuration) {
            throw new \InvalidArgumentException(
                "Duration must be between 1 and {$maxDuration} seconds for feature '{$feature}'."
            );
        }

        $minutes = ceil($durationSeconds / 60);
        $credits = (int) ($minutes * $pricing['credits_per_minute']);

        return [
            'feature' => $feature,
            'duration_seconds' => $durationSeconds,
            'credits' => $credits,
        ];
    }

    public static function getFeaturePricing(): array
    {
        return self::FEATURE_PRICING;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CreditServiceTest`
Expected: PASS (6 tests — 2 from Phase 1 + 4 new).

- [ ] **Step 5: Commit**

```bash
git add app/Services/CreditService.php tests/Unit/CreditServiceTest.php
git commit -m "Add feature-based credit pricing to CreditService"
```

---

### Task 3: SePay integration — package, config, `SePayService`

**Files:**
- Modify: `composer.json` (require `sepayvn/laravel-sepay`)
- Create: `config/sepay.php`
- Create: `config/credit_packages.php`
- Create: `app/Services/SePayService.php`
- Modify: `.env.example` (add SePay placeholder keys)
- Test: `tests/Unit/SePayServiceTest.php`

**Interfaces:**
- Produces: `SePayService::generateTransactionCode(int $userId, string $kind = ''): string`, `::bankInfo(int $amount, string $code): array`, `::qrUrl(int $amount, string $code): string`, `::hasBankConfig(): bool`.
- Consumed by: `ToolSubscriptionController`, `CreditTopupController` (Tasks 6-7).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/SePayServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\SePayService;
use Tests\TestCase;

class SePayServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sepay.pattern' => 'CMB',
            'sepay.account_number' => '0123456789',
            'sepay.account_name' => 'CONG TY TNHH CMB',
            'sepay.bank_name' => 'MBBank',
        ]);
    }

    public function test_generate_transaction_code_is_alphanumeric_and_contains_pattern(): void
    {
        $code = SePayService::generateTransactionCode(42, 'SUB');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $code);
        $this->assertStringStartsWith('CMBSUB42', $code);
    }

    public function test_generate_transaction_code_without_kind(): void
    {
        $code = SePayService::generateTransactionCode(7);

        $this->assertStringStartsWith('CMB7', $code);
    }

    public function test_bank_info_returns_configured_account_details(): void
    {
        $info = SePayService::bankInfo(50000, 'CMB123');

        $this->assertEquals('0123456789', $info['account_number']);
        $this->assertEquals('MBBank', $info['bank_name']);
        $this->assertEquals(50000, $info['amount']);
        $this->assertEquals('CMB123', $info['content']);
    }

    public function test_qr_url_contains_amount_and_code(): void
    {
        $url = SePayService::qrUrl(50000, 'CMB123');

        $this->assertStringStartsWith('https://qr.sepay.vn/img?', $url);
        $this->assertStringContainsString('amount=50000', $url);
        $this->assertStringContainsString('des=CMB123', $url);
    }

    public function test_has_bank_config_true_when_all_three_set(): void
    {
        $this->assertTrue(SePayService::hasBankConfig());
    }

    public function test_has_bank_config_false_when_missing(): void
    {
        config(['sepay.account_number' => null]);

        $this->assertFalse(SePayService::hasBankConfig());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SePayServiceTest`
Expected: FAIL — `App\Services\SePayService` does not exist.

- [ ] **Step 3: Require the SePay package**

```bash
composer require sepayvn/laravel-sepay:^1.2
```

- [ ] **Step 4: Write the config files**

Create `config/sepay.php`:

```php
<?php

return [
    'webhook_token' => env('SEPAY_WEBHOOK_TOKEN'),
    'pattern' => env('SEPAY_MATCH_PATTERN', 'CMB'),
    'account_number' => env('SEPAY_ACCOUNT_NUMBER'),
    'account_name' => env('SEPAY_ACCOUNT_NAME'),
    'bank_name' => env('SEPAY_BANK_NAME'),
];
```

Create `config/credit_packages.php`:

```php
<?php

return [
    ['id' => 'starter',  'name' => 'Starter',  'credits' => 5000,   'price' => 30000],
    ['id' => 'basic',    'name' => 'Basic',    'credits' => 20000,  'price' => 110000],
    ['id' => 'pro',      'name' => 'Pro',      'credits' => 50000,  'price' => 260000],
    ['id' => 'business', 'name' => 'Business', 'credits' => 100000, 'price' => 500000],
    ['id' => 'agency',   'name' => 'Agency',   'credits' => 500000, 'price' => 2250000],
];
```

- [ ] **Step 5: Add SePay placeholders to `.env.example`**

Append to `.env.example`:

```env
SEPAY_WEBHOOK_TOKEN=
SEPAY_MATCH_PATTERN=CMB
SEPAY_ACCOUNT_NUMBER=
SEPAY_ACCOUNT_NAME=
SEPAY_BANK_NAME=
```

- [ ] **Step 6: Write `SePayService`**

Create `app/Services/SePayService.php`:

```php
<?php

namespace App\Services;

class SePayService
{
    public static function generateTransactionCode(int $userId, string $kind = ''): string
    {
        $code = config('sepay.pattern', 'CMB') . $kind . $userId . time();
        return preg_replace('/[^A-Za-z0-9]/', '', $code);
    }

    public static function bankInfo(int $amount, string $code): array
    {
        return [
            'account_number' => (string) config('sepay.account_number', ''),
            'account_name' => (string) config('sepay.account_name', ''),
            'bank_name' => (string) config('sepay.bank_name', ''),
            'amount' => $amount,
            'content' => $code,
        ];
    }

    public static function qrUrl(int $amount, string $code): string
    {
        $query = http_build_query([
            'acc' => preg_replace('/[^A-Za-z0-9]/', '', (string) config('sepay.account_number', '')),
            'bank' => (string) config('sepay.bank_name', ''),
            'amount' => $amount,
            'des' => $code,
        ]);
        return 'https://qr.sepay.vn/img?' . $query;
    }

    public static function hasBankConfig(): bool
    {
        return !empty(config('sepay.account_number'))
            && !empty(config('sepay.account_name'))
            && !empty(config('sepay.bank_name'));
    }
}
```

- [ ] **Step 7: Run migrations (package brings its own `sepay_transactions` migration) and the test**

Run: `php artisan migrate:fresh`
Expected: migration list now includes a `create_sepay_table` migration from the `sepayvn/laravel-sepay` package (auto-loaded — no manual publish needed).
Run: `php artisan test --filter=SePayServiceTest`
Expected: PASS (6 tests).

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS (65 tests).

- [ ] **Step 9: Commit**

```bash
git add composer.json composer.lock config/sepay.php config/credit_packages.php app/Services/SePayService.php .env.example tests/Unit/SePayServiceTest.php
git commit -m "Add SePay payment integration package, config, and SePayService"
```

---

### Task 4: `PremiumService` — activate/extend Premium subscriptions

**Files:**
- Create: `app/Services/PremiumService.php`
- Test: `tests/Unit/PremiumServiceTest.php`

**Interfaces:**
- Produces: `PremiumService::activate(User $user, array $plan, ?string $txId = null, string $method = 'sepay'): Subscription` — `$plan` keys: `id`, `price`, `duration_days`, `monthly_credits`.
- Consumes: `User::deductCredits`/`lockForUpdate` pattern (Phase 1), `Subscription`/`CreditTransaction`/`SystemSetting` (Task 1, Phase 1).
- Consumed by: `SePaySubscriptionListener` (Task 9).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/PremiumServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\CreditTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PremiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PremiumServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_sets_premium_and_expiry_from_now_for_free_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free', 'package_expires_at' => null, 'monthly_credits' => 0]);

        $subscription = PremiumService::activate($user, [
            'id' => 'monthly',
            'price' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
        ], 'tx-123');

        $fresh = $user->fresh();
        $this->assertEquals('premium', $fresh->package_type);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $fresh->package_expires_at->timestamp, 5);
        $this->assertEquals(5000, $fresh->monthly_credits);
        $this->assertEquals(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertEquals('tx-123', $subscription->transaction_id);
    }

    public function test_activate_extends_cumulatively_from_existing_future_expiry(): void
    {
        $futureExpiry = now()->addDays(10);
        $user = User::factory()->create([
            'package_type' => 'premium',
            'package_expires_at' => $futureExpiry,
            'monthly_credits' => 5000,
        ]);

        PremiumService::activate($user, [
            'id' => 'monthly',
            'price' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
        ]);

        $fresh = $user->fresh();
        $this->assertEqualsWithDelta($futureExpiry->copy()->addDays(30)->timestamp, $fresh->package_expires_at->timestamp, 5);
    }

    public function test_activate_never_decreases_monthly_credits(): void
    {
        $user = User::factory()->create(['monthly_credits' => 8000, 'package_type' => 'free', 'package_expires_at' => null]);

        PremiumService::activate($user, [
            'id' => 'monthly',
            'price' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
        ]);

        $this->assertEquals(8000, $user->fresh()->monthly_credits);
    }

    public function test_activate_records_credit_transaction_only_when_credits_increase(): void
    {
        $user = User::factory()->create(['monthly_credits' => 0, 'package_type' => 'free', 'package_expires_at' => null]);

        PremiumService::activate($user, [
            'id' => 'monthly',
            'price' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
        ]);

        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => CreditTransaction::TYPE_SUBSCRIPTION,
            'amount' => 5000,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PremiumServiceTest`
Expected: FAIL — `App\Services\PremiumService` does not exist.

- [ ] **Step 3: Write `PremiumService`**

Create `app/Services/PremiumService.php`:

```php
<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Subscription;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PremiumService
{
    /**
     * @param array $plan keys: id, price, duration_days, monthly_credits
     */
    public static function activate(User $user, array $plan, ?string $txId = null, string $method = 'sepay'): Subscription
    {
        return DB::transaction(function () use ($user, $plan, $txId, $method) {
            $user = User::where('id', $user->id)->lockForUpdate()->first();

            $planId = (string) ($plan['id'] ?? 'monthly');
            $durationDays = (int) ($plan['duration_days'] ?? 30);
            $amount = (int) ($plan['price'] ?? 0);
            $targetMonthly = (int) ($plan['monthly_credits'] ?? SystemSetting::getPremiumMonthlyCredits());

            $base = ($user->package_expires_at && Carbon::parse($user->package_expires_at)->isFuture())
                ? Carbon::parse($user->package_expires_at)
                : now();
            $newExpiry = $base->copy()->addDays($durationDays);

            $user->package_type = 'premium';
            $user->package_expires_at = $newExpiry;

            $delta = max(0, $targetMonthly - (int) $user->monthly_credits);
            if ($delta > 0) {
                $user->monthly_credits = $targetMonthly;
                $user->credits = $user->monthly_credits + $user->purchased_credits;
                $user->credits_reset_at = now();
            }
            $user->save();

            if ($delta > 0) {
                CreditTransaction::create([
                    'user_id' => $user->id,
                    'type' => CreditTransaction::TYPE_SUBSCRIPTION,
                    'amount' => $delta,
                    'balance_after' => $user->monthly_credits + $user->purchased_credits,
                    'description' => "Premium {$planId} - cấp {$targetMonthly} monthly credits",
                ]);
            }

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan' => $planId,
                'status' => Subscription::STATUS_ACTIVE,
                'amount' => $amount,
                'payment_method' => $method,
                'transaction_id' => $txId,
                'starts_at' => now(),
                'expires_at' => $newExpiry,
            ]);

            Log::info('Premium activated', [
                'user_id' => $user->id,
                'plan' => $planId,
                'expires_at' => $newExpiry->toDateTimeString(),
                'tx' => $txId,
                'method' => $method,
            ]);

            return $subscription;
        });
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PremiumServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS (69 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/PremiumService.php tests/Unit/PremiumServiceTest.php
git commit -m "Add PremiumService for activating/extending subscriptions"
```

---

### Task 5: `ToolCreditController` — balance, transactions, referral info

**Files:**
- Create: `app/Http/Controllers/API/ToolCreditController.php`
- Modify: `routes/api.php` (create the new `tool` prefix group)
- Test: `tests/Feature/Tool/ToolCreditControllerTest.php`

**Interfaces:**
- Produces: `GET /api/tool/credits`, `GET /api/tool/credits/transactions`, `GET /api/tool/credits/referral` — all under a new `Route::prefix('tool')->middleware(['auth:sanctum', 'token.version'])` group. Every later task in this plan adds its routes inside this same group.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tool/ToolCreditControllerTest.php`:

```php
<?php

namespace Tests\Feature\Tool;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolCreditControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_balance_returns_credit_summary(): void
    {
        $user = User::factory()->create([
            'monthly_credits' => 3000,
            'purchased_credits' => 500,
            'package_type' => 'premium',
        ]);
        CreditTransaction::factory()->create([
            'user_id' => $user->id,
            'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -200,
            'balance_after' => 3300,
        ]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/credits');

        $response->assertOk()
            ->assertJsonPath('monthly_credits', 3000)
            ->assertJsonPath('purchased_credits', 500)
            ->assertJsonPath('credits', 3500)
            ->assertJsonPath('total_used', 200)
            ->assertJsonPath('is_premium', true);
    }

    public function test_transactions_returns_paginated_history(): void
    {
        $user = User::factory()->create();
        CreditTransaction::factory()->count(3)->create(['user_id' => $user->id, 'type' => 'deduct']);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/credits/transactions');

        $response->assertOk()->assertJsonCount(3, 'transactions');
    }

    public function test_transactions_filters_by_type(): void
    {
        $user = User::factory()->create();
        CreditTransaction::factory()->create(['user_id' => $user->id, 'type' => 'deduct']);
        CreditTransaction::factory()->create(['user_id' => $user->id, 'type' => 'topup']);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/tool/credits/transactions?type=topup');

        $response->assertOk()->assertJsonCount(1, 'transactions');
    }

    public function test_referral_info_generates_code_if_missing(): void
    {
        $user = User::factory()->create(['referral_code' => null]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/credits/referral');

        $response->assertOk()->assertJsonStructure(['referral_code', 'referral_link', 'total_referrals']);
        $this->assertNotNull($user->fresh()->referral_code);
    }

    public function test_referral_info_counts_referred_users(): void
    {
        $referrer = User::factory()->create();
        User::factory()->count(2)->create(['referred_by' => $referrer->id]);

        $response = $this->withHeaders($this->authHeader($referrer))->getJson('/api/tool/credits/referral');

        $response->assertOk()->assertJsonPath('total_referrals', 2);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ToolCreditControllerTest`
Expected: FAIL — 404 on `/api/tool/credits`.

- [ ] **Step 3: Add a `CreditTransactionFactory` (needed by this test, doesn't exist yet)**

Create `database/factories/CreditTransactionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CreditTransactionFactory extends Factory
{
    protected $model = CreditTransaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -10,
            'balance_after' => 100,
            'description' => 'Test transaction',
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/API/ToolCreditController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CreditService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ToolCreditController extends Controller
{
    public function balance(Request $request)
    {
        $user = $request->user();

        $totalUsed = CreditTransaction::where('user_id', $user->id)
            ->where('type', CreditTransaction::TYPE_DEDUCT)
            ->sum('amount');

        $totalRefunded = CreditTransaction::where('user_id', $user->id)
            ->where('type', CreditTransaction::TYPE_REFUND)
            ->sum('amount');

        $charsPerMinute = max(SystemSetting::getCharsPerMinute(), 1);
        $totalCredits = ($user->monthly_credits ?? 0) + ($user->purchased_credits ?? 0);

        return response()->json([
            'minutes_remaining' => CreditService::creditsToMinutes($totalCredits, $charsPerMinute),
            'minutes_used' => CreditService::creditsToMinutes(abs($totalUsed), $charsPerMinute),
            'minutes_refunded' => CreditService::creditsToMinutes($totalRefunded, $charsPerMinute),
            'credits' => $totalCredits,
            'monthly_credits' => $user->monthly_credits ?? 0,
            'purchased_credits' => $user->purchased_credits ?? 0,
            'credits_reset_at' => $user->credits_reset_at ? Carbon::parse($user->credits_reset_at)->toIso8601String() : null,
            'total_used' => abs($totalUsed),
            'total_refunded' => $totalRefunded,
            'chars_per_minute' => $charsPerMinute,
            'package_type' => $user->package_type,
            'package_expires_at' => $user->package_expires_at ? Carbon::parse($user->package_expires_at)->toIso8601String() : null,
            'is_premium' => $user->isPremium(),
        ]);
    }

    public function transactions(Request $request)
    {
        $pageSize = min((int) $request->get('page_size', 30), 100);
        $page = max((int) $request->get('page', 1), 1);
        $type = $request->get('type');

        $query = CreditTransaction::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc');

        if ($type && in_array($type, ['deduct', 'topup', 'bonus', 'refund'])) {
            $query->where('type', $type);
        }

        $transactions = $query->paginate($pageSize, ['*'], 'page', $page);

        return response()->json([
            'transactions' => $transactions->map(function ($t) {
                return [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount' => $t->amount,
                    'balance_after' => $t->balance_after,
                    'description' => $t->description,
                    'created_at' => $t->created_at->toIso8601String(),
                ];
            }),
            'has_more' => $transactions->hasMorePages(),
            'total' => $transactions->total(),
            'current_page' => $transactions->currentPage(),
        ]);
    }

    public function referralInfo(Request $request)
    {
        $user = $request->user();

        if (empty($user->referral_code)) {
            $user->referral_code = User::generateUniqueReferralCode();
            $user->save();
        }

        $totalReferrals = User::where('referred_by', $user->id)->count();

        $totalEarned = CreditTransaction::where('user_id', $user->id)
            ->whereIn('type', [CreditTransaction::TYPE_REFERRAL, CreditTransaction::TYPE_REFERRAL_COMMISSION])
            ->where('amount', '>', 0)
            ->sum('amount');

        $recentReferrals = CreditTransaction::where('user_id', $user->id)
            ->whereIn('type', [CreditTransaction::TYPE_REFERRAL, CreditTransaction::TYPE_REFERRAL_COMMISSION])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount' => $t->amount,
                    'description' => $t->description,
                    'created_at' => $t->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'referral_code' => $user->referral_code,
            'referral_link' => $user->referral_link,
            'total_referrals' => $totalReferrals,
            'total_earned' => (int) $totalEarned,
            'referral_reward' => 800,
            'commission_rate' => 10,
            'recent_referrals' => $recentReferrals,
        ]);
    }
}
```

- [ ] **Step 5: Wire the routes — create the new `tool` prefix group**

Add to `routes/api.php` (a new top-level group; every subsequent task in this plan adds its routes inside this same `Route::prefix('tool')->middleware(...)->group(...)` block):

```php
use App\Http\Controllers\API\ToolCreditController;

Route::prefix('tool')->middleware(['auth:sanctum', 'token.version'])->group(function () {
    Route::get('/credits', [ToolCreditController::class, 'balance']);
    Route::get('/credits/transactions', [ToolCreditController::class, 'transactions']);
    Route::get('/credits/referral', [ToolCreditController::class, 'referralInfo']);
});
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ToolCreditControllerTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS (74 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/API/ToolCreditController.php routes/api.php database/factories/CreditTransactionFactory.php tests/Feature/Tool/ToolCreditControllerTest.php
git commit -m "Add ToolCreditController and the tool/ route group"
```

---

### Task 6: `CreditTopupController` — credit packages + pending topup

**Files:**
- Create: `app/Http/Controllers/API/CreditTopupController.php`
- Modify: `routes/api.php` (add routes inside the existing `tool` group from Task 5)
- Test: `tests/Feature/Tool/CreditTopupControllerTest.php`

**Interfaces:**
- Produces: `GET /api/tool/credits/packages`, `POST /api/tool/credits/topup`, `GET /api/tool/credits/topup/status/{id}`.
- Consumes: `SePayService` (Task 3), `PendingCreditTopup` (Task 1), `config('credit_packages')` (Task 3).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tool/CreditTopupControllerTest.php`:

```php
<?php

namespace Tests\Feature\Tool;

use App\Models\PendingCreditTopup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditTopupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sepay.account_number' => '0123456789',
            'sepay.account_name' => 'CONG TY TNHH CMB',
            'sepay.bank_name' => 'MBBank',
        ]);
    }

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_packages_lists_all_configured_packages(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/credits/packages');

        $response->assertOk()->assertJsonCount(5, 'packages');
    }

    public function test_create_topup_returns_bank_info_and_qr(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/topup', ['package_id' => 'starter']);

        $response->assertOk()->assertJsonStructure(['topup_id', 'package', 'transaction_code', 'bank_info', 'qr_url']);
        $this->assertDatabaseHas('pending_credit_topups', [
            'user_id' => $user->id,
            'package_id' => 'starter',
            'credits' => 5000,
            'amount' => 30000,
            'status' => PendingCreditTopup::STATUS_PENDING,
        ]);
    }

    public function test_create_topup_rejects_unknown_package(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/topup', ['package_id' => 'not-a-real-package'])
            ->assertStatus(422);
    }

    public function test_create_topup_fails_when_bank_not_configured(): void
    {
        config(['sepay.account_number' => null]);
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/topup', ['package_id' => 'starter'])
            ->assertStatus(500);
    }

    public function test_topup_status_returns_current_state(): void
    {
        $user = User::factory()->create();
        $topup = PendingCreditTopup::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/credits/topup/status/{$topup->id}");

        $response->assertOk()->assertJsonPath('id', $topup->id)->assertJsonPath('status', 'pending');
    }

    public function test_topup_status_404s_for_another_users_topup(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $topup = PendingCreditTopup::factory()->create(['user_id' => $owner->id]);

        $this->withHeaders($this->authHeader($other))
            ->getJson("/api/tool/credits/topup/status/{$topup->id}")
            ->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CreditTopupControllerTest`
Expected: FAIL — 404 on `/api/tool/credits/packages`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/API/CreditTopupController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PendingCreditTopup;
use App\Services\SePayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CreditTopupController extends Controller
{
    public function packages()
    {
        $packages = collect(config('credit_packages'))->map(function ($p) {
            return [
                'id' => $p['id'],
                'name' => $p['name'],
                'credits' => $p['credits'],
                'price' => $p['price'],
                'price_per_credit' => round($p['price'] / $p['credits'], 2),
            ];
        });

        return response()->json(['packages' => $packages]);
    }

    public function createTopup(Request $request)
    {
        $request->validate(['package_id' => 'required|string']);

        $package = collect(config('credit_packages'))->firstWhere('id', $request->package_id);

        if (!$package) {
            return response()->json(['error' => 'Gói không tồn tại'], 422);
        }

        $user = $request->user();
        $transactionCode = SePayService::generateTransactionCode($user->id);

        $topup = PendingCreditTopup::create([
            'user_id' => $user->id,
            'package_id' => $package['id'],
            'credits' => $package['credits'],
            'amount' => $package['price'],
            'transaction_code' => $transactionCode,
            'status' => PendingCreditTopup::STATUS_PENDING,
        ]);

        if (!SePayService::hasBankConfig()) {
            Log::error('SePay bank config missing');
            return response()->json(['error' => 'Chưa cấu hình thanh toán. Vui lòng liên hệ admin.'], 500);
        }

        return response()->json([
            'topup_id' => $topup->id,
            'package' => $package,
            'transaction_code' => $transactionCode,
            'bank_info' => SePayService::bankInfo((int) $package['price'], $transactionCode),
            'qr_url' => SePayService::qrUrl((int) $package['price'], $transactionCode),
        ]);
    }

    public function topupStatus(Request $request, int $id)
    {
        $topup = PendingCreditTopup::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$topup) {
            return response()->json(['error' => 'Không tìm thấy giao dịch'], 404);
        }

        return response()->json([
            'id' => $topup->id,
            'status' => $topup->status,
            'package_id' => $topup->package_id,
            'credits' => $topup->credits,
            'amount' => $topup->amount,
            'transaction_code' => $topup->transaction_code,
            'completed_at' => $topup->completed_at?->toIso8601String(),
            'created_at' => $topup->created_at->toIso8601String(),
        ]);
    }
}
```

- [ ] **Step 4: Wire the routes**

Add inside the existing `tool` group in `routes/api.php` (alongside Task 5's routes):

```php
use App\Http\Controllers\API\CreditTopupController;

    Route::get('/credits/packages', [CreditTopupController::class, 'packages']);
    Route::post('/credits/topup', [CreditTopupController::class, 'createTopup']);
    Route::get('/credits/topup/status/{id}', [CreditTopupController::class, 'topupStatus'])->where('id', '[0-9]+');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CreditTopupControllerTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS (80 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/API/CreditTopupController.php routes/api.php tests/Feature/Tool/CreditTopupControllerTest.php
git commit -m "Add CreditTopupController for SePay-based credit purchases"
```

---

### Task 7: `ToolSubscriptionController` — Premium plans + subscribe flow

**Files:**
- Create: `app/Http/Controllers/API/ToolSubscriptionController.php`
- Modify: `routes/api.php` (add routes inside the `tool` group)
- Test: `tests/Feature/Tool/ToolSubscriptionControllerTest.php`

**Interfaces:**
- Produces: `GET /api/tool/subscription`, `POST /api/tool/subscription/subscribe`, `GET /api/tool/subscription/status/{id}`.
- Consumes: `SystemSetting::getPremiumPlans()` (Phase 1), `PendingSubscriptionPayment` (Task 1), `SePayService` (Task 3), `User::activeSubscription()` (Task 1).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tool/ToolSubscriptionControllerTest.php`:

```php
<?php

namespace Tests\Feature\Tool;

use App\Models\PendingSubscriptionPayment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolSubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sepay.account_number' => '0123456789',
            'sepay.account_name' => 'CONG TY TNHH CMB',
            'sepay.bank_name' => 'MBBank',
        ]);
    }

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_current_returns_no_subscription_for_free_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/subscription');

        $response->assertOk()
            ->assertJsonPath('has_subscription', false)
            ->assertJsonPath('is_premium', false)
            ->assertJsonStructure(['plans']);
    }

    public function test_current_returns_active_subscription_details(): void
    {
        $user = User::factory()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(20)]);
        Subscription::factory()->create([
            'user_id' => $user->id,
            'status' => Subscription::STATUS_ACTIVE,
            'expires_at' => now()->addDays(20),
        ]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/subscription');

        $response->assertOk()->assertJsonPath('has_subscription', true)->assertJsonPath('is_premium', true);
    }

    public function test_subscribe_creates_pending_payment_with_bank_info(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/subscription/subscribe', ['plan' => 'monthly']);

        $response->assertOk()->assertJsonStructure(['subscription_payment_id', 'plan', 'transaction_code', 'bank_info', 'qr_url']);
        $this->assertDatabaseHas('pending_subscription_payments', [
            'user_id' => $user->id,
            'plan' => 'monthly',
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
        ]);
    }

    public function test_subscribe_rejects_unknown_plan(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/subscription/subscribe', ['plan' => 'not-a-real-plan'])
            ->assertStatus(422);
    }

    public function test_status_returns_pending_payment_state(): void
    {
        $user = User::factory()->create();
        $payment = PendingSubscriptionPayment::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/subscription/status/{$payment->id}");

        $response->assertOk()->assertJsonPath('id', $payment->id)->assertJsonPath('status', 'pending');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ToolSubscriptionControllerTest`
Expected: FAIL — 404 on `/api/tool/subscription`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/API/ToolSubscriptionController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PendingSubscriptionPayment;
use App\Models\SystemSetting;
use App\Services\SePayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ToolSubscriptionController extends Controller
{
    public function current(Request $request)
    {
        $user = $request->user();
        $subscription = $user->activeSubscription();

        return response()->json([
            'has_subscription' => $subscription !== null,
            'is_premium' => $user->isPremium(),
            'package_type' => $user->package_type,
            'package_expires_at' => $user->package_expires_at?->toIso8601String(),
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'plan' => $subscription->plan,
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'expires_at' => $subscription->expires_at?->toIso8601String(),
            ] : null,
            'plans' => SystemSetting::getPremiumPlans(),
        ]);
    }

    public function subscribe(Request $request)
    {
        $request->validate(['plan' => 'required|string']);

        $plan = collect(SystemSetting::getPremiumPlans())->firstWhere('id', $request->plan);
        if (!$plan) {
            return response()->json(['error' => 'Gói không tồn tại'], 422);
        }

        if (!SePayService::hasBankConfig()) {
            Log::error('SePay bank config missing');
            return response()->json(['error' => 'Chưa cấu hình thanh toán. Vui lòng liên hệ admin.'], 500);
        }

        $user = $request->user();
        $code = SePayService::generateTransactionCode($user->id, 'SUB');

        $payment = PendingSubscriptionPayment::create([
            'user_id' => $user->id,
            'plan' => $plan['id'],
            'amount' => (int) $plan['price'],
            'duration_days' => (int) $plan['duration_days'],
            'monthly_credits' => (int) ($plan['monthly_credits'] ?? SystemSetting::getPremiumMonthlyCredits()),
            'transaction_code' => $code,
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
        ]);

        return response()->json([
            'subscription_payment_id' => $payment->id,
            'plan' => $plan,
            'transaction_code' => $code,
            'bank_info' => SePayService::bankInfo((int) $plan['price'], $code),
            'qr_url' => SePayService::qrUrl((int) $plan['price'], $code),
        ]);
    }

    public function status(Request $request, int $id)
    {
        $payment = PendingSubscriptionPayment::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$payment) {
            return response()->json(['error' => 'Không tìm thấy giao dịch'], 404);
        }

        return response()->json([
            'id' => $payment->id,
            'status' => $payment->status,
            'plan' => $payment->plan,
            'amount' => $payment->amount,
            'transaction_code' => $payment->transaction_code,
            'completed_at' => $payment->completed_at?->toIso8601String(),
            'created_at' => $payment->created_at->toIso8601String(),
        ]);
    }
}
```

- [ ] **Step 4: Wire the routes**

Add inside the existing `tool` group in `routes/api.php`:

```php
use App\Http\Controllers\API\ToolSubscriptionController;

    Route::get('/subscription', [ToolSubscriptionController::class, 'current']);
    Route::post('/subscription/subscribe', [ToolSubscriptionController::class, 'subscribe']);
    Route::get('/subscription/status/{id}', [ToolSubscriptionController::class, 'status'])->where('id', '[0-9]+');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ToolSubscriptionControllerTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS (85 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/API/ToolSubscriptionController.php routes/api.php tests/Feature/Tool/ToolSubscriptionControllerTest.php
git commit -m "Add ToolSubscriptionController for Premium plan subscriptions"
```

---

### Task 8: `ToolFeatureCreditController` — 2-phase feature credit deduction

**Files:**
- Create: `app/Http/Controllers/API/ToolFeatureCreditController.php`
- Modify: `routes/api.php` (add routes inside the `tool` group)
- Test: `tests/Feature/Tool/ToolFeatureCreditControllerTest.php`

**Interfaces:**
- Produces: `POST /api/tool/credits/deduct-feature`, `POST /api/tool/credits/confirm-feature/{id}`, `GET /api/tool/credits/feature-pricing`.
- Consumes: `CreditService::calculateFeatureCredits()`/`getFeaturePricing()` (Task 2), `FeatureCreditUsage` (Task 1), `User::deductCredits()` (Phase 1).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tool/ToolFeatureCreditControllerTest.php`:

```php
<?php

namespace Tests\Feature\Tool;

use App\Models\FeatureCreditUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolFeatureCreditControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_deduct_feature_creates_pending_record_without_deducting_credits(): void
    {
        $user = User::factory()->create(['monthly_credits' => 1000, 'purchased_credits' => 0, 'credits' => 1000]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/deduct-feature', [
                'feature' => 'create_video_script',
                'duration_seconds' => 300,
            ]);

        $response->assertStatus(201)->assertJsonPath('credits', 700)->assertJsonPath('status', 'pending');
        $this->assertEquals(1000, $user->fresh()->monthly_credits);
    }

    public function test_deduct_feature_rejects_unknown_feature(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/deduct-feature', ['feature' => 'not-real', 'duration_seconds' => 60])
            ->assertStatus(422);
    }

    public function test_deduct_feature_rejects_insufficient_credits(): void
    {
        $user = User::factory()->create(['monthly_credits' => 10, 'purchased_credits' => 0, 'credits' => 10]);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/deduct-feature', ['feature' => 'create_video_script', 'duration_seconds' => 300])
            ->assertStatus(402);
    }

    public function test_confirm_feature_completed_deducts_credits(): void
    {
        $user = User::factory()->create(['monthly_credits' => 1000, 'purchased_credits' => 0, 'credits' => 1000]);
        $usage = FeatureCreditUsage::factory()->create([
            'user_id' => $user->id,
            'feature' => 'create_video_script',
            'duration_seconds' => 300,
            'credits' => 700,
            'status' => FeatureCreditUsage::STATUS_PENDING,
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/tool/credits/confirm-feature/{$usage->id}", ['status' => 'completed']);

        $response->assertOk()->assertJsonPath('credits_deducted', true)->assertJsonPath('balance', 300);
        $this->assertEquals(300, $user->fresh()->monthly_credits);
    }

    public function test_confirm_feature_failed_does_not_deduct(): void
    {
        $user = User::factory()->create(['monthly_credits' => 1000, 'purchased_credits' => 0, 'credits' => 1000]);
        $usage = FeatureCreditUsage::factory()->create([
            'user_id' => $user->id,
            'credits' => 700,
            'status' => FeatureCreditUsage::STATUS_PENDING,
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/tool/credits/confirm-feature/{$usage->id}", ['status' => 'failed']);

        $response->assertOk()->assertJsonPath('credits_deducted', false);
        $this->assertEquals(1000, $user->fresh()->monthly_credits);
    }

    public function test_confirm_feature_404s_for_already_processed_record(): void
    {
        $user = User::factory()->create();
        $usage = FeatureCreditUsage::factory()->create([
            'user_id' => $user->id,
            'status' => FeatureCreditUsage::STATUS_COMPLETED,
        ]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/tool/credits/confirm-feature/{$usage->id}", ['status' => 'completed'])
            ->assertStatus(404);
    }

    public function test_feature_pricing_returns_the_pricing_table(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/credits/feature-pricing');

        $response->assertOk()->assertJsonStructure(['pricing' => ['create_video_script']]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ToolFeatureCreditControllerTest`
Expected: FAIL — 404 / missing `FeatureCreditUsageFactory`.

- [ ] **Step 3: Add `FeatureCreditUsageFactory`**

Create `database/factories/FeatureCreditUsageFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\FeatureCreditUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeatureCreditUsageFactory extends Factory
{
    protected $model = FeatureCreditUsage::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'feature' => 'create_video_script',
            'duration_seconds' => 60,
            'credits' => 140,
            'status' => FeatureCreditUsage::STATUS_PENDING,
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/API/ToolFeatureCreditController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FeatureCreditUsage;
use App\Services\CreditService;
use Illuminate\Http\Request;

class ToolFeatureCreditController extends Controller
{
    public function deductFeature(Request $request)
    {
        $request->validate([
            'feature' => 'required|string|max:50',
            'duration_seconds' => 'required|integer|min:1',
        ]);

        $feature = $request->input('feature');
        $durationSeconds = (int) $request->input('duration_seconds');
        $user = $request->user();

        try {
            $calculation = CreditService::calculateFeatureCredits($feature, $durationSeconds);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if ($calculation === null) {
            return response()->json([
                'error' => "Unknown feature: {$feature}",
                'available_features' => array_keys(CreditService::FEATURE_PRICING),
            ], 422);
        }

        $totalCredits = ($user->monthly_credits ?? 0) + ($user->purchased_credits ?? 0);
        if ($totalCredits < $calculation['credits']) {
            return response()->json([
                'error' => 'Insufficient credits.',
                'credits_required' => $calculation['credits'],
                'credits_available' => $totalCredits,
            ], 402);
        }

        $usage = FeatureCreditUsage::create([
            'user_id' => $user->id,
            'feature' => $feature,
            'duration_seconds' => $durationSeconds,
            'credits' => $calculation['credits'],
            'status' => FeatureCreditUsage::STATUS_PENDING,
        ]);

        return response()->json([
            'id' => $usage->id,
            'feature' => $usage->feature,
            'duration_seconds' => $usage->duration_seconds,
            'credits' => $usage->credits,
            'status' => $usage->status,
        ], 201);
    }

    public function confirmFeature(Request $request, int $id)
    {
        $request->validate(['status' => 'required|string|in:completed,failed']);

        $user = $request->user();
        $newStatus = $request->input('status');

        $usage = FeatureCreditUsage::where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', FeatureCreditUsage::STATUS_PENDING)
            ->first();

        if (!$usage) {
            return response()->json(['error' => 'Pending usage record not found or already processed.'], 404);
        }

        if ($newStatus === FeatureCreditUsage::STATUS_COMPLETED) {
            $deducted = $user->deductCredits(
                $usage->credits,
                "Feature: {$usage->feature} ({$usage->duration_seconds}s)",
                'feature_credit_usage',
                $usage->id
            );

            if (!$deducted) {
                return response()->json([
                    'error' => 'Insufficient credits. Cannot complete deduction.',
                    'credits_required' => $usage->credits,
                    'credits_available' => ($user->monthly_credits ?? 0) + ($user->purchased_credits ?? 0),
                ], 402);
            }

            $usage->update(['status' => FeatureCreditUsage::STATUS_COMPLETED]);
        } else {
            $usage->update(['status' => FeatureCreditUsage::STATUS_FAILED]);
        }

        $totalCredits = ($user->fresh()->monthly_credits ?? 0) + ($user->fresh()->purchased_credits ?? 0);

        return response()->json([
            'id' => $usage->id,
            'feature' => $usage->feature,
            'credits' => $usage->credits,
            'status' => $usage->status,
            'credits_deducted' => $newStatus === 'completed',
            'balance' => $totalCredits,
        ]);
    }

    public function featurePricing()
    {
        return response()->json(['pricing' => CreditService::getFeaturePricing()]);
    }
}
```

(Note: unlike the original source project's version, `$totalCredits` in `confirmFeature()` uses `$user->fresh()` to read the authoritative post-deduction balance, since `$user->deductCredits()` mutates the DB row directly via a locked sub-query and only partially syncs the in-memory `$this` — reading `fresh()` avoids returning a stale `balance` in the response.)

- [ ] **Step 5: Wire the routes**

Add inside the existing `tool` group in `routes/api.php`:

```php
use App\Http\Controllers\API\ToolFeatureCreditController;

    Route::post('/credits/deduct-feature', [ToolFeatureCreditController::class, 'deductFeature']);
    Route::post('/credits/confirm-feature/{id}', [ToolFeatureCreditController::class, 'confirmFeature'])->where('id', '[0-9]+');
    Route::get('/credits/feature-pricing', [ToolFeatureCreditController::class, 'featurePricing']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ToolFeatureCreditControllerTest`
Expected: PASS (7 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS (92 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/API/ToolFeatureCreditController.php routes/api.php database/factories/FeatureCreditUsageFactory.php tests/Feature/Tool/ToolFeatureCreditControllerTest.php
git commit -m "Add ToolFeatureCreditController for 2-phase feature credit deduction"
```

---

### Task 9: SePay webhook listeners — complete topups and subscriptions

**Files:**
- Create: `app/Listeners/SePayCreditListener.php`
- Create: `app/Listeners/SePaySubscriptionListener.php`
- Modify: `app/Providers/EventServiceProvider.php`
- Test: `tests/Feature/SePayWebhookTest.php`

**Interfaces:**
- Produces: both listeners subscribed to `SePay\SePay\Events\SePayWebhookEvent` (fired automatically by the package's `POST /api/sepay/webhook` route once its bearer-token check passes).
- Consumes: `PendingCreditTopup`/`PendingSubscriptionPayment::findByTransactionCode()` (Task 1), `User::addCredits()` (Phase 1), `PremiumService::activate()` (Task 4).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SePayWebhookTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\PendingCreditTopup;
use App\Models\PendingSubscriptionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SePayWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sepay.webhook_token' => 'test-webhook-secret',
            'sepay.pattern' => 'CMB',
        ]);
    }

    private function webhookPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => random_int(100000, 999999),
            'gateway' => 'MBBank',
            'transactionDate' => now()->toDateTimeString(),
            'accountNumber' => '0123456789',
            'subAccount' => '',
            'code' => '',
            'content' => '',
            'transferType' => 'in',
            'description' => 'Chuyen tien',
            'transferAmount' => 0,
            'referenceCode' => 'REF' . random_int(1000, 9999),
            'accumulated' => 0,
        ], $overrides);
    }

    private function postWebhook(array $payload)
    {
        return $this->postJson('/api/sepay/webhook', $payload, [
            'Authorization' => 'Apikey test-webhook-secret',
        ]);
    }

    public function test_webhook_credits_user_when_topup_transaction_code_matches(): void
    {
        $user = User::factory()->create(['purchased_credits' => 0, 'credits' => 0]);
        $topup = PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'credits' => 5000,
            'amount' => 30000,
            'transaction_code' => 'CMB121234567890',
        ]);

        $response = $this->postWebhook($this->webhookPayload([
            'content' => 'CMB121234567890 chuyen tien',
            'transferAmount' => 30000,
        ]));

        $response->assertNoContent();
        $this->assertEquals(PendingCreditTopup::STATUS_COMPLETED, $topup->fresh()->status);
        $this->assertEquals(5000, $user->fresh()->purchased_credits);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'topup',
            'amount' => 5000,
        ]);
    }

    public function test_webhook_grants_referral_commission_on_topup(): void
    {
        $referrer = User::factory()->create(['purchased_credits' => 0, 'credits' => 0]);
        $user = User::factory()->create(['referred_by' => $referrer->id, 'purchased_credits' => 0, 'credits' => 0]);
        PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'credits' => 5000,
            'amount' => 30000,
            'transaction_code' => 'CMB999888777',
        ]);

        $this->postWebhook($this->webhookPayload([
            'content' => 'CMB999888777',
            'transferAmount' => 30000,
        ]));

        $this->assertEquals(500, $referrer->fresh()->purchased_credits); // 10% of 5000
    }

    public function test_webhook_ignores_amount_below_expected(): void
    {
        $user = User::factory()->create();
        $topup = PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'amount' => 30000,
            'transaction_code' => 'CMB555444333',
        ]);

        $this->postWebhook($this->webhookPayload([
            'content' => 'CMB555444333',
            'transferAmount' => 10000,
        ]));

        $this->assertEquals(PendingCreditTopup::STATUS_PENDING, $topup->fresh()->status);
    }

    public function test_webhook_is_idempotent_for_already_completed_topup(): void
    {
        $user = User::factory()->create(['purchased_credits' => 0, 'credits' => 0]);
        $topup = PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'credits' => 5000,
            'amount' => 30000,
            'transaction_code' => 'CMB111222333',
        ]);

        $payload = $this->webhookPayload(['content' => 'CMB111222333', 'transferAmount' => 30000]);
        $this->postWebhook($payload)->assertNoContent();

        // Second webhook with a different SePay transaction id but same content — must not double-credit
        $this->postWebhook(array_merge($payload, ['id' => $payload['id'] + 1]))->assertNoContent();

        $this->assertEquals(5000, $user->fresh()->purchased_credits);
    }

    public function test_webhook_activates_subscription_when_pattern_matches(): void
    {
        $user = User::factory()->create(['package_type' => 'free', 'package_expires_at' => null, 'monthly_credits' => 0]);
        $payment = PendingSubscriptionPayment::factory()->create([
            'user_id' => $user->id,
            'plan' => 'monthly',
            'amount' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
            'transaction_code' => 'CMBSUB777666555',
        ]);

        $response = $this->postWebhook($this->webhookPayload([
            'content' => 'CMBSUB777666555',
            'transferAmount' => 99000,
        ]));

        $response->assertNoContent();
        $this->assertEquals(PendingSubscriptionPayment::STATUS_COMPLETED, $payment->fresh()->status);
        $fresh = $user->fresh();
        $this->assertEquals('premium', $fresh->package_type);
        $this->assertEquals(5000, $fresh->monthly_credits);
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'status' => 'active']);
    }

    public function test_webhook_rejects_invalid_bearer_token(): void
    {
        $response = $this->postJson('/api/sepay/webhook', $this->webhookPayload(), [
            'Authorization' => 'Apikey wrong-token',
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_ignores_outgoing_transfers(): void
    {
        $user = User::factory()->create(['purchased_credits' => 0, 'credits' => 0]);
        PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'credits' => 5000,
            'amount' => 30000,
            'transaction_code' => 'CMB222333444',
        ]);

        $this->postWebhook($this->webhookPayload([
            'content' => 'CMB222333444',
            'transferAmount' => 30000,
            'transferType' => 'out',
        ]));

        $this->assertEquals(0, $user->fresh()->purchased_credits);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SePayWebhookTest`
Expected: FAIL — no listener credits the user (the package's webhook route exists from Task 3, but nothing is subscribed to the event yet, so credits never get added / subscription never activates).

- [ ] **Step 3: Write the listeners**

Create `app/Listeners/SePayCreditListener.php`:

```php
<?php

namespace App\Listeners;

use App\Models\CreditTransaction;
use App\Models\PendingCreditTopup;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use SePay\SePay\Events\SePayWebhookEvent;

class SePayCreditListener
{
    public function handle(SePayWebhookEvent $event): void
    {
        $data = $event->sePayWebhookData;

        if ($data->transferType !== 'in') {
            Log::info('SePay credit: ignored transfer type: ' . $data->transferType);
            return;
        }

        $content = $data->content;
        $pattern = config('sepay.pattern', 'CMB');

        $escapedPattern = preg_quote($pattern, '/');
        if (!preg_match('/' . $escapedPattern . '[A-Za-z0-9]+/', $content, $matches)) {
            Log::warning('SePay credit: transaction code not found', ['content' => $content, 'pattern' => $pattern]);
            return;
        }

        $transactionCode = $matches[0];
        $topup = PendingCreditTopup::findByTransactionCode($transactionCode);

        if (!$topup) {
            Log::warning('SePay credit: no pending topup found', ['transaction_code' => $transactionCode]);
            return;
        }

        if ($topup->status === PendingCreditTopup::STATUS_COMPLETED) {
            Log::info('SePay credit: topup already completed', ['topup_id' => $topup->id]);
            return;
        }

        if ($data->transferAmount < $topup->amount) {
            Log::warning('SePay credit: amount mismatch', [
                'expected' => $topup->amount,
                'received' => $data->transferAmount,
                'topup_id' => $topup->id,
            ]);
            return;
        }

        $user = User::find($topup->user_id);
        if (!$user) {
            Log::error('SePay credit: user not found', ['user_id' => $topup->user_id]);
            return;
        }

        $user->addCredits(
            $topup->credits,
            'topup',
            "Nạp {$topup->credits} credit - Gói {$topup->package_id}",
            PendingCreditTopup::class,
            $topup->id,
            'purchased'
        );

        $topup->markCompleted();

        Log::info('SePay credit: topup completed', ['topup_id' => $topup->id, 'user_id' => $user->id, 'credits' => $topup->credits]);

        if ($user->referred_by) {
            $referrer = User::find($user->referred_by);
            if ($referrer) {
                $commission = (int) floor($topup->credits * 0.10);
                if ($commission > 0) {
                    $referrer->addCredits(
                        $commission,
                        CreditTransaction::TYPE_REFERRAL_COMMISSION,
                        "Hoa hồng 10%: {$user->name} nạp {$topup->credits} credits",
                        PendingCreditTopup::class,
                        $topup->id,
                        'purchased'
                    );
                }
            }
        }
    }
}
```

Create `app/Listeners/SePaySubscriptionListener.php`:

```php
<?php

namespace App\Listeners;

use App\Models\PendingSubscriptionPayment;
use App\Models\User;
use App\Services\PremiumService;
use Illuminate\Support\Facades\Log;
use SePay\SePay\Events\SePayWebhookEvent;

class SePaySubscriptionListener
{
    public function handle(SePayWebhookEvent $event): void
    {
        $data = $event->sePayWebhookData;

        if ($data->transferType !== 'in') {
            return;
        }

        $pattern = config('sepay.pattern', 'CMB');
        $escaped = preg_quote($pattern, '/');
        if (!preg_match('/' . $escaped . '[A-Za-z0-9]+/', $data->content, $matches)) {
            return;
        }

        $payment = PendingSubscriptionPayment::findByTransactionCode($matches[0]);
        if (!$payment) {
            return;
        }

        if ($data->transferAmount < $payment->amount) {
            Log::warning('SePay subscription: amount mismatch', [
                'expected' => $payment->amount,
                'received' => $data->transferAmount,
                'id' => $payment->id,
            ]);
            return;
        }

        $claimed = PendingSubscriptionPayment::where('id', $payment->id)
            ->where('status', PendingSubscriptionPayment::STATUS_PENDING)
            ->update(['status' => PendingSubscriptionPayment::STATUS_COMPLETED, 'completed_at' => now()]);

        if ($claimed === 0) {
            Log::info('SePay subscription: already processed', ['id' => $payment->id]);
            return;
        }

        $user = User::find($payment->user_id);
        if (!$user) {
            Log::error('SePay subscription: user not found', ['user_id' => $payment->user_id]);
            return;
        }

        PremiumService::activate($user, [
            'id' => $payment->plan,
            'price' => $payment->amount,
            'duration_days' => $payment->duration_days,
            'monthly_credits' => $payment->monthly_credits,
        ], (string) $data->id, 'sepay');

        Log::info('SePay subscription: completed', ['id' => $payment->id, 'user_id' => $user->id, 'plan' => $payment->plan]);
    }
}
```

- [ ] **Step 4: Register the listeners**

Modify `app/Providers/EventServiceProvider.php` — add to the `$listen` array (add the `use` imports at the top of the file):

```php
use SePay\SePay\Events\SePayWebhookEvent;
use App\Listeners\SePayCreditListener;
use App\Listeners\SePaySubscriptionListener;
```

```php
        SePayWebhookEvent::class => [
            SePayCreditListener::class,
            SePaySubscriptionListener::class,
        ],
```

(Add this array entry alongside the existing `Registered::class => [SendEmailVerificationNotification::class]` entry — don't remove it.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SePayWebhookTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS (99 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Listeners/SePayCreditListener.php app/Listeners/SePaySubscriptionListener.php app/Providers/EventServiceProvider.php tests/Feature/SePayWebhookTest.php
git commit -m "Add SePay webhook listeners to complete credit topups and subscriptions"
```

---

## What's next

Phase 2 ships a complete, independently testable credit-purchase and Premium-subscription system on top of Phase 1's auth foundation. Phase 3 (AI Tools — TTS, SRT generate/translate, Video Dub, Script generation, Scene generation, Stock media/Pexels — plus the `ProcessSrtGenerate`/`ProcessSrtTranslate`/`ProcessVideoDub` jobs) gets its own plan document once this one is executed and verified.
