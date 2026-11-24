# Configuration reference

The app is the hosted service's code unchanged except `app/lib/dw_config.php` (this release's copy of
`config/dw_config.php`). Every setting is read through `DwConfig`: the environment variable `DW_<KEY>`
wins, then `lib/dw_config.php`, then a built-in fallback. `install.sh` writes the per-install values to
`/etc/dwalliance/dwalliance.env`.

## `/etc/dwalliance/dwalliance.env` (runtime environment, secrets)

Loaded into the PHP-FPM pool (`env[...]`) and by `dw-run` for cron jobs. Edit, then re-run `install.sh` (it rewrites the pool from this file and keeps your values). Values with spaces are written in single quotes because the file is sourced by bash. Branding and affiliate-terms values are also saved in `/etc/dwalliance/dwalliance.conf`, and re-running `install.sh` writes them back, so to change them pass the matching option (`--brand-name` and so on) or edit `dwalliance.conf`.

| Variable | Meaning |
|---|---|
| `DW_DB_HOST`, `DW_DB_NAME`, `DW_DB_USER`, `DW_DB_PASSWORD` | Application database (default `localhost`, `dwalliance`, `dwalliance`, generated) |
| `DW_DNS_DB_HOST`, `DW_DNS_DB_NAME`, `DW_DNS_DB_USER`, `DW_DNS_DB_PASSWORD` | DNS records database (`crm.dns`) used by `/dns`, `/domain` and the tinydns build |
| `DW_SMTP_HOST`, `DW_SMTP_PORT` | Plain SMTP relay the app talks to (default the local Postfix, `127.0.0.1:25`) |
| `DW_MAIL_OUTBOX` | If set, mail is written to this folder instead of sent (testing) |
| `DW_ADMIN_EMAIL` | Operator address for signup alerts |
| `DW_DB_TIMEZONE` | Time zone of the database clock (used when showing times) |
| `DW_TRUSTED_PROXIES` | Comma-separated IPs of a reverse proxy allowed to set `X-Real-IP` / `X-Forwarded-For` |
| `DW_INTERNAL_NETS` | Extra CIDRs treated as internal (never blocked by signup network checks); loopback is always internal |
| `DW_RESTOCK_ALLOW_ALL`, `DW_RESTOCK_PUBLIC_BASE`, `DW_RESTOCK_IMAP_PASSWORD` | Back-in-stock mail: send to everyone, public base URL, bounce mailbox password |
| `DW_TINYDNS_ROOT` | tinydns data folder (default `/var/lib/dwalliance/tinydns/root`) |
| `DW_PUBLIC_IP` | Public IP shown on the platform help page |
| `DW_BRAND_NAME`, `DW_BRAND_LEGAL_NAME`, `DW_BRAND_MARK` | Your platform name, legal name and 1-3 letter badge mark (`--brand-name`, `--brand-legal-name`, `--brand-mark`) |
| `DW_PLATFORM_DOMAIN`, `DW_APP_HOST` | Platform domain and app/API host (`--domain`, `--app-host`) |
| `DW_SUPPORT_EMAIL`, `DW_LOGO_URL` | Support address shown to users, and the email logo URL |
| `DW_AFFILIATE_TERMS_COMPANY`, `_SITE`, `_CONTACT`, `_JURISDICTION` | Legal clause added to your platform's default affiliate terms (`--terms-*`). **Have your own lawyer review the terms before use** (`docs/AFFILIATE-TERMS.md`) |
| `DW_RESTOCK_LIVE_DB_NAME` | Database name treated as live by the back-in-stock sender (set to `DW_DB_NAME`) |

Other `lib/dw_config.php` keys (`DNS_DB_SQL_MODE`, `SMTP_HELO`, `AFFILIATE_TERMS_NOTICE`, ...) can also be
set here as `DW_<KEY>`. `/etc/dwalliance/platform-brand` (root:www-data 640) holds the brand that owns the
platform affiliate program: the first admin's brand.

## Branding and trademarks

Pages, emails, the API specs (`/openapi/*.json`) and the JS client (`/js/dw/dw.js`) show your brand
values. The "DW Alliance" name and logo are trademarks of DW Alliance LLC and are not licensed with the
code (see `TRADEMARKS.md`), so `install.sh` refuses them for any other domain. The email logo
(`/var/lib/app/data/email/dw-logo.png`; the file name is fixed in the code) is a plain mark made at install
time. Replace it with your own 96x96 PNG.

## `/var/lib/app/data` (runtime config, created once and then left alone)

| File | Purpose |
|---|---|
| `store.json` | Store defaults, PayPal switch (`paypal.enabled`, `env` sandbox/live), entitlement gates, `seller_domain` |
| `banner.json` | Banner fallback and emergency kill switch |
| `magiclink.json` | Magic-link sign-in: `mail` smtp/sink, `timezone`, `logo` url/cid, rate limits |
| `oauth.json` | OAuth clients for AI editors (`clients` with `secret_sha256`, `redirect_uris`, `scopes`) and the CSRF key |
| `markup.json` | Suggest-an-edit fallback brands and alert address |
| `trust/config.json` | Signup protection: rate limits, Tor/VPN blocking, and the country rule. The default is everyone except the blocked list (US OFAC comprehensive sanctions as a starting point; **not legal advice**; see `docs/SIGNUP-COUNTRIES.md`) |
| `paypal/default.json` | PayPal REST credentials (see `paypal/README.txt`) |
| `email/` | Magic-link email templates and logo |
| `editlink.secret`, `markup.secret`, `affiliate-referral-salt` | Generated keys (mode 600) |

## DNS

`/dns` and `/domain` write records to `crm.dns` (one row per tinydns line; see `sql/crm.sql`). `cli/dw-dns-build.php` (cron, every minute) turns them into `/var/lib/dwalliance/tinydns/root/data` and compiles `data.cdb`. tinydns answers UDP queries on `DW_DNS_IP` (see `/etc/dwalliance/tinydns.env`). Customer zones claimed with `/domain` get NS records for `ns1`–`ns3` of your platform domain automatically.
