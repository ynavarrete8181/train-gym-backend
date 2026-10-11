<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class ReglasClave
{
    public static function segura(): Password
    {
        return Password::min(6)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols();
    }
}
