<?php

namespace App\Services;

use App\Support\Recommendation\ExplanationCacheKey;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExplanationGenerator
{
    private const CACHE_TTL_SECONDS = 2592000;

    public function __construct(private readonly GeminiClient $client) {}

    /**
     * @param  array{settings: array<string, mixed>, gpu_tier: string, cpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}  $recommendation
     */
    public function forward(array $recommendation, string $goal, int $gameId, string $fallback): string
    {
        $key = ExplanationCacheKey::forward(
            $gameId,
            $recommendation['gpu_tier'],
            $recommendation['cpu_tier'],
            $recommendation['ram_bucket'],
            $goal,
        );

        return $this->generateWithCache($key, $this->forwardPrompt($recommendation, $goal), $fallback);
    }

    /**
     * @param  list<array{setting: string, current: string, recommended: string, label: string}>  $diff
     * @param  array{gpu_tier: string, cpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}  $recommendation
     */
    public function reverse(array $diff, array $recommendation, string $goal, string $fallback): string
    {
        $key = ExplanationCacheKey::reverse(
            $diff,
            $recommendation['gpu_tier'],
            $recommendation['cpu_tier'],
            $recommendation['ram_bucket'],
            $goal,
        );

        return $this->generateWithCache($key, $this->reversePrompt($diff, $recommendation, $goal), $fallback);
    }

    private function generateWithCache(string $key, string $prompt, string $fallback): string
    {
        try {
            $store = Cache::store($this->cacheStore());
            $cached = $store->get($key);
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return $fallback;
        }

        if (is_string($cached) && trim($cached) !== '') {
            return $cached;
        }

        try {
            $prose = $this->client->generate($prompt);
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return $fallback;
        }

        $this->cacheSuccessfulResponse($store, $key, $prose);

        return $prose;
    }

    private function cacheStore(): string
    {
        return (string) (config('services.gemini.cache_store') ?: config('cache.default'));
    }

    private function cacheSuccessfulResponse(Repository $store, string $key, string $prose): void
    {
        try {
            $store->put($key, $prose, self::CACHE_TTL_SECONDS);
        } catch (Throwable $exception) {
            Log::warning('Gemini explanation cache write failed.', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function logFailure(Throwable $exception): void
    {
        Log::warning('Gemini explanation failed; serving static fallback.', [
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  array{settings: array<string, mixed>, gpu_tier: string, cpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}  $recommendation
     */
    private function forwardPrompt(array $recommendation, string $goal): string
    {
        $settings = (string) json_encode($recommendation['settings'], JSON_PRETTY_PRINT);
        $bottleneck = $recommendation['cpu_bottleneck'] ? 'yes' : 'no';

        return <<<PROMPT
            You are a PC gaming graphics-settings assistant. A deterministic rule-based engine has already chosen the settings below. Write a short, friendly explanation of why they suit this player: 3-4 sentences, plain prose, no lists, no markdown. Do not change, add, remove, or second-guess any value; explain only the settings exactly as given.

            Goal: {$goal}
            GPU tier: {$recommendation['gpu_tier']}
            CPU tier: {$recommendation['cpu_tier']}
            RAM bucket: {$recommendation['ram_bucket']}
            CPU bottleneck: {$bottleneck}
            Chosen settings (JSON):
            {$settings}
            PROMPT;
    }

    /**
     * @param  list<array{setting: string, current: string, recommended: string, label: string}>  $diff
     * @param  array{gpu_tier: string, cpu_tier: string, ram_bucket: string, cpu_bottleneck: bool}  $recommendation
     */
    private function reversePrompt(array $diff, array $recommendation, string $goal): string
    {
        $bottleneck = $recommendation['cpu_bottleneck'] ? 'yes' : 'no';
        $changes = $diff === []
            ? '(none - the pasted settings already match the recommendation)'
            : implode("\n", array_map(
                static fn (array $entry): string => "- {$entry['setting']}: {$entry['label']}",
                $diff,
            ));

        return <<<PROMPT
            You are a PC gaming graphics-settings assistant. The player pasted their current settings; a deterministic rule-based engine computed the exact changes needed to reach the recommended {$goal} configuration. Write a short, friendly explanation of why each change helps: 3-4 sentences, plain prose, no lists, no markdown. Do not invent or alter any change; explain only the changes listed.

            Goal: {$goal}
            GPU tier: {$recommendation['gpu_tier']}
            CPU tier: {$recommendation['cpu_tier']}
            RAM bucket: {$recommendation['ram_bucket']}
            CPU bottleneck: {$bottleneck}
            Changes ("current -> recommended"):
            {$changes}
            PROMPT;
    }
}
