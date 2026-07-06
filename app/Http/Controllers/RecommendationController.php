<?php

namespace App\Http\Controllers;

use App\Http\Requests\Recommendations\RecommendRequest;
use App\Models\Cpu;
use App\Models\Gpu;
use App\Services\RecommendationEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class RecommendationController extends Controller
{
    public function store(RecommendRequest $request, RecommendationEngine $engine): JsonResponse
    {
        try {
            $game = $request->user()->games()->findOrFail($request->validated('game_id'));
        } catch (ModelNotFoundException) {
            return response()->json(null, 404);
        }

        $gpu = Gpu::query()->findOrFail($request->validated('gpu_id'));
        $cpu = Cpu::query()->findOrFail($request->validated('cpu_id'));
        $goal = $request->validated('goal');

        $result = $engine->recommend($game, $gpu, $cpu, (int) $request->validated('ram_gb'), $goal);

        return response()->json([
            'data' => [
                'game_id' => $game->id,
                'goal' => $goal,
                ...$result,
                'explanation' => $this->fallbackExplanation($result, $goal),
            ],
        ]);
    }

    /**
     * The later LLM explanation layer will replace this string and reuse it as
     * the API-failure fallback.
     *
     * @param  array{source: string, gpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}  $result
     */
    private function fallbackExplanation(array $result, string $goal): string
    {
        $source = $result['source'] === 'anchor'
            ? 'a curated anchor preset'
            : 'the heuristic engine';

        $bottleneck = $result['cpu_bottleneck']
            ? ' Your CPU trails your GPU by two or more tiers, so CPU-bound scenes may still cap frame rate.'
            : '';

        return "These {$goal} settings come from {$source} for your {$result['gpu_tier']}-tier GPU "
            . "and {$result['ram_bucket']} memory bucket.{$bottleneck}";
    }
}
