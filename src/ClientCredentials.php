<?php

declare(strict_types=1);

namespace RantaiLabs\Canton;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use Psr\SimpleCache\CacheInterface;

/**
 * Gets access tokens from an OAuth2 provider (Keycloak, Auth0, ...) with the
 * client credentials grant, as used by backends on DevNet, TestNet and MainNet.
 * Tokens are reused and renewed shortly before they expire.
 *
 * Pass an instance to LedgerClient in place of a token string.
 */
final class ClientCredentials
{
    // renew this many seconds early so a token never expires mid-request
    private const EARLY_RENEWAL = 30;

    private Client $http;
    private ?string $token = null;
    private int $expiresAt = 0;

    /**
     * @param string|null $audience Sent as "audience" (needed by Auth0)
     * @param string|null $scope    Sent as "scope" (e.g. "openid" for Keycloak)
     * @param CacheInterface|null $cache Shares tokens between PHP processes. Without it, every
     *     process (e.g. every PHP-FPM request) asks the provider for a new token.
     */
    public function __construct(
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $audience = null,
        private readonly ?string $scope = null,
        private readonly ?CacheInterface $cache = null,
        ?HandlerStack $handler = null,
    ) {
        $this->http = new Client([
            'http_errors' => false,
            'connect_timeout' => 10,
            'timeout' => 10,
            'handler' => $handler,
        ]);
    }

    public function __invoke(): string
    {
        if ($this->token !== null && time() < $this->expiresAt - self::EARLY_RENEWAL) {
            return $this->token;
        }

        $key = 'canton-token-' . sha1("$this->tokenUrl|$this->clientId|$this->audience|$this->scope");
        $cached = $this->cache?->get($key);
        if (is_array($cached) && time() < $cached['expiresAt'] - self::EARLY_RENEWAL) {
            [$this->token, $this->expiresAt] = [$cached['token'], $cached['expiresAt']];

            return $this->token;
        }

        $this->fetch();
        $this->cache?->set(
            $key,
            ['token' => $this->token, 'expiresAt' => $this->expiresAt],
            $this->expiresAt - time(),
        );

        return $this->token;
    }

    private function fetch(): void
    {
        try {
            $response = $this->http->post($this->tokenUrl, ['form_params' => array_filter([
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'audience' => $this->audience,
                'scope' => $this->scope,
            ])]);
        } catch (TransferException $e) {
            throw new LedgerException($e->getMessage(), 0, null, $e);
        }
        $body = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() >= 400 || !isset($body['access_token'])) {
            throw new LedgerException(
                $body['error_description'] ?? 'Could not get an access token',
                $response->getStatusCode(),
                $body['error'] ?? null,
            );
        }

        $this->token = $body['access_token'];
        $this->expiresAt = time() + ($body['expires_in'] ?? 300);
    }
}
