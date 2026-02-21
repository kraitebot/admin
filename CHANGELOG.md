# Changelog

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
