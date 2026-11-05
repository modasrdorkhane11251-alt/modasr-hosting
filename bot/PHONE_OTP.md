# MODASR phone and email OTP authentication

## Final channel decision

- Iranian users sign in with an Iranian mobile number (`+98`) and SMS OTP.
- International users sign in with an email address and email OTP.
- International SMS is disabled in the service and provider router. The legacy
  `MODASR_SMS_INTERNATIONAL_PROVIDER` variable is ignored.
- Telegram, Bale, Google, account linking, orders, wallets, payments, and the
  existing persistent sessions are preserved.

This implementation remains **test-only**. Mock providers are the defaults and
no real SMS or email is sent by the automated tests.

## OTP and abuse controls

Both channels use:

- Six-digit random codes and keyed HMAC-SHA256 storage, never plaintext codes.
- Two-minute expiration for phone OTP and five minutes for email OTP, with at most five verification attempts.
- Sixty-second resend cooldown.
- Per-destination and per-IP hourly/daily request budgets.
- Request-ID-bound verification and superseding of older codes after resend.
- Transactions, row locks, and conditional single-use consumption.
- No account-existence lookup during code request.
- No blind retry after a provider `unknown` result.

## Public Express APIs

### Iranian phone

`POST /api/auth/phone/request`

```json
{"phone":"+989121234567"}
```

`POST /api/auth/phone/verify`

```json
{"phone":"+989121234567","request_id":"32-lowercase-hex-characters","code":"123456"}
```

A non-Iranian phone returns `foreign_email_required` before an OTP row is
created or a provider is called.

### International email

`POST /api/auth/email/request`

```json
{"email":"foreign@example.com"}
```

`POST /api/auth/email/verify`

```json
{"email":"foreign@example.com","request_id":"32-lowercase-hex-characters","code":"123456"}
```

Both request endpoints return `request_id`, `expires_in: 300`, and
`resend_after: 60`, never the OTP. Successful verification returns the existing
persistent session token and MODASR user object.

The internal PHP endpoints `api/storefront_phone_auth.php` and
`api/storefront_email_auth.php` accept only signed server-to-server payloads.

## New-site account provisioning

No legacy-user migration or compatibility rewrite is needed. The architecture is
optimized for a new site:

- Requesting a code or entering a wrong code creates no user, identity binding,
  or session.
- Only successful OTP verification calls the shared
  `lib/auth_account.php` provisioning function inside the OTP transaction.
- New IDs are generated with `random_int` as numeric ten-digit MODASR user IDs,
  preserving the existing order/payment API contract. Collision checks and the
  user primary key protect uniqueness.
- Unique phone/email binding keys prevent duplicate accounts for the same
  verified identifier, including concurrent verification attempts.
- Express issues the existing persistent session only after successful
  verification. Failed verification cannot issue a session.
- The resulting MODASR user ID is the identifier already used by customer
  orders, future payments, and wallet-scoped queries.

Phone users have `platform = phone`; new email users have `platform = email`.
Bindings live in separate tables rather than overloading messenger IDs.

When an email OTP proves control of a mailbox already stored as `google_email`,
the binding is attached to that existing Google identity instead of creating a
duplicate account. This is cross-provider deduplication, not a legacy migration
or an automatic wallet merge. Existing primary-account resolution is then
applied without modifying wallet or order rows.

## Optional sign-in methods

Telegram, Bale, and Google are not required for the new site's core phone/email
registration flow. They remain unchanged as optional convenience methods rather
than being removed: deleting them would not reduce SMS/email provider costs and
would unnecessarily remove existing functionality. They should not be treated
as prerequisites for launch or as a second account system. After real usage is
available, retain them only if their completion rate and user demand justify
their maintenance and provider-specific compliance work.

## Schema

- `db/migrations/026_phone_otp.php` — Iranian phone OTP, phone identity, and
  the additive nullable `user.platform` column when it is absent.
- `db/migrations/027_email_otp.php` — email OTP and email identity.
- Rollback SQL is provided beside both migrations under
  `db/migrations/rollback/`; it does not drop the additive platform column.

No migration or rollback was executed on production. Applying or rolling back
either one requires explicit approval and a verified backup.

## Environment configuration

### Shared

```dotenv
MODASR_PHONE_OTP_HASH_KEY=replace-with-a-random-server-only-key
TRUST_PROXY=0
```

Use `TRUST_PROXY=1` only behind the trusted Apache reverse proxy. The same
server-only hash key protects phone and email OTP records.

### Development and tests

```dotenv
MODASR_SMS_IRAN_PROVIDER=mock
MODASR_EMAIL_PROVIDER=mock
```

No credentials or real delivery is needed.

### Iranian SMS with Ghasedak

```dotenv
MODASR_SMS_IRAN_PROVIDER=ghasedak
MODASR_GHASEDAK_API_BASE=https://gateway.ghasedak.me
MODASR_GHASEDAK_API_KEY=
MODASR_GHASEDAK_OTP_TEMPLATE=modasr_login
```

Create an OTP-eligible account and a template containing the single code
parameter. The adapter uses the documented `sendotpwithparams` operation with
`templateName`, `param1`, and `clientReferenceId`. API credentials exist only in
the PHP server environment and server-side `apikey` header.

### Iranian SMS with SMS.ir

```dotenv
MODASR_SMS_IRAN_PROVIDER=smsir
MODASR_SMSIR_API_BASE=https://api.sms.ir
MODASR_SMSIR_API_KEY=
MODASR_SMSIR_TEMPLATE_ID=707965
MODASR_SMSIR_PARAMETER_NAME=Code
```

The SMS.ir adapter posts `mobile`, `templateId`, and the six-digit code in the
`parameters` array to `/v1/send/verify`. The API key exists only in the
server-side `x-api-key` header. Template approval and delivery are separate
provider-side operations and require separate smoke-test approval.

### Email without a third-party provider, when an MTA already exists

```dotenv
MODASR_EMAIL_PROVIDER=native
MODASR_EMAIL_FROM=no-reply@modasr.site
MODASR_EMAIL_FROM_NAME=MODASR HUB
MODASR_EMAIL_REPLY_TO=
```

`native` uses PHP `mail()` and therefore requires a correctly configured MTA on
the server. The project inspection found no existing SMTP provider, mailer
library, or transactional-email configuration. Do not select `native` until the
server MTA, sender domain, SPF, DKIM, DMARC, and delivery behavior are verified.
`mail()` acceptance is not proof of inbox delivery. If no suitable MTA exists,
a future SMTP adapter will be required; do not purchase one during this phase.

## Verification

```bash
php tests/phone_otp_test.php
php tests/email_otp_test.php
php tests/ghasedak_sms_provider_test.php
php tests/smsir_sms_provider_test.php
npm run lint
npm run test:phone-auth
npm run build
node --check server.js
```

All provider responses and PDO storage used by these tests are synthetic. No
production database, migration, MTA, SMS provider, or customer account is used.
Real MySQL integration and separately approved delivery smoke tests remain
production-gated verification steps.
