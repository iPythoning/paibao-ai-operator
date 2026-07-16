# Paibao AI Operations Officer for WordPress

GPL-2.0-or-later WordPress bridge and administrator approval console for the Paibao AI Operations managed service.

The plugin provides two first-party surfaces:

- a site-bound REST bridge for controlled content, SEO and GEO changes;
- an administrator page for creating proposals, reviewing change summaries, explicitly approving execution and requesting rollback.

The hosted planning, orchestration, entitlement, billing and verification service is not included. The GPL license for this plugin does not create or grant a Paibao SaaS subscription.

## Security contract

- Customers never paste a service token into WordPress.
- The managed host injects a site-bound `st_` credential through server configuration. It is exchanged for a five-minute `aio_` session in PHP memory; the session is never persisted or sent to the browser.
- The control plane reaches the bridge with a dedicated WordPress user, dedicated `paibao_ai_operator` role and Application Password. That identity is restricted to `paibao-ai-operations/v1` and cannot use XML-RPC.
- Mutations require opaque CAS (`If-Match`), deterministic idempotency, an InnoDB transaction, before/after revisions and a transactional audit record.
- Approve and rollback are only initiated by explicit administrator POST forms guarded by capability, nonce, same-origin validation, exact fields and confirmation.
- Arbitrary scripts are not supported. Managed SEO/GEO is schema-limited and conflicts with Yoast, Rank Math, AIOSEO or SEOPress fail closed.

## Managed installation

Requirements: WordPress 6.4+, PHP 8.1+, MySQL/MariaDB with InnoDB, HTTPS and a Paibao-managed site binding.

The managed installer defines these server-side constants without exposing their values in the CMS:

```php
define( 'PAIBAO_AI_OPERATIONS_TENANT_ID', getenv( 'PAIBAO_AI_OPERATIONS_TENANT_ID' ) );
define( 'PAIBAO_AI_OPERATIONS_SITE_ID', getenv( 'PAIBAO_AI_OPERATIONS_SITE_ID' ) );
define( 'PAIBAO_AI_OPERATIONS_LOCALE', getenv( 'PAIBAO_AI_OPERATIONS_LOCALE' ) );
define( 'PAIBAO_MARKETPLACE_SITE_TOKEN', getenv( 'PAIBAO_MARKETPLACE_SITE_TOKEN' ) );
```

Activation creates the dedicated role and transactional audit tables. Provisioning then creates a service user with only that role and stores its Application Password in the Paibao control plane. It must not be shown to customers or reused by another integration.

## Verification

```sh
npm ci
npm run check
npm run build
```

`npm run build` creates a deterministic ZIP, SHA-256 checksum, release manifest and static security audit under `dist/`.

## License and service boundary

Plugin code is licensed under GPL-2.0-or-later; see `LICENSE`. Paibao control-plane code, hosted AI operations, Marketplace billing and managed support are separate proprietary services governed by their service terms.
