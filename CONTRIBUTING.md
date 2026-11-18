# Contributing

Thanks for helping. Issues and pull requests are welcome.

## Before you start

For anything larger than a small fix, please open an issue first so we can
agree on the approach.

## Development

```bash
composer install
composer test       # unit tests
composer analyse    # PHPStan
```

Integration tests run against a
[cn-quickstart](https://github.com/digital-asset/cn-quickstart) LocalNet:

```bash
CANTON_LOCALNET=1 composer test        # LocalNet with AUTH_MODE=shared-secret
CANTON_LOCALNET=oauth2 composer test   # LocalNet with AUTH_MODE=oauth2
```

## Pull requests

- Add or update tests for every change in behaviour.
- Keep `composer test` and `composer analyse` passing; CI runs both.
- Add a line to the "Unreleased" section of `CHANGELOG.md`.
- Match the style of the surrounding code.

By contributing you agree that your contribution is licensed under the
Apache License 2.0.
