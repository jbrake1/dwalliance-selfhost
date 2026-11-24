# DW Alliance — self-hosted

The complete DW Alliance platform — the website, files, sessions, DNS, domains, store, affiliate program, OAuth for AI editors, Suggest-an-edit markup, and the rest of the API — packaged to run on **one server you control**. Nothing is held back: every feature that runs on our hosted service is in here.

We publish it so you are never locked in. If you ever want to leave our hosting, you can run the same software yourself.

## License (plain English)

DW Alliance is **source-available** under the [Elastic License 2.0](LICENSE) (ELv2). The licensor is DW Alliance LLC.

- ✅ **Allowed:** install it, run it for your own business or organization, change it, and keep your changes private.
- ❌ **Not allowed:** offering it to third parties as a hosted or managed service (selling or giving others access to the platform's features as a service, e.g. running a hosting business on it). Also don't remove the license notices.

This summary is for convenience only; [LICENSE](LICENSE) is the binding text. ELv2 is not an OSI "open source" license, because of the hosting restriction.

## Trademarks: use your own brand

The code is licensed under ELv2, but **the "DW Alliance" name and logos are trademarks of DW Alliance LLC and are not licensed**. The license covers the software, not the brand. If you run or fork this software, you must use your own name and logo. See [TRADEMARKS.md](TRADEMARKS.md).

Branding is configuration, not code. `install.sh --brand-name "Acme Sites" --brand-mark AS` sets the name that pages, emails, the API specs and the JS client show. The email logo is a plain mark generated at install, which you can replace with your own. The installer refuses the DW Alliance name for any other domain. See [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Install (one command)

On a fresh **Debian 12**, **Ubuntu 22.04** or **Ubuntu 24.04** server (2 GB RAM or more), as root:

```bash
curl -fsSL https://raw.githubusercontent.com/jbrake1/dwalliance-selfhost/main/install.sh | sudo bash -s -- --domain example.com --admin-email you@example.com
```

Or from a checkout:

```bash
git clone https://github.com/jbrake1/dwalliance-selfhost.git && cd dwalliance-selfhost
sudo ./install.sh --domain example.com --admin-email you@example.com --brand-name "Acme Sites"
```

Leave out the options on a terminal and the installer asks for them. `./install.sh --help` lists every option. The installer is **idempotent**: re-run it any time (for example after `git pull`) and it keeps your passwords, data and settings.

### What it installs and configures

| Part | What you get |
|---|---|
| nginx | The app at `/var/www/html` with the full module routing (link_tree, store, affiliate, files, …), host-keyed HTML cache, and a default server for every claimed domain |
| PHP-FPM | A dedicated `dwalliance` pool with pdo_mysql, gd, mbstring, curl, xml, imap, apcu, zip |
| MySQL | MySQL 8.4 LTS (Debian, from repo.mysql.com) or 8.0 (Ubuntu); database `dwalliance` with the full schema through migration 025, and the `crm` DNS database |
| DNS | tinydns (djbdns) serving every zone in `crm.dns`, rebuilt each minute from what `/dns` and `/domain` write; the platform zone (ns1–3, app, www, mail, MX, SPF) is created for you |
| Mail | Postfix on localhost for sign-in codes, magic links and notifications (optional relay with `--smtp-relay`) |
| Cron | File-cache cleanup, HTML-cache sweep, DNS rebuild, signup IP lists, back-in-stock mail and bounce checks |
| HTTPS | `--https` (or later `dw-cert add …`) gets Let's Encrypt certificates and switches those hosts to HTTPS |
| First admin | A brand with SUPER_ADMIN for `--admin-email`, created through the app's own `/account_setup` API; your platform domain is attached to it |
| Signup rule | Signups open to every country except a configurable blocked list (see below) |

Single host only: no load balancer, replicas or multi-node sync.

## After installing

1. **DNS.** Point `example.com`, `www.example.com` and `app.example.com` at the server. To use the built-in DNS server, register `ns1/ns2/ns3.example.com` as glue/nameservers at your registrar (they all point at this server).
2. **HTTPS.** `sudo dw-cert add example.com www.example.com app.example.com`. For a customer domain: `sudo dw-cert add customer.com www.customer.com`.
3. **Mail deliverability.** Add DKIM and DMARC for your domain, or send through a relay (`--smtp-relay smtp.provider.com:587`, then put the credentials in `/etc/postfix/sasl_passwd` and run `postmap /etc/postfix/sasl_passwd`).
4. **Sign in.** Your brand code was emailed to you (and saved in `/etc/dwalliance/admin-brand-code`, root only). POST `{"email","brand"}` to `https://app.example.com/session/` and the session id is emailed; or use the magic link at `https://example.com/session/login`. The API overview is at `https://app.example.com/`.
5. **Optional integrations.** PayPal (see `/var/lib/app/data/paypal/README.txt`; sandbox first), OAuth clients for AI editors (`/var/lib/app/data/oauth.json`), restock bounce mailbox (`DW_RESTOCK_IMAP_PASSWORD` in `/etc/dwalliance/dwalliance.env`).

## Where things live

| Path | Contents |
|---|---|
| `/opt/dwalliance/current` | This release (`releases/<version>`) |
| `/var/www/html` | Deployed app (the shipped code as is; names and branding come from `dwalliance.env`) |
| `/var/lib/app/data` | Runtime config and caches: `store.json`, `banner.json`, `magiclink.json`, `oauth.json`, `trust/`, `paypal/`, generated secrets |
| `/etc/dwalliance/dwalliance.conf` | Installer settings (re-used on every run) |
| `/etc/dwalliance/dwalliance.env` | Database passwords and runtime environment (root:www-data 640) |
| `/var/lib/dwalliance/tinydns/root/data` | Generated DNS data |
| `/var/log/dwalliance/` | Cron job logs |

Helper commands: `dw-cert`, `dw-render-nginx`, `dw-migrate`, `dw-run`.

## Signup countries (not legal advice)

By default anyone may sign up **except** from the countries and regions in `/var/lib/app/data/trust/config.json`. The shipped list is a starting point based on US OFAC comprehensive sanctions: Cuba, Iran, North Korea, and the Crimea, Donetsk and Luhansk regions of Ukraine.

- **This is not legal advice.** You are responsible for which countries you serve. Review the list with your own counsel and keep it current.
- **Syria** is not blocked: OFAC ended its comprehensive Syria program on July 1, 2025.
- **Russia and Belarus** are under sectoral, list-based US sanctions and are **not** blocked by default. Decide this for your own business.
- Blocking uses IP geolocation (DB-IP Lite), which is approximate and can be bypassed. There is no screening of names against the SDN list.

Details are in [docs/SIGNUP-COUNTRIES.md](docs/SIGNUP-COUNTRIES.md). Use `--signup-countries all` for no country rule, or `--signup-countries US,CA` to allow only certain countries.

## Affiliate program terms: have a lawyer review them

The affiliate program ships with plain-language default terms. You can add your company, site, contact and governing law with `--terms-company`, `--terms-site`, `--terms-contact` and `--terms-jurisdiction`. **Before you use them, have your own lawyer review and adapt the terms.** They are a starting point, not legal advice. See [docs/AFFILIATE-TERMS.md](docs/AFFILIATE-TERMS.md).

## Upgrading and versions

Each release has a `VERSION` file and a git tag (`v0.1.2`, …). To upgrade: `git pull` (or check out a tag) and re-run `sudo ./install.sh`. New schema changes ship as `sql/migrations/NNN_*.sql` and are applied once.

This repository is generated. The code is the same as the hosted service: `app/` is copied from the main DW Alliance repository at the commit named in `SOURCE`, and only `app/lib/dw_config.php` differs. How releases are cut is described in [docs/RELEASING.md](docs/RELEASING.md).

## Backups

Back up the MySQL databases (`dwalliance` and `crm`), `/var/lib/app/data` and `/etc/dwalliance`. Keep backups outside the web root.

## Known limitations (v0.1.2)

- DNS: tinydns answers UDP only (no TCP/AXFR secondaries, no DNSSEC); records are IPv4 (`A`), as in the hosted service.
- HTTPS for customer domains is per domain: run `dw-cert add customer.com www.customer.com` once their DNS points here.
- Some staff pages and emails show times in Hawaii time (HST), as on the hosted service.
- Outgoing mail needs DKIM/DMARC (or a relay) to reach inboxes reliably.
- Systemd hosts are the supported target; without systemd the installer starts services but they will not restart after a reboot.

## Security notes

- Secrets are generated on your server at install time; none are shipped in this repository.
- Codes, session ids and brand codes are only ever sent by email, never in API responses.
- Signup protection (rate limits, Tor/VPN blocking, the country rule) is set in `/var/lib/app/data/trust/config.json`.

---
Release tracking: jbrake1/dwalliance#79 (Open self-host release (ELv2)).
