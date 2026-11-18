<?php

declare(strict_types=1);

namespace RantaiLabs\Canton;

/**
 * Builds an HS256 JWT for participants running in shared-secret auth mode
 * (e.g. LocalNet from cn-quickstart). Production nodes use an OAuth2 provider instead.
 */
final class SharedSecretToken
{
    public static function create(string $user, string $audience, string $secret): string
    {
        $header = self::base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64Url(json_encode(['sub' => $user, 'aud' => $audience], JSON_THROW_ON_ERROR));
        $signature = self::base64Url(hash_hmac('sha256', "$header.$payload", $secret, true));

        return "$header.$payload.$signature";
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
