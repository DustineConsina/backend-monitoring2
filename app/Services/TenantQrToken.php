<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class TenantQrToken
{
    public function issue(Tenant $tenant): string
    {
        $encrypted = Crypt::encryptString('tenant:' . $tenant->getKey());

        return rtrim(strtr($encrypted, '+/', '-_'), '=');
    }

    public function resolve(string $token): ?Tenant
    {
        if ($token === '' || strlen($token) > 2048) {
            return null;
        }

        $encoded = strtr($token, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);

        try {
            $payload = Crypt::decryptString($encoded);
        } catch (DecryptException) {
            return null;
        }

        if (!preg_match('/\Atenant:([0-9]+)\z/', $payload, $matches)) {
            return null;
        }

        return Tenant::query()
            ->whereKey($matches[1])
            ->where('status', 'active')
            ->first();
    }
}
