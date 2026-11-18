<?php

declare(strict_types=1);

namespace RantaiLabs\Canton;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

/**
 * Gets access tokens from an OAuth2 provider (Keycloak, Auth0, ...) with the
 * client credentials grant, as used by backends on DevNet, TestNet and MainNet.
 * Tokens are cached and renewed shortly before they expire.
 *
 * Pass an instance to LedgerClient in place of a token string.
 */
final class ClientCredentials
{
    private Client $http;
    private ?string $token = null;
    private int $expiresAt = 0;

    /**
     * @param string|null $audience Sent as "audience" (needed by Auth0)
     * @param string|null $scope    Sent as "scope" (e.g. "openid" for Keycloak)
     */
    public function __construct(
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $audience = null,
        private readonly ?string $scope = null,
        ?HandlerStack $handler = null,
    ) {
        $this->http = new Client(['http_errors' => false, 'handler' => $handler]);
    }

    public function __invoke(): string
    {
        // renew 30 seconds early so a token never expires mid-request
        if ($this->token === null || time() >= $this->expiresAt - 30) {
            $this->fetch();
        }

        return $this->token;
    }

    private function fetch(): void
    {
        $response = $this->http->post($this->tokenUrl, ['form_params' => array_filter([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'audience' => $this->audience,
            'scope' => $this->scope,
        ])]);
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
