<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Admin shares its DB with the kraite ecosystem; schema is owned by
     * kraitebot/core. Limit the test migrate path to admin's own scaffold
     * migrations so the in-memory SQLite suite doesn't try to run core's
     * `create_martingalian_complete_schema` migration — that migration
     * uses MySQL-only prefix-index syntax (`position_side(8)`) that
     * SQLite can't parse.
     */
    protected function migrateFreshUsing()
    {
        return [
            '--path' => 'database/migrations',
            '--realpath' => false,
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
        ];
    }

    /**
     * Hook fired after RefreshDatabase finishes running the test migrations,
     * before the per-test transaction starts. Builds the minimum
     * kraitebot/core-owned schema admin's tests actually touch, so password
     * reset tests pass against SQLite without running core's MySQL-coupled
     * schema migration.
     *
     * Stub only the core-owned tables and columns touched by admin tests;
     * everything else lives in admin's own migrations.
     */
    protected function afterRefreshingDatabase(): void
    {
        $this->stubCoreNotificationsSchema();
        $this->stubCoreUsersTableExtensions();
        $this->stubCoreKraiteSingleton();
        $this->stubCoreModelLogsSchema();
    }

    /**
     * Mirror the core `kraite` singleton row (id=1). Core's notification /
     * config paths read it (`Kraite::find(1)`, BSCS gates, billing floors);
     * without the table, any flow that fires an AlertNotification 500s in
     * tests — surfaced 2026-06-07 when the forgot-password flow started
     * reading it through a core change.
     */
    private function stubCoreKraiteSingleton(): void
    {
        if (Schema::hasTable('kraite')) {
            return;
        }

        Schema::create('kraite', function (Blueprint $table): void {
            $table->id();
            $table->json('notification_channels')->nullable();
            $table->json('timeframes')->nullable();
            $table->string('admin_telegram_chat_id')->nullable();
            $table->string('email')->nullable();
            $table->boolean('allow_opening_positions')->default(false);
            $table->boolean('can_trade')->nullable();
            $table->boolean('notifications_enabled')->nullable();
            $table->string('td_correlation_type', 20)->nullable();
            $table->boolean('corr_enabled')->nullable();
            $table->boolean('elast_enabled')->nullable();
            $table->unsignedInteger('trail_retention_hours')->nullable();
            $table->boolean('is_cooling_down')->default(true);
            $table->unsignedTinyInteger('bscs_score')->nullable();
            $table->string('bscs_band', 16)->nullable();
            $table->dateTime('bscs_synced_at')->nullable();
            $table->boolean('bscs_block_active')->default(false);
            $table->unsignedTinyInteger('bscs_block_threshold')->default(80);
            $table->unsignedInteger('bscs_freshness_max_seconds')->default(6900);
            $table->unsignedSmallInteger('bscs_cooldown_hours')->nullable();
            $table->dateTime('bscs_override_until')->nullable();
            $table->string('bscs_override_reason')->nullable();
            $table->dateTime('bscs_cooldown_until')->nullable();
            $table->decimal('top_up_minimum_when_covered_usdt', 12, 4)->default(20);
            $table->boolean('in_private_beta')->default(false);
            $table->timestamps();
        });

        DB::table('kraite')->insert([
            'id' => 1,
            'notifications_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Mirror core's technical audit table for runtime-setting change logs.
     */
    private function stubCoreModelLogsSchema(): void
    {
        if (Schema::hasTable('model_logs')) {
            return;
        }

        Schema::create('model_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('loggable_type');
            $table->unsignedBigInteger('loggable_id');
            $table->string('relatable_type')->nullable();
            $table->unsignedBigInteger('relatable_id')->nullable();
            $table->string('event_type');
            $table->string('attribute_name')->nullable();
            $table->text('message')->nullable();
            $table->longText('previous_value')->nullable();
            $table->longText('new_value')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['loggable_type', 'loggable_id']);
        });
    }

    /**
     * Mirror the `notifications` table + the `password_reset` canonical row
     * that kraitebot/core's production schema migration installs but that
     * admin's sqlite test schema can't include (the core migration uses
     * MySQL-only prefix-index syntax).
     */
    private function stubCoreNotificationsSchema(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('verified')->default(false);
            $table->string('default_severity')->nullable();
            $table->unsignedInteger('cache_duration')->default(600);
            $table->json('cache_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('notifications')->insert([
            'canonical' => 'password_reset',
            'title' => 'Password Reset',
            'is_active' => true,
            'cache_duration' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Mirror the subset of kraitebot/core's `Schema::table('users', ...)`
     * extension that admin's tests rely on. Specifically:
     *  - `is_active` — AlertNotification::via() bails out (returns no channels)
     *    if the recipient isn't active, which silently swallows password-reset
     *    notifications in tests. We default it to TRUE here (prod defaults to
     *    false) so factory-created users in tests are eligible by default.
     *  - `is_admin` — EnsureAdmin middleware reads this; tests that bypass the
     *    middleware don't strictly need it, but adding it keeps the test
     *    User model aligned with prod for any code paths that touch it.
     *  - `notification_channels` — AlertNotification::via() falls back to it
     *    when no per-send override is given. Nullable.
     */
    private function stubCoreUsersTableExtensions(): void
    {
        if (Schema::hasColumn('users', 'is_active')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->boolean('is_admin')->default(false);
            $table->json('notification_channels')->nullable();
            // Trading-day basis in minutes from UTC; 0 keeps the UTC day.
            $table->smallInteger('utc_offset_minutes')->default(0);
            // Where the trader was last seen, and the last country a basis
            // suggestion was offered for.
            $table->string('last_seen_country', 2)->nullable();
            $table->string('basis_hint_country', 2)->nullable();
        });
    }
}
