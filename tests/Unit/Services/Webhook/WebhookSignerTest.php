<?php

namespace Tests\Unit\Services\Webhook;

use App\Services\Webhook\WebhookSigner;
use PHPUnit\Framework\TestCase;

class WebhookSignerTest extends TestCase
{
    public function test_sign_is_deterministic_and_matches_expected_hash(): void
    {
        $signer = new WebhookSigner;
        $sig1 = $signer->sign('topsecret', '1700000000', 'evt-1', '{"a":1}');
        $sig2 = $signer->sign('topsecret', '1700000000', 'evt-1', '{"a":1}');

        $this->assertSame($sig1, $sig2);
        $this->assertSame(
            hash_hmac('sha256', '1700000000.evt-1.{"a":1}', 'topsecret'),
            $sig1
        );
    }

    public function test_sign_differs_when_any_input_changes(): void
    {
        $signer = new WebhookSigner;
        $base = $signer->sign('s', '1', 'e', 'b');

        $this->assertNotSame($base, $signer->sign('s2', '1', 'e', 'b'));
        $this->assertNotSame($base, $signer->sign('s', '2', 'e', 'b'));
        $this->assertNotSame($base, $signer->sign('s', '1', 'e2', 'b'));
        $this->assertNotSame($base, $signer->sign('s', '1', 'e', 'b2'));
    }

    public function test_headers_includes_required_fields(): void
    {
        $signer = new WebhookSigner;
        $headers = $signer->headers('secret', 'evt-1', '{"x":1}');

        $this->assertArrayHasKey('X-Pandora-Event-Id', $headers);
        $this->assertArrayHasKey('X-Pandora-Timestamp', $headers);
        $this->assertArrayHasKey('X-Pandora-Signature', $headers);
        $this->assertSame('evt-1', $headers['X-Pandora-Event-Id']);
        $this->assertSame('application/json', $headers['Content-Type']);

        // 用 timestamp + secret + body 重算應該對得起來
        $expected = hash_hmac(
            'sha256',
            "{$headers['X-Pandora-Timestamp']}.evt-1.{\"x\":1}",
            'secret'
        );
        $this->assertSame($expected, $headers['X-Pandora-Signature']);
    }
}
