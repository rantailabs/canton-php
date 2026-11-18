<?php

declare(strict_types=1);

namespace RantaiLabs\Canton\Tests;

use Orchestra\Testbench\TestCase;
use RantaiLabs\Canton\Laravel\Canton;
use RantaiLabs\Canton\Laravel\CantonServiceProvider;
use RantaiLabs\Canton\LedgerClient;

final class LaravelTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [CantonServiceProvider::class];
    }

    public function testConfigDefaultsPointToLocalNet(): void
    {
        $this->assertSame('http://localhost:3975', config('canton.url'));
        $this->assertSame('ledger-api-user', config('canton.user'));
    }

    public function testContainerBuildsOneSharedLedgerClient(): void
    {
        $client = $this->app->make(LedgerClient::class);

        $this->assertInstanceOf(LedgerClient::class, $client);
        $this->assertSame($client, $this->app->make(LedgerClient::class));
    }

    public function testOauth2ModeBuildsClientWithoutFetchingTokenUpFront(): void
    {
        config([
            'canton.auth' => 'oauth2',
            'canton.token_url' => 'http://auth.test/token',
            'canton.client_id' => 'my-client',
            'canton.client_secret' => 'my-secret',
        ]);

        $this->assertInstanceOf(LedgerClient::class, $this->app->make(LedgerClient::class));
    }

    public function testFacadeForwardsToLedgerClient(): void
    {
        Canton::shouldReceive('ledgerEnd')->once()->andReturn(55);

        $this->assertSame(55, Canton::ledgerEnd());
    }
}
