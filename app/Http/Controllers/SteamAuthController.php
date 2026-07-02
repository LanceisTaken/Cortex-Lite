<?php

namespace App\Http\Controllers;

use App\Http\Requests\Steam\ConnectVanityRequest;
use App\Models\User;
use App\Services\SteamClient;
use App\Services\SteamOpenIdVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SteamAuthController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        $callback = route('steam.callback', absolute: true);
        $realm = rtrim((string) config('services.steam.openid_realm', config('app.url')), '/');

        return redirect()->away(
            SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT.'?'.http_build_query([
                'openid.ns' => 'http://specs.openid.net/auth/2.0',
                'openid.mode' => 'checkid_setup',
                'openid.return_to' => $callback,
                'openid.realm' => $realm,
                'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
                'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
            ])
        );
    }

    public function callback(Request $request, SteamOpenIdVerifier $verifier): RedirectResponse
    {
        $steamId = $verifier->verify($request->query());

        if ($steamId === null) {
            return redirect()->away($this->frontendDashboardUrl([
                'steam_error' => 'steam_openid_verification_failed',
            ]));
        }

        $result = $this->persistSteamId($request->user(), $steamId);

        if ($result !== null) {
            return redirect()->away($this->frontendDashboardUrl([
                'steam_error' => $result,
            ]));
        }

        return redirect()->away($this->frontendDashboardUrl([
            'steam_connected' => '1',
        ]));
    }

    public function connectVanity(ConnectVanityRequest $request, SteamClient $steamClient): JsonResponse
    {
        $steamId = $steamClient->resolveVanityUrl($request->validated('vanity'));

        if ($steamId === null) {
            return response()->json([
                'error_code' => 'steam_vanity_unresolved',
                'message' => 'We could not resolve that Steam vanity URL or handle.',
                'help_url' => 'https://steamcommunity.com/my/edit/settings',
            ], 422);
        }

        $result = $this->persistSteamId($request->user(), $steamId);

        if ($result !== null) {
            return response()->json([
                'error_code' => $result,
                'message' => 'That Steam account is already linked to another Cortex Lite account.',
            ], 409);
        }

        return response()->json([
            'steam_id' => $steamId,
        ]);
    }

    private function persistSteamId(User $user, string $steamId): ?string
    {
        $existing = User::query()
            ->where('steam_id', $steamId)
            ->whereKeyNot($user->id)
            ->exists();

        if ($existing) {
            return 'steam_id_already_linked';
        }

        $user->steam_id = $steamId;
        $user->steam_id_resolved_at = now();
        $user->save();

        return null;
    }

    private function frontendDashboardUrl(array $query = []): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $url = $base.'/dashboard';

        if ($query === []) {
            return $url;
        }

        return $url.'?'.http_build_query($query);
    }
}
