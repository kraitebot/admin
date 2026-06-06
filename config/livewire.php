<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Asset injection
    |--------------------------------------------------------------------------
    | Livewire's runtime (and its bundled Alpine) ships inside the Vite
    | bundle (resources/js/app.js imports livewire.esm). Auto-injection
    | would load a second copy of Livewire + Alpine on every page, so it
    | stays off. Remaining keys fall back to the package defaults.
    */

    'inject_assets' => false,

];
