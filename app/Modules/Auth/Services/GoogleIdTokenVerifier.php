<?php

namespace App\Modules\Auth\Services;

use Google\Client as GoogleClient;
use RuntimeException;

class GoogleIdTokenVerifier
{
    public function verify(string $idToken): ?array
    {
        $clientId = config('services.google.client_id');

        if (!is_string($clientId) || $clientId === '') {
            throw new RuntimeException(
                'Google Client ID is not configured.'
            );
        }

        $client = new GoogleClient([
            'client_id' => $clientId,
        ]);

        $payload = $client->verifyIdToken($idToken);

        return is_array($payload)
            ? $payload
            : null;
    }
}
