<?php

declare(strict_types=1);

namespace App\Nova\Fields;

use Laravel\Nova\Fields\ID as NovaID;

class ID extends NovaID
{
    public function __construct($name = null, ?string $attribute = null, ?callable $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);

        $this->hideFromIndex();
    }
}
