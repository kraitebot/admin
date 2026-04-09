# Ralph Task Queue

Tasks for Ralph to execute autonomously.

---

## 1. SQL Query: Truncate & Reset buttons

**Priority:** High

**Description:** Add Truncate and Reset buttons to the SQL Query page when browsing a table.

**Implementation Notes:**

### 1. Add Truncate button to SQL Query page
- Location: `resources/views/system/sql-query.blade.php`, on the same line as the "Run Query" button, right-aligned
- Only visible when: `baseQuery` matches `SELECT * FROM <tablename>` pattern (use regex: `/^SELECT \* FROM (\w+)$/i`) AND `results` exist and `results.length > 0`
- Extract the table name from `baseQuery` using the regex match
- On click: trigger `window.showConfirmation()` — the component already exists at `/home/waygou/packages/brunocfalcao/hub-ui/resources/views/components/modal-confirmation.blade.php` and already has backdrop blur. Call it with:
  ```js
  window.showConfirmation({
      title: 'Truncate Table',
      message: 'Are you sure you want to truncate "' + tableName + '"? All data will be permanently deleted.',
      confirmText: 'Truncate',
      type: 'danger',
      onConfirm: () => this.truncateTable(tableName)
  });
  ```
- After truncate succeeds: auto-run the base query again (table shows empty result), show `window.showToast('Table truncated successfully', 'success')`
- On error: show `window.showToast(errorMessage, 'error')`
- Button style: `ui-btn ui-btn-danger ui-btn-sm` (red, matches the destructive action)

### 2. New Truncate backend endpoint
- Add method `truncate()` to `app/Http/Controllers/System/SqlQueryController.php`
- Route: `POST /system/sql-query/truncate` — Name: `system.sql-query.truncate`
- Add route to `routes/web.php` inside the auth middleware group, under the SQL Query section
- Request validation: `{ table: 'required|string' }`
- Before executing: validate the table actually exists in the database using `Schema::hasTable($table)` or querying `information_schema.TABLES`
- Execute: `DB::statement('TRUNCATE TABLE ' . $table)` — use backtick-escaped table name to prevent SQL injection: `` DB::statement('TRUNCATE TABLE `' . str_replace('`', '', $table) . '`') ``
- Return JSON: `{ success: true }` on success, or `{ error: 'message' }` with 422 on failure

### 3. Add Reset button to SQL Query page
- Same line, between "Run Query" and "Truncate"
- Same visibility conditions as Truncate
- Button style: `ui-btn ui-btn-ghost ui-btn-sm` (subtle, non-destructive)
- Label: "Reset" with a refresh icon
- On click: resets `query` back to `baseQuery` (which is `SELECT * FROM <tablename>`), clears `columnFilters`, sets `page = 1`, calls `fetchResults()`
- No confirmation needed — it's just re-running the base select

### 4. Button layout
- Current layout: `<div class="flex items-center gap-3">` contains Run Query button + "Ctrl+Enter" hint
- Change to: `<div class="flex items-center justify-between">` with Run Query + hint on the left, Reset + Truncate on the right
- Both new buttons wrapped in a `<div class="flex items-center gap-2">` with `x-show="isBrowsingTable && results && results.length > 0"`
- Add computed property `isBrowsingTable` that tests `baseQuery` against the regex
- Add computed property `browsedTableName` that extracts the table name

### Files to Modify
- `app/Http/Controllers/System/SqlQueryController.php` (add `truncate()` method)
- `routes/web.php` (add truncate route)
- `resources/views/system/sql-query.blade.php` (add buttons, JS methods, computed properties)

### Acceptance Criteria
- [ ] Truncate button only shows when browsing a table (`SELECT * FROM tablename`) with results
- [ ] Reset button only shows under same conditions
- [ ] Truncate button triggers the existing `window.showConfirmation()` danger modal with table name in message
- [ ] Confirming truncate sends POST to `/system/sql-query/truncate` with the table name
- [ ] Truncate endpoint validates table exists via `Schema::hasTable()` or information_schema
- [ ] Truncate endpoint escapes table name to prevent SQL injection
- [ ] After successful truncate, base query auto-runs showing empty results
- [ ] `window.showToast()` called with success message after truncate
- [ ] `window.showToast()` called with error message if truncate fails
- [ ] Reset button clears filters and re-runs `SELECT * FROM tablename`
- [ ] Reset does NOT trigger a confirmation modal
- [ ] Buttons not visible when running custom queries (e.g., `SELECT id FROM users WHERE ...`)
- [ ] Buttons not visible when no results exist
- [ ] `npm run build` passes after changes
- [ ] No new components created — uses existing `window.showConfirmation()` and `window.showToast()`

---

## 2. SQL Query: Inline cell editing with async UPDATE

**Priority:** High

**Description:** Add inline cell editing to the SQL Query datagrid. Double-click a cell to edit in-place, Tab/Enter to save via async SQL UPDATE, ESC to cancel.

**Implementation Notes:**

### 1. PK Detection endpoint
- New method in `SqlQueryController`: `primaryKey(Request $request)`
- Route: `GET /system/sql-query/primary-key?table=tablename` — Name: `system.sql-query.primary-key`
- Add route to `routes/web.php` inside the auth middleware group
- Detect PK using: `DB::select("SHOW KEYS FROM \`{$table}\` WHERE Key_name = 'PRIMARY'")` — returns the `Column_name` field
- Do NOT hardcode `id` — use whatever the actual PK column is
- Handle composite PKs: if multiple columns form the PK, return all of them (though for simplicity, the UI can require a single-column PK for editing)
- Return JSON: `{ pk: 'column_name' }` for single PK, `{ pk: null, reason: 'No primary key' }` if none, `{ pk: null, reason: 'Composite primary key not supported' }` if composite
- Validate table exists before querying keys

### 2. Cell UPDATE endpoint
- New method in `SqlQueryController`: `update(Request $request)`
- Route: `POST /system/sql-query/update` — Name: `system.sql-query.update`
- Request validation: `{ table: 'required|string', pk_column: 'required|string', pk_value: 'required', column: 'required|string', value: 'nullable' }`
- Value handling:
  - If `value` is the literal string `"NULL"` (case-insensitive) → execute `UPDATE table SET column = NULL WHERE pk_column = pk_value`
  - If `value` is `""` (empty string) → execute `UPDATE table SET column = '' WHERE pk_column = pk_value`
  - Otherwise → execute `UPDATE table SET column = value WHERE pk_column = pk_value`
- Use parameter binding for safety: `DB::table($table)->where($pkColumn, $pkValue)->update([$column => $resolvedValue])`
- Escape table/column names to prevent SQL injection
- Validate table and column exist before executing
- On success: return `{ success: true, value: <the value that was set> }`
- On DB error (wrong data type, constraint violation, NOT NULL violation, etc.): catch `\Throwable`, return `{ error: $e->getMessage() }` with 422 status

### 3. Inline editing UI in `resources/views/system/sql-query.blade.php`

**New Alpine state variables:**
- `editingCell: null` — object `{ rowIndex, colName, originalValue }` or null when not editing
- `editingValue: ''` — current input value
- `savingCell: false` — loading state during save
- `tablePk: null` — cached PK column name for current table
- `tablePkFetched: false` — whether PK has been fetched for current table

**Fetch PK on table browse:**
- In `queryTable(name)` method, after setting `baseQuery`, call a new async method `fetchPrimaryKey(name)` that:
  - Fetches `GET /system/sql-query/primary-key?table=name`
  - Stores result in `tablePk` (string or null)
  - Sets `tablePkFetched = true`
- Reset `tablePk` and `tablePkFetched` when switching tables or running custom queries

**Triggering edit (double-click):**
- Change the existing `@click="copyCell(row[col])"` on td to `@click="copyCell(row[col])"` (keep single-click for copy)
- Add `@dblclick.stop="startEditing(i, col, row[col])"` on each td
- `startEditing(rowIndex, colName, currentValue)`:
  - If `!isBrowsingTable` → return (no editing on custom queries)
  - If `!tablePk` → `window.showToast('Cannot edit: table has no primary key', 'error')` and return
  - Set `editingCell = { rowIndex, colName, originalValue: currentValue }`
  - Set `editingValue = currentValue === null ? '' : String(currentValue)`
  - `$nextTick` → focus the input

**Cell rendering (conditional):**
- When `editingCell` matches current cell (`editingCell.rowIndex === i && editingCell.colName === col`):
  - Render an `<input>` instead of the span
  - Input classes: remove normal cell classes, add `w-full bg-transparent outline-none font-mono text-xs`
  - Cell (td) gets: `border-2` with `border-color: rgb(var(--ui-primary))` (thick primary border)
  - Input gets `x-model="editingValue"`, `x-ref="editInput"`, auto-focus
  - Key handlers on input:
    - `@keydown.enter.prevent="commitEdit()"` 
    - `@keydown.tab.prevent="commitEdit()"` 
    - `@keydown.escape.prevent="cancelEdit()"`
    - `@blur="commitEdit()"` (commit on blur too, unless ESC was pressed)
- When NOT editing: render normal cell content (existing spans)

**Committing (`commitEdit()`):**
- If `savingCell` → return (prevent double-submit)
- Set `savingCell = true`
- Resolve value: if `editingValue.toUpperCase() === 'NULL'` → send `"NULL"`, otherwise send `editingValue`
- Extract PK value from the current row: `results[editingCell.rowIndex][tablePk]`
- Extract table name from `browsedTableName` computed property
- POST to `/system/sql-query/update` with `{ table, pk_column: tablePk, pk_value, column: editingCell.colName, value: resolvedValue }`
- On success:
  - Update `results[editingCell.rowIndex][editingCell.colName]` with the new value (use `null` if sent "NULL")
  - `window.showToast('Cell updated', 'success')`
  - Set `editingCell = null`
- On error:
  - `window.showToast(errorMessage, 'error')`
  - Restore: `results[editingCell.rowIndex][editingCell.colName] = editingCell.originalValue`
  - Set `editingCell = null`
- Set `savingCell = false`

**Cancelling (`cancelEdit()`):**
- Set `editingCell = null` — no network request, original value stays
- Use a flag `_escPressed = true` to prevent `@blur` from triggering `commitEdit()` after ESC

**Value display after edit:**
- If value is `null` → show italic "NULL" span (existing pattern in the view)
- If value is `''` (empty string) → show empty cell
- Otherwise show the value as text

### Files to Modify
- `app/Http/Controllers/System/SqlQueryController.php` (add `primaryKey()` and `update()` methods)
- `routes/web.php` (add 2 new routes: `system.sql-query.primary-key`, `system.sql-query.update`)
- `resources/views/system/sql-query.blade.php` (add editing state, double-click handler, conditional input rendering, commit/cancel methods, PK fetching)

### Acceptance Criteria
- [ ] Double-click a cell enters edit mode with input field replacing cell content
- [ ] Editing cell has thick primary-color border (`border-2` + primary color)
- [ ] Input auto-focuses and contains current cell value (empty string for NULL cells)
- [ ] Tab commits the update via async POST
- [ ] Enter commits the update via async POST
- [ ] ESC cancels edit and restores original value without network request
- [ ] Blur (clicking away) commits the update (unless ESC was pressed)
- [ ] `window.showToast('Cell updated', 'success')` on successful update
- [ ] `window.showToast(errorMessage, 'error')` on failed update, original value restored
- [ ] PK detected via `SHOW KEYS` — NOT hardcoded to `id`
- [ ] Tables without PK: double-click shows error toast, no edit mode
- [ ] Composite PK tables: double-click shows error toast, no edit mode
- [ ] Typing literal `NULL` (case-insensitive) sets DB value to `NULL`
- [ ] Empty input sets DB value to empty string `''`
- [ ] Invalid data type errors from DB caught and shown as error toast
- [ ] Editing only available when browsing a table (`isBrowsingTable` is true)
- [ ] Single-click still copies cell value (existing behavior preserved)
- [ ] Cell value updates in-place after successful save (no full page/query reload)
- [ ] `npm run build` passes after changes

---

## 3. System Heartbeat: Real-time server health dashboard

**Priority:** High

**Description:** Create a new `/system/heartbeat` page — a beautiful, fully visual real-time server health dashboard that auto-refreshes every 5 seconds. This should be the most visually polished page in the admin. Circular gauges, color-coded indicators, smooth transitions.

**Implementation Notes:**

### Route & Controller
- New controller: `App\Http\Controllers\System\HeartbeatController`
- Routes (add to `routes/web.php` inside auth middleware, under System section):
  - `GET /system/heartbeat` → `index()` — Name: `system.heartbeat`
  - `GET /system/heartbeat/data` → `data()` — Name: `system.heartbeat.data`

### Sidebar
- Add "Heartbeat" link to System section in `resources/views/layouts/app.blade.php`
- Add `system.heartbeat` to the `activeHighlight` match block at the top of the layout
- Use Heroicons `heart` icon (outline):
  ```
  <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
  ```
- Label: "Heartbeat"

### Layout
- Full-width content (`:flush="true"`), no left sidebar
- Alpine component: `x-data="heartbeat()"` with `x-init="fetchData(); startPolling()"`
- Auto-refresh every 5 seconds with pulsing green dot indicator (same pattern as step-dispatcher page)
- Header bar with title "Heartbeat" and auto-refresh indicator (same structure as step-dispatcher header)

### Section 1: Server Gauges (row of 3 circular gauges)
- Display in a horizontal row: CPU | RAM | HDD
- Each gauge is a **circular SVG arc** (not a full circle — use `stroke-dasharray` and `stroke-dashoffset` technique):
  - Background arc: subtle gray (`ui-border` color)
  - Foreground arc: color-coded by percentage
    - 0-59%: green (`--ui-success`)
    - 60-79%: yellow/amber (`--ui-warning`)
    - 80-100%: red (`--ui-danger`)
  - Large percentage number centered inside the arc (e.g., "34%")
  - Label below the gauge (e.g., "CPU", "RAM", "HDD")
  - Sub-label with absolute values (e.g., "8.2 GB / 16 GB" for RAM, "120 GB / 500 GB" for HDD)
  - Arc should animate smoothly on value changes using CSS `transition: stroke-dashoffset 0.5s ease`
- **Backend data collection:**
  - CPU: `sys_getloadavg()[0]` divided by CPU count from `nproc` or `/proc/cpuinfo` count. Cap at 100%. This server has 32 vCPUs.
  - RAM: parse `/proc/meminfo` — `MemTotal`, `MemAvailable`. Used = Total - Available. Return both in MB.
  - HDD: `disk_total_space('/')` and `disk_free_space('/')`. Return both in GB (rounded to 1 decimal).

### Section 2: Supervisor Health
- **Backend:** Execute `sudo supervisorctl status` via `shell_exec()` or `Process::run()`. Parse each line:
  - Format: `process-name                    STATE     pid XXXX, uptime X:XX:XX`
  - Extract: name, state, pid, uptime
  - Only show kraite-related processes (filter by name containing `kraite`)? Or show all? **Show all** — this is a server-level health view.
  - NOTE: requires `sudo` — the web user (www-data) needs sudoers entry: `www-data ALL=(ALL) NOPASSWD: /usr/bin/supervisorctl status`
  - If `supervisorctl` fails (permission denied), return an error state and show a message in the UI
- **Frontend:** Use `<x-hub-ui::data-table>` with columns: Name, State, PID, Uptime
  - State column uses `<x-hub-ui::badge>`:
    - RUNNING → `type="success"`
    - STOPPED → `type="warning"`
    - FATAL → `type="danger"`
    - STARTING → `type="info"`
    - Other → `type="default"`
  - Section title: "Supervisor" with a count badge (e.g., "15 processes")

### Section 3: Scheduled Commands
- **Backend:** The schedule is defined in `ingestion.kraite.com`, NOT in `admin.kraite.com`. The admin app has no scheduled tasks.
  - Option A: Read the schedule from the ingestion app's console kernel programmatically (complex, cross-app)
  - Option B: Run `cd /home/waygou/ingestion.kraite.com && php artisan schedule:list` via shell and parse the output
  - **Use Option B** — simpler. Parse each line for: command, expression, next due time
  - If no schedule found, show "No scheduled tasks" empty state
- **Frontend:** Use `<x-hub-ui::data-table>` with columns: Command, Expression, Next Run, Countdown
  - Countdown timer: calculate seconds until next run in JS, update every second (`setInterval` at 1s, independent from the 5s data refresh)
  - Format countdown as "Xm Xs" or "Xh Xm" depending on duration
  - Section title: "Scheduled Commands"

### Section 4: Step Dispatcher Summary
- **Backend:** Query the database:
  - `steps_dispatcher` table: check `can_dispatch` flag and `last_tick_completed` across all groups
  - `steps` table: `SELECT state, COUNT(*) FROM steps GROUP BY state` (same query as step-dispatcher page)
  - Determine "running" status: if any `can_dispatch = 1` AND `last_tick_completed` is within last 2 minutes → running
- **Frontend:** Display as a compact card with:
  - Status indicator: `<x-hub-ui::status>` with animated dot — "Running" (success) or "Stopped" (danger)
  - Key metrics in a row: Total steps, Running, Pending, Failed — each as a number with label
  - Last tick timestamp
  - "View Details →" link to `/system/step-dispatcher`
  - Section title: "Step Dispatcher"

### Section 5: Slow Queries
- **Backend:** Query `slow_queries` table:
  - Last 10 entries: `SELECT id, time_ms, sql, connection, created_at FROM slow_queries ORDER BY created_at DESC LIMIT 10`
  - Last hour count: `SELECT COUNT(*) FROM slow_queries WHERE created_at >= NOW() - INTERVAL 1 HOUR`
  - Note: table might be empty (currently 0 rows). Handle gracefully.
- **Frontend:** Use `<x-hub-ui::data-table>` with columns: Duration, Query, Connection, Time
  - Duration column: `<x-hub-ui::badge>` with color by time:
    - `<100ms` → `type="success"`
    - `100-500ms` → `type="warning"`
    - `>500ms` → `type="danger"`
  - Query column: truncated with `max-w-md truncate`, full SQL on hover via `title` attribute
  - Section title: "Slow Queries" with "X in last hour" count
  - If no slow queries: show `<x-hub-ui::empty-state>` with "No slow queries recorded"

### Backend Data Endpoint (`data()` method)
Single JSON payload with all sections:
```json
{
  "server": {
    "cpu_percent": 23.5,
    "ram_used_mb": 8192,
    "ram_total_mb": 16384,
    "hdd_used_gb": 120.3,
    "hdd_total_gb": 500.0
  },
  "supervisor": {
    "available": true,
    "processes": [
      { "name": "kraite-horizon", "state": "RUNNING", "pid": 1981, "uptime": "2 days, 0:53:42" }
    ]
  },
  "schedule": {
    "available": true,
    "tasks": [
      { "command": "steps:dispatch", "expression": "* * * * *", "next_run_iso": "2026-04-10T00:01:00+00:00" }
    ]
  },
  "step_dispatcher": {
    "running": true,
    "total": 500,
    "by_state": { "Running": 3, "Pending": 12, "Failed": 1, "Completed": 480 },
    "last_tick": "2026-04-10 00:00:55"
  },
  "slow_queries": {
    "last_hour_count": 5,
    "recent": [
      { "id": 1, "time_ms": 450, "sql": "SELECT ...", "connection": "mysql", "created_at": "2026-04-10 00:00:30" }
    ]
  }
}
```

### Design Guidelines
- **ATTENTION TO DETAILS** — spacing, alignment, color consistency, typography
- Use hub-ui theme variables for ALL colors: `rgb(var(--ui-success))`, `rgb(var(--ui-warning))`, `rgb(var(--ui-danger))`, etc. NO hardcoded hex colors.
- Use hub-ui utility classes: `ui-text`, `ui-text-muted`, `ui-text-subtle`, `ui-bg-elevated`, `ui-border`, etc.
- Use hub-ui components: `data-table`, `badge`, `status`, `card`, `spinner`, `empty-state`
- SVG gauges should use `transition: stroke-dashoffset 0.5s ease` for smooth animation
- Countdown timers tick every 1 second via a separate `setInterval` (not tied to the 5s data refresh)
- Each section should be wrapped in a visual container (rounded border or card) with clear title
- Responsive: gauges row should work on smaller screens (flex-wrap)
- Read `CLAUDE.md` at the project root for full component reference and coding standards

### Files to Create/Modify
- `app/Http/Controllers/System/HeartbeatController.php` (new)
- `resources/views/system/heartbeat.blade.php` (new)
- `resources/views/layouts/app.blade.php` (add sidebar link + activeHighlight)
- `routes/web.php` (add 2 routes)

### Acceptance Criteria
- [ ] Page loads at `/system/heartbeat` with sidebar link highlighted
- [ ] CPU gauge shows aggregate percentage (load avg / 32 vCPUs, capped at 100%)
- [ ] RAM gauge shows used/total in GB with percentage
- [ ] HDD gauge shows disk usage with percentage
- [ ] All 3 gauges use SVG circular arcs with smooth CSS transitions
- [ ] Gauge colors: green <60%, yellow 60-79%, red >=80% — using `--ui-success`, `--ui-warning`, `--ui-danger`
- [ ] Supervisor section shows all processes with correct state badges
- [ ] Supervisor handles permission errors gracefully (shows message if sudo fails)
- [ ] Scheduled commands section shows tasks from ingestion app (or "No tasks" if empty)
- [ ] Countdown timers tick every second and show time until next run
- [ ] Step dispatcher summary shows running/stopped status, key counts, last tick time
- [ ] Step dispatcher section links to `/system/step-dispatcher`
- [ ] Slow queries shows last 10 with duration badges (color-coded by time_ms)
- [ ] Slow queries shows "X in last hour" count in section header
- [ ] Empty states handled for all sections (no data = informative message, not blank)
- [ ] Auto-refresh every 5 seconds with pulsing indicator
- [ ] All hub-ui components used (data-table, badge, status, card, spinner, empty-state)
- [ ] All colors from theme variables, zero hardcoded hex/rgb values
- [ ] `npm run build` passes
- [ ] Page is visually polished — professional monitoring dashboard quality

---
