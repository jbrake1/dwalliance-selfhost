# Cutting a self-host release

Refs #79. The self-host release is generated from the main repository, `jbrake1/dwalliance`. There is
one codebase. The release's `app/` is the hosted service's code at a given commit, byte for byte,
except `app/lib/dw_config.php`, which is replaced by `selfhost/config/dw_config.php`. Install-specific
values come from `/etc/dwalliance/dwalliance.env` at run time.

## Where things live in jbrake1/dwalliance

| Path | What |
|---|---|
| app code (`lib/`, `store/`, `files/`, ...) | Shared by the hosted service and the self-host release |
| `lib/dw_config.php` | Hosted-service configuration (production values). Never shipped |
| `selfhost/` | Self-host packaging: `install.sh`, `cli/`, `config/` (incl. `dw_config.php`), `sql/`, `tests/`, `docs/`, README, LICENSE, TRADEMARKS, VERSION |
| `selfhost/build.py` | Builds a release tree and checks it |
| `docs/signup-countries.md`, `docs/affiliates/TERMS.md` | Shipped as `docs/SIGNUP-COUNTRIES.md` and `docs/AFFILIATE-TERMS.md` |

## Steps

1. **Pick the source commit.** Use `main`, or an integration branch while feature branches are still open
   in review. For example, v0.1.2 was cut from `selfhost-release`, which is `main` plus
   `session-get-permissions` and `link-tree` (merged, not rebased). Push the branch so the commit can
   be reproduced.
2. **Update the packaging if needed** (all in `selfhost/`):
   - `VERSION`.
   - New database migrations: copy each new `docs/sql/NNN_*.sql` to `selfhost/sql/migrations/`.
     `schema.sql` covers migrations up to 025. The `_down`/`_verify` files are skipped by `dw-migrate`.
   - New config keys: add a neutral default to `selfhost/config/dw_config.php`, and if it is per-install,
     an installer option and a `DW_<KEY>` line in `install.sh`.
   - New cron/maintenance scripts used on single hosts: add them to `CLI_ITEMS` in `build.py`.
   - New static text files that name the platform (`.json`, `.js`): route them through
     `lib/dw_theme_static.php` in `config/nginx/dwalliance-app.conf`.
3. **Build:**
   ```bash
   git clone git@github.com:jbrake1/dwalliance-selfhost.git /tmp/dwalliance-selfhost   # once
   python3 selfhost/build.py /tmp/dwalliance-selfhost --ref selfhost-release
   ```
   The build empties the target (it keeps `.git`), copies the files, writes `SOURCE` (the source commit),
   and fails if any app file differs from the source commit, or if private values are present: internal
   addresses, the platform brand code, personal addresses, or the old legal-review marker.
4. **Scan for secrets:** `gitleaks detect --source /tmp/dwalliance-selfhost --no-git` (and with git history).
5. **Test** on fresh Debian 12, Ubuntu 22.04 and Ubuntu 24.04: install with `--mail-mode outbox --yes`,
   then run `sudo python3 tests/e2e.py`.
6. **Commit** in `dwalliance-selfhost` with a message that references `jbrake1/dwalliance#79` (or the
   release's own issue), tag `vX.Y.Z`, and push. The repository stays **private** until the owner
   approves making it public.

Hosted-service deploys are not affected by any of this. `selfhost/` is packaging only, and the hosted
service keeps reading `lib/dw_config.php`.
