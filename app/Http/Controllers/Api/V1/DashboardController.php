<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController as WebDashboardController;
use App\Http\Requests\Api\V1\DashboardRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kraite\Core\Models\Account;

final class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, WebDashboardController $dashboard): JsonResponse
    {
        $validated = $request->validated();

        $accounts = Account::query()
            ->with(['apiSystem', 'user.subscription'])
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get();

        $accountId = isset($validated['account_id']) ? (int) $validated['account_id'] : null;
        $selected = $this->selectedAccount($accounts, $accountId);

        return response()->json([
            'data' => [
                'accounts' => $accounts->map($this->accountPayload(...))->values(),
                'selected_account_id' => $selected?->id,
                'dashboard' => $selected
                    ? Cache::remember("mobile-dashboard:account:{$selected->id}", 5, fn (): array => $dashboard->mobilePayload($selected))
                    : null,
            ],
        ]);
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function selectedAccount(Collection $accounts, ?int $accountId): ?Account
    {
        $selected = $accountId !== null
            ? $accounts->firstWhere('id', $accountId)
            : $accounts->first();

        if ($accountId !== null && ! $selected) {
            abort(404);
        }

        return $selected;
    }

    /** @return array{id: int, name: string, exchange: string, is_trading: bool} */
    private function accountPayload(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'exchange' => $account->apiSystem?->name ?? 'Unknown',
            'is_trading' => $account->isReadyToTrade(),
        ];
    }
}
