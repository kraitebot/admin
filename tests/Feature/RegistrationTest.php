<?php

declare(strict_types=1);

use App\Livewire\RegisterForm;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Account\TestExchangeConnectivityStep;
use Kraite\Core\Jobs\Lifecycles\Account\TestServerConnectivityStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Server;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\TradeConfiguration;
use Kraite\Core\Models\User;
use Livewire\Livewire;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);

    prepareRegistrationSchema();
});

function prepareRegistrationSchema(): void
{
    Schema::table('users', function (Blueprint $table): void {
        if (! Schema::hasColumn('users', 'uuid')) {
            $table->char('uuid', 36)->nullable()->unique()->after('id');
        }
        if (! Schema::hasColumn('users', 'subscription_id')) {
            $table->unsignedBigInteger('subscription_id')->nullable()->after('uuid');
        }
        if (! Schema::hasColumn('users', 'wallet_balance_usdt')) {
            $table->decimal('wallet_balance_usdt', 14, 4)->default(0)->after('subscription_id');
        }
        if (! Schema::hasColumn('users', 'trial_started_at')) {
            $table->timestamp('trial_started_at')->nullable()->after('wallet_balance_usdt');
        }
        if (! Schema::hasColumn('users', 'subscription_renews_at')) {
            $table->timestamp('subscription_renews_at')->nullable()->after('trial_started_at');
        }
        if (! Schema::hasColumn('users', 'subscription_paused_at')) {
            $table->timestamp('subscription_paused_at')->nullable()->after('subscription_renews_at');
        }
        if (! Schema::hasColumn('users', 'trial_days_override')) {
            $table->unsignedSmallInteger('trial_days_override')->nullable()->after('subscription_paused_at');
        }
        if (! Schema::hasColumn('users', 'active_account_id')) {
            $table->unsignedBigInteger('active_account_id')->nullable()->after('trial_days_override');
        }
        if (! Schema::hasColumn('users', 'status')) {
            $table->string('status', 16)->default('pending')->index()->after('email');
        }
        if (! Schema::hasColumn('users', 'is_active')) {
            $table->boolean('is_active')->default(true)->after('remember_token');
        }
        if (! Schema::hasColumn('users', 'can_trade')) {
            $table->boolean('can_trade')->default(true)->after('is_active');
        }
        if (! Schema::hasColumn('users', 'is_admin')) {
            $table->boolean('is_admin')->default(false)->after('can_trade');
        }
        if (! Schema::hasColumn('users', 'notification_channels')) {
            $table->json('notification_channels')->nullable()->after('is_admin');
        }
        if (! Schema::hasColumn('users', 'current_connectivity_test_uuid')) {
            $table->char('current_connectivity_test_uuid', 36)->nullable()->after('notification_channels');
        }
    });

    Schema::create('subscriptions', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('canonical')->unique();
        $table->text('description')->nullable();
        $table->decimal('monthly_rate_usdt', 12, 4)->default(0);
        $table->unsignedSmallInteger('trial_days')->default(7);
        $table->unsignedInteger('max_accounts')->nullable();
        $table->unsignedInteger('max_exchanges')->nullable();
        $table->decimal('max_balance', 15, 2)->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('api_systems', function (Blueprint $table): void {
        $table->id();
        $table->boolean('is_exchange')->default(true);
        $table->string('name');
        $table->string('canonical')->unique();
        $table->string('logo_url')->nullable();
        $table->timestamps();
    });

    Schema::create('trade_configuration', function (Blueprint $table): void {
        $table->id();
        $table->boolean('is_default')->default(false);
        $table->string('canonical')->unique();
        $table->string('description')->nullable();
        $table->decimal('min_account_balance', 20, 8)->default(100);
        $table->timestamps();
    });

    Schema::create('accounts', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->nullable();
        $table->string('name');
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('api_system_id');
        $table->unsignedBigInteger('trade_configuration_id');
        $table->string('portfolio_quote', 20)->nullable();
        $table->string('trading_quote', 20)->nullable();
        $table->decimal('margin', 20, 8)->nullable();
        $table->boolean('can_trade')->default(true);
        $table->boolean('is_active')->default(true);
        $table->string('disabled_reason')->nullable();
        $table->timestamp('disabled_at')->nullable();
        $table->decimal('profit_percentage', 6, 3)->default(0.360);
        $table->unsignedTinyInteger('total_limit_orders_filled_to_notify')->default(0);
        $table->decimal('stop_market_initial_percentage', 5, 2)->default(2.50);
        $table->unsignedInteger('total_positions_short')->default(4);
        $table->unsignedInteger('total_positions_long')->default(4);
        $table->unsignedInteger('position_leverage_short')->default(10);
        $table->decimal('margin_percentage_short', 5, 2)->default(4.00);
        $table->unsignedInteger('position_leverage_long')->default(10);
        $table->decimal('margin_percentage_long', 5, 2)->default(4.00);
        $table->string('margin_mode')->nullable();
        $table->boolean('on_hedge_mode')->default(false);
        $table->boolean('allow_other_positions')->default(false);
        $table->boolean('allow_other_orders')->default(false);
        $table->longText('binance_api_key')->nullable();
        $table->longText('binance_api_secret')->nullable();
        $table->longText('bybit_api_key')->nullable();
        $table->longText('bybit_api_secret')->nullable();
        $table->longText('kraken_api_key')->nullable();
        $table->longText('kraken_private_key')->nullable();
        $table->longText('kucoin_api_key')->nullable();
        $table->longText('kucoin_api_secret')->nullable();
        $table->longText('kucoin_passphrase')->nullable();
        $table->longText('bitget_api_key')->nullable();
        $table->longText('bitget_api_secret')->nullable();
        $table->longText('bitget_passphrase')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('servers', function (Blueprint $table): void {
        $table->id();
        $table->string('hostname');
        $table->string('ip_address')->nullable();
        $table->boolean('is_apiable')->default(false);
        $table->boolean('needs_whitelisting')->default(false);
        $table->string('own_queue_name')->nullable();
        $table->string('description')->nullable();
        $table->string('type')->default('server');
        $table->text('secret')->nullable();
        $table->timestamps();
    });

    Schema::create('steps', function (Blueprint $table): void {
        $table->id();
        $table->char('block_uuid', 36)->index();
        $table->string('type', 50)->default('default')->index();
        $table->string('group', 50)->nullable();
        $table->string('state')->default(Pending::class)->index();
        $table->string('class')->nullable();
        $table->string('label')->nullable();
        $table->integer('index')->nullable();
        $table->longText('response')->nullable();
        $table->text('error_message')->nullable();
        $table->longText('error_stack_trace')->nullable();
        $table->longText('step_log')->nullable();
        $table->string('relatable_type')->nullable()->index();
        $table->unsignedBigInteger('relatable_id')->nullable()->index();
        $table->char('child_block_uuid', 36)->nullable()->index();
        $table->string('execution_mode', 50)->nullable();
        $table->tinyInteger('double_check')->default(0);
        $table->unsignedBigInteger('tick_id')->nullable();
        $table->char('workflow_id', 36)->nullable()->index();
        $table->string('canonical', 100)->nullable();
        $table->string('queue', 50)->default('default');
        $table->json('arguments')->nullable();
        $table->integer('retries')->default(0);
        $table->tinyInteger('was_throttled')->default(0)->index();
        $table->tinyInteger('is_throttled')->default(0)->index();
        $table->string('priority', 20)->nullable()->index();
        $table->timestamp('dispatch_after')->nullable()->index();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->bigInteger('duration')->default(0);
        $table->string('hostname', 100)->nullable();
        $table->tinyInteger('was_notified')->default(0);
        $table->timestamps();
    });

    Schema::create('steps_dispatcher', function (Blueprint $table): void {
        $table->id();
        $table->string('group', 50)->nullable()->unique();
        $table->tinyInteger('can_dispatch')->default(1)->index();
        $table->unsignedBigInteger('current_tick_id')->nullable()->index();
        $table->timestamp('last_tick_completed')->nullable()->index();
        $table->timestamp('last_selected_at', 6)->nullable()->index();
        $table->timestamps();
    });

    Schema::create('steps_dispatcher_ticks', function (Blueprint $table): void {
        $table->id();
        $table->string('group', 50)->nullable();
        $table->integer('progress')->default(0);
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->integer('duration')->nullable();
        $table->timestamps();
    });

    Schema::create('model_logs', function (Blueprint $table): void {
        $table->id();
        $table->string('loggable_type')->nullable();
        $table->unsignedBigInteger('loggable_id')->nullable();
        $table->string('relatable_type')->nullable();
        $table->unsignedBigInteger('relatable_id')->nullable();
        $table->string('event_type');
        $table->string('attribute_name')->nullable();
        $table->longText('message')->nullable();
        $table->longText('previous_value')->nullable();
        $table->longText('new_value')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
    });
}

function registrationUser(string $status, ?string $uuid = null, ?string $email = null, ?string $name = null): User
{
    $email ??= fake()->unique()->safeEmail();

    return User::create([
        'uuid' => $uuid ?? (string) Str::uuid(),
        'name' => $name ?? 'Private Beta',
        'email' => $email,
        'email_verified_at' => $status === 'pending' ? null : now(),
        'password' => 'temporary-password',
        'status' => $status,
        'is_active' => true,
        'can_trade' => false,
        'notification_channels' => ['mail'],
    ]);
}

function seedRegistrationCatalog(): array
{
    $basic = Subscription::create([
        'name' => 'Basic',
        'canonical' => 'basic',
        'description' => 'Entry tier',
        'monthly_rate_usdt' => 75,
        'trial_days' => 7,
        'max_accounts' => 1,
        'is_active' => true,
    ]);

    Subscription::create([
        'name' => 'Unlimited',
        'canonical' => 'unlimited',
        'description' => 'Unlimited tier',
        'monthly_rate_usdt' => 150,
        'trial_days' => 7,
        'max_accounts' => null,
        'is_active' => true,
    ]);

    $binance = ApiSystem::create([
        'name' => 'Binance',
        'canonical' => 'binance',
        'is_exchange' => true,
    ]);

    foreach (['bybit' => 'Bybit', 'kucoin' => 'KuCoin', 'bitget' => 'Bitget'] as $canonical => $name) {
        ApiSystem::create([
            'name' => $name,
            'canonical' => $canonical,
            'is_exchange' => true,
        ]);
    }

    TradeConfiguration::create([
        'is_default' => true,
        'canonical' => 'default',
        'description' => 'Default',
        'min_account_balance' => 100,
    ]);

    Server::create([
        'hostname' => 'orion',
        'ip_address' => '10.0.0.1',
        'is_apiable' => true,
        'needs_whitelisting' => true,
        'own_queue_name' => 'orion',
        'type' => 'trading',
    ]);

    Server::create([
        'hostname' => 'vega',
        'ip_address' => '10.0.0.2',
        'is_apiable' => true,
        'needs_whitelisting' => true,
        'own_queue_name' => 'vega',
        'type' => 'trading',
    ]);

    return [$basic, $binance];
}

function registrationDraftAccount(User $user, ApiSystem $apiSystem): Account
{
    $tradeConfiguration = TradeConfiguration::firstOrFail();

    $account = new Account([
        'uuid' => (string) Str::uuid(),
        'name' => 'Registration connectivity test',
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
        'trade_configuration_id' => $tradeConfiguration->id,
        'portfolio_quote' => 'USDT',
        'trading_quote' => 'USDT',
        'margin' => 1000,
        'can_trade' => false,
        'is_active' => false,
        'profit_percentage' => '0.360',
        'stop_market_initial_percentage' => '2.50',
        'total_positions_long' => 4,
        'total_positions_short' => 4,
        'position_leverage_long' => 10,
        'position_leverage_short' => 10,
        'margin_percentage_long' => '4.00',
        'margin_percentage_short' => '4.00',
        'on_hedge_mode' => false,
        'allow_other_positions' => false,
        'allow_other_orders' => false,
    ]);

    $account->all_credentials = [
        'binance_api_key' => 'binance-key',
        'binance_api_secret' => 'binance-secret',
    ];
    $account->save();

    return $account;
}

function registrationConnectivityBlock(User $user, Account $account, bool $allConnected = true): string
{
    $blockUuid = (string) Str::uuid();
    $childBlockUuid = (string) Str::uuid();

    $user->forceFill(['current_connectivity_test_uuid' => $blockUuid])->save();

    Step::create([
        'block_uuid' => $blockUuid,
        'child_block_uuid' => $childBlockUuid,
        'class' => TestExchangeConnectivityStep::class,
        'state' => Completed::class,
        'queue' => 'cronjobs',
        'relatable_type' => Account::class,
        'relatable_id' => $account->id,
        'arguments' => ['accountId' => $account->id],
        'index' => 1,
        'completed_at' => now(),
    ]);

    Server::query()
        ->where('is_apiable', true)
        ->where('needs_whitelisting', true)
        ->whereNotNull('ip_address')
        ->orderBy('hostname')
        ->get()
        ->each(function (Server $server, int $index) use ($account, $allConnected, $childBlockUuid): void {
            Step::create([
                'block_uuid' => $childBlockUuid,
                'class' => TestServerConnectivityStep::class,
                'state' => $allConnected || $index === 0 ? Completed::class : Failed::class,
                'queue' => $server->own_queue_name ?: 'default',
                'relatable_type' => Account::class,
                'relatable_id' => $account->id,
                'arguments' => [
                    'accountId' => $account->id,
                    'serverId' => $server->id,
                ],
                'index' => $index + 1,
                'completed_at' => now(),
                'error_message' => $allConnected || $index === 0 ? null : 'IP is not whitelisted.',
            ]);
        });

    return $blockUuid;
}

it('hides unknown registration uuids', function (): void {
    $this->get(route('register.show', (string) Str::uuid()))
        ->assertNotFound();
});

it('hides pending users from the registration form', function (): void {
    $user = registrationUser('pending');

    $this->get(route('register.show', $user->uuid))
        ->assertNotFound();
});

it('renders registration for confirmed users', function (): void {
    seedRegistrationCatalog();
    config(['kraite.website_url' => 'https://kraite.test']);

    $user = registrationUser('confirmed');

    $this->get(route('register.show', $user->uuid))
        ->assertSuccessful()
        ->assertSee('Welcome to Kraite!')
        ->assertSee($user->email)
        ->assertSee('1. Profile')
        ->assertSee('2. API keys')
        ->assertDontSee('Trading exchange')
        ->assertDontSee('Coming soon')
        ->assertSee('href="https://kraite.test/terms-and-conditions"', false)
        ->assertSee('novalidate', false)
        ->assertSee('Since you registered for private beta, you get a 7-day free trial');
});

it('proposes a title-cased name from the email local part', function (): void {
    seedRegistrationCatalog();
    $user = registrationUser(
        status: 'confirmed',
        email: 'bruno.falcao@live.com',
        name: 'bruno.falcao',
    );

    $this->get(route('register.show', $user->uuid))
        ->assertSuccessful()
        ->assertSee('Bruno Falcao');
});

it('redirects active users to login with their email', function (): void {
    $user = registrationUser('active');

    $this->get(route('register.show', $user->uuid))
        ->assertRedirect(route('login', ['email' => $user->email]));
});

it('hides disabled or future statuses', function (): void {
    $user = registrationUser('disabled');

    $this->get(route('register.show', $user->uuid))
        ->assertNotFound();
});

it('shows server validation errors through livewire without creating an account', function (): void {
    seedRegistrationCatalog();
    $user = registrationUser('confirmed');

    $component = Livewire::test(RegisterForm::class, ['uuid' => $user->uuid])
        ->set('name', '')
        ->set('password', '')
        ->set('password_confirmation', '')
        ->set('terms', false)
        ->call('continueToCredentials')
        ->assertHasErrors([
            'name' => ['required'],
            'password' => ['required'],
            'terms' => ['accepted'],
        ])
        ->assertSet('step', 'profile');

    $html = $component->html(stripInitialData: true);

    expect(substr_count($html, 'The password field is required.'))->toBe(1)
        ->and($html)->not->toContain('The API key field is required.')
        ->and($html)->not->toContain('mb-5 rounded-lg border border-red-200 bg-red-50');

    expect(Account::count())->toBe(0);
});

it('moves to the credentials step after profile validation passes', function (): void {
    seedRegistrationCatalog();
    $user = registrationUser('confirmed');

    Livewire::test(RegisterForm::class, ['uuid' => $user->uuid])
        ->set('name', 'Bruno Falcao')
        ->set('password', 'correct-password')
        ->set('password_confirmation', 'correct-password')
        ->set('terms', true)
        ->call('continueToCredentials')
        ->assertHasNoErrors()
        ->assertSet('step', 'credentials');
});

it('does not let an already active user move to the credentials step from a stale tab', function (): void {
    seedRegistrationCatalog();
    $user = registrationUser('confirmed');

    $component = Livewire::test(RegisterForm::class, ['uuid' => $user->uuid])
        ->set('name', 'Bruno Falcao')
        ->set('password', 'correct-password')
        ->set('password_confirmation', 'correct-password')
        ->set('terms', true);

    $user->forceFill(['status' => 'active'])->save();

    $component
        ->call('continueToCredentials')
        ->assertRedirect(route('login', ['email' => $user->email]));

    expect(Account::count())->toBe(0);
});

it('requires api credentials on the credentials step', function (): void {
    seedRegistrationCatalog();
    $user = registrationUser('confirmed');

    Livewire::test(RegisterForm::class, ['uuid' => $user->uuid])
        ->set('name', 'Bruno Falcao')
        ->set('password', 'correct-password')
        ->set('password_confirmation', 'correct-password')
        ->set('exchange', 'binance')
        ->set('terms', true)
        ->call('continueToCredentials')
        ->call('register')
        ->assertHasErrors([
            'api_key' => ['required'],
            'api_secret' => ['required'],
        ])
        ->assertNoRedirect();

    expect(Account::count())->toBe(0);
});

it('rejects passwords below the strength threshold', function (): void {
    seedRegistrationCatalog();
    $user = registrationUser('confirmed');

    Livewire::test(RegisterForm::class, ['uuid' => $user->uuid])
        ->set('name', 'Bruno Falcao')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->set('api_key', 'binance-key')
        ->set('api_secret', 'binance-secret')
        ->set('terms', true)
        ->call('register')
        ->assertHasErrors(['password']);

    expect(Account::count())->toBe(0);
});

it('rejects registration exchanges that are visible but coming soon', function (): void {
    [$basic] = seedRegistrationCatalog();
    $user = registrationUser('confirmed');

    Livewire::test(RegisterForm::class, ['uuid' => $user->uuid])
        ->set('name', 'Bruno Falcao')
        ->set('password', 'correct-password')
        ->set('password_confirmation', 'correct-password')
        ->set('exchange', 'bybit')
        ->set('api_key', 'bybit-key')
        ->set('api_secret', 'bybit-secret')
        ->set('subscription_id', $basic->id)
        ->set('terms', true)
        ->call('register')
        ->assertHasErrors(['exchange']);

    expect(Account::count())->toBe(0);
});

it('completes registration through livewire and creates a tradeable account', function (): void {
    [$basic, $binance] = seedRegistrationCatalog();
    $user = registrationUser('confirmed');
    $draftAccount = registrationDraftAccount($user, $binance);
    $blockUuid = registrationConnectivityBlock($user, $draftAccount);

    Livewire::test(RegisterForm::class, ['uuid' => $user->uuid])
        ->set('name', 'Bruno Falcao')
        ->set('password', 'correct-password')
        ->set('password_confirmation', 'correct-password')
        ->set('exchange', 'binance')
        ->set('api_key', 'binance-key')
        ->set('api_secret', 'binance-secret')
        ->set('subscription_id', $basic->id)
        ->set('terms', true)
        ->set('connectivity_test_uuid', $blockUuid)
        ->call('register')
        ->assertHasNoErrors()
        ->assertSet('step', 'confirmation')
        ->assertNoRedirect();

    $this->assertAuthenticated();

    $user->refresh();
    expect($user->name)->toBe('Bruno Falcao')
        ->and($user->status)->toBe('active')
        ->and($user->subscription_id)->toBe($basic->id)
        ->and($user->trial_started_at)->not->toBeNull()
        ->and((bool) $user->can_trade)->toBeTrue();

    $account = Account::firstOrFail();
    expect($account->user_id)->toBe($user->id)
        ->and($account->api_system_id)->toBe($binance->id)
        ->and($account->can_trade)->toBeTrue()
        ->and($account->is_active)->toBeTrue()
        ->and($account->name)->toBe('Binance Account')
        ->and($account->binance_api_key)->toBe('binance-key')
        ->and($account->binance_api_secret)->toBe('binance-secret');

    expect($user->active_account_id)->toBe($account->id);
});

it('requires connectivity to be verified before creating the account', function (): void {
    [$basic] = seedRegistrationCatalog();
    $user = registrationUser('confirmed');

    Livewire::test(RegisterForm::class, ['uuid' => $user->uuid])
        ->set('name', 'Bruno Falcao')
        ->set('password', 'correct-password')
        ->set('password_confirmation', 'correct-password')
        ->set('exchange', 'binance')
        ->set('api_key', 'binance-key')
        ->set('api_secret', 'binance-secret')
        ->set('subscription_id', $basic->id)
        ->set('terms', true)
        ->call('register')
        ->assertHasErrors(['connectivity_verified'])
        ->assertNoRedirect();

    expect(Account::count())->toBe(0);
});

it('can complete registration with trading disabled when required servers fail connectivity', function (): void {
    [$basic, $binance] = seedRegistrationCatalog();
    $user = registrationUser('confirmed');
    $draftAccount = registrationDraftAccount($user, $binance);
    $blockUuid = registrationConnectivityBlock($user, $draftAccount, allConnected: false);

    Livewire::test(RegisterForm::class, ['uuid' => $user->uuid])
        ->set('name', 'Bruno Falcao')
        ->set('password', 'correct-password')
        ->set('password_confirmation', 'correct-password')
        ->set('exchange', 'binance')
        ->set('api_key', 'binance-key')
        ->set('api_secret', 'binance-secret')
        ->set('subscription_id', $basic->id)
        ->set('terms', true)
        ->set('continue_without_connectivity', true)
        ->set('connectivity_test_uuid', $blockUuid)
        ->call('register')
        ->assertHasNoErrors()
        ->assertSet('step', 'confirmation')
        ->assertSet('connectivity_passed', false)
        ->assertNoRedirect();

    $this->assertAuthenticated();

    $user->refresh();
    expect($user->status)->toBe('active')
        ->and((bool) $user->can_trade)->toBeTrue()
        ->and($user->current_connectivity_test_uuid)->toBeNull();

    $account = Account::firstOrFail();
    expect($account->user_id)->toBe($user->id)
        ->and($account->can_trade)->toBeFalse()
        ->and($account->is_active)->toBeTrue()
        ->and($account->disabled_reason)->toBe('Registration connectivity test failed on one or more required servers.')
        ->and($account->disabled_at)->not->toBeNull()
        ->and($account->binance_api_key)->toBe('binance-key')
        ->and($account->binance_api_secret)->toBe('binance-secret');

    expect($user->active_account_id)->toBe($account->id);
});

it('requires an explicit choice before completing with failed connectivity', function (): void {
    [$basic, $binance] = seedRegistrationCatalog();
    $user = registrationUser('confirmed');
    $draftAccount = registrationDraftAccount($user, $binance);
    $blockUuid = registrationConnectivityBlock($user, $draftAccount, allConnected: false);

    Livewire::test(RegisterForm::class, ['uuid' => $user->uuid])
        ->set('name', 'Bruno Falcao')
        ->set('password', 'correct-password')
        ->set('password_confirmation', 'correct-password')
        ->set('exchange', 'binance')
        ->set('api_key', 'binance-key')
        ->set('api_secret', 'binance-secret')
        ->set('subscription_id', $basic->id)
        ->set('terms', true)
        ->set('connectivity_test_uuid', $blockUuid)
        ->call('register')
        ->assertHasErrors(['connectivity_verified'])
        ->assertSee('Some servers could not connect. You can still create the account but the trading will be disabled.')
        ->assertSet('step', 'profile')
        ->assertNoRedirect();

    $user->refresh();

    expect($user->status)->toBe('confirmed');
    expect($draftAccount->refresh()->is_active)->toBeFalse();
});

it('does not complete registration for an already active user from a stale tab', function (): void {
    [$basic] = seedRegistrationCatalog();
    $user = registrationUser('confirmed');

    $component = Livewire::test(RegisterForm::class, ['uuid' => $user->uuid])
        ->set('name', 'Bruno Falcao')
        ->set('password', 'correct-password')
        ->set('password_confirmation', 'correct-password')
        ->set('exchange', 'binance')
        ->set('api_key', 'binance-key')
        ->set('api_secret', 'binance-secret')
        ->set('subscription_id', $basic->id)
        ->set('terms', true)
        ->set('connectivity_verified', true);

    $user->forceFill(['status' => 'active'])->save();

    $component
        ->call('register')
        ->assertRedirect(route('login', ['email' => $user->email]));

    expect(Account::count())->toBe(0);
});

it('tests registration exchange connectivity with the entered credentials', function (): void {
    seedRegistrationCatalog();
    $user = registrationUser('confirmed');

    $response = $this->postJson(route('register.connectivity', $user->uuid), [
        'exchange' => 'binance',
        'api_key' => 'binance-key',
        'api_secret' => 'binance-secret',
    ]);

    $response
        ->assertOk()
        ->assertJsonStructure(['block_uuid', 'is_complete', 'all_connected', 'servers'])
        ->assertJson([
            'is_complete' => false,
            'all_connected' => false,
            'total_servers' => 2,
        ]);

    $user->refresh();
    $account = Account::firstOrFail();

    expect($user->current_connectivity_test_uuid)->toBe($response->json('block_uuid'))
        ->and($account->user_id)->toBe($user->id)
        ->and($account->name)->toBe('Registration connectivity test')
        ->and($account->can_trade)->toBeFalse()
        ->and($account->is_active)->toBeFalse()
        ->and($account->binance_api_key)->toBe('binance-key')
        ->and($account->binance_api_secret)->toBe('binance-secret');
});

it('reports registration connectivity workflow status', function (): void {
    [, $binance] = seedRegistrationCatalog();
    $user = registrationUser('confirmed');
    $draftAccount = registrationDraftAccount($user, $binance);
    $blockUuid = registrationConnectivityBlock($user, $draftAccount);

    $this->getJson(route('register.connectivity.status', [$user->uuid, $blockUuid]))
        ->assertOk()
        ->assertJson([
            'is_complete' => true,
            'all_connected' => true,
            'connected_servers' => 2,
            'failed_servers' => 0,
        ]);
});

it('keeps registration connectivity status polling on a dedicated throttle', function (): void {
    $middleware = collect(Route::getRoutes()->getByName('register.connectivity.status')?->gatherMiddleware());

    expect($middleware)->toContain('throttle:60,1')
        ->and($middleware)->not->toContain('throttle:10,1');
});

it('returns a validation error when connectivity credentials are incomplete', function (): void {
    $user = registrationUser('confirmed');

    $this->postJson(route('register.connectivity', $user->uuid), [
        'exchange' => 'bitget',
        'api_key' => 'bitget-key',
        'api_secret' => 'bitget-secret',
    ])->assertInvalid(['passphrase']);
});

it('rejects connectivity checks for coming soon exchanges', function (): void {
    $user = registrationUser('confirmed');

    $this->postJson(route('register.connectivity', $user->uuid), [
        'exchange' => 'kucoin',
        'api_key' => 'kucoin-key',
        'api_secret' => 'kucoin-secret',
        'passphrase' => 'kucoin-passphrase',
    ])->assertInvalid(['exchange']);
});

it('hides connectivity checks for users that are not ready to register', function (): void {
    $user = registrationUser('pending');

    $this->postJson(route('register.connectivity', $user->uuid), [
        'exchange' => 'binance',
        'api_key' => 'binance-key',
        'api_secret' => 'binance-secret',
    ])->assertNotFound();
});

it('hides connectivity checks for active users', function (): void {
    $user = registrationUser('active');

    $this->postJson(route('register.connectivity', $user->uuid), [
        'exchange' => 'binance',
        'api_key' => 'binance-key',
        'api_secret' => 'binance-secret',
    ])->assertNotFound();
});

it('returns failed connectivity workflow status', function (): void {
    [, $binance] = seedRegistrationCatalog();
    $user = registrationUser('confirmed');
    $draftAccount = registrationDraftAccount($user, $binance);
    $blockUuid = registrationConnectivityBlock($user, $draftAccount, allConnected: false);

    $this->getJson(route('register.connectivity.status', [$user->uuid, $blockUuid]))
        ->assertOk()
        ->assertJson([
            'is_complete' => true,
            'all_connected' => false,
            'failed_servers' => 1,
        ]);
});
