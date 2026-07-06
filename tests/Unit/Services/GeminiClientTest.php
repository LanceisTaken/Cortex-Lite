<?php

namespace Tests\Unit\Services;

use App\Exceptions\GeminiApiException;
use App\Services\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.5-flash');
    }

    public function test_generate_returns_trimmed_candidate_text(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->candidate('  Great settings.  ')),
        ]);

        $this->assertSame('Great settings.', (new GeminiClient())->generate('hi'));
    }

    public function test_generate_sends_api_key_header_and_model_in_url(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->candidate('ok')),
        ]);

        (new GeminiClient())->generate('hi');

        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key')
            && str_contains($request->url(), 'models/gemini-3.5-flash:generateContent'));
    }

    public function test_missing_api_key_throws_without_sending(): void
    {
        config()->set('services.gemini.api_key', '');
        Http::fake();

        try {
            (new GeminiClient())->generate('hi');
            $this->fail('Expected GeminiApiException.');
        } catch (GeminiApiException) {
            Http::assertNothingSent();
        }
    }

    public function test_non_2xx_throws(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $this->expectException(GeminiApiException::class);

        (new GeminiClient())->generate('hi');
    }

    public function test_connection_failure_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->expectException(GeminiApiException::class);

        (new GeminiClient())->generate('hi');
    }

    public function test_empty_candidates_throws(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => []])]);

        $this->expectException(GeminiApiException::class);

        (new GeminiClient())->generate('hi');
    }

    public function test_blank_candidate_text_throws(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->candidate('   '))]);

        $this->expectException(GeminiApiException::class);

        (new GeminiClient())->generate('hi');
    }

    private function candidate(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }
}
