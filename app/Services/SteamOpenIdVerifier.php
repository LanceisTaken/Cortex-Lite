<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SteamOpenIdVerifier
{
    public const STEAM_OPENID_ENDPOINT = 'https://steamcommunity.com/openid/login';

    public function verify(array $queryParams): ?string
    {
        $queryParams = $this->normalizeQueryParams($queryParams);

        if (($queryParams['openid.ns'] ?? null) !== 'http://specs.openid.net/auth/2.0') {
            return null;
        }

        if (($queryParams['openid.mode'] ?? null) !== 'id_res') {
            return null;
        }

        if (($queryParams['openid.op_endpoint'] ?? null) !== self::STEAM_OPENID_ENDPOINT) {
            return null;
        }

        if (($queryParams['openid.return_to'] ?? null) !== route('steam.callback', absolute: true)) {
            return null;
        }

        $claimedId = $queryParams['openid.claimed_id'] ?? null;

        if (! is_string($claimedId) || preg_match('#^https://steamcommunity\.com/openid/id/(\d{17})$#', $claimedId, $matches) !== 1) {
            return null;
        }

        $response = Http::asForm()
            ->timeout(10)
            ->post(self::STEAM_OPENID_ENDPOINT, [
                ...$queryParams,
                'openid.mode' => 'check_authentication',
            ]);

        if (
            ! $response->successful()
            || preg_match('/^is_valid:true$/m', $response->body()) !== 1
        ) {
            return null;
        }

        return $matches[1];
    }

    private function normalizeQueryParams(array $queryParams): array
    {
        $normalized = [];

        foreach ($queryParams as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'openid_')) {
                $normalized[str_replace('openid_', 'openid.', $key)] = $value;
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
