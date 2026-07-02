<?php

namespace App\Http\Controllers;

use App\Exceptions\SteamPrivacyException;
use App\Services\SteamLibrarySynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SteamSyncController extends Controller
{
    public function store(Request $request, SteamLibrarySynchronizer $synchronizer): JsonResponse
    {
        $user = $request->user();

        if ($user->steam_id === null) {
            return response()->json([
                'error_code' => 'steam_not_connected',
            ], 409);
        }

        try {
            return response()->json($synchronizer->sync($user));
        } catch (SteamPrivacyException) {
            return response()->json($this->privateProfilePayload(), 422);
        }
    }

    public static function privateProfilePayload(): array
    {
        return [
            'error_code' => 'steam_profile_private',
            'message' => 'Steam profile visibility must allow library sync.',
            'help' => [
                'profile_toggle' => 'Set "My profile" to Public.',
                'game_details_toggle' => 'Set "Game details" to Public.',
                'url' => 'https://steamcommunity.com/my/edit/settings',
            ],
        ];
    }
}
