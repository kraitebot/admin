<?php

declare(strict_types=1);

namespace App\Nova;

use App\Nova\Fields\HumanDateTime;
use App\Nova\Fields\ID;
use Kraite\Core\Models\User as UserModel;
use Laravel\Nova\Auth\PasswordValidationRules;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Gravatar;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class User extends Resource
{
    use PasswordValidationRules;

    /**
     * The model the resource corresponds to.
     *
     * @var class-string<UserModel>
     */
    public static $model = UserModel::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    /**
     * The columns that should be searched.
     *
     * @var array<int, string>
     */
    public static $search = [
        'id', 'name', 'email',
    ];

    /**
     * The relationships that should be eager loaded on index queries.
     *
     * @var array<int, string>
     */
    public static $with = ['subscription'];

    /**
     * Get the displayable subtitle of the resource.
     */
    public function subtitle(): ?string
    {
        return $this->email;
    }

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field|\Laravel\Nova\Panel>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Gravatar::make()->maxWidth(50),

            Text::make('Name')
                ->sortable()
                ->rules('required', 'max:255'),

            Text::make('Email')
                ->sortable()
                ->rules('required', 'email', 'max:254')
                ->creationRules('unique:users,email')
                ->updateRules('unique:users,email,{{resourceId}}'),

            Password::make('Password')
                ->onlyOnForms()
                ->creationRules($this->passwordRules())
                ->updateRules($this->optionalPasswordRules()),

            Panel::make('Status & Permissions', [
                Boolean::make('Is Active', 'is_active')
                    ->sortable()
                    ->filterable(),

                Boolean::make('Is Admin', 'is_admin')
                    ->sortable()
                    ->filterable(),

                Boolean::make('Can Trade', 'can_trade')
                    ->sortable()
                    ->filterable()
                    ->onlyOnDetail(),

                Boolean::make('Distinct Position Tokens', 'have_distinct_position_tokens_on_all_accounts')
                    ->help('Prevents opening active positions with the same token across different accounts')
                    ->onlyOnDetail(),
            ]),

            Panel::make('Notifications', [
                Text::make('Pushover Key', 'pushover_key')
                    ->onlyOnForms()
                    ->nullable(),

                Code::make('Notification Channels', 'notification_channels')
                    ->json()
                    ->onlyOnDetail()
                    ->nullable(),
            ]),

            Panel::make('Configuration', [
                Code::make('Behaviours', 'behaviours')
                    ->json()
                    ->onlyOnDetail()
                    ->nullable(),
            ]),

            HasMany::make('Accounts'),

            Panel::make('Activity', [
                HumanDateTime::make('Last Logged In', 'last_logged_in_at')
                    ->sortable()
                    ->onlyOnDetail(),

                HumanDateTime::make('Previous Logged In', 'previous_logged_in_at')
                    ->onlyOnDetail(),

                HumanDateTime::make('Email Verified At', 'email_verified_at')
                    ->onlyOnDetail(),

                HumanDateTime::make('Created At')
                    ->sortable()
                    ->onlyOnDetail(),

                HumanDateTime::make('Updated At')
                    ->onlyOnDetail(),
            ]),
        ];
    }

    /**
     * Get the cards available for the request.
     *
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array<int, \Laravel\Nova\Filters\Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @return array<int, \Laravel\Nova\Lenses\Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @return array<int, \Laravel\Nova\Actions\Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [];
    }
}
