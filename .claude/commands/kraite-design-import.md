---
description: Import the latest Claude Design export from ~/Downloads/, diff against the current claude-design/ snapshot, autonomously port the changes throughout the codebase, run build + tests, and push two clean commits (design snapshot + port).
---

# /kraite-design-import — Import latest Claude Design export

Operate on the current branch (whatever Bruno is on). Caveman mode applies to chat output. The command itself runs end-to-end without confirmation prompts unless one of the **Critical-Decision Triggers** below fires.

---

## Stage 0 — Pre-flight

1. Locate latest export zip in `~/Downloads/` matching `Kraite Admin II*.zip` (covers `Kraite Admin II.zip`, `Kraite Admin II (1).zip`, etc.). Pick the file with the newest mtime.
2. If no matching zip → halt with: "No Claude Design export found in ~/Downloads/."
3. Check mtime age. If older than **10 minutes** → ask Bruno:
   > "Found `<filename>` last modified <X> minutes ago. That's older than the usual 10 min window. Import anyway?"
   Proceed only if Bruno confirms.

## Stage 1 — Lock current baseline on remote

1. If `claude-design/` exists and has uncommitted changes → commit them with message `Claude Design: pre-import baseline snapshot`. Otherwise skip.
2. Push current branch to remote. If branch has no upstream → `git push -u origin <current-branch>`. Otherwise `git push`.
3. This locks the previous design version on remote so the diff is meaningful across machines.

## Stage 2 — Extract new export

1. If `claude-design/` exists, wipe its contents entirely: `rm -rf claude-design/*` (and any dotfiles inside). This guarantees files Claude Design deleted upstream disappear locally too, so the diff doesn't lie.
2. If `claude-design/` doesn't exist, create it.
3. Extract the zip into `claude-design/` preserving structure exactly as the zip provides. Do not rename, reshape, or transform anything.
4. Rename the source zip in `~/Downloads/` to `(imported) <original-filename>` so future exports don't get confused with already-imported ones. If a file with that name already exists, append a timestamp suffix before the extension.

## Stage 3 — No-op detection

Run `git status --short -- claude-design/`. If empty (no changes vs HEAD) → report "No design changes detected, nothing to port." and exit cleanly. Do not commit, do not push.

## Stage 4 — Diff + analyze

1. Run `git diff HEAD -- claude-design/` to surface what Claude Design changed: new files, removed files, modified files.
2. Read both `Kraite Admin.html` and `Design System.html` to understand the changes in context. Diffing raw HTML is noisy; the readable interpretation lives in the rendered structure.
3. Categorize each change into one of:
   - **Token change** (radius scale, color, spacing, typography, shadow) → maps to `--ui-*` CSS vars + tailwind config
   - **Component change** (button, badge, card, table, input, modal) → maps to Blade component(s) under `resources/views/components/`
   - **Page change** (Dashboard, Positions, BSCS, Accounts, Billing, etc.) → maps to the corresponding page Blade + Livewire component if present
   - **New component / new page** → build from scratch with mock data where business data isn't yet wired
   - **Removed component / removed page** → see Critical-Decision Triggers

## Stage 5 — Critical-Decision Triggers (ASK Bruno)

Stop and ask Bruno **only** for these:

1. **Removed component currently used in code** → which existing code paths reference it. Example: "Claude Design removed `<x-status-pill>` but it's used in `dashboard.blade.php:42`, `positions.blade.php:71`, `accounts/edit.blade.php:88`. Replace with `<x-badge>` (closest match in new system), delete the usages, or keep both?"
2. **Removed page currently routed** → "Page `/billing` removed from design but route is active in `routes/web.php:34`. Remove route + controller method too, or keep page using legacy view?"
3. **Token semantic conflict** → e.g. new `--ui-warning` hex equals existing `--ui-danger`, or contrast ratio drops below WCAG AA. "Warning color now visually identical to danger. Override one, keep both, or proceed as-is?"
4. **New business behaviour implied by a label or component** → e.g. new column "Refund eligible" in the positions table. "Design introduces 'Refund eligible' column. Need product decision on the source of truth and computation rule before I wire it."

Everything else → auto-apply. Renames, new tokens, new components, new pages with mock data, layout shifts, copy edits, font swaps, color tweaks — all autonomous.

## Stage 6 — Port autonomously

Apply changes throughout the codebase:

- **Tokens** → update `resources/css/app.css` (or wherever `--ui-*` lives once rebuilt), `tailwind.config.js` if scale-level (radius, spacing, fonts), Blade component classes if they pin specific values.
- **Components** → edit or create Blade components under `resources/views/components/`. Match the new visual exactly: structure, classes, props, slots.
- **Pages** → edit or create the corresponding page Blade. Update Livewire component classes if interaction model changes.
- **Assets** → copy any new fonts, icons, images from `claude-design/assets/` into `public/` or `resources/` as appropriate. Reference them with relative paths that survive the Vite build.
- **Orphan cleanup** → remove imports, unused props, dead Blade partials, dead Livewire methods that the port made obsolete.

## Stage 7 — Verify

1. Run `npm run build`. If it fails → halt, surface the error, do not commit, do not push.
2. Run `php artisan test --compact`. Report results. If any test fails → halt, surface the failures, do not push. Bruno decides whether to fix or override before re-running.

## Stage 8 — Commit + push (two commits, one push)

Two clean commits in this order:

1. **`Claude Design export: <short delta summary>`** — contains ONLY `claude-design/` folder changes. Body of the commit message lists the high-level deltas (new pages, renamed components, token scale changes).
2. **`Port Claude Design <SHA-prefix> to codebase`** — everything else (CSS, Blade components, pages, asset moves, orphan cleanup). The `<SHA-prefix>` is the first 7 chars of commit (1)'s SHA, so the port commit links back to the design snapshot it implements.

Then a single `git push` for both.

## Stage 9 — Report

Reply to Bruno in caveman mode with:

- Source zip name + mtime
- Number of files changed in `claude-design/` (added / modified / removed)
- Number of files changed in codebase (the port)
- Build result + test result summary
- Both commit SHAs (short)
- Any decisions auto-applied that Bruno might want to know about (e.g. "renamed `<x-status-pill>` to `<x-badge>` across 12 files")

---

## Failure handling

- Zip missing → halt, no changes.
- Zip too old (>10 min) → ask Bruno, halt if no confirmation.
- Build fail → halt before commits.
- Tests fail → halt after commits exist locally but before push, so Bruno can amend.
- Critical-Decision trigger fires → halt at decision point, ask, resume after answer.

Never auto-resolve conflicts by guessing. When in genuine doubt about scope or behaviour, surface it.
