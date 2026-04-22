<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;

class UiComponentsController extends Controller
{
    public function index()
    {
        return view('system.ui-components');
    }
}
