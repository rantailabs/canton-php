# canton-php

PHP client for the [Canton](https://www.canton.network/) JSON Ledger API (v2).

Early work in progress.

## Authentication

LocalNet from cn-quickstart uses a shared secret:

```php
$token = SharedSecretToken::create('ledger-api-user', 'https://canton.network.global', 'unsafe');
```

DevNet, TestNet and MainNet nodes use an OAuth2 provider. `ClientCredentials`
gets tokens with the client credentials grant and renews them before they expire:

```php
$token = new ClientCredentials(
    tokenUrl: 'https://auth.example.com/oauth/token',
    clientId: 'my-backend',
    clientSecret: getenv('CANTON_CLIENT_SECRET'),
    audience: 'https://canton.network.global', // needed by Auth0
    scope: 'openid',                           // needed by Keycloak
);
$ledger = new LedgerClient('https://ledger.example.com', $token);
```

`LedgerClient` accepts any callable that returns a token, so other token
sources can be plugged in the same way.

## Install

```bash
composer require rantailabs/canton-php
```

## Usage

```php
use RantaiLabs\Canton\LedgerClient;
use RantaiLabs\Canton\SharedSecretToken;

// LocalNet (cn-quickstart) runs in shared-secret auth mode
$token = SharedSecretToken::create('ledger-api-user', 'https://canton.network.global', 'unsafe');
$ledger = new LedgerClient('http://localhost:3975', $token);

$ledger->version();               // participant version and features
$ledger->ledgerEnd();             // latest offset
$ledger->parties();               // known parties
$ledger->activeContracts($party); // active contracts visible to $party

// write: both wait until the transaction is committed
$created = $ledger->create('#my-package:My.Module:Asset', ['owner' => $party], actAs: $party);
$result = $ledger->exercise('#my-package:My.Module:Asset', $created['contractId'], 'Transfer', ['newOwner' => $bob], actAs: $party);
```

Daml records with no fields (e.g. an empty `TextMap`) must be passed as
`new stdClass()`, since PHP encodes an empty array as `[]`.

See [examples/licensing-demo.php](examples/licensing-demo.php) for a full flow
against the cn-quickstart licensing app.

Errors from the participant throw `RantaiLabs\Canton\LedgerException`
with `httpStatus` and the Canton `errorCode`.

## Laravel

The service provider and the `Canton` facade are registered automatically.
Configure the connection in `.env`:

```dotenv
CANTON_URL=http://localhost:3975
CANTON_USER=ledger-api-user
CANTON_AUDIENCE=https://canton.network.global
CANTON_SECRET=unsafe

# or, for OAuth2
CANTON_AUTH=oauth2
CANTON_TOKEN_URL=https://auth.example.com/oauth/token
CANTON_CLIENT_ID=my-backend
CANTON_CLIENT_SECRET=...
CANTON_SCOPE=openid
```

```php
use RantaiLabs\Canton\Laravel\Canton;

Canton::activeContracts($party);
Canton::create('#my-package:My.Module:Asset', ['owner' => $party], actAs: $party);
```

You can also inject `RantaiLabs\Canton\LedgerClient`, and mock it in tests with
`Canton::shouldReceive(...)`. To publish the config file:

```bash
php artisan vendor:publish --tag=canton-config
```

## Tests

```bash
composer test                     # unit tests
CANTON_LOCALNET=1 composer test        # plus tests against a running cn-quickstart LocalNet
CANTON_LOCALNET=oauth2 composer test   # same, for a LocalNet started with AUTH_MODE=oauth2
```

## License

Apache-2.0
