<?php

namespace App\Http\Controllers;

use App\Http\Requests\Recommendations\ReverseRequest;
use App\Models\Cpu;
use App\Models\Gpu;
use App\Services\ExplanationGenerator;
use App\Services\SettingsDiffEngine;
use App\Services\UsageQuota;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class ReverseController extends Controller
{
    public function store(ReverseRequest $request, SettingsDiffEngine $engine, ExplanationGenerator $explanations, UsageQuota $quota): JsonResponse
    {
        $user = $request->user();
        $quota->ensureWithinLimit($user, 'reverse');

        try {
            $game = $user->games()->findOrFail($request->validated('game_id'));
        } catch (ModelNotFoundException) {
            return response()->json(null, 404);
        }

        $gpu = Gpu::query()->findOrFail($request->validated('gpu_id'));
        $cpu = Cpu::query()->findOrFail($request->validated('cpu_id'));
        $goal = $request->validated('goal');

        $result = $engine->diff(
            $game,
            $gpu,
            $cpu,
            (int) $request->validated('ram_gb'),
            $goal,
            $request->validated('current_settings'),
        );

        $fallback = $this->fallbackExplanation($result['diff'], $result['recommendation'], $goal);
        $explanation = $explanations->reverse($result['diff'], $result['recommendation'], $goal, $fallback);

        $quota->record($user, 'reverse');

        return response()->json([
            'data' => [
                'game_id' => $game->id,
                'goal' => $goal,
                ...$result,
                'explanation' => $explanation,
            ],
        ]);
    }

    /**
     * The later LLM explanation layer will replace this string and reuse it as
     * the API-failure fallback.
     *
     * @param  list<array{setting: string, label: string}>  $diff
     * @param  array{gpu_tier: string}  $recommendation
     */
    private function fallbackExplanation(array $diff, array $recommendation, string $goal): string
    {
        $tier = $recommendation['gpu_tier'];

        if ($diff === []) {
            return "Your current settings already match the {$goal} recommendation for your {$tier}-tier GPU.";
        }

        $changes = implode(', ', array_map(
            static fn (array $entry): string => "{$entry['setting']} {$entry['label']}",
            $diff,
        ));
        $count = count($diff);
        $noun = $count === 1 ? 'change' : 'changes';

        return "{$count} {$noun} will align your settings with the {$goal} recommendation "
            . "for your {$tier}-tier GPU: {$changes}.";
    }
}
