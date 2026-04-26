<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * System overview landing for sysadmin / operators. Shares the JSON
     * data feed with the trader-facing /dashboard route — `dashboard.data`
     * is the single source until that surface diverges.
     */
    public function index(): View
    {
        return view('system.dashboard');
    }
}
