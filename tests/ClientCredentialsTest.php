<?php

declare(strict_types=1);

namespace RantaiLabs\Canton\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RantaiLabs\Canton\ClientCredentials;
use RantaiLabs\Canton\LedgerClient;
use RantaiLabs\Canton\LedgerException;

final class ClientCredentialsTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    private function stack(Response ...$responses): HandlerStack
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        return $stack;
    }

    private static function token(string $token, int $expiresIn): Response
    {
        return new Response(200, [], json_encode(['access_token' => $token, 'expires_in' => $expiresIn]));
    }

    public function testRequestsTokenWithClientCredentialsGrant(): void
    {
        $credentials = new ClientCredentials(
            'http://auth.test/token', 'my-client', 'my-secret', 'https://canton.network.global', null,
            $this->stack(self::token('abc', 300)),
        );

        $this->assertSame('abc', $credentials());

        parse_str((string) $this->sent[0]['request']->getBody(), $form);
        $this->assertSame([
            'grant_type' => 'client_credentials',
            'client_id' => 'my-client',
            'client_secret' => 'my-secret',
            'audience' => 'https://canton.network.global',
        ], $form);
    }

    public function testReusesTokenUntilItIsAboutToExpire(): void
    {
        // only one response queued: a second token request would fail
        $credentials = new ClientCredentials(
            'http://auth.test/token', 'my-client', 'my-secret', handler: $this->stack(self::token('long-lived', 300)),
        );

        $this->assertSame('long-lived', $credentials());
        $this->assertSame('long-lived', $credentials());
    }

    public function testRenewsTokenThatExpiresWithinThirtySeconds(): void
    {
        $credentials = new ClientCredentials(
            'http://auth.test/token', 'my-client', 'my-secret', handler: $this->stack(
                self::token('short-lived', 10),
                self::token('next', 300),
            ),
        );

        $this->assertSame('short-lived', $credentials());
        $this->assertSame('next', $credentials());
    }

    public function testRejectedCredentialsThrowLedgerException(): void
    {
        $credentials = new ClientCredentials('http://auth.test/token', 'my-client', 'wrong', handler: $this->stack(
            new Response(401, [], json_encode(['error' => 'unauthorized_client', 'error_description' => 'Invalid client secret'])),
        ));

        try {
            $credentials();
            $this->fail('Expected LedgerException');
        } catch (LedgerException $e) {
            $this->assertSame('Invalid client secret', $e->getMessage());
            $this->assertSame('unauthorized_client', $e->errorCode);
        }
    }

    public function testLedgerClientAsksForTokenOnEveryRequest(): void
    {
        $tokens = ['first', 'second'];
        $client = new LedgerClient('http://ledger.test', function () use (&$tokens) {
            return array_shift($tokens);
        }, $this->stack(
            new Response(200, [], '{"offset":1}'),
            new Response(200, [], '{"offset":2}'),
        ));

        $client->ledgerEnd();
        $client->ledgerEnd();

        $this->assertSame('Bearer first', $this->sent[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer second', $this->sent[1]['request']->getHeaderLine('Authorization'));
    }
}
