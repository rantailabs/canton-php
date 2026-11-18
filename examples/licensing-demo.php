<?php

// Walks through the cn-quickstart licensing app from PHP against a running LocalNet:
//   php examples/licensing-demo.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use RantaiLabs\Canton\LedgerClient;
use RantaiLabs\Canton\LedgerException;
use RantaiLabs\Canton\SharedSecretToken;

$token = SharedSecretToken::create('ledger-api-user', 'https://canton.network.global', 'unsafe');
$provider = new LedgerClient('http://localhost:3975', $token);
$user = new LedgerClient('http://localhost:2975', $token);

$localParty = function (LedgerClient $client, string $prefix): string {
    foreach ($client->parties() as $party) {
        if ($party['isLocal'] && str_starts_with($party['party'], $prefix)) {
            return $party['party'];
        }
    }
    exit("No local party starting with $prefix\n");
};
$providerParty = $localParty($provider, 'app_provider');
$userParty = $localParty($user, 'app_user');
$meta = ['values' => new stdClass()];

echo "Connected to Canton {$provider->version()['version']}\n";

$request = $user->create('#quickstart-licensing:Licensing.AppInstall:AppInstallRequest', [
    'provider' => $providerParty,
    'user' => $userParty,
    'meta' => $meta,
], $userParty);
echo "1. User asked to install the app      -> " . substr($request['contractId'], 0, 16) . "...\n";

$installId = $provider->exercise(
    '#quickstart-licensing:Licensing.AppInstall:AppInstallRequest',
    $request['contractId'],
    'AppInstallRequest_Accept',
    ['installMeta' => $meta, 'meta' => $meta],
    $providerParty,
);
echo "2. Provider accepted the installation  -> " . substr($installId, 0, 16) . "...\n";

for ($i = 1; $i <= 4; $i++) {
    try {
        $result = $provider->exercise(
            '#quickstart-licensing:Licensing.AppInstall:AppInstall',
            $installId,
            'AppInstall_CreateLicense',
            ['params' => ['meta' => $meta]],
            $providerParty,
        );
        $installId = $result['installId'];
        echo "3. Provider issued license #$i          -> " . substr($result['licenseId'], 0, 16) . "...\n";
    } catch (LedgerException $e) {
        echo "3. License #$i rejected by the ledger: {$e->errorCode}\n";
    }
}
