<?php

declare(strict_types=1);

namespace App\Nova\Fields;

use Carbon\Carbon;
use Laravel\Nova\Fields\Text;

class HumanDateTime extends Text
{
    public function __construct($name, $attribute = null, $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);

        $this->resolveUsing(function ($value) {
            if (! $value) {
                return null;
            }

            return Carbon::parse($value)->diffForHumans();
        });

        $this->exceptOnForms();
    }
}
