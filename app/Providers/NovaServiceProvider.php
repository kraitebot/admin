<?php

namespace App\Providers;

use App\Nova\Dashboards\Main;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Kraite\Core\Models\User;
use Laravel\Fortify\Features;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaApplicationServiceProvider;

class NovaServiceProvider extends NovaApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        Nova::withoutThemeSwitcher();
        Nova::script('nova-custom', resource_path('js/nova.js'));
        Nova::style('nova-custom', resource_path('css/nova.css'));

        Nova::mainMenu(function (Request $request) {
            return [
                MenuSection::dashboard(Main::class)->icon('chart-bar'),

                MenuSection::make('Trading', [
                    MenuItem::resource(\App\Nova\Position::class),
                    MenuItem::resource(\App\Nova\Order::class),
                ])->icon('currency-dollar')->collapsable(),

                MenuSection::make('Accounts', [
                    MenuItem::resource(\App\Nova\Account::class),
                    MenuItem::resource(\App\Nova\User::class),
                ])->icon('user-group')->collapsable(),

                MenuSection::make('Logs', [
                    MenuItem::resource(\App\Nova\AppLog::class),
                    MenuItem::resource(\App\Nova\ModelLog::class),
                    MenuItem::resource(\App\Nova\ApiRequestLog::class),
                    MenuItem::resource(\App\Nova\Step::class),
                ])->icon('document-text')->collapsable(),

                MenuSection::make('Command Runner')
                    ->path('/command-runner')
                    ->icon('command-line'),
            ];
        });
    }

    /**
     * Register the configurations for Laravel Fortify.
     */
    protected function fortify(): void
    {
        Nova::fortify()
            ->features([
                Features::updatePasswords(),
                // Features::emailVerification(),
                // Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
            ])
            ->register();
    }

    /**
     * Register the Nova routes.
     */
    protected function routes(): void
    {
        Nova::routes()
            ->withAuthenticationRoutes(default: true)
            ->withPasswordResetRoutes()
            ->withoutEmailVerificationRoutes()
            ->register();
    }

    /**
     * Register the Nova gate.
     *
     * This gate determines who can access Nova in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewNova', function (User $user) {
            return (bool) $user->is_admin;
        });
    }

    /**
     * Get the dashboards that should be listed in the Nova sidebar.
     *
     * @return array<int, \Laravel\Nova\Dashboard>
     */
    protected function dashboards(): array
    {
        return [
            new \App\Nova\Dashboards\Main,
        ];
    }

    /**
     * Get the tools that should be listed in the Nova sidebar.
     *
     * @return array<int, \Laravel\Nova\Tool>
     */
    public function tools(): array
    {
        return [
            new \Kraite\CommandRunner\CommandRunner,
        ];
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();

        //
    }
}
