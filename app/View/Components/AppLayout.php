<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;
use Kraite\Core\Models\Account;

class AppLayout extends Component
{
    public function __construct(
        public ?string $activeSection = null,
        public ?string $activeHighlight = null,
        public bool $flush = false,
        public bool $showConnectivityAlert = true,
    ) {}

    public function render(): View
    {
        return view('layouts.app', [
            'connectivityIssueAccounts' => $this->showConnectivityAlert
                ? $this->connectivityIssueAccounts()
                : collect(),
        ]);
    }

    /**
     * @return Collection<int, Account>
     */
    private function connectivityIssueAccounts(): Collection
    {
        $user = auth()->user();

        if ($user === null || (bool) $user->is_admin) {
            return collect();
        }

        return Account::query()
            ->with('apiSystem')
            ->where('user_id', $user->id)
            ->whereNotNull('disabled_reason')
            ->orderBy('name')
            ->get();
    }
}
