# MODASR Hosting

Private control plane and runtime templates for MODASR multi-tenant bot hosting.

## Current state

- PHP 8.2 / PDO MySQL control plane
- Telegram Factory wizard with paid-order guard and manual receipt review
- Versioned workflow engine
- Tenant provisioning in stopped state with recovery support
- Full MODASR VPN core copied and configured per tenant
- Telegram, Bale and Soroush long-polling worker template
- Fifteen packages across four platforms: Telegram, Bale, Rubika and Soroush
- Rubika packages remain blocked until an independent HTTPS webhook route is available

## Security boundaries

- Never commit `.env`, real `config.php`, tokens, database dumps, tenant files or backups.
- Tenant tokens are encrypted at rest.
- New tenant provisioning does not start systemd services automatically.
- Only the configured platform owner can start, stop or inspect tenant runtimes.
- Mirza SQL/ZIP backups are quarantined and are never executed automatically.

## Repository layout

- `bot/` — Factory and MODASR BOT source
- `modasr-hosting-tenants/worker-template.php` — tenant runtime template
- `modasr-factory-spawn` — constrained systemd helper
- `modasr-factory-backup` — encrypted backup helper
- `payment_gate_test.php` — isolated payment/workflow/static regression checks

## Test

Run `payment_gate_test.php` in an environment where its relative fixture paths point to the Factory files. The test suite does not require a database, live token or external provider.

Production deployment, migrations, live payments and live provider tests require separate owner approval.
