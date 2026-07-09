<?php

namespace Tests\Unit;

use App\Support\ShellEnv;
use PHPUnit\Framework\TestCase;

class ShellEnvTest extends TestCase
{
    public function test_wraps_value_in_single_quotes(): void
    {
        $this->assertSame(
            "export STRIPE_SECRET='sk_test_123'",
            ShellEnv::export('STRIPE_SECRET', 'sk_test_123'),
        );
    }

    public function test_escapes_embedded_single_quote(): void
    {
        $this->assertSame(
            "export PW='a'\\''b'",
            ShellEnv::export('PW', "a'b"),
        );
    }
}
