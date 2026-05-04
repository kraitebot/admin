# CAVEMAN MODE — MANDATORY

**ALWAYS invoke the `/caveman` skill at the start of EVERY response. No exceptions.** Applies to ALL output: brainstorms, planning, reviews, debugging, explanations, status updates, Telegram replies. Exceptions (stay normal English): code bodies, git commit messages, PR descriptions.

---

# Admin Kraite — Project Instructions

## Architecture

This is a Laravel admin panel (`admin.kraite.com`) sharing a database with `ingestion.kraite.com` and `kraite.com`. All three apps MUST use the same `APP_KEY`.

## NO MIGRATIONS HERE — HARD RULE

**Never create or place migration files under `database/migrations/` in this repo.** Admin is a UI consumer, not a schema owner. ALL schema changes (CREATE TABLE, ALTER TABLE, DROP TABLE, seeders) belong in `kraitebot/core` at `/home/waygou/packages/kraitebot/core/database/migrations/`.

The three apps share ONE `kraite` database with ONE `migrations` tracking table. Two repos issuing migrations against the same DB is a guaranteed silent-collision trap — happened once on 2026-05-04 when a stale orphan drop migration in admin destroyed a `binance_listen_keys` table that core had created weeks later.

If a schema change is needed: route it through `kraitebot/core`. The only files allowed under `database/migrations/` in this repo are the Laravel scaffolding defaults (`create_users_table`, `create_cache_table`, `create_jobs_table`). Same rule applies to `database/seeders/` — seeders belong in core.

### Key Packages
- **`brunocfalcao/hub-ui`** — UI component library at `/home/waygou/packages/brunocfalcao/hub-ui/`. Provides layout, sidebar, theme system, and reusable Blade components.
- **`kraitebot/core`** — Trading system core at `/home/waygou/packages/kraitebot/core/`. Models, jobs, commands, step-dispatcher integration.
- **`brunocfalcao/step-dispatcher`** — Step-based job orchestration at `/home/waygou/packages/brunocfalcao/step-dispatcher/`.

### Layout
- App layout: `<x-app-layout>` wraps `<x-hub-ui::layouts.dashboard>` with sidebar
- Sidebar sections defined in `resources/views/layouts/app.blade.php`
- All system pages live under `/system/*` routes

## Hub-UI Components — USE THEM

Before building any UI, check if a hub-ui component exists. Never hand-roll what the library already provides.

Available components (use as `<x-hub-ui::component-name>`):
- **data-table** — Tables with `head`/`foot` slots, size variants (sm/md/lg), CSS-driven cell styling
- **button** — Variants: primary, secondary, danger, ghost, link. Sizes: sm, md, lg. Loading state.
- **card** — Title, subtitle, padding, footer
- **badge** — Types: default, primary, success, warning, danger, info, online, offline, pending
- **status** — Colored dot + label, animated option
- **alert** — Types: info, success, warning, error. Dismissible.
- **modal** — Alpine-driven, focus trap, named modals
- **modal-confirmation** — JS-driven via `window.showConfirmation()`
- **spinner** — Sizes: xs, sm, md, lg, xl
- **empty-state** — Icon slot, title, description, action
- **page-header** — Title + description
- **input, select, textarea, checkbox** — Form components with error/hint/notice
- **dropdown, dropdown-link** — Alpine-driven dropdown
- **toast** — Via `window.showToast(message, type, duration)`

## Theme & Color System

### CSS Variables (space-separated RGB, set by hub-ui)
- Semantic: `--ui-primary`, `--ui-success`, `--ui-warning`, `--ui-danger`, `--ui-info` (+ `-hover`, `-soft`)
- Surfaces: `--ui-bg-body`, `--ui-bg-sidebar`, `--ui-bg-card`, `--ui-bg-input`, `--ui-bg-elevated`
- Borders: `--ui-border`, `--ui-border-light`
- Text: `--ui-text`, `--ui-text-muted`, `--ui-text-subtle`

### Utility Classes
- `.ui-text`, `.ui-text-muted`, `.ui-text-subtle`, `.ui-text-primary`, `.ui-text-danger`, etc.
- `.ui-bg-body`, `.ui-bg-elevated`, `.ui-bg-card`, `.ui-bg-primary`, etc.
- `.ui-border`, `.ui-border-light`
- `.ui-btn .ui-btn-primary .ui-btn-sm` (button classes)
- `.ui-table` (table styling: colors, borders, hover)
- `.ui-data-table` (sizing: padding, font-size, uppercase headers via CSS)
- `.ui-input` (form input styling)

### Rules
- Use `ui-*` utility classes, not hardcoded Tailwind colors for theme-aware elements
- Use `style="color: rgb(var(--ui-primary))"` for inline semantic colors
- `window.hubUiFetch(url, options)` for AJAX — handles CSRF, JSON, returns `{ ok, data }`
- `window.showToast(message, type, duration)` for notifications

## Commands
All custom artisan commands use the `kraite:` prefix. Sub-groups: `kraite:cron-*`, `kraite:debug-*`, `kraite:ingestion-*`.

## No Technical Debt
- Use existing hub-ui components instead of inline HTML
- Follow existing patterns — check how similar pages are built before creating new ones
- No orphan CSS, no one-off styling, no inline styles that should be classes
- `npm run build` after ANY frontend change

## Telegram Replies

Only send replies via the Telegram `reply` tool when the incoming message originated from Telegram — i.e. it arrived inside a `<channel source="telegram" ...>` block. If Bruno is typing in the CLI directly, just respond in the terminal. Do not mirror terminal responses to Telegram.
