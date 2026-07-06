<?php

namespace App\Services;

use App\Exceptions\GeminiApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GeminiClient
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const TIMEOUT_SECONDS = 10;

    private const MAX_OUTPUT_TOKENS = 300;

    private const TEMPERATURE = 0.2;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {}

    public function generate(string $prompt): string
    {
        $apiKey = $this->resolvedApiKey();

        if ($apiKey === '') {
            throw new GeminiApiException('GEMINI_API_KEY is not configured.');
        }

        $url = sprintf('%s/%s:generateContent', self::API_BASE, $this->resolvedModel());

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post($url, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature' => self::TEMPERATURE,
                        'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new GeminiApiException('Gemini request failed.', 0, $exception);
        }

        if (! $response->successful()) {
            throw new GeminiApiException('Gemini returned HTTP '.$response->status().'.');
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new GeminiApiException('Gemini returned no usable text.');
        }

        return trim($text);
    }

    private function resolvedApiKey(): string
    {
        return trim((string) ($this->apiKey ?? config('services.gemini.api_key')));
    }

    private function resolvedModel(): string
    {
        return (string) ($this->model ?? config('services.gemini.model', 'gemini-3.5-flash'));
    }
}
