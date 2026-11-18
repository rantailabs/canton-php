<?php

declare(strict_types=1);

namespace RantaiLabs\Canton\Tests;

use PHPUnit\Framework\TestCase;
use RantaiLabs\Canton\ClientCredentials;
use RantaiLabs\Canton\LedgerClient;
use RantaiLabs\Canton\LedgerException;
use RantaiLabs\Canton\SharedSecretToken;
use stdClass;

/**
 * Runs against a real cn-quickstart LocalNet. Skipped unless CANTON_LOCALNET is set:
 *   CANTON_LOCALNET=1 composer test        # LocalNet with AUTH_MODE=shared-secret
 *   CANTON_LOCALNET=oauth2 composer test   # LocalNet with AUTH_MODE=oauth2 (Keycloak)
 */
final class LocalNetTest extends TestCase
{
    private LedgerClient $provider;
    private LedgerClient $user;

    protected function setUp(): void
    {
        if (!getenv('CANTON_LOCALNET')) {
            $this->markTestSkipped('Set CANTON_LOCALNET=1 to run LocalNet tests');
        }

        if (getenv('CANTON_LOCALNET') === 'oauth2') {
            // test clients shipped with cn-quickstart's Keycloak realms
            $keycloak = fn (string $realm, string $client, string $secret) => new ClientCredentials(
                "http://keycloak.localhost:8082/realms/$realm/protocol/openid-connect/token",
                $client,
                $secret,
                scope: 'openid',
            );
            $providerToken = $keycloak('AppProvider', 'app-provider-validator', 'AL8648b9SfdTFImq7FV56Vd0KHifHBuC');
            $userToken = $keycloak('AppUser', 'app-user-validator', '6m12QyyGl81d9nABWQXMycZdXho6ejEX');
        } else {
            $providerToken = $userToken = SharedSecretToken::create('ledger-api-user', 'https://canton.network.global', 'unsafe');
        }

        $this->provider = new LedgerClient('http://localhost:3975', $providerToken);
        $this->user = new LedgerClient('http://localhost:2975', $userToken);
    }

    public function testReadsVersionPartiesAndActiveContracts(): void
    {
        $this->assertNotEmpty($this->provider->version()['version']);
        $this->assertGreaterThan(0, $this->provider->ledgerEnd());

        $local = array_values(array_filter($this->provider->parties(), fn (array $p) => $p['isLocal']));
        $this->assertNotEmpty($local);

        $party = $this->provider->authenticatedUser()['primaryParty'];
        $this->assertNotEmpty($this->provider->connectedSynchronizers($party));
        $this->assertNotEmpty($this->provider->packages());

        $all = $this->provider->activeContracts($party);
        foreach ($all as $contract) {
            $this->assertArrayHasKey('contractId', $contract);
            $this->assertArrayHasKey('templateId', $contract);
        }

        // small pages force several requests; the result must be the same set
        $this->assertGreaterThan(2, count($all));
        $paged = $this->provider->activeContracts($party, pageSize: 2);
        $this->assertEqualsCanonicalizing(array_column($all, 'contractId'), array_column($paged, 'contractId'));
    }

    public function testLicensingFlowAllowsAtMostThreeLicenses(): void
    {
        $providerParty = $this->localParty($this->provider, 'app_provider');
        $userParty = $this->localParty($this->user, 'app_user');
        $meta = ['values' => new stdClass()];

        $request = $this->user->create('#quickstart-licensing:Licensing.AppInstall:AppInstallRequest', [
            'provider' => $providerParty,
            'user' => $userParty,
            'meta' => $meta,
        ], $userParty);

        $installId = $this->provider->exercise(
            '#quickstart-licensing:Licensing.AppInstall:AppInstallRequest',
            $request['contractId'],
            'AppInstallRequest_Accept',
            ['installMeta' => $meta, 'meta' => $meta],
            $providerParty,
        );

        $createLicense = fn (string $installId) => $this->provider->exercise(
            '#quickstart-licensing:Licensing.AppInstall:AppInstall',
            $installId,
            'AppInstall_CreateLicense',
            ['params' => ['meta' => $meta]],
            $providerParty,
        );

        for ($i = 1; $i <= 3; $i++) {
            $result = $createLicense($installId);
            $this->assertArrayHasKey('licenseId', $result);
            $installId = $result['installId'];
        }

        $this->expectException(LedgerException::class);
        $this->expectExceptionMessage('Maksimal 3 lisensi per pelanggan');
        $createLicense($installId);
    }

    public function testRetryWithSameCommandIdIsRejectedAsDuplicate(): void
    {
        $providerParty = $this->localParty($this->provider, 'app_provider');
        $userParty = $this->localParty($this->user, 'app_user');
        $commandId = 'retry-test-' . bin2hex(random_bytes(8));
        $create = fn () => $this->user->create('#quickstart-licensing:Licensing.AppInstall:AppInstallRequest', [
            'provider' => $providerParty,
            'user' => $userParty,
            'meta' => ['values' => new stdClass()],
        ], $userParty, commandId: $commandId);

        $first = $create();
        $this->assertArrayHasKey('contractId', $first);

        try {
            $create();
            $this->fail('Expected the retry to be rejected');
        } catch (LedgerException $e) {
            $this->assertSame('DUPLICATE_COMMAND', $e->errorCode);
        }
    }

    public function testUnreachableNodeThrowsLedgerException(): void
    {
        $client = new LedgerClient('http://localhost:1', 'any-token', timeout: 2);

        $this->expectException(LedgerException::class);
        $client->version();
    }

    private function localParty(LedgerClient $client, string $prefix): string
    {
        foreach ($client->parties() as $party) {
            if ($party['isLocal'] && str_starts_with($party['party'], $prefix)) {
                return $party['party'];
            }
        }
        $this->fail("No local party starting with $prefix");
    }
}
