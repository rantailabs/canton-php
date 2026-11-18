<?php

declare(strict_types=1);

namespace RantaiLabs\Canton\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
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

        return new LedgerClient('http://ledger.test/', 'my-token', handler: $stack);
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

    public function testPartiesFollowsPagesUntilNoNextPageToken(): void
    {
        $parties = $this->client(
            self::json(['partyDetails' => [['party' => 'alice::1220ab']], 'nextPageToken' => 'page-2']),
            self::json(['partyDetails' => [['party' => 'bob::1220cd']], 'nextPageToken' => '']),
        )->parties();

        $this->assertSame([['party' => 'alice::1220ab'], ['party' => 'bob::1220cd']], $parties);
        $this->assertSame('', $this->sent[0]['request']->getUri()->getQuery());
        $this->assertSame('pageToken=page-2', $this->sent[1]['request']->getUri()->getQuery());
    }

    public function testAuthenticatedUserConnectedSynchronizersAndPackages(): void
    {
        $client = $this->client(
            self::json(['user' => ['id' => 'ledger-api-user', 'primaryParty' => 'alice::1220ab']]),
            self::json(['connectedSynchronizers' => [['synchronizerId' => 'global-domain::1220ef']]]),
            self::json(['packageIds' => ['abc123']]),
        );

        $this->assertSame('alice::1220ab', $client->authenticatedUser()['primaryParty']);
        $this->assertSame('global-domain::1220ef', $client->connectedSynchronizers('alice::1220ab')[0]['synchronizerId']);
        $this->assertSame(['abc123'], $client->packages());
        $this->assertSame('party=alice%3A%3A1220ab', $this->sent[1]['request']->getUri()->getQuery());
    }

    public function testActiveContractsFollowsPagesAndKeepsOnlyActiveContracts(): void
    {
        $first = ['contractId' => '00abc'];
        $second = ['contractId' => '00def'];

        $contracts = $this->client(
            self::json(['activeAtOffset' => 55, 'nextPageToken' => 'page-2', 'activeContracts' => [
                ['contractEntry' => ['JsActiveContract' => ['createdEvent' => $first]]],
                ['contractEntry' => ['JsIncompleteAssigned' => ['assignedEvent' => []]]],
            ]]),
            self::json(['activeAtOffset' => 55, 'nextPageToken' => '', 'activeContracts' => [
                ['contractEntry' => ['JsActiveContract' => ['createdEvent' => $second]]],
            ]]),
        )->activeContracts('alice::1220ab');

        $this->assertSame([$first, $second], $contracts);

        $firstBody = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertSame('/v2/state/active-contracts-page', $this->sent[0]['request']->getUri()->getPath());
        $this->assertArrayHasKey('alice::1220ab', $firstBody['eventFormat']['filtersByParty']);
        $this->assertArrayNotHasKey('activeAtOffset', $firstBody);

        $secondBody = json_decode((string) $this->sent[1]['request']->getBody(), true);
        $this->assertSame('page-2', $secondBody['pageToken']);
        // the node rejects a page token sent together with an offset
        $this->assertArrayNotHasKey('activeAtOffset', $secondBody);
    }

    public function testActiveContractsSendsGivenOffsetWithFirstPageOnly(): void
    {
        $this->client(
            self::json(['activeAtOffset' => 10, 'nextPageToken' => 'page-2', 'activeContracts' => []]),
            self::json(['activeAtOffset' => 10, 'nextPageToken' => '', 'activeContracts' => []]),
        )->activeContracts('alice::1220ab', 10);

        $this->assertSame(10, json_decode((string) $this->sent[0]['request']->getBody(), true)['activeAtOffset']);
        $this->assertArrayNotHasKey('activeAtOffset', json_decode((string) $this->sent[1]['request']->getBody(), true));
    }

    public function testLoggerGetsMethodPathAndStatusButNoToken(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        $stack = HandlerStack::create(new MockHandler([self::json(['offset' => 55])]));
        (new LedgerClient('http://ledger.test/', 'secret-token', logger: $logger, handler: $stack))->ledgerEnd();

        [$level, $message, $context] = $logger->records[0];
        $this->assertSame('debug', $level);
        $this->assertSame(['GET', '/v2/state/ledger-end', 200], [$context['method'], $context['path'], $context['status']]);
        $this->assertStringNotContainsString('secret-token', json_encode($logger->records));
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

    public function testOwnCommandIdIsSentSoRetriesCanBeDeduplicated(): void
    {
        $this->client(self::json(['transaction' => ['events' => [['CreatedEvent' => ['contractId' => '00abc']]]]]))
            ->create('#pkg:Main:Asset', ['owner' => 'alice::1220ab'], 'alice::1220ab', commandId: 'order-42');

        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertSame('order-42', $body['commands']['commandId']);
    }

    public function testRequestsHaveATimeout(): void
    {
        $this->client(self::json(['version' => '3.5.7']))->version();

        $this->assertEquals(30, $this->sent[0]['options']['timeout']);
        $this->assertEquals(10, $this->sent[0]['options']['connect_timeout']);
    }

    public function testUnreachableNodeThrowsLedgerException(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'http://ledger.test/v2/version')),
        ]));
        $client = new LedgerClient('http://ledger.test/', 'my-token', handler: $stack);

        try {
            $client->version();
            $this->fail('Expected LedgerException');
        } catch (LedgerException $e) {
            $this->assertSame(0, $e->httpStatus);
            $this->assertInstanceOf(ConnectException::class, $e->getPrevious());
        }
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
