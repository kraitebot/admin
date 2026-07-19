<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController as WebDashboardController;
use App\Http\Requests\Api\V1\DashboardRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Kraite\Core\Models\Account;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, WebDashboardController $dashboard): JsonResponse
    {
        $validated = $request->validated();

        $accounts = Account::query()
            ->with(['apiSystem', 'user.subscription'])
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get();

        $selected = isset($validated['account_id'])
            ? $accounts->firstWhere('id', (int) $validated['account_id'])
            : $accounts->first();

        if (isset($validated['account_id']) && ! $selected) {
            abort(404);
        }

        $payload = $selected
            ? Cache::remember(
                "mobile-dashboard:account:{$selected->id}",
                5,
                fn (): array => $dashboard->mobilePayload($selected),
            )
            : null;

        return response()->json([
            'data' => [
                'accounts' => $accounts->map(fn (Account $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'exchange' => $account->apiSystem?->name ?? 'Unknown',
                    'is_trading' => $account->isReadyToTrade(),
                ])->values(),
                'selected_account_id' => $selected?->id,
                'dashboard' => $payload,
            ],
        ]);
    }
}
