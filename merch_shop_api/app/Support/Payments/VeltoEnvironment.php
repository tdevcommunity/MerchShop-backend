<?php

namespace App\Support\Payments;

final class VeltoEnvironment
{
    public static function isSandbox(): bool
    {
        $key = (string) config('services.velto.secret_key', '');

        if ($key !== '' && (str_contains($key, 'live') || str_contains($key, 'sk_live'))) {
            return false;
        }

        if (str_contains($key, 'sandbox') || str_contains($key, 'sk_test')) {
            return true;
        }

        return app()->environment('local', 'testing');
    }
}
