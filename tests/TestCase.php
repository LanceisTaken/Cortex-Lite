<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum only attaches the session middleware (StartSession) to
        // requests it recognizes as coming from the SPA frontend, i.e. an
        // Origin/Referer header matching a configured stateful domain
        // (see EnsureFrontendRequestsAreStateful::fromFrontend()). Without
        // this, $request->session() throws in any test that logs a user in.
        // Set a default Origin header matching the first stateful domain so
        // feature tests exercise the same stateful-cookie auth path the
        // real SPA uses.
        $stateful = config('sanctum.stateful')[0] ?? 'localhost';

        $this->withHeader('Origin', "http://{$stateful}");
    }
}
