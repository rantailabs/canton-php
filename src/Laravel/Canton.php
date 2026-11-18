<?php

declare(strict_types=1);

namespace RantaiLabs\Canton\Laravel;

use Illuminate\Support\Facades\Facade;
use RantaiLabs\Canton\LedgerClient;

/**
 * @method static array version()
 * @method static int ledgerEnd()
 * @method static array parties()
 * @method static array authenticatedUser()
 * @method static array connectedSynchronizers(string $party)
 * @method static array packages()
 * @method static array activeContracts(string $party, ?int $atOffset = null, ?int $pageSize = null)
 * @method static array create(string $templateId, array $arguments, string $actAs, ?string $commandId = null)
 * @method static mixed exercise(string $templateId, string $contractId, string $choice, array $argument, string $actAs, ?string $commandId = null)
 *
 * @see LedgerClient
 */
final class Canton extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LedgerClient::class;
    }
}
