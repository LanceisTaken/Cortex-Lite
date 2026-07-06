<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UsageQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    public function show(Request $request, UsageQuota $quota): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'is_premium' => (bool) $user->is_premium,
                'window_days' => UsageQuota::WINDOW_DAYS,
                'recommend' => $this->line($quota, $user, 'recommend'),
                'reverse' => $this->line($quota, $user, 'reverse'),
            ],
        ]);
    }

    /**
     * @return array{used: int, limit: int|null, remaining: int|null}
     */
    private function line(UsageQuota $quota, User $user, string $type): array
    {
        return [
            'used' => $quota->used($user, $type),
            'limit' => $user->is_premium ? null : $quota->limit($type),
            'remaining' => $quota->remaining($user, $type),
        ];
    }
}
