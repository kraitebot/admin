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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- laravel/ai (AI) - v0
- laravel/framework (LARAVEL) - v12
- laravel/horizon (HORIZON) - v5
- laravel/mcp (MCP) - v0
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/breeze (BREEZE) - v2
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- alpinejs (ALPINEJS) - v3
- tailwindcss (TAILWINDCSS) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== ai/core rules ===

## Laravel AI SDK

- This application uses the Laravel AI SDK (`laravel/ai`) for all AI functionality.
- Activate the `developing-with-ai-sdk` skill when building, editing, updating, debugging, or testing AI agents, text generation, chat, streaming, structured output, tools, image generation, audio, transcription, embeddings, reranking, vector stores, files, conversation memory, or any AI provider integration (OpenAI, Anthropic, Gemini, Cohere, Groq, xAI, ElevenLabs, Jina, OpenRouter).

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
