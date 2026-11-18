<?php

declare(strict_types=1);

namespace RantaiLabs\Canton;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use stdClass;

/**
 * Client for the Canton JSON Ledger API (v2).
 * Not final, so Laravel apps can mock it with Canton::shouldReceive().
 */
class LedgerClient
{
    private Client $http;

    /** @var string|callable(): string */
    private $token;

    /**
     * @param string $baseUrl JSON Ledger API address, e.g. http://localhost:3975
     * @param string|callable(): string $token Bearer token, or something that returns a fresh one
     *                                         on every call (e.g. ClientCredentials)
     * @param float $timeout Seconds to wait for an answer before giving up
     * @param HandlerStack|null $handler Custom Guzzle handler, mainly for tests
     */
    public function __construct(
        string $baseUrl,
        string|callable $token,
        float $timeout = 30,
        ?HandlerStack $handler = null,
    ) {
        $this->token = $token;
        $this->http = new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'http_errors' => false,
            'connect_timeout' => min($timeout, 10),
            'timeout' => $timeout,
            'handler' => $handler,
        ]);
    }

    /** Participant version and supported features. */
    public function version(): array
    {
        return $this->request('GET', '/v2/version');
    }

    /** Offset of the latest transaction the participant has seen. */
    public function ledgerEnd(): int
    {
        return $this->request('GET', '/v2/state/ledger-end')['offset'];
    }

    /** Parties known to the participant. */
    public function parties(): array
    {
        return $this->request('GET', '/v2/parties')['partyDetails'];
    }

    /**
     * Contracts currently active for a party, as created events
     * (contractId, templateId, createArgument, signatories, ...).
     *
     * @param int|null $atOffset Snapshot offset; defaults to the ledger end
     */
    public function activeContracts(string $party, ?int $atOffset = null): array
    {
        $entries = $this->request('POST', '/v2/state/active-contracts', [
            'activeAtOffset' => $atOffset ?? $this->ledgerEnd(),
            'eventFormat' => [
                'filtersByParty' => [
                    $party => [
                        'cumulative' => [
                            ['identifierFilter' => ['WildcardFilter' => ['value' => ['includeCreatedEventBlob' => false]]]],
                        ],
                    ],
                ],
                'verbose' => true,
            ],
        ]);

        // Entries can also be in-flight reassignments; keep only active contracts.
        $contracts = [];
        foreach ($entries as $entry) {
            if (isset($entry['contractEntry']['JsActiveContract'])) {
                $contracts[] = $entry['contractEntry']['JsActiveContract']['createdEvent'];
            }
        }

        return $contracts;
    }

    /**
     * Create a contract and wait until it is committed.
     *
     * @param string $templateId e.g. "#my-package:My.Module:MyTemplate"
     * @param string|null $commandId See exercise()
     * @return array The created event (contractId, createArgument, ...)
     */
    public function create(string $templateId, array $arguments, string $actAs, ?string $commandId = null): array
    {
        $events = $this->submit(['CreateCommand' => [
            'templateId' => $templateId,
            'createArguments' => $arguments ?: new stdClass(),
        ]], $actAs, $commandId);

        return $events[0]['CreatedEvent'];
    }

    /**
     * Exercise a choice on a contract and wait until it is committed.
     *
     * @param string|null $commandId Your own ID for this command. When a call fails with a
     *     network error, retry it with the same ID: if the first attempt did reach the ledger,
     *     the retry is rejected with DUPLICATE_COMMAND instead of running twice.
     *     A random ID is used when omitted.
     * @return mixed The value returned by the choice, e.g. a new contract ID
     */
    public function exercise(
        string $templateId,
        string $contractId,
        string $choice,
        array $argument,
        string $actAs,
        ?string $commandId = null,
    ): mixed {
        $events = $this->submit(['ExerciseCommand' => [
            'templateId' => $templateId,
            'contractId' => $contractId,
            'choice' => $choice,
            'choiceArgument' => $argument ?: new stdClass(),
        ]], $actAs, $commandId);

        return $events[0]['ExercisedEvent']['exerciseResult'];
    }

    /** Submit one command and return the events of the resulting transaction, root event first. */
    private function submit(array $command, string $actAs, ?string $commandId): array
    {
        $response = $this->request('POST', '/v2/commands/submit-and-wait-for-transaction', [
            'commands' => [
                'commandId' => $commandId ?? bin2hex(random_bytes(16)),
                'actAs' => [$actAs],
                'commands' => [$command],
            ],
            'transactionFormat' => [
                'transactionShape' => 'TRANSACTION_SHAPE_LEDGER_EFFECTS',
                'eventFormat' => ['filtersByParty' => [$actAs => new stdClass()], 'verbose' => true],
            ],
        ]);

        return $response['transaction']['events'];
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $token = is_string($this->token) ? $this->token : ($this->token)();
        $options = ['headers' => ['Authorization' => "Bearer $token"]];
        if ($body !== null) {
            $options['json'] = $body;
        }
        try {
            $response = $this->http->request($method, $path, $options);
        } catch (TransferException $e) {
            // node unreachable or too slow; no HTTP status
            throw new LedgerException($e->getMessage(), 0, null, $e);
        }
        $content = (string) $response->getBody();
        $status = $response->getStatusCode();

        if ($status >= 400) {
            $error = json_decode($content, true);
            throw new LedgerException(
                $error['cause'] ?? $content,
                $status,
                $error['code'] ?? null,
            );
        }

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
