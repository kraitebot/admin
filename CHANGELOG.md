# Changelog

## 1.1.3 - 2026-02-21

### Features

- [NEW FEATURE] ApiSystem, ExchangeSymbol, and Symbol Nova resources
- [NEW FEATURE] MorphTo field on ApiRequestLog and ModelLog replacing raw relatable type/id fields
- [NEW FEATURE] 7 custom filters for ApiRequestLog: HttpMethod, HttpResponseCode, RelatableType, RelatableModel, Hostname, HasResponse, HasErrorMessage
- [NEW FEATURE] ApiSystemFilter — dropdown of distinct API systems by name for filtering by api_system_id
- [NEW FEATURE] "Requests with errors" lens on ApiRequestLog
- [NEW FEATURE] API Errors by Exchange partition metric (last hour)
- [NEW FEATURE] CommandRunner nova-component with DependentSelectFilter Vue component
- [NEW FEATURE] BelongsTo API System field on ApiRequestLog index

### Improvements

- [IMPROVED] ApiRequestLog made read-only (no create, edit, delete, replicate)
- [IMPROVED] Truncated Response and Error Message fields on index for quick scanning
- [IMPROVED] Nova sidebar font size increased to 17px, filter popup height to 600px
- [IMPROVED] Duration field moved to detail-only on ApiRequestLog

## 1.1.2 - 2026-02-21

### Features

- [NEW FEATURE] AppLog, ModelLog, Step, and ApiRequestLog Nova resources with full field configuration
- [NEW FEATURE] Nova sidebar "Logs" section with all log/step resources

## 1.1.1 - 2026-02-21

### Features

- [NEW FEATURE] Account, Order, and Position Nova resources
- [NEW FEATURE] Nova metrics: NewUsers, ActivePositions, AccountsBalance, OrdersPerDay

### Dependencies

- [DEPENDENCIES] Sync kraitebot/core with centralized artisan commands

## 1.1.0 - 2026-02-21

### Features

- [NEW FEATURE] Custom `App\Nova\Fields\ID` field — hidden from index by default
- [NEW FEATURE] Custom `App\Nova\Fields\HumanDateTime` field — Carbon diffForHumans display
- [NEW FEATURE] Kraite-themed Nova with green branding, SVG logo, and forced dark mode
- [NEW FEATURE] Fully configured User Nova resource with panels, relationships, and validation
- [NEW FEATURE] Shared `.env.kraite` environment file integration via `KRAITE_ENV_PATH`

### Improvements

- [IMPROVED] Unified APP_KEY across all Kraite apps for encryption consistency
- [IMPROVED] Removed `App\Models\User` — all apps use `Kraite\Core\Models\User`
- [IMPROVED] Nova domain hardened to `admin.kraite.com`
- [IMPROVED] CLAUDE.md updated with custom field conventions and nova-upsert skill

## 1.0.0 - 2026-02-21

### Features
- [NEW FEATURE] Fresh Laravel 12 project with Nova 5 admin panel
- [NEW FEATURE] Nova gate restricted to admin users (`is_admin = 1`)
- [NEW FEATURE] Integration with `kraitebot/core` via local path symlink

### Improvements
- [IMPROVED] Default Laravel migrations restored for users, cache, and jobs tables
- [IMPROVED] Project-scoped CLAUDE.md with Nova resource guidelines
