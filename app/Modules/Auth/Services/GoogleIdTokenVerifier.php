<?php

namespace App\Modules\Auth\Services;

use Google\Auth\AccessToken;
use RuntimeException;
use Throwable;

class GoogleIdTokenVerifier
{
    public function verify(string $idToken): ?array
    {
        $clientId = config('services.google.client_id');

        if (empty($clientId)) {
            throw new RuntimeException(
                'Google Client ID is not configured.'
            );
        }

        try {
            $payload = (new AccessToken())->verify(
                $idToken,
                [
                    'audience' => $clientId,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_array($payload)
            ? $payload
            : null;
    }
}