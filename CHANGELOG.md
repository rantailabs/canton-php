# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/). Until 1.0, minor versions may
contain breaking changes; they are listed under "Changed".

## [Unreleased]

### Added

- `authenticatedUser()`, `connectedSynchronizers()` and `packages()`.
- `activeContracts()` takes an optional `pageSize`.
- Optional PSR-3 logger: one debug line per request (method, path, status,
  duration). Tokens and payloads are never logged. Laravel: `CANTON_LOGGING=true`.
- Request timeout (30 seconds by default). Laravel: `CANTON_TIMEOUT`.
- `create()` and `exercise()` take an optional `commandId` for safe retries.
- `ClientCredentials` can share tokens between PHP processes through a PSR-16
  cache. The Laravel integration uses the default cache store.
- CI on PHP 8.2 to 8.5 with lowest and highest dependencies, and PHPStan level 8.

### Changed

- `activeContracts()` and `parties()` now fetch page by page, so large result
  sets no longer fail with `413 Content Too Large`.
- Network failures throw `LedgerException` with `httpStatus` 0 instead of
  Guzzle exceptions.
- `LedgerClient` constructor: the Guzzle handler moved after the new `timeout`
  and `logger` arguments. Pass it by name (`handler:`).
- An unknown `CANTON_AUTH` value fails with a clear message.

## [0.1.0] - 2026-11-18

### Added

- `LedgerClient` for the JSON Ledger API v2: `version()`, `ledgerEnd()`,
  `parties()`, `activeContracts()`, `create()` and `exercise()`.
- Authentication with a shared secret (cn-quickstart LocalNet) or OAuth2
  client credentials (`ClientCredentials`).
- Laravel service provider, `Canton` facade and `config/canton.php`.
- Example of the cn-quickstart licensing flow.

[Unreleased]: https://github.com/rantailabs/canton-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/rantailabs/canton-php/releases/tag/v0.1.0
