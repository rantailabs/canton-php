<?php

declare(strict_types=1);

namespace RantaiLabs\Canton\Laravel;

use Illuminate\Support\Facades\Facade;
use RantaiLabs\Canton\LedgerClient;

/**
 * @method static array version()
 * @method static int ledgerEnd()
 * @method static array parties()
 * @method static array activeContracts(string $party, ?int $atOffset = null)
 * @method static array create(string $templateId, array $arguments, string $actAs)
 * @method static mixed exercise(string $templateId, string $contractId, string $choice, array $argument, string $actAs)
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
