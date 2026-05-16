<?php

declare(strict_types=1);

use App\Livewire\RegisterForm;
use App\Support\Registration\RegistrationConnectivityResult;
use App\Support\Registration\RegistrationConnectivityTester;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\TradeConfiguration;
use Kraite\Core\Models\User;
use Livewire\Livewire;

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

    return [$basic, $binance];
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
        ->assertSee('Add API Keys')
        ->assertSee('Coming soon')
        ->assertSee('href="https://kraite.test/terms-and-conditions"', false)
        ->assertSee('wire:submit="register"', false)
        ->assertSee('novalidate', false)
        ->assertSee('data-api-keys-modal', false)
        ->assertSee('Since you registered for private beta');
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
        ->set('api_key', '')
        ->set('api_secret', '')
        ->set('terms', false)
        ->call('register')
        ->assertHasErrors([
            'name' => ['required'],
            'password' => ['required'],
            'api_key' => ['required'],
            'api_secret' => ['required'],
            'terms' => ['accepted'],
        ]);

    $html = $component->html(stripInitialData: true);

    expect(substr_count($html, 'The password field is required.'))->toBe(1)
        ->and(substr_count($html, 'The API key field is required.'))->toBe(1)
        ->and($html)->not->toContain('mb-5 rounded-lg border border-red-200 bg-red-50');

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
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $this->assertTrue(session()->has('status'));

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
        ->and($account->binance_api_key)->toBe('binance-key')
        ->and($account->binance_api_secret)->toBe('binance-secret');

    expect($user->active_account_id)->toBe($account->id);
});

it('tests registration exchange connectivity with the entered credentials', function (): void {
    $user = registrationUser('confirmed');

    $this->mock(RegistrationConnectivityTester::class)
        ->shouldReceive('test')
        ->once()
        ->with('binance', 'binance-key', 'binance-secret', null)
        ->andReturn(new RegistrationConnectivityResult(
            connected: true,
            message: 'Connectivity verified, all good!',
            ordersCount: 0,
        ));

    $this->postJson(route('register.connectivity', $user->uuid), [
        'exchange' => 'binance',
        'api_key' => 'binance-key',
        'api_secret' => 'binance-secret',
    ])
        ->assertOk()
        ->assertJson([
            'connected' => true,
            'message' => 'Connectivity verified, all good!',
            'orders_count' => 0,
        ]);
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

it('returns failed connectivity results without creating an account', function (): void {
    $user = registrationUser('confirmed');

    $this->mock(RegistrationConnectivityTester::class)
        ->shouldReceive('test')
        ->once()
        ->andReturn(new RegistrationConnectivityResult(
            connected: false,
            message: 'Credentials rejected or this server IP is not whitelisted.',
        ));

    $this->postJson(route('register.connectivity', $user->uuid), [
        'exchange' => 'binance',
        'api_key' => 'bad-key',
        'api_secret' => 'bad-secret',
    ])
        ->assertUnprocessable()
        ->assertJson([
            'connected' => false,
            'message' => 'Credentials rejected or this server IP is not whitelisted.',
        ]);

    expect(Account::count())->toBe(0);
});
