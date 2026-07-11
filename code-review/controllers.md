# Controller diff review

Scope: `app/Http/Controllers/DashboardController.php` working-tree diff, including `payload()`, the connectivity service API, authorization inherited from the dashboard endpoints, and existing tests.

## Correctness verdict

No surviving correctness bug was found in the latest controller diff.

The initially missing `Kraite\Core\Support\Connectivity\AccountServerConnectivityService` import was added while this review was running. In the final snapshot, the class resolves correctly, `apiConnectivityServers()` is a public service method, its filters define the intended API/whitelist fleet, and the mapped `id`, `hostname`, and `ip_address` fields exist. Both initial rendering and polling remain protected by the dashboard's owner-or-admin account scoping.

## Enhancements and refactorings

### Medium — Avoid querying a global roster on every account poll

- Location: `app/Http/Controllers/DashboardController.php:143` and `app/Http/Controllers/DashboardController.php:159`
- Impact: `connectivity_servers` is fleet-global and changes infrequently, but it is queried again for every account-specific `/dashboard/data` request. With the current ten-second browser polling interval, each open dashboard tab adds six identical server queries per minute.
- Recommendation: cache the serialized roster for a short period or deliver it outside the account payload so ordinary account metric refreshes do not reload invariant fleet data. Keep invalidation aligned with server configuration changes.

### Low — Make the service dependency explicit

- Location: `app/Http/Controllers/DashboardController.php:162`
- Impact: resolving `AccountServerConnectivityService` through `app()` hides the controller dependency and makes the payload method harder to reason about and isolate in tests.
- Recommendation: use constructor injection with a promoted, typed property, following the application's dependency-injection conventions.

### Low — Narrow and document the fallback contract

- Location: `app/Http/Controllers/DashboardController.php:161`
- Impact: `rescue(..., [])` intentionally preserves the rest of the dashboard when the roster fails, and Laravel reports the exception by default. However, the client cannot distinguish a genuinely empty fleet from a failed roster lookup.
- Recommendation: either expose a small availability/error flag alongside the empty roster or handle the expected infrastructure exception explicitly. The UI can then show “unavailable” instead of implying that there are no servers.

## Missing coverage

Add focused feature coverage for:

1. `connectivity_servers` appearing in both the initial view payload and `/dashboard/data` JSON.
2. The exact filtering and serialized shape of the API-connectivity roster.
3. Owner/admin account authorization remaining intact.
4. The roster failure fallback and its observable UI state.

Verification performed during review: `php artisan test --compact tests/Feature/DashboardActivityFeedTest.php` passed (3 tests, 6 assertions), but it does not cover the new payload field.
