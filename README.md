# WopCart Platform

The server side of wopcart.com.

WopCart's free plan works because wopcart.com stands between a merchant's store and
Stripe: the store holds no Stripe key of its own, and every call it makes is relayed
by us with the platform fee applied. Somebody has to be the other end of that. This
plugin is it.

It serves four things, all of them on wopcart.com and nowhere else:

- **Plan verification** (`Server`) — the `/plan/activate`, `/plan/check` and
  `/plan/deactivate` routes a merchant's `Platform\Plan` client asks, answered from
  the licence that the plan key belongs to.
- **Stripe Connect onboarding** (`ConnectServer`) — creating the merchant's
  connected account, sending them through Stripe's onboarding and issuing the site
  token everything else is authenticated with.
- **The payment proxy** (`ConnectServer` + `ProxyPolicy`) — relaying the merchant's
  Stripe calls with the platform key, after checking each one against an allowlist
  and recomputing the fee from the amounts actually being charged.
- **The event relay** (`ConnectServer`) — receiving Stripe's Connect webhook,
  queueing each event in `platform_deliveries` and forwarding it, signed, to the
  merchant's own site, with backoff and a retention cutoff.

## This belongs on wopcart.com only

Not on a merchant store, not in the WordPress.org directory, not in any
distributed build. The code was carried inside the core WopCart plugin until now,
which meant every customer site shipped roughly 2,900 lines it could never run and
two database tables it never wrote a row to.

It is also not merely redundant elsewhere: the proxy is the thing that holds a key
able to move money for every connected account. A second install answering the same
routes is the failure this plugin is shaped to avoid, which is why it refuses to
register anything unless the install really is the platform. Two conditions decide
that, neither of them a setting:

- `WOPCART_PLATFORM_PRODUCT_ID` must be defined in `wp-config.php`, naming the
  product plan keys are issued for. Without it, not a single route is registered.
- `WOPCART_PLATFORM_STRIPE_KEY` (or the `stripe_platform_secret` option) must hold
  the platform's Stripe secret key. Without it there is nothing the proxy could
  relay, so the Connect routes stay unregistered too.

`WOPCART_CONNECT_WEBHOOK_SECRET` must also be defined, or the Connect webhook is
refused — deliberately a constant and never an option, because it is what stops
anyone forging payment events for every store at once.

## Installing and updating

1. Make sure the core **WopCart** plugin on the site is a build with the server half
   removed. This plugin checks: as long as core still defines
   `\WopCart\Platform\Server`, it stays inert and says so in the admin rather than
   racing core for the same REST routes.
2. Copy this directory to `wp-content/plugins/wopcart-platform/` and activate it.
   WordPress enforces the `Requires Plugins: wopcart` header, so core has to be
   active first.

Updating is a directory replacement — there is no updater and no licence check,
because there is one install. Replacing a directory never fires an activation hook,
so the two things activation does are also done on every boot, each guarded:
`Schema::maybe_install()` compares a stored schema version before running `dbDelta`,
and the delivery cron event is rescheduled only when it is missing.

Deactivating clears the delivery cron and nothing else. The tables are left alone:
they hold every connected merchant account and every event still queued for one, so
a ten-minute deactivation during a deployment must not touch them. There is
deliberately no `uninstall.php`.

### Tables

`wp_wopcart_platform_sites` and `wp_wopcart_platform_deliveries`, created by
`Schema` on activation. Both keep the names and column definitions they had when
core created them, prefix included, so the rows already on wopcart.com are found
exactly where they are. Core's own migration (`1.11.0-platform-tables`) drops these
two tables everywhere *except* the platform, and spares any table that still holds
rows even there.

## What it needs from the core plugin

Everything below is called from this plugin's `src/`. The list is the contract: a
rename on either side breaks the platform, and nothing here is behind a filter or a
hook, so a change shows up as a fatal rather than as a wrong answer.

| Core class | Used for |
| --- | --- |
| `WopCart\Contracts\Bootable` | the `hooks()` interface both modules implement |
| `WopCart\Plugin::supports()` | the minimum-core check in the bootstrap |
| `WopCart\VERSION` | the User-Agent the relay sends to merchant sites |
| `WopCart\Gateways\Connect` | `fee_for()`, `fee_percent_for()`, `platform_key()`, `platform_publishable()` |
| `WopCart\Gateways\Stripe` | `API_VERSION`, the Stripe version every proxied call is pinned to |
| `WopCart\Emails\Mailer` | `send()`, for the onboarding and account-problem emails |
| `WopCart\Licensing\Api` | `NAMESPACE` (the shared `wopcart/v1` REST namespace) and `covers_product()` |
| `WopCart\Licensing\License` | `find_by_key()`, `DISABLED`, `EXPIRED`, and the seat/expiry accessors |
| `WopCart\Licensing\LicenseKeys` | `normalize()`, `is_well_formed()`, `normalize_site()` |
| `WopCart\Licensing\LicenseManager` | `activate()`, `deactivate()` — plan seats are licence seats |
| `WopCart\Platform\PlanVerdict` | `SITE_INACTIVE` and `BUSY`, the codes the plan client reads |
| `WopCart\Platform\SiteToken` | `generate()`, `hash()`, `matches()`, `site_id()`, `forward_key()` |
| `WopCart\Security\RateLimiter` | `hit()`, the atomic per-address counter on the plan routes |
| `WopCart\Support\Http` | `flatten()`, form-encoding a Stripe request body |
| `WopCart\Support\Logger` | `info()`, `warning()`, `error()` |
| `WopCart\Support\Signature` | `sign()` and `verify()`, for Stripe's webhook and our own forwards |
| `WopCart\Support\Tables` | `PREFIX` and `exist()`, used by this plugin's `Schema` |
| `WopCart\Support\Util` | `ip()` and `now()` |

`Platform\SiteToken` and `Platform\PlanVerdict` stay in core on purpose: the
merchant half of the free plan (`Platform\ConnectClient`, `Platform\Plan`) needs
both, and the two ends have to agree on the token format and the verdict codes
exactly.

## Tests

The suite runs without WordPress loaded, like core's, but it does need the core
plugin's source — the proxy computes its fee with `Gateways\Connect`, and the plan
server answers from the licensing classes. The bootstrap looks for core next to this
directory (`digital-cart` or `wopcart`), or wherever `WOPCART_CORE_DIR` points.

There is no `vendor/` here, so run the core plugin's PHPUnit:

```bash
cd wopcart-platform
../digital-cart/vendor/bin/phpunit
```

or from anywhere:

```bash
/path/to/digital-cart/vendor/bin/phpunit -c /path/to/wopcart-platform/phpunit.xml.dist
```

Coding standards the same way:

```bash
cd wopcart-platform
../digital-cart/vendor/bin/phpcs
```

## Changelog

### 1.0.3

* **Fixed: a rejected attempt locked a site out of connecting for 24 hours.** The
  idempotency key for creating a connected account was hashed from the site alone.
  Stripe stores the outcome of the first request under a key — refusals included —
  and answers any later request carrying it with *"keys for idempotent requests can
  only be used with the same parameters they were first used with"*. So once the
  Express-dashboard refusal of 1.0.0 had been stored, even the fixed parameters of
  1.0.2 were met with that stored refusal. The key now hashes the parameters too:
  identical parameters still return the account Stripe already created, changed
  parameters get a fresh attempt.

### 1.0.2

* **Fixed: activating this plugin next to an older WopCart took the whole site
  down.** `Plugin::problem()` called core's `supports()` helper, which only exists
  from core 1.3.0. On an older core the class is there and the method is not, so
  the call was a fatal error on every request, wp-admin included — the one place
  a merchant would go to undo it. wopcart.com was running core 1.2.3 when this
  plugin was first installed there. The helper is now only called when it exists,
  and an absent helper reads as "too old", which is what it means.
* The refusal logic moved into `Plugin::problem_for()`, which takes the three
  facts as arguments so every combination can be pinned by the suite — including
  the old-core case, which the suite cannot produce by loading classes.

### 1.0.1

* **Fixed: no store could ever be connected.** New accounts were created with
  `controller[stripe_dashboard][type]=express` while the fees stayed with the
  merchant and the losses with Stripe. Stripe refuses that combination — *"your
  platform must collect fees and be liable for negative balances or refunds and
  chargebacks"* — so account creation failed on every attempt. The dashboard type
  is now `full`: the merchant keeps their own Stripe Dashboard and pays Stripe's
  processing fees, Stripe carries the losses, and onboarding still runs through
  our hosted account link. Pinned by `ConnectAccountControllerTest`.
* **Fixed: the plan route would have fatalled on core 1.4.** `Server.php` imported
  `WopCart\Platform\PlanVerdict`, a class core deleted in 1.4.0. The two codes it
  used are part of the wire protocol older sites speak, so they are written out
  here instead.
