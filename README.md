# silvershop/newsletter-laposta

[Laposta](https://www.laposta.nl/) ESP adapter for
[`silvershop/newsletter`](https://github.com/RVXD/silvershop-newsletter). It implements the core's
`NewsletterProvider` contract with a **thin Guzzle client** (no heavy official SDK) and maps subscriber changes to
the Laposta API v2, plus a webhook back to the local list.

The core owns capture, consent and double opt-in; this adapter just syncs confirmed subscribers to a Laposta list
and keeps the local status in step via webhooks. Laposta is a Dutch ESP, so it fits the suite's NL-first lean.

## Requirements

- `silvershop/newsletter` `dev-main` (the core — not on Packagist, see **Installation**)
- `guzzlehttp/guzzle` ^7
- `silverstripe/framework` ^6, `silverstripe/cms` ^6, PHP 8.3 / 8.4

`silvershop/newsletter` hard-requires `symbiote/silverstripe-queuedjobs`, so the queued sync runner comes in
transitively.

## Installation

`silvershop/newsletter` is an RVXD module (not published to Packagist), so it is resolved from a composer
`repositories` entry. The shipped `composer.json` uses the **VCS** form:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/RVXD/silvershop-newsletter.git" }
]
```

Then:

```bash
composer require silvershop/newsletter-laposta:dev-main
```

(A private newsletter repo needs a GitHub token with `repo` scope, e.g. `composer config -g github-oauth.github.com <token>`.)

### Local development (core not yet pushed)

When you are developing both modules side by side and `silvershop/newsletter` isn't published yet, point the
adapter at your local checkout with a **path** repository instead of (or in addition to) the VCS entry — this is
how the dev site and the standalone clean-room resolve it:

```json
"repositories": [
    { "type": "path", "url": "../silvershop-newsletter" }
]
```

## Configuration

Credentials come from the environment (`.env`):

```
SS_LAPOSTA_API_KEY="your-api-key"
SS_LAPOSTA_LIST_ID="your-list-id"
SS_LAPOSTA_WEBHOOK_SECRET="the-webhook-secret"
```

Authentication is **HTTP Basic with the API key as the username and an empty password** (the curl `-u KEY:` form).
`SS_LAPOSTA_LIST_ID` is the target list's id.

Then make Laposta the active provider:

```yaml
SilverShop\Newsletter\Provider\NewsletterProviderRegistry:
  active: laposta
```

Laposta has no native first/last-name fields — everything is a **custom field**. Map the subscription's merge
fields onto your list's custom fields by their `custom_name` (the fields must already exist on the list):

```yaml
SilverShop\Newsletter\Laposta\LapostaProvider:
  custom_field_map:
    FirstName: 'voornaam'
    Surname: 'achternaam'
    Locale: 'taal'
```

### The required `ip`

Laposta **requires an `ip`** on member-create (its registration IP, for anti-spam / opt-in audit). The sync runs
inside a queued job with no originating web request, so there is no real subscriber IP at push time. The adapter
therefore sends a **configurable placeholder** (default `0.0.0.0`); set your server IP if you prefer:

```yaml
SilverShop\Newsletter\Laposta\LapostaProvider:
  default_ip: '0.0.0.0'
```

With single opt-in (the default, see below) Laposta does not run its public-signup spam check, so a placeholder IP
is expected to be accepted. **Verify against a live Laposta list** that `0.0.0.0` is accepted for API-originated
signups; if not, set `default_ip` to a real (server) IP. A cleaner long-term fix is to capture the real signup IP
in `silvershop/newsletter` core and have this adapter prefer it — tracked as a coordinated follow-up.

### API host

The adapter talks to `https://api.laposta.nl/v2/` by default. The Laposta docs show both `api.laposta.nl` and
`api.laposta.org`; the official PHP SDK uses `.nl`. Override only for a sandbox:

```yaml
SilverShop\Newsletter\Laposta\LapostaProvider:
  api_base: 'https://api.laposta.nl/v2/'
```

> **Configure the Laposta list as SINGLE opt-in.** The core already performs double opt-in (it emails the
> subscriber a confirm link and only syncs them once confirmed). The adapter therefore sends
> `options[ignore_doubleoptin]=true` so Laposta activates the member immediately and sends **no** second
> confirmation email. If the list is *also* set to double opt-in, subscribers could receive a redundant
> confirmation. If you would rather Laposta run the confirmed-opt-in step itself, set `double_opt_in: true` and the
> adapter omits `ignore_doubleoptin`:
>
> ```yaml
> SilverShop\Newsletter\Laposta\LapostaProvider:
>   double_opt_in: true
> ```

## How it maps

| Operation | Laposta API v2 |
|---|---|
| subscribe / update | `POST member` with `options[upsert]=true` (idempotent add-or-update) |
| unsubscribe | `POST member/{email}` with `state=unsubscribed` (soft — keeps the record, not a `DELETE`) |
| full resync (`sake tasks:NewsletterResync --run`) | upsert for every locally-subscribed record |

Member bodies are **form-encoded** (`application/x-www-form-urlencoded`), not JSON — nested parameters use PHP
array-bracket syntax (`custom_fields[voornaam]=…`, `options[upsert]=true`). The member is addressed by its **email
address** in the unsubscribe URL (no hash).

## Webhook (Laposta → local)

Register one webhook per event (`subscribed`, `modified`, `deactivated`) pointing at:

```
https://your-shop.example/newsletter/webhook/laposta
```

A webhook is created with `POST /v2/webhook` (`list_id`, `event`, `url`, `blocked=false`); the response includes a
per-webhook **`secret`** — that is `SS_LAPOSTA_WEBHOOK_SECRET`.

> **Real HMAC signature verification.** Unlike the Mailchimp / Campaign Monitor adapters (which only compare a
> shared secret), Laposta signs every webhook with **HMAC-SHA256 of the raw request body**, keyed with the
> webhook's `secret`, delivered as the header `X-Laposta-Signature: sha256=<hex>`. The adapter recomputes the HMAC
> over the raw body and constant-time compares it. If `SS_LAPOSTA_WEBHOOK_SECRET` is unset, the webhook is accepted
> without a signature (it only ever changes status for emails already on the local list).

Laposta posts a JSON **batch** — `{ "date_requested": …, "data": [ { "event": …, "data": { … }, "info": … }, … ] }`.
The core's webhook controller calls this adapter's `verifyWebhook()` + `parseWebhook()`, which map each event's
member `state` (and finer `info.action`) to the local status:

| Laposta signal | Local status |
|---|---|
| `event: subscribed` / `modified`, member `state: active` | Subscribed |
| `event: deactivated`, `info.action: unsubscribed` / `state: unsubscribed` | Unsubscribed |
| `info.action: hardbounce` | Bounced |
| member `state: cleaned` | Cleaned |
| `info.action: deleted` | Unsubscribed |

### Assumptions to verify against a live Laposta delivery

This v1 was built from the published API docs without a live sandbox account. The following defaults are applied
and made configurable; confirm them against a real Laposta list + webhook delivery, and adjust if needed:

- **API host** — `api.laposta.nl` (configurable via `api_base`; the docs also show `api.laposta.org`).
- **`ip` placeholder** — `0.0.0.0` is accepted for an API signup with `ignore_doubleoptin` (configurable via
  `default_ip`).
- **Webhook signature** — the header is `X-Laposta-Signature` with a literal `sha256=` prefix and lowercase hex.
- **`deactivated` action vocabulary** — `deleted` is mapped to Unsubscribed locally; status is driven primarily
  off the member `state`.

## Development

```bash
composer install
composer run lint    # phpcs (PSR-12)
composer run stan    # phpstan (cambis/silverstan, level 5)
composer run test    # phpunit — uses a FakeLapostaClient + a mocked Guzzle handler, no live HTTP
```

## License

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
