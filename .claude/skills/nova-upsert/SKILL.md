# /nova-upsert — Create or Update a Nova Resource

Create or update a fully configured Nova resource for a given Eloquent model. If the resource already exists, re-run all verifications and update it to match the current model state — it was likely scaffolded earlier but not fully configured with the latest changes.

## Arguments

The user provides a model class name (e.g. `Account`, `Position`, `User`).

## Instructions

When the user runs `/nova-upsert <ModelName>`, follow these steps **in order**:

### Step 0: Refresh Nova Knowledge

**Mandatory.** Re-read the project's `CLAUDE.md` file at the project root before doing anything else. This ensures you have the latest Nova conventions and project guidelines loaded.

### Step 1: Locate and Analyze the Model

1. Find the model class in `kraitebot/core` (`/home/waygou/packages/kraitebot/core/src/Models/`). If it doesn't exist, **stop and tell Bruno**.
2. Read the model file completely. Extract:
   - All `$fillable` / `$guarded` properties
   - All `$casts` / `casts()` definitions
   - All relationship methods (`belongsTo`, `hasMany`, `hasOne`, `belongsToMany`, `morphTo`, etc.)
   - Any accessors, scopes, or state configurations
   - The database table name (explicit `$table` or convention)

### Step 2: Inspect the Database Table

Use the `database-schema` tool with `include_column_details: true` to get the full table schema including column types, nullability, defaults, indexes, and **column comments**. Read the MySQL column comments/descriptions to understand what each column is used for. Cross-reference with the model to understand every field.

### Step 3: Audit Existing Nova Resources

1. List all existing Nova resources in `app/Nova/`.
2. For each relationship on the model, check if the related model already has a Nova resource.
3. Build two lists:
   - **Available relationships** — related model has a Nova resource (use `BelongsTo`, `HasMany`, etc.)
   - **Missing resources** — related model does NOT have a Nova resource yet (skip these relationships for now, alert Bruno at the end)

### Step 4: Create or Update the Nova Resource

**Check if `app/Nova/<ModelName>.php` already exists.**

- **If it does NOT exist**: Run `php artisan nova:resource <ModelName> --no-interaction` to scaffold it, then fully configure it.
- **If it ALREADY exists**: Read the existing resource file, then update it to reflect the current model state. The resource was likely created before but not fully updated with the latest model changes (new fields, new relationships, changed casts, etc.). Re-apply all configuration rules below, preserving any intentional customizations Bruno may have added (custom methods, actions, etc.).

Configuration rules for both create and update:

1. **Model import**: Set `$model` to the full `Kraite\Core\Models\<ModelName>` namespace.
2. **`$title`**: Choose the most meaningful display column.
3. **`subtitle()`**: Add if there's useful secondary context.
4. **`fields()`**: Configure all fields following these rules:
   - `App\Nova\Fields\ID::make()` first (custom field, hidden from index by default), timestamps last. Timestamps must use `->onlyOnDetail()` — never show on index.
   - Use the most specific field type (`Boolean`, `Currency`, `Badge`, `Select`, `Number`, `Textarea`, etc.).
   - For datetime columns, use `App\Nova\Fields\HumanDateTime` instead of `DateTime` — it displays as human-readable diff (e.g. "2 days ago"). This is the default for all datetime fields unless Bruno says otherwise.
   - Add `->sortable()` on columns that make sense for sorting.
   - Add `->filterable()` on columns commonly used for filtering.
   - Add `->rules()` for validation on each field.
   - Add `->readonly()` or `->hideWhenCreating()` / `->hideWhenUpdating()` for system/computed fields.
   - Use `Panel` to group related fields visually when there are 6+ fields.
   - Relationship fields: only add for **available relationships** (Step 3). Use `->searchable()` on `BelongsTo` fields with potentially large datasets.
   - JSON/array columns: use `KeyValue` or `Code` field as appropriate.
   - Enum columns: use `Select` with the enum values.
5. **Index view limit**: The index view should display **no more than 5 columns** (excluding ID which is already hidden). Choose the most meaningful columns for quick scanning — typically name/title, status, key relationship, and 1-2 other high-value fields. Everything else should use `->onlyOnDetail()`.
6. **`$with`**: Eager-load relationships that appear on the index view.
6. **`$search`**: Include searchable text columns.

### Step 4.1: Sync Inverse Relationships on Existing Resources

After configuring relationship fields on the new/updated resource, check the **other side** of each relationship. For every relationship field you added (e.g. `BelongsTo User`), open the corresponding Nova resource (e.g. `app/Nova/User.php`) and verify it has the inverse relationship field (e.g. `HasMany Account`). If the inverse is missing, **add it** to that resource. This ensures both sides of every relationship are always wired in Nova.

### Step 5: Propose Enhancements

Based on the model analysis, **propose** (don't auto-create) any of these if they'd add value:

- **Filters**: For status columns, boolean flags, date ranges, or relationship-based filtering beyond what `->filterable()` provides.
- **Lenses**: For useful alternative views (e.g. "Active Only", "Recently Created", "Flagged Items").
- **Actions**: For common operations on this model (e.g. activate/deactivate, sync, export).
- **Metrics**: For dashboard cards (trends, values, partitions) if the model has countable/measurable data.

Present these as a concise list. Bruno decides what to implement.

### Step 5.1: Register in Nova Sidebar

If this is a **new** resource (not an update), add it to the Nova sidebar menu in `app/Providers/NovaServiceProvider.php`. Read the `mainMenu` closure and add a `MenuItem::resource()` entry in the appropriate `MenuSection`. If no existing section fits, create a new one. Don't forget this step — resources not in the menu won't appear in the sidebar.

### Step 6: Validate

1. Run `php artisan nova:check` (if available) or clear caches: `php artisan optimize:clear`.
2. Use the `get-absolute-url` tool to get the Nova resource URL and verify it returns 200:
   ```
   curl -s -o /dev/null -w "%{http_code}" <url>/nova/resources/<resource-uri>
   ```
3. If there are errors, read the last error with the `last-error` tool and fix them.

### Step 7: Run Pint

Run `vendor/bin/pint --dirty --format agent` to format the new/modified files.

### Step 8: Report

Output a brief summary:
- Resource created/updated at `app/Nova/<ModelName>.php`
- Fields configured (count)
- Relationships wired (list)
- **Missing Nova resources** (alert!) — list related models that still need `/nova-upsert`
- Enhancement proposals (if any)

## Rules

- `declare(strict_types=1);` on all PHP files.
- Never add business logic to Nova resources — keep them thin.
- **No business logic, custom accessors, or computed behavior** — just map database columns directly to Nova fields. Business logic will be added later when Bruno asks for it.
- Follow existing resource patterns if other resources already exist in `app/Nova/`.
- Use the `search-docs` tool with `packages: ["laravel/nova"]` to consult Nova 5.x documentation for field types, best practices, and features before making decisions.
- All subagents must use `model: "haiku"`.
