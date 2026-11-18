<?php

declare(strict_types=1);

namespace RantaiLabs\Canton\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RantaiLabs\Canton\LedgerClient;
use RantaiLabs\Canton\LedgerException;

final class LedgerClientTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    /** Client whose HTTP calls return the given responses in order. */
    private function client(Response ...$responses): LedgerClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        return new LedgerClient('http://ledger.test/', 'my-token', $stack);
    }

    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function testVersionSendsBearerToken(): void
    {
        $version = $this->client(self::json(['version' => '3.5.7']))->version();

        $this->assertSame('3.5.7', $version['version']);
        $request = $this->sent[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('http://ledger.test/v2/version', (string) $request->getUri());
        $this->assertSame('Bearer my-token', $request->getHeaderLine('Authorization'));
    }

    public function testLedgerEndReturnsOffset(): void
    {
        $this->assertSame(55, $this->client(self::json(['offset' => 55]))->ledgerEnd());
    }

    public function testPartiesReturnsPartyDetails(): void
    {
        $parties = $this->client(self::json(['partyDetails' => [['party' => 'alice::1220ab', 'isLocal' => true]]]))->parties();

        $this->assertSame([['party' => 'alice::1220ab', 'isLocal' => true]], $parties);
    }

    public function testActiveContractsQueriesAtLedgerEndAndKeepsOnlyActiveContracts(): void
    {
        $contract = ['contractId' => '00abc', 'templateId' => 'pkg:Licensing.License:License'];

        $contracts = $this->client(
            self::json(['offset' => 55]),
            self::json([
                ['contractEntry' => ['JsActiveContract' => ['createdEvent' => $contract]]],
                ['contractEntry' => ['JsIncompleteAssigned' => ['assignedEvent' => []]]],
            ]),
        )->activeContracts('alice::1220ab');

        $this->assertSame([$contract], $contracts);

        $body = json_decode((string) $this->sent[1]['request']->getBody(), true);
        $this->assertSame(55, $body['activeAtOffset']);
        $this->assertArrayHasKey('alice::1220ab', $body['eventFormat']['filtersByParty']);
    }

    public function testActiveContractsUsesGivenOffsetWithoutAskingLedgerEnd(): void
    {
        $this->client(self::json([]))->activeContracts('alice::1220ab', 10);

        $this->assertCount(1, $this->sent);
        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertSame(10, $body['activeAtOffset']);
    }

    public function testCreateSubmitsCreateCommandAndReturnsCreatedEvent(): void
    {
        $created = ['contractId' => '00abc', 'createArgument' => ['owner' => 'alice::1220ab']];

        $result = $this->client(
            self::json(['transaction' => ['events' => [['CreatedEvent' => $created]]]]),
        )->create('#pkg:Main:Asset', ['owner' => 'alice::1220ab'], 'alice::1220ab');

        $this->assertSame($created, $result);

        $request = $this->sent[0]['request'];
        $this->assertSame('/v2/commands/submit-and-wait-for-transaction', $request->getUri()->getPath());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(['alice::1220ab'], $body['commands']['actAs']);
        $this->assertNotEmpty($body['commands']['commandId']);
        $this->assertSame(
            [['CreateCommand' => ['templateId' => '#pkg:Main:Asset', 'createArguments' => ['owner' => 'alice::1220ab']]]],
            $body['commands']['commands'],
        );
    }

    public function testExerciseReturnsChoiceResult(): void
    {
        $result = $this->client(self::json(['transaction' => ['events' => [
            ['ExercisedEvent' => ['choice' => 'Accept', 'exerciseResult' => '00new']],
            ['CreatedEvent' => ['contractId' => '00new']],
        ]]]))->exercise('#pkg:Main:Offer', '00old', 'Accept', [], 'bob::1220cd');

        $this->assertSame('00new', $result);

        $body = (string) $this->sent[0]['request']->getBody();
        // a choice without arguments must be sent as {} and not []
        $this->assertStringContainsString('"choiceArgument":{}', $body);
        $command = json_decode($body, true)['commands']['commands'][0]['ExerciseCommand'];
        $this->assertSame(['00old', 'Accept'], [$command['contractId'], $command['choice']]);
    }

    public function testErrorResponseThrowsLedgerExceptionWithCantonCode(): void
    {
        $client = $this->client(self::json(['code' => 'PERMISSION_DENIED', 'cause' => 'Token is invalid'], 403));

        try {
            $client->parties();
            $this->fail('Expected LedgerException');
        } catch (LedgerException $e) {
            $this->assertSame('Token is invalid', $e->getMessage());
            $this->assertSame(403, $e->httpStatus);
            $this->assertSame('PERMISSION_DENIED', $e->errorCode);
        }
    }
}
