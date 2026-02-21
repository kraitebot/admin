# Kraite Admin — Laravel Nova Project

## Stack
- Laravel 12 + Nova 5 + PHP 8.4
- MySQL (kraite database)
- `kraitebot/core` symlinked from `/home/waygou/packages/kraitebot/core`
- Domain: `https://admin.kraite.com`

## Nova Resources

### Creating Resources
- `php artisan nova:resource ModelName` — always use artisan, never create manually.
- Place resources in `app/Nova/`. One resource per Eloquent model.
- Models live in `kraitebot/core` (`Kraite\Core\Models\*`). Import them, don't duplicate.

### Resource Structure
- `$model` property: always set explicitly with full namespace.
- `title()`: return a meaningful display column (e.g. `name`, `email`, `symbol`).
- `subtitle()`: use for secondary context when helpful.
- `fields()`: group logically — ID first, timestamps last. Use `->sortable()` on filterable columns.
- Use `Panel` to group related fields visually.
- Use `->rules()` for validation directly on fields — no separate Form Requests in Nova.
- Use `->help()` sparingly for non-obvious fields.

### Field Best Practices
- Use the most specific field type: `Currency`, `Boolean`, `DateTime`, `Badge`, `Select`, etc.
- `BelongsTo` / `HasMany` / `BelongsToMany` for relationships — never raw ID fields.
- `->searchable()` on BelongsTo fields with large datasets.
- `->filterable()` on fields commonly used for filtering.
- `->readonly()` or `->hideWhenCreating()` / `->hideWhenUpdating()` for computed or system fields.
- `->displayUsing()` for formatted output without changing stored values.

### Actions, Filters, Lenses
- Actions: for bulk or single-record operations. Use `->confirmText()` for destructive actions.
- Filters: prefer `->filterable()` on fields over custom filter classes unless logic is complex.
- Lenses: for alternative queries on the same resource (e.g. "Active Positions", "Expired Subscriptions").

### Authorization
- Nova gate (`viewNova`): restricted to `is_admin = 1` users.
- Use Nova policies when per-resource authorization is needed. Follow Laravel policy conventions.

### Performance
- Define `$with` on resources for eager-loaded relationships shown in index.
- Use `->onlyOnDetail()` / `->onlyOnIndex()` to avoid loading heavy fields everywhere.
- Implement `indexQuery()` / `detailQuery()` for custom scoping when needed.

## Conventions
- All PHP files: `declare(strict_types=1);`, typed properties, return types.
- Resource naming: singular, matching model name (e.g. `User`, `Account`, `Position`).
- Keep resources thin — business logic stays in `kraitebot/core` service classes.
