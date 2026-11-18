<?php

declare(strict_types=1);

namespace RantaiLabs\Canton\Tests;

use PHPUnit\Framework\TestCase;
use RantaiLabs\Canton\SharedSecretToken;

final class SharedSecretTokenTest extends TestCase
{
    public function testTokenCarriesUserAndAudienceAndIsSignedWithSecret(): void
    {
        $token = SharedSecretToken::create('ledger-api-user', 'https://canton.network.global', 'unsafe');

        [$header, $payload, $signature] = explode('.', $token);
        $decode = fn (string $part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true);

        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $decode($header));
        $this->assertSame(['sub' => 'ledger-api-user', 'aud' => 'https://canton.network.global'], $decode($payload));

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", 'unsafe', true)), '+/', '-_'), '=');
        $this->assertSame($expected, $signature);
    }
}
