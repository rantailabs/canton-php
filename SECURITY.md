# Security policy

## Reporting a vulnerability

Please do not open a public issue. Report it privately through
[GitHub security advisories](https://github.com/rantailabs/canton-php/security/advisories/new).

You will get a first answer within 7 days. Once a fix is released, the
advisory is published with credit to the reporter, unless you prefer
otherwise.

## Supported versions

Security fixes go into the latest release.

## Notes for users

- The shared-secret defaults (`CANTON_SECRET=unsafe`) exist only for
  cn-quickstart LocalNet. Never use shared-secret auth against a node that
  holds real assets.
- With OAuth2, access tokens are kept in the cache you configure (in Laravel,
  the default cache store). Anyone who can read that cache can act as your
  ledger user until the token expires, so protect it like any other secret
  store.
- Logging never includes tokens or request and response bodies.
