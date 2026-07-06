<?php

namespace App\Services;

use App\Exceptions\QuotaExceededException;
use App\Models\User;
use Illuminate\Support\Carbon;

class UsageQuota
{
    public const WINDOW_DAYS = 30;

    /** @var array<string, int> */
    public const LIMITS = [
        'recommend' => 3,
        'reverse' => 5,
    ];

    public function used(User $user, string $type): int
    {
        return $user->usageEvents()
            ->where('type', $type)
            ->where('created_at', '>=', Carbon::now()->subDays(self::WINDOW_DAYS))
            ->count();
    }

    public function limit(string $type): int
    {
        return self::LIMITS[$type];
    }

    public function remaining(User $user, string $type): ?int
    {
        if ($user->is_premium) {
            return null;
        }

        return max(0, $this->limit($type) - $this->used($user, $type));
    }

    public function ensureWithinLimit(User $user, string $type): void
    {
        if ($user->is_premium) {
            return;
        }

        $limit = $this->limit($type);
        $used = $this->used($user, $type);

        if ($used >= $limit) {
            throw new QuotaExceededException($type, $limit, $used);
        }
    }

    public function record(User $user, string $type): void
    {
        $user->usageEvents()->create(['type' => $type]);
    }
}
