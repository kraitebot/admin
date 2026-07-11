# Admin UI diff review

Scope: `resources/views/dashboard.blade.php` working-tree diff, including the surrounding Alpine component, its route contracts, account switching, Livewire navigation cleanup, and the existing connectivity card.

## Correctness findings

### High — The connectivity feature has no usable call path

- Location: `resources/views/dashboard.blade.php:12`, `resources/views/dashboard.blade.php:264`, `resources/views/dashboard.blade.php:279`, `resources/views/dashboard.blade.php:304`, and `resources/views/dashboard.blade.php:855`
- Bug: `dashPage()` now requires `connUrls`, but its only invocation still passes four arguments and the card remains a static placeholder that neither calls `runConnCheck()` nor renders `connRows()`.
- Trigger: load the dashboard with `localStorage['kraite.connCheck.<account id>']` populated, or wire a button to the newly added `runConnCheck()` without also fixing the initializer. `hydrateConn()` / `pollConn()` dereferences `connUrls.status` (or the button dereferences `connUrls.start`) while `connUrls` is `undefined`, producing a JavaScript `TypeError` and no connectivity result.
- Correction: pass URL templates for the already-authorized core routes `connectivity-test.accounts.start` and `connectivity-test.status`, then replace the placeholder card with bindings for `connRows()`, `connMeta()`, `conn.testing`, `conn.error`, and `runConnCheck()`. Land the state, routes, and card atomically so dormant methods cannot be mistaken for a completed feature.

### Medium — Switching accounts keeps or resumes the previous account's connectivity state

- Location: `resources/views/dashboard.blade.php:83` and `resources/views/dashboard.blade.php:247`
- Bug: `setAccount()` changes `accountId` but never resets or rehydrates `conn`; `connRows()` gives the retained `conn.rows` precedence over the new account's roster.
- Trigger: run or complete a check for account A, then choose account B. Account B can display A's server results. If A has a scheduled poll, the next poll can capture B as `forAccount` while retaining A's block UUID, accept A's response under B, or leave `conn.testing` true so B cannot start a check.
- Correction: call `hydrateConn()` immediately after assigning the new account ID and before starting work for that account. Ensure it cancels the old timer, clears rows/error/testing state, and loads only the new account's persisted block.

### Medium — A stale stored block UUID polls forever

- Location: `resources/views/dashboard.blade.php:275` and `resources/views/dashboard.blade.php:293`
- Bug: every non-OK status response reaches the unconditional three-second reschedule; the invalid block is never removed from local storage.
- Trigger: a saved workflow is deleted, belongs to a user who no longer has access, the session expires, or local storage contains an invalid UUID. Every dashboard visit then issues a failing request every three seconds indefinitely.
- Correction: distinguish transient failures from terminal HTTP failures. Retry network errors and selected 5xx responses with bounded backoff; for terminal 4xx responses, remove the stored key, clear `conn.block`, set `testing` false, and surface an actionable error.

## Enhancements and refactorings

- Persist or return the workflow's actual completion timestamp. Setting `checkedAt = Date.now()` when an old completed block is rehydrated makes an historical check look newly completed.
- Put the connectivity state machine in a small Alpine module or shared component if the account edit and dashboard surfaces will both expose it. Route replacement, polling, terminal-state handling, and cleanup should have one implementation.
- Keep the existing `_connTimer` cleanup in `destroy()`; it correctly prevents a Livewire `wire:navigate` visit from leaving a background poller behind.

## Missing coverage

Add browser coverage for:

1. Idle roster rendering and start/status URL wiring.
2. Successful and failed checks.
3. Switching accounts during an in-flight poll.
4. Rehydrating completed, pending, invalid, and unauthorized block UUIDs.
5. Navigating away while polling and confirming no further requests occur.

No existing trader-dashboard test exercises these paths; `DashboardActivityFeedTest` only invokes the private activity-feed method, and the system-dashboard tests cover a different controller and view.
