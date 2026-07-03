<?php

namespace App\Services;

use App\Exceptions\PcGamingWikiApiException;
use App\Exceptions\PcGamingWikiRateLimitException;
use App\Services\PcGamingWiki\PcGamingWikiFieldMap;
use App\Services\RateLimiter\PcGamingWikiLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PcGamingWikiClient
{
    private const API_URL = 'https://www.pcgamingwiki.com/w/api.php';

    private const CACHE_TTL_SECONDS = 604800;

    private const RAW_RESPONSE_MAX_BYTES = 32768;

    private const RAW_RESPONSE_MAX_DEPTH = 8;

    private const NOT_FOUND_SENTINEL = ['_not_found' => true];

    public function __construct(
        private readonly PcGamingWikiLimiter $limiter,
        private readonly ?string $contactEmail = null,
        private readonly ?string $cacheStore = null,
    ) {
        if ($this->resolvedContactEmail() === '') {
            throw new InvalidArgumentException('PCGAMINGWIKI_CONTACT_EMAIL must be set.');
        }
    }

    public function fetchMetadata(int $steamAppId): ?array
    {
        $metadata = Cache::store($this->resolvedCacheStore())->remember(
            $this->cacheKey($steamAppId),
            self::CACHE_TTL_SECONDS,
            fn () => $this->limiter->throttle(fn () => $this->fetchFreshMetadata($steamAppId)),
        );

        return $this->isNotFoundSentinel($metadata) ? null : $metadata;
    }

    public function cacheKey(int $steamAppId): string
    {
        return 'pcgw:metadata:'.$steamAppId;
    }

    private function fetchFreshMetadata(int $steamAppId): array
    {
        $infoboxPayload = $this->cargoQuery([
            'tables' => PcGamingWikiFieldMap::INFOBOX_TABLE,
            'fields' => implode(',', PcGamingWikiFieldMap::INFOBOX_FIELDS),
            'where' => PcGamingWikiFieldMap::STEAM_APP_ID.' HOLDS "'.$steamAppId.'"',
            'limit' => 1,
        ]);

        if ($this->isNotFoundSentinel($infoboxPayload)) {
            return self::NOT_FOUND_SENTINEL;
        }

        $pageName = $this->firstTitleValue($infoboxPayload, PcGamingWikiFieldMap::PAGE_ALIAS);

        if ($pageName === null) {
            Log::warning('PCGamingWiki Infobox response did not include a page name.');

            return self::NOT_FOUND_SENTINEL;
        }

        $videoPayload = $this->cargoQuery([
            'tables' => PcGamingWikiFieldMap::VIDEO_TABLE,
            'fields' => implode(',', PcGamingWikiFieldMap::VIDEO_FIELDS),
            'where' => '_pageName="'.$this->escapeCargoValue($pageName).'"',
            'limit' => 1,
        ]);

        if ($this->isNotFoundSentinel($videoPayload)) {
            return self::NOT_FOUND_SENTINEL;
        }

        return $this->parsePayload([
            'infobox' => $infoboxPayload,
            'video' => $videoPayload,
        ]);
    }

    private function cargoQuery(array $query): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent(),
            ])->timeout(10)->get(self::API_URL, [
                'action' => 'cargoquery',
                'format' => 'json',
                ...$query,
            ]);
        } catch (ConnectionException $exception) {
            throw new PcGamingWikiApiException('PCGamingWiki request failed.', 0, $exception);
        }

        if ($response->status() === 429) {
            throw new PcGamingWikiRateLimitException('PCGamingWiki returned HTTP 429.');
        }

        if ($response->serverError()) {
            throw new PcGamingWikiApiException('PCGamingWiki returned a server error.');
        }

        if (! $response->successful()) {
            return self::NOT_FOUND_SENTINEL;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            Log::warning('PCGamingWiki returned malformed JSON.');

            return self::NOT_FOUND_SENTINEL;
        }

        if (array_key_exists('error', $payload)) {
            $message = is_array($payload['error'])
                ? (string) ($payload['error']['info'] ?? 'PCGamingWiki returned an API error.')
                : 'PCGamingWiki returned an API error.';

            throw new PcGamingWikiApiException($message);
        }

        if (($payload['cargoquery'] ?? null) === []) {
            return self::NOT_FOUND_SENTINEL;
        }

        return $payload;
    }

    private function parsePayload(array $payload): array
    {
        $videoPayload = $payload['video'] ?? null;

        if (! is_array($videoPayload)) {
            Log::warning('PCGamingWiki parsed payload was missing video data.');

            return self::NOT_FOUND_SENTINEL;
        }

        $title = $this->firstTitle($videoPayload);
        $rawResponse = $this->safeRawResponse($payload);
        $upscaling = $title[PcGamingWikiFieldMap::LOCAL_TO_CARGO['dlss_supported']] ?? null;

        return [
            'direct3d_versions' => null,
            'vulkan_supported' => null,
            'hdr_supported' => $this->parseBoolean($title[PcGamingWikiFieldMap::LOCAL_TO_CARGO['hdr_supported']] ?? null),
            'ultrawide_supported' => $this->parseBoolean($title[PcGamingWikiFieldMap::LOCAL_TO_CARGO['ultrawide_supported']] ?? null),
            'dlss_supported' => $this->parseListedFeature($upscaling, ['dlss', 'deep learning super sampling']),
            'fsr_supported' => $this->parseListedFeature($upscaling, ['fsr', 'fidelityfx super resolution']),
            'ray_tracing_supported' => $this->parseBoolean($title[PcGamingWikiFieldMap::LOCAL_TO_CARGO['ray_tracing_supported']] ?? null),
            'raw_response' => $rawResponse,
        ];
    }

    private function firstTitleValue(array $payload, string $field): ?string
    {
        $title = $this->firstTitle($payload);
        $value = $title[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function firstTitle(array $payload): array
    {
        if (! array_key_exists('cargoquery', $payload) || ! is_array($payload['cargoquery'])) {
            Log::warning('PCGamingWiki Cargo response was missing cargoquery.');

            return [];
        }

        $title = $payload['cargoquery'][0]['title'] ?? null;

        if (! is_array($title)) {
            Log::warning('PCGamingWiki Cargo response title was malformed.');

            return [];
        }

        return $title;
    }

    private function parseBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match (true) {
            in_array($normalized, ['false', 'no', 'unsupported', 'unknown', 'n/a', '0', 'hackable', 'limited'], true) => false,
            in_array($normalized, ['true', 'yes', 'supported', 'native', '1'], true) => true,
            str_contains($normalized, 'unsupported') || str_contains($normalized, 'false')
                || str_contains($normalized, 'no') || str_contains($normalized, 'hackable')
                || str_contains($normalized, 'limited') => false,
            str_contains($normalized, 'true') || str_contains($normalized, 'yes') || str_contains($normalized, 'supported') => true,
            default => null,
        };
    }

    private function parseListedFeature(mixed $value, array $needles): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        $haystack = is_array($value)
            ? strtolower(implode(',', array_map('strval', $value)))
            : strtolower((string) $value);

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function safeRawResponse(array $payload): array
    {
        $depth = $this->depth($payload);
        $json = json_encode($payload);
        $byteLength = $json === false ? null : strlen($json);

        if ($depth > self::RAW_RESPONSE_MAX_DEPTH || $json === false || $byteLength > self::RAW_RESPONSE_MAX_BYTES) {
            Log::warning('PCGamingWiki Cargo response exceeded raw_response safety limits.', [
                'byte_len' => $byteLength,
                'depth' => $depth,
            ]);

            return [
                '_truncated' => true,
                'byte_len' => $byteLength,
                'depth' => $depth,
            ];
        }

        return $payload;
    }

    private function depth(mixed $value): int
    {
        if (! is_array($value) || $value === []) {
            return 0;
        }

        return 1 + max(array_map(fn (mixed $child): int => $this->depth($child), $value));
    }

    private function userAgent(): string
    {
        return sprintf('Cortex-Lite/1.0 (contact: %s)', $this->resolvedContactEmail());
    }

    private function resolvedContactEmail(): string
    {
        return trim((string) ($this->contactEmail ?? config('services.pcgamingwiki.contact_email')));
    }

    private function resolvedCacheStore(): string
    {
        return (string) ($this->cacheStore ?? config('services.pcgamingwiki.cache_store', 'redis'));
    }

    private function isNotFoundSentinel(mixed $metadata): bool
    {
        return is_array($metadata) && ($metadata['_not_found'] ?? false) === true;
    }

    private function escapeCargoValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\"'], $value);
    }
}
