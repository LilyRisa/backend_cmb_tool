<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time import of real production data from the source ESP32/marketing
 * monolith's database into this project's isolated database, preserving IDs
 * so internal foreign keys (credit_transactions.user_id, etc.) stay intact.
 *
 * Per the design spec (docs/superpowers/specs/2026-07-30-extract-marketing-backend-design.md
 * section 4), this only migrates tables that already have a matching schema
 * in this project — see the design spec's "Cập nhật" note for the tables
 * deferred until their own admin/portal phase is built (tools,
 * feature_usages, email_campaigns, fb_auto_comment_campaigns, meta_accounts,
 * bug_reports, contact_messages, blog_posts, blog_categories, preorders).
 *
 * Source connection details are NEVER stored in this repo (it's public) —
 * pass them via environment variables at invocation time only:
 *   OLD_DB_HOST, OLD_DB_PORT, OLD_DB_DATABASE, OLD_DB_USERNAME, OLD_DB_PASSWORD
 */
class ImportMarketingData extends Command
{
    protected $signature = 'import:marketing-data {--dry-run : Preview row counts without writing anything}';
    protected $description = 'One-time import of marketing data from the source monolith database (see class docblock)';

    /**
     * Tables in FK-safe insert order. users.storage_limit is intentionally
     * excluded (ESP32-specific, not part of this project's users schema) —
     * every other column is a verbatim 1:1 match, verified by hand against
     * the live source schema before this command was written.
     */
    private const TABLES = [
        'users',
        'system_settings',
        'credit_transactions',
        'pending_credit_topups',
        'feature_credit_usages',
        'subscriptions',
        'pending_subscription_payments',
        'tts_histories',
        'srt_generate_jobs',
        'srt_translate_jobs',
        'video_dub_jobs',
        'login_logs',
    ];

    private const EXCLUDED_COLUMNS = [
        'users' => ['storage_limit'],
    ];

    public function handle(): int
    {
        foreach (['OLD_DB_HOST', 'OLD_DB_PORT', 'OLD_DB_DATABASE', 'OLD_DB_USERNAME', 'OLD_DB_PASSWORD'] as $var) {
            if (empty(env($var))) {
                $this->error("Missing required env var: {$var}");
                return self::FAILURE;
            }
        }

        Config::set('database.connections.old_source', [
            'driver' => 'mysql',
            'host' => env('OLD_DB_HOST'),
            'port' => env('OLD_DB_PORT'),
            'database' => env('OLD_DB_DATABASE'),
            'username' => env('OLD_DB_USERNAME'),
            'password' => env('OLD_DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $dryRun = (bool) $this->option('dry-run');

        try {
            DB::connection('old_source')->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot connect to source database: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info($dryRun ? 'DRY RUN — no rows will be written.' : 'LIVE RUN — rows will be written to the local database.');

        if (!$dryRun) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        $summary = [];

        try {
            foreach (self::TABLES as $table) {
                $summary[$table] = $this->copyTable($table, $dryRun);
            }
        } finally {
            if (!$dryRun) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        $this->newLine();
        $this->table(['Table', 'Source rows', 'Inserted'], collect($summary)->map(
            fn ($s, $t) => [$t, $s['source'], $s['inserted']]
        )->values()->toArray());

        return self::SUCCESS;
    }

    /**
     * @return array{source: int, inserted: int}
     */
    private function copyTable(string $table, bool $dryRun): array
    {
        $sourceColumns = collect(DB::connection('old_source')->select("SHOW COLUMNS FROM `{$table}`"))
            ->pluck('Field')
            ->reject(fn ($col) => in_array($col, self::EXCLUDED_COLUMNS[$table] ?? [], true))
            ->values();

        $sourceCount = DB::connection('old_source')->table($table)->count();

        if ($sourceCount === 0) {
            $this->line("  {$table}: 0 rows in source, skipping.");
            return ['source' => 0, 'inserted' => 0];
        }

        $existingLocal = DB::table($table)->count();
        if ($existingLocal > 0) {
            $this->warn("  {$table}: already has {$existingLocal} row(s) locally — skipping to avoid duplicate/ID-collision inserts. Truncate manually first if you intend to re-import.");
            return ['source' => $sourceCount, 'inserted' => 0];
        }

        $this->line("  {$table}: copying {$sourceCount} row(s)...");

        $inserted = 0;

        DB::connection('old_source')->table($table)
            ->select($sourceColumns->toArray())
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($table, &$inserted, $dryRun) {
                if ($dryRun) {
                    $inserted += count($rows);
                    return;
                }

                $batch = $rows->map(fn ($row) => (array) $row)->toArray();
                DB::table($table)->insert($batch);
                $inserted += count($batch);
            });

        return ['source' => $sourceCount, 'inserted' => $inserted];
    }
}
