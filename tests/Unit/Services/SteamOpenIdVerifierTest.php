<?php

namespace Tests\Unit\Services;

use App\Services\SteamOpenIdVerifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SteamOpenIdVerifierTest extends TestCase
{
    public function test_verify_returns_steam_id_on_valid_openid_payload(): void
    {
        Http::fake([
            SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n"),
        ]);

        $steamId = app(SteamOpenIdVerifier::class)->verify($this->validPayload());

        $this->assertSame('76561198000000000', $steamId);
    }

    public function test_verify_returns_null_when_namespace_is_wrong(): void
    {
        $payload = $this->validPayload();
        $payload['openid.ns'] = 'https://example.com/openid';

        $this->assertNull(app(SteamOpenIdVerifier::class)->verify($payload));
    }

    public function test_verify_returns_null_when_mode_is_not_id_res(): void
    {
        $payload = $this->validPayload();
        $payload['openid.mode'] = 'cancel';

        $this->assertNull(app(SteamOpenIdVerifier::class)->verify($payload));
    }

    public function test_verify_returns_null_when_op_endpoint_is_missing_or_wrong(): void
    {
        $payload = $this->validPayload();
        unset($payload['openid.op_endpoint']);

        $this->assertNull(app(SteamOpenIdVerifier::class)->verify($payload));

        $payload = $this->validPayload();
        $payload['openid.op_endpoint'] = 'https://example.com/openid';

        $this->assertNull(app(SteamOpenIdVerifier::class)->verify($payload));
    }

    public function test_verify_returns_null_when_return_to_does_not_match_registered_callback(): void
    {
        $payload = $this->validPayload();
        $payload['openid.return_to'] = 'http://localhost:8080/api/steam/callback?bad=1';

        $this->assertNull(app(SteamOpenIdVerifier::class)->verify($payload));
    }

    public function test_verify_returns_null_when_steam_marks_the_assertion_invalid(): void
    {
        Http::fake([
            SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT => Http::response('is_valid:false'),
        ]);

        $this->assertNull(app(SteamOpenIdVerifier::class)->verify($this->validPayload()));
    }

    public function test_verify_returns_null_when_claimed_id_is_not_a_steamid64(): void
    {
        $payload = $this->validPayload();
        $payload['openid.claimed_id'] = 'https://steamcommunity.com/openid/id/12345';

        $this->assertNull(app(SteamOpenIdVerifier::class)->verify($payload));
    }

    public function test_verification_post_always_targets_hard_coded_steam_endpoint(): void
    {
        Http::fake([
            SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT => Http::response('is_valid:false'),
        ]);

        $payload = $this->validPayload();
        $payload['openid.op_endpoint'] = SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT;

        app(SteamOpenIdVerifier::class)->verify($payload);

        Http::assertSent(function ($request): bool {
            return $request->url() === SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT
                && $request['openid.mode'] === 'check_authentication';
        });
    }

    public function test_verify_requires_is_valid_true_on_its_own_line(): void
    {
        Http::fake([
            SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT => Http::response("note:is_valid:true-but-not-really\n"),
        ]);

        $this->assertNull(app(SteamOpenIdVerifier::class)->verify($this->validPayload()));
    }

    private function validPayload(): array
    {
        return [
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => 'id_res',
            'openid.op_endpoint' => SteamOpenIdVerifier::STEAM_OPENID_ENDPOINT,
            'openid.return_to' => route('steam.callback', absolute: true),
            'openid.claimed_id' => 'https://steamcommunity.com/openid/id/76561198000000000',
            'openid.identity' => 'https://steamcommunity.com/openid/id/76561198000000000',
            'openid.response_nonce' => '2026-07-02T00:00:00Zabc',
            'openid.assoc_handle' => '123',
            'openid.signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
            'openid.sig' => 'signature',
        ];
    }
}
