#!/usr/bin/env bash
# DW Alliance self-host installer. One command installs and configures everything on one host:
# nginx, PHP-FPM (+ gd, mbstring, curl, xml, imap, apcu, mysql), MySQL, the app and its schema,
# a tinydns authoritative DNS server fed from the app's DNS table, Postfix for outgoing mail,
# cron jobs, and optional HTTPS with Let's Encrypt (certbot).
#
#   curl -fsSL https://raw.githubusercontent.com/jbrake1/dwalliance-selfhost/main/install.sh | sudo bash -s -- --domain example.com --admin-email you@example.com
#   or, from a checkout:  sudo ./install.sh --domain example.com --admin-email you@example.com
#
# Supported: Debian 12, Ubuntu 22.04, Ubuntu 24.04. Safe to re-run: it keeps existing passwords,
# data and settings, and only changes what is missing or out of date. Run with --help for options.
# Licensed under the Elastic License 2.0 (see LICENSE).
set -euo pipefail
umask 022

DW_REPO_URL=${DW_REPO_URL:-https://github.com/jbrake1/dwalliance-selfhost.git}
DW_REF=${DW_REF:-main}
ETC=/etc/dwalliance
CONF=$ETC/dwalliance.conf
ENVF=$ETC/dwalliance.env
BASE=/opt/dwalliance
DATA=/var/lib/app/data
WEB=/var/www/html

log()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[33mWARNING:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

usage() {
cat <<'EOF'
Usage: install.sh [options]

Required on the first run (prompted for when a terminal is attached):
  --domain NAME           Platform domain, e.g. example.com (the app runs at app.NAME)
  --admin-email EMAIL     First super admin (gets the first brand)
Optional:
  --admin-first NAME      Admin first name (default: Admin)
  --admin-last NAME       Admin last name (default: User)
  --company NAME          Admin company name (default: the domain)
  --brand-name NAME       Platform name shown in pages and email (default: the company name)
  --brand-legal-name NAME Legal name (default: the brand name)
  --brand-mark XY         1-3 letter badge mark (default: initials of the brand name)
  --support-email EMAIL   Support address shown to users (default: support@DOMAIN)
  --terms-company NAME    Affiliate terms: company that runs the program (default: the legal name)
  --terms-site URL        Affiliate terms: site (default: https://DOMAIN)
  --terms-contact TEXT    Affiliate terms: contact for questions (default: the support email)
  --terms-jurisdiction X  Affiliate terms: governing law, e.g. "the State of Hawaii, USA" (default: none)
  --app-host HOST         API/app host name (default: app.DOMAIN)
  --public-ip IP          Public IPv4 of this server (default: auto-detected)
  --dns / --no-dns        Install the tinydns DNS server (default: on)
  --dns-ip IP             Address tinydns listens on (default: the public IP if it is on this host, else the primary address)
  --no-dns-seed           Do not create the platform zone records (ns1-3, app, www, mail, MX, SPF)
  --https                 Get Let's Encrypt certificates for DOMAIN, www.DOMAIN and the app host (DNS must already point here)
  --certbot-email EMAIL   Contact email for Let's Encrypt (default: the admin email)
  --mail-mode MODE        smtp (default, local Postfix) or outbox (write mail to /var/lib/app/data/outbox; for testing)
  --smtp-relay HOST:PORT  Send through this relay (set credentials later in /etc/postfix/sasl_passwd)
  --signup-countries LIST "sanctions" (default: everyone except the list in config/data/trust.json; NOT legal
                          advice, see docs/signup-countries.md), "all" (no country rule), or comma separated ISO
                          codes that alone may sign up (e.g. US,CA). Applies only when trust config is first written.
  --db-name NAME          Database name (default: dwalliance)
  --skip-admin            Do not create the first admin
  --yes, -y               Never prompt (fail if something required is missing)
  --force-os              Try anyway on an unsupported OS
  --help                  This help
Re-running keeps every setting saved in /etc/dwalliance/dwalliance.conf unless you pass a new value.
EOF
}

[ "$(id -u)" -eq 0 ] || die "run as root (sudo)"

# ---------------------------------------------------------------- settings
DOMAIN=""; ADMIN_EMAIL=""; ADMIN_FIRST=""; ADMIN_LAST=""; COMPANY=""; APP_HOST=""; PUBLIC_IP=""
DNS_ENABLE=""; DNS_IP=""; DNS_SEED=""; HTTPS=""; CERTBOT_EMAIL=""; MAIL_MODE=""; SMTP_RELAY=""
SIGNUP_COUNTRIES=""; DB_NAME=""; SKIP_ADMIN=0; ASSUME_YES=0; FORCE_OS=0
BRAND_NAME=""; BRAND_LEGAL=""; BRAND_MARK=""; SUPPORT_EMAIL=""; TERMS_COMPANY=""; TERMS_SITE=""; TERMS_CONTACT=""; TERMS_JURISDICTION=""
if [ -r "$CONF" ]; then
  # shellcheck disable=SC1090
  . "$CONF"
  DOMAIN=${DW_DOMAIN:-}; ADMIN_EMAIL=${DW_ADMIN_EMAIL:-}; ADMIN_FIRST=${DW_ADMIN_FIRST:-}; ADMIN_LAST=${DW_ADMIN_LAST:-}
  COMPANY=${DW_COMPANY:-}; APP_HOST=${DW_APP_HOST:-}; PUBLIC_IP=${DW_PUBLIC_IP:-}; DNS_ENABLE=${DW_DNS_ENABLE:-}
  DNS_IP=${DW_DNS_IP:-}; DNS_SEED=${DW_DNS_SEED:-}; HTTPS=${DW_HTTPS:-}; CERTBOT_EMAIL=${DW_CERTBOT_EMAIL:-}
  MAIL_MODE=${DW_MAIL_MODE:-}; SMTP_RELAY=${DW_SMTP_RELAY:-}; SIGNUP_COUNTRIES=${DW_SIGNUP_COUNTRIES:-}; DB_NAME=${DW_DB_NAME:-}
  BRAND_NAME=${DW_BRAND_NAME:-}; BRAND_LEGAL=${DW_BRAND_LEGAL_NAME:-}; BRAND_MARK=${DW_BRAND_MARK:-}; SUPPORT_EMAIL=${DW_SUPPORT_EMAIL:-}
  TERMS_COMPANY=${DW_TERMS_COMPANY:-}; TERMS_SITE=${DW_TERMS_SITE:-}; TERMS_CONTACT=${DW_TERMS_CONTACT:-}; TERMS_JURISDICTION=${DW_TERMS_JURISDICTION:-}
fi
while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN=${2:?}; shift ;;
    --admin-email) ADMIN_EMAIL=${2:?}; shift ;;
    --admin-first) ADMIN_FIRST=${2:?}; shift ;;
    --admin-last) ADMIN_LAST=${2:?}; shift ;;
    --company) COMPANY=${2:?}; shift ;;
    --brand-name) BRAND_NAME=${2:?}; shift ;;
    --brand-legal-name) BRAND_LEGAL=${2:?}; shift ;;
    --brand-mark) BRAND_MARK=${2:?}; shift ;;
    --support-email) SUPPORT_EMAIL=${2:?}; shift ;;
    --terms-company) TERMS_COMPANY=${2:?}; shift ;;
    --terms-site) TERMS_SITE=${2:?}; shift ;;
    --terms-contact) TERMS_CONTACT=${2:?}; shift ;;
    --terms-jurisdiction) TERMS_JURISDICTION=${2:?}; shift ;;
    --app-host) APP_HOST=${2:?}; shift ;;
    --public-ip) PUBLIC_IP=${2:?}; shift ;;
    --dns) DNS_ENABLE=1 ;;
    --no-dns) DNS_ENABLE=0 ;;
    --dns-ip) DNS_IP=${2:?}; shift ;;
    --no-dns-seed) DNS_SEED=0 ;;
    --https) HTTPS=1 ;;
    --no-https) HTTPS=0 ;;
    --certbot-email) CERTBOT_EMAIL=${2:?}; shift ;;
    --mail-mode) MAIL_MODE=${2:?}; shift ;;
    --smtp-relay) SMTP_RELAY=${2:?}; shift ;;
    --signup-countries) SIGNUP_COUNTRIES=${2:?}; SIGNUP_SET=1; shift ;;
    --db-name) DB_NAME=${2:?}; shift ;;
    --skip-admin) SKIP_ADMIN=1 ;;
    -y|--yes) ASSUME_YES=1 ;;
    --force-os) FORCE_OS=1 ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown option: $1 (see --help)" ;;
  esac
  shift
done

ask() { # ask VAR "Question" default
  local var=$1 q=$2 def=${3:-} ans=""
  if [ -n "${!var}" ]; then return; fi
  if [ "$ASSUME_YES" -eq 1 ] || ! { [ -r /dev/tty ] && (exec </dev/tty) 2>/dev/null; }; then
    [ -n "$def" ] && { printf -v "$var" '%s' "$def"; return; }
    die "$q is required (pass it as an option; see --help)"
  fi
  read -r -p "$q${def:+ [$def]}: " ans </dev/tty || true
  printf -v "$var" '%s' "${ans:-$def}"
  [ -n "${!var}" ] || die "$q is required"
}

ask DOMAIN "Platform domain (e.g. example.com)"
DOMAIN=$(printf '%s' "$DOMAIN" | tr 'A-Z' 'a-z' | sed 's/\.$//')
[[ "$DOMAIN" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || die "not a domain name: $DOMAIN"
APP_HOST=${APP_HOST:-app.$DOMAIN}
if [ "$SKIP_ADMIN" -eq 0 ]; then ask ADMIN_EMAIL "First admin email"; fi
ADMIN_FIRST=${ADMIN_FIRST:-Admin}; ADMIN_LAST=${ADMIN_LAST:-User}; COMPANY=${COMPANY:-$DOMAIN}
DNS_ENABLE=${DNS_ENABLE:-1}; DNS_SEED=${DNS_SEED:-1}; HTTPS=${HTTPS:-0}; MAIL_MODE=${MAIL_MODE:-smtp}
# v0.1.0 saved its default "all" in dwalliance.conf; this release's default is the deny list.
[ "${SIGNUP_SET:-0}" = 0 ] && [ "${DW_VERSION:-}" = 0.1.0 ] && [ "$SIGNUP_COUNTRIES" = all ] && SIGNUP_COUNTRIES=sanctions
SIGNUP_COUNTRIES=${SIGNUP_COUNTRIES:-sanctions}; DB_NAME=${DB_NAME:-dwalliance}; CERTBOT_EMAIL=${CERTBOT_EMAIL:-$ADMIN_EMAIL}
case "$MAIL_MODE" in smtp|outbox) ;; *) die "--mail-mode must be smtp or outbox" ;; esac
if [ -n "$ADMIN_EMAIL" ]; then [[ "$ADMIN_EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || die "not an email address: $ADMIN_EMAIL"; fi
[[ "$APP_HOST" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || die "not a host name: $APP_HOST"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "bad --db-name"
# Branding: your own name, never the DW Alliance trademarks (TRADEMARKS.md).
BRAND_NAME=${BRAND_NAME:-$COMPANY}; BRAND_LEGAL=${BRAND_LEGAL:-$BRAND_NAME}
if [ -z "$BRAND_MARK" ]; then
  BRAND_MARK=$(printf '%s' "$BRAND_NAME" | tr -c 'A-Za-z0-9 ' ' ' | awk '{m=""; for(i=1;i<=NF && length(m)<2;i++) m=m toupper(substr($i,1,1)); print m}')
  BRAND_MARK=${BRAND_MARK:-X}
fi
SUPPORT_EMAIL=${SUPPORT_EMAIL:-support@$DOMAIN}
TERMS_COMPANY=${TERMS_COMPANY:-$BRAND_LEGAL}; TERMS_SITE=${TERMS_SITE:-https://$DOMAIN}; TERMS_CONTACT=${TERMS_CONTACT:-$SUPPORT_EMAIL}
safe_text() { [[ "$2" != *$'\n'* && "$2" =~ ^[^\'\"\\\`\$]{1,120}$ ]] || die "$1 may not be empty or contain quotes, backslashes, \` or \$: $2"; }
safe_text --company "$COMPANY"; safe_text --brand-name "$BRAND_NAME"; safe_text --brand-legal-name "$BRAND_LEGAL"
safe_text --terms-company "$TERMS_COMPANY"; safe_text --terms-site "$TERMS_SITE"; safe_text --terms-contact "$TERMS_CONTACT"
[ -z "$TERMS_JURISDICTION" ] || safe_text --terms-jurisdiction "$TERMS_JURISDICTION"
[[ "$BRAND_MARK" =~ ^[A-Za-z0-9]{1,3}$ ]] || die "--brand-mark must be 1-3 letters or digits"
[[ "$SUPPORT_EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || die "not an email address: $SUPPORT_EMAIL"
case "$(printf '%s %s' "$BRAND_NAME" "$BRAND_LEGAL" | tr 'A-Z' 'a-z')" in
  *"dw alliance"*|*dwalliance*) [ "$DOMAIN" = dwalliance.com ] || die "the DW Alliance name is a trademark of DW Alliance LLC and is not licensed for other installs (TRADEMARKS.md); pass --brand-name with your own name" ;;
esac

# ---------------------------------------------------------------- OS
log "Checking the operating system"
. /etc/os-release
case "${ID}:${VERSION_ID}" in
  debian:12|ubuntu:22.04|ubuntu:24.04) info "$PRETTY_NAME" ;;
  *) [ "$FORCE_OS" -eq 1 ] && warn "$PRETTY_NAME is not tested; continuing (--force-os)" || die "$PRETTY_NAME is not supported (Debian 12, Ubuntu 22.04/24.04). Use --force-os to try anyway." ;;
esac
HAS_SYSTEMD=0; [ -d /run/systemd/system ] && HAS_SYSTEMD=1
export DEBIAN_FRONTEND=noninteractive

svc() { # svc enable|restart|reload NAME
  local act=$1 name=$2
  if [ "$HAS_SYSTEMD" -eq 1 ]; then
    case "$act" in
      enable) systemctl enable --now "$name" >/dev/null 2>&1 || systemctl restart "$name" ;;
      *) systemctl "$act" "$name" ;;
    esac
  else
    case "$act" in enable) act=start ;; esac
    service "$name" "$act" >/dev/null 2>&1 || service "$name" restart >/dev/null 2>&1 || true
  fi
}

apt_install() { apt-get install -y -q --no-install-recommends "$@" >/dev/null; }

# ---------------------------------------------------------------- packages
log "Installing packages (nginx, PHP, MySQL, DNS, mail, cron)"
MYSQL_LIST="deb [signed-by=/usr/share/keyrings/mysql.gpg] https://repo.mysql.com/apt/debian ${VERSION_CODENAME:-bookworm} mysql-8.4-lts"
mysql_repo() { # official MySQL 8.4 LTS repository (Debian); the signing key is re-fetched every run
  install -d -m 755 /usr/share/keyrings
  local k ok=0
  for k in RPM-GPG-KEY-mysql-2025 RPM-GPG-KEY-mysql-2023; do
    if curl -fsSL "https://repo.mysql.com/$k" -o /tmp/dw-mysql.key && gpg --dearmor --yes -o /usr/share/keyrings/mysql.gpg /tmp/dw-mysql.key; then ok=1; break; fi
  done
  rm -f /tmp/dw-mysql.key
  [ "$ok" = 1 ] || return 1
  echo "$MYSQL_LIST" > /etc/apt/sources.list.d/mysql.list
}
# A MySQL source left by an earlier run: refresh its key and line before the first apt-get update.
if [ -e /etc/apt/sources.list.d/mysql.list ]; then mysql_repo || rm -f /etc/apt/sources.list.d/mysql.list; fi
apt-get update -q >/dev/null || die "apt-get update failed"
apt_install ca-certificates curl gnupg rsync cron python3 openssl dnsutils git lsb-release tzdata procps iproute2 logrotate
apt_install nginx php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-gd php-imap php-apcu php-zip

if ! command -v mysqld >/dev/null 2>&1; then
  if [ "$ID" = "ubuntu" ]; then
    apt_install mysql-server
  else
    # Debian has no MySQL server package; use the official MySQL 8.4 LTS repository.
    info "adding the MySQL 8.4 LTS APT repository (repo.mysql.com)"
    mysql_repo || die "could not fetch the MySQL signing key from repo.mysql.com"
    apt-get update -q >/dev/null || { rm -f /etc/apt/sources.list.d/mysql.list; die "could not read the MySQL repository (repo.mysql.com); re-run later"; }
    # Empty root password = root signs in through the local socket only (auth_socket).
    echo "mysql-community-server mysql-community-server/root-pass password " | debconf-set-selections
    echo "mysql-community-server mysql-community-server/re-root-pass password " | debconf-set-selections
    apt_install mysql-server
  fi
fi

if [ "$MAIL_MODE" = smtp ] && ! dpkg -s postfix >/dev/null 2>&1; then
  echo "postfix postfix/main_mailer_type select Internet Site" | debconf-set-selections
  echo "postfix postfix/mailname string $DOMAIN" | debconf-set-selections
  apt_install postfix
fi
if [ "$DNS_ENABLE" = 1 ]; then apt_install tinydns; fi
if [ "$HTTPS" = 1 ]; then apt_install certbot; fi

PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PHP_SOCK=/run/php/dwalliance-fpm.sock
info "PHP $PHPV, $(mysqld --version 2>/dev/null | sed 's/^.*Ver /MySQL /' | cut -d' ' -f1-2)"
svc enable mysql
for i in $(seq 1 60); do mysqladmin ping --silent >/dev/null 2>&1 && break; sleep 1; done
mysqladmin ping --silent >/dev/null 2>&1 || die "MySQL did not start"
mysql -e 'SELECT 1' >/dev/null 2>&1 || die "cannot sign in to MySQL as root through the local socket. If root has a password, put it in /root/.my.cnf ([client] user=root password=...) and re-run."

# ---------------------------------------------------------------- addresses
detect_ip() { ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}'; }
LOCAL_IP=$(detect_ip || true)
if [ -z "$PUBLIC_IP" ]; then
  PUBLIC_IP=$(curl -fsS -4 --max-time 5 https://api.ipify.org 2>/dev/null || true)
  [[ "$PUBLIC_IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || PUBLIC_IP=$LOCAL_IP
fi
if [ -z "$DNS_IP" ]; then
  if ip -4 addr 2>/dev/null | grep -q "inet $PUBLIC_IP/"; then DNS_IP=$PUBLIC_IP; else DNS_IP=${LOCAL_IP:-127.0.0.1}; fi
fi
info "public IP $PUBLIC_IP, DNS listens on $DNS_IP"
HOST_TZ=$(cat /etc/timezone 2>/dev/null || timedatectl show -p Timezone --value 2>/dev/null || echo UTC)
[ -n "$HOST_TZ" ] || HOST_TZ=UTC

# ---------------------------------------------------------------- source tree
log "Installing the application files"
SCRIPT_DIR=""
if [ -n "${BASH_SOURCE[0]:-}" ] && [ -f "${BASH_SOURCE[0]}" ]; then SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd); fi
if [ -n "$SCRIPT_DIR" ] && [ -f "$SCRIPT_DIR/VERSION" ] && [ -d "$SCRIPT_DIR/app" ]; then
  SRC=$SCRIPT_DIR
else
  SRC=$(mktemp -d /tmp/dwalliance-src.XXXXXX)
  info "downloading $DW_REPO_URL ($DW_REF)"
  git clone -q --depth 1 --branch "$DW_REF" "$DW_REPO_URL" "$SRC" || die "could not download $DW_REPO_URL"
fi
VERSION=$(tr -d ' \n' < "$SRC/VERSION")
REL=$BASE/releases/$VERSION
install -d -m 755 "$BASE/releases"
rsync -a --delete --exclude .git "$SRC/" "$REL/"
ln -sfn "$REL" "$BASE/current"
chmod 755 "$REL"/cli/dw-* 2>/dev/null || true
info "version $VERSION in $REL"

# ---------------------------------------------------------------- config + secrets
log "Writing configuration (/etc/dwalliance)"
install -d -m 750 -o root -g www-data "$ETC"
genhex() { openssl rand -hex "${1:-24}"; }
getenv_old() { [ -r "$ENVF" ] && sed -n "s/^$1=//p" "$ENVF" | tail -1 || true; }
DB_PASS=$(getenv_old DW_DB_PASSWORD); DB_PASS=${DB_PASS:-$(genhex 24)}
DNS_PASS=$(getenv_old DW_DNS_DB_PASSWORD); DNS_PASS=${DNS_PASS:-$(genhex 24)}
RESTOCK_IMAP=$(getenv_old DW_RESTOCK_IMAP_PASSWORD)
OUTBOX=""; [ "$MAIL_MODE" = outbox ] && OUTBOX=$DATA/outbox
cat > "$CONF.tmp" <<EOF
# DW Alliance settings saved by install.sh. Re-run install.sh after changing them.
DW_DOMAIN=$DOMAIN
DW_APP_HOST=$APP_HOST
DW_ADMIN_EMAIL=$ADMIN_EMAIL
DW_ADMIN_FIRST='${ADMIN_FIRST//\'/}'
DW_ADMIN_LAST='${ADMIN_LAST//\'/}'
DW_COMPANY='${COMPANY//\'/}'
DW_BRAND_NAME='$BRAND_NAME'
DW_BRAND_LEGAL_NAME='$BRAND_LEGAL'
DW_BRAND_MARK=$BRAND_MARK
DW_SUPPORT_EMAIL=$SUPPORT_EMAIL
DW_TERMS_COMPANY='$TERMS_COMPANY'
DW_TERMS_SITE='$TERMS_SITE'
DW_TERMS_CONTACT='$TERMS_CONTACT'
DW_TERMS_JURISDICTION='$TERMS_JURISDICTION'
DW_PUBLIC_IP=$PUBLIC_IP
DW_DNS_ENABLE=$DNS_ENABLE
DW_DNS_IP=$DNS_IP
DW_DNS_SEED=$DNS_SEED
DW_HTTPS=$HTTPS
DW_CERTBOT_EMAIL=$CERTBOT_EMAIL
DW_MAIL_MODE=$MAIL_MODE
DW_SMTP_RELAY=$SMTP_RELAY
DW_SIGNUP_COUNTRIES=$SIGNUP_COUNTRIES
DW_DB_NAME=$DB_NAME
DW_PHP_VERSION=$PHPV
DW_PHP_SOCK=$PHP_SOCK
DW_SRC_DIR=$BASE/current
DW_VERSION=$VERSION
EOF
install -m 640 -o root -g root "$CONF.tmp" "$CONF"; rm -f "$CONF.tmp"
cat > "$ENVF.tmp" <<EOF
# Runtime environment for PHP-FPM and the cron jobs (DW_<KEY> overrides lib/dw_config.php; see docs/CONFIGURATION.md).
# Secrets: keep this file mode 640 root:www-data. Values with spaces are in single quotes (the file is sourced by bash).
DW_DB_HOST=localhost
DW_DB_NAME=$DB_NAME
DW_DB_USER=dwalliance
DW_DB_PASSWORD=$DB_PASS
DW_DNS_DB_HOST=localhost
DW_DNS_DB_NAME=crm
DW_DNS_DB_USER=dwdns
DW_DNS_DB_PASSWORD=$DNS_PASS
DW_SMTP_HOST=127.0.0.1
DW_SMTP_PORT=25
DW_MAIL_OUTBOX=$OUTBOX
DW_ADMIN_EMAIL=$ADMIN_EMAIL
DW_DB_TIMEZONE=$HOST_TZ
DW_TRUSTED_PROXIES=
DW_INTERNAL_NETS=
DW_PUBLIC_IP=$PUBLIC_IP
DW_BRAND_NAME='$BRAND_NAME'
DW_BRAND_LEGAL_NAME='$BRAND_LEGAL'
DW_BRAND_MARK=$BRAND_MARK
DW_PLATFORM_DOMAIN=$DOMAIN
DW_APP_HOST=$APP_HOST
DW_SUPPORT_EMAIL=$SUPPORT_EMAIL
DW_LOGO_URL=https://$DOMAIN/assets/email/dw-logo.png
DW_AFFILIATE_TERMS_COMPANY='$TERMS_COMPANY'
DW_AFFILIATE_TERMS_SITE='$TERMS_SITE'
DW_AFFILIATE_TERMS_CONTACT='$TERMS_CONTACT'
DW_AFFILIATE_TERMS_JURISDICTION='$TERMS_JURISDICTION'
DW_RESTOCK_LIVE_DB_NAME=$DB_NAME
DW_RESTOCK_ALLOW_ALL=1
DW_RESTOCK_PUBLIC_BASE=
DW_RESTOCK_IMAP_PASSWORD=$RESTOCK_IMAP
DW_TINYDNS_ROOT=/var/lib/dwalliance/tinydns/root
EOF
install -m 640 -o root -g www-data "$ENVF.tmp" "$ENVF"; rm -f "$ENVF.tmp"

# ---------------------------------------------------------------- database
log "Configuring MySQL (database $DB_NAME and crm)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'dwalliance'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER 'dwalliance'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO 'dwalliance'@'localhost';
SQL
mysql "$DB_NAME" < "$REL/sql/schema.sql"
mysql < "$REL/sql/crm.sql"
mysql <<SQL
CREATE USER IF NOT EXISTS 'dwdns'@'localhost' IDENTIFIED BY '$DNS_PASS';
ALTER USER 'dwdns'@'localhost' IDENTIFIED BY '$DNS_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`crm\`.* TO 'dwdns'@'localhost';
FLUSH PRIVILEGES;
SQL
DW_SRC_DIR=$REL "$REL/cli/dw-migrate"
info "$(mysql -N -B "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'") tables; schema version $(mysql -N -B "$DB_NAME" -e 'SELECT MAX(version) FROM dw_schema_migrations')"

# ---------------------------------------------------------------- web root
log "Deploying the app to $WEB"
# The app is deployed as shipped: it is the hosted service's code with only lib/dw_config.php
# different. Names, hosts and branding come from $ENVF at run time.
install -d -m 755 "$WEB"
# Top-level folders that are not part of the app (brand file caches written by 404.php) are kept.
rsync -a --delete --filter='P /*/' --exclude='.*' "$REL/app/" "$WEB/"
for d in "$REL/app"/*/; do rsync -a --delete "$d" "$WEB/$(basename "$d")/"; done
chown -R www-data:www-data "$WEB"

# ---------------------------------------------------------------- data dir
log "Preparing $DATA"
install -d -m 750 -o www-data -g www-data "$DATA" "$DATA/html-cache" "$DATA/sitemap-cache" "$DATA/email" "$DATA/trust" "$DATA/trust/iplists" "$DATA/paypal"
[ -n "$OUTBOX" ] && install -d -m 700 -o www-data -g www-data "$OUTBOX"
put_new() { # put_new SRC DEST MODE : copy only when DEST is missing (keeps operator edits)
  [ -e "$2" ] && return 0
  install -m "$3" -o www-data -g www-data "$1" "$2"
}
render_tpl() { # render SRC > DEST with the settings filled in
  python3 - "$1" "$2" <<PY
import sys
s = open(sys.argv[1]).read()
for k, v in {"__DOMAIN__": "$DOMAIN", "__APP_HOST__": "$APP_HOST", "__TZ__": "$HOST_TZ", "__ADMIN_EMAIL__": "$ADMIN_EMAIL"}.items():
    s = s.replace(k, v)
open(sys.argv[2], "w").write(s)
PY
}
T=$(mktemp -d)
for f in store.json banner.json markup.json; do render_tpl "$REL/config/data/$f" "$T/$f"; put_new "$T/$f" "$DATA/$f" 640; done
render_tpl "$REL/config/data/magiclink.json" "$T/magiclink.json"
python3 - "$T/magiclink.json" "$MAIL_MODE" <<'PY'
import json, sys
p, mode = sys.argv[1], sys.argv[2]
j = json.load(open(p)); j["mail"] = "sink" if mode == "outbox" else "smtp"
json.dump(j, open(p, "w"), indent=2)
PY
put_new "$T/magiclink.json" "$DATA/magiclink.json" 640
if [ ! -e "$DATA/oauth.json" ]; then
  printf '{\n  "key": "%s",\n  "access_ttl": 3600,\n  "refresh_ttl": 2592000,\n  "code_ttl": 600,\n  "direct_writes": false,\n  "clients": {}\n}\n' "$(genhex 32)" > "$T/oauth.json"
  install -m 600 -o www-data -g www-data "$T/oauth.json" "$DATA/oauth.json"
fi
python3 - "$REL/config/data/trust.json" "$T/trust.json" "$SIGNUP_COUNTRIES" <<'PY'
import json, sys
src, p, c = sys.argv[1], sys.argv[2], sys.argv[3].strip()
j = json.load(open(src))
if c.lower() == "all":
    j["signup"] = {"country": "allow"}
elif c.lower() not in ("", "sanctions", "default"):
    j["signup"] = {"country": "block", "countries": [x.strip().upper() for x in c.split(",") if x.strip()]}
json.dump(j, open(p, "w"), indent=2)
PY
put_new "$T/trust.json" "$DATA/trust/config.json" 640
# v0.1.0 wrote "no country rule" by default; an untouched v0.1.0 default moves to this release's default.
if python3 - "$DATA/trust/config.json" <<'PY'
import json, sys
j = json.load(open(sys.argv[1]))
sys.exit(0 if j.get("signup") == {"countries": ["US"], "country": "allow"} and j.get("_note", "").startswith("Signup protection (lib/DwSignup.php). country allow = any country.") and len(j) == 2 else 1)
PY
then
  if [ "$SIGNUP_COUNTRIES" = sanctions ]; then info "signup rule: replacing the untouched v0.1.0 default with the deny list (docs/signup-countries.md)"; install -m 640 -o www-data -g www-data "$T/trust.json" "$DATA/trust/config.json"; fi
fi
hostname -f 2>/dev/null > "$T/node" || hostname > "$T/node"; install -m 640 -o www-data -g www-data "$T/node" "$DATA/trust/node"
for s in editlink.secret markup.secret affiliate-referral-salt; do
  if [ ! -s "$DATA/$s" ]; then genhex 32 > "$T/$s"; install -m 600 -o www-data -g www-data "$T/$s" "$DATA/$s"; fi
done
render_tpl "$REL/config/data/email/magic_link_login.html" "$T/magic_link_login.html"; put_new "$T/magic_link_login.html" "$DATA/email/magic_link_login.html" 640
put_new "$REL/config/data/email/magic_link_login.txt" "$DATA/email/magic_link_login.txt" 640
# Email logo: a plain mark made for this install (replace it with your own 96x96 PNG). The DW Alliance logo
# that v0.1.0 copied here is a trademark of DW Alliance LLC and is replaced.
DW_LOGO_V010=2f4e824f09364789f19a21c0baadcd263608146f4220d87fea1713a658fe95e4
if [ ! -s "$DATA/email/dw-logo.png" ] || [ "$(sha256sum "$DATA/email/dw-logo.png" | cut -d' ' -f1)" = "$DW_LOGO_V010" ]; then
  php "$REL/cli/dw-make-logo.php" "$BRAND_MARK" "$T/dw-logo.png" >/dev/null && install -m 640 -o www-data -g www-data "$T/dw-logo.png" "$DATA/email/dw-logo.png"
fi
put_new "$REL/config/data/paypal-README.txt" "$DATA/paypal/README.txt" 640
rm -rf "$T"
chown -R www-data:www-data "$DATA"

# ---------------------------------------------------------------- PHP-FPM
log "Configuring PHP-FPM $PHPV"
PHPDIR=/etc/php/$PHPV
install -m 644 "$REL/config/php/99-dwalliance.ini" "$PHPDIR/fpm/conf.d/99-dwalliance.ini"
install -m 644 "$REL/config/php/99-dwalliance.ini" "$PHPDIR/cli/conf.d/99-dwalliance.ini"
MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo); PM_MAX=$(( MEM_MB / 80 )); [ "$PM_MAX" -lt 5 ] && PM_MAX=5; [ "$PM_MAX" -gt 50 ] && PM_MAX=50
POOL=$PHPDIR/fpm/pool.d/dwalliance.conf
sed -e "s#__PHP_SOCK__#$PHP_SOCK#" -e "s#__PM_MAX__#$PM_MAX#" "$REL/config/php/pool.conf.tmpl" > "$POOL.tmp"
# PHP-FPM refuses empty env[] values; an unset variable reads the same as an empty one in the app.
grep -E '^[A-Z_]+=' "$ENVF" | while IFS='=' read -r k v; do v=${v#\'}; v=${v%\'}; [ -n "$v" ] && printf 'env[%s] = "%s"\n' "$k" "$v"; done >> "$POOL.tmp" || true
install -m 640 -o root -g root "$POOL.tmp" "$POOL"; rm -f "$POOL.tmp"
"php-fpm$PHPV" -t >/dev/null 2>&1 || die "php-fpm$PHPV config test failed"
svc enable "php$PHPV-fpm"; svc restart "php$PHPV-fpm"

# ---------------------------------------------------------------- nginx
log "Configuring nginx"
DW_SRC_DIR=$REL "$REL/cli/dw-render-nginx" --no-reload
svc enable nginx; svc reload nginx || svc restart nginx

# ---------------------------------------------------------------- helper commands
for c in dw-render-nginx dw-migrate dw-cert dw-run; do ln -sfn "$BASE/current/cli/$c" "/usr/local/sbin/$c"; done

# ---------------------------------------------------------------- mail
if [ "$MAIL_MODE" = smtp ]; then
  log "Configuring Postfix (outgoing mail, listens on localhost only)"
  postconf -e "myhostname = $APP_HOST" "myorigin = $DOMAIN" "inet_interfaces = loopback-only" "mydestination = localhost" "inet_protocols = ipv4"
  if [ -n "$SMTP_RELAY" ]; then
    postconf -e "relayhost = [${SMTP_RELAY%:*}]:${SMTP_RELAY##*:}" "smtp_sasl_auth_enable = yes" "smtp_sasl_password_maps = hash:/etc/postfix/sasl_passwd" "smtp_sasl_security_options = noanonymous" "smtp_tls_security_level = encrypt"
    if [ ! -e /etc/postfix/sasl_passwd ]; then
      printf '# [relay.example.com]:587 username:password   then run: postmap /etc/postfix/sasl_passwd\n' > /etc/postfix/sasl_passwd
      chmod 600 /etc/postfix/sasl_passwd
    fi
    postmap /etc/postfix/sasl_passwd
  fi
  svc enable postfix; svc restart postfix
else
  info "mail mode outbox: messages are written to $OUTBOX, nothing is sent"
fi

# ---------------------------------------------------------------- DNS
if [ "$DNS_ENABLE" = 1 ]; then
  log "Configuring tinydns (authoritative DNS from the app's DNS table)"
  # Our own unit (the package's runit-based unit needs runit; it is masked so only one tinydns binds port 53).
  if [ "$HAS_SYSTEMD" -eq 1 ]; then systemctl disable --now tinydns >/dev/null 2>&1 || true; systemctl mask tinydns >/dev/null 2>&1 || true; fi
  id tinydns >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin tinydns
  TDROOT=/var/lib/dwalliance/tinydns/root
  install -d -m 755 /var/lib/dwalliance /var/lib/dwalliance/tinydns "$TDROOT"
  printf 'IP=%s\nROOT=%s\nUID=%s\nGID=%s\n' "$DNS_IP" "$TDROOT" "$(id -u tinydns)" "$(id -g tinydns)" > "$ETC/tinydns.env"
  install -m 644 "$REL/config/systemd/dw-tinydns.service" /etc/systemd/system/dw-tinydns.service
  [ "$HAS_SYSTEMD" -eq 1 ] && systemctl daemon-reload
  if [ "$DNS_SEED" = 1 ]; then
    has=$(mysql -N -B crm -e "SELECT COUNT(*) FROM dns WHERE domain='$DOMAIN'")
    if [ "$has" = 0 ]; then
      info "adding the platform zone $DOMAIN"
      now=$(date '+%F %T')
      ins() { mysql crm -e "INSERT INTO dns (rr_type, rr_fqdn, rr_ip, rr_target, rr_mx_dist, rr_ttl, rr_timestamp, rr_email, domain, brand) VALUES ($1, '$now', '', '$DOMAIN', '')"; }
      for n in 1 2 3; do ins "'.', '$DOMAIN', '$PUBLIC_IP', 'ns$n.$DOMAIN', 0, 3600"; done
      for h in "$DOMAIN" "www.$DOMAIN" "$APP_HOST" "mail.$DOMAIN"; do ins "'+', '$h', '$PUBLIC_IP', '', 0, 600"; done
      ins "'@', '$DOMAIN', '', 'mail.$DOMAIN', 10, 600"
      ins "'''', '$DOMAIN', '', 'v=spf1 a mx ip4:$PUBLIC_IP ~all', 0, 600"
    fi
  fi
  "$REL/cli/dw-run" php "$REL/cli/dw-dns-build.php" --force
  if [ "$HAS_SYSTEMD" -eq 1 ]; then
    svc enable dw-tinydns; svc restart dw-tinydns
  else
    pkill -x tinydns 2>/dev/null || true
    ( set -a; . "$ETC/tinydns.env"; set +a; setsid /usr/sbin/tinydns >/dev/null 2>&1 < /dev/null & )
    warn "no systemd: tinydns was started in the background and will not start again after a reboot"
  fi
  sleep 1
fi

# ---------------------------------------------------------------- cron
log "Installing cron jobs"
install -d -m 750 /var/log/dwalliance
install -m 644 "$REL/config/logrotate/dwalliance" /etc/logrotate.d/dwalliance
sed "s#__SRC__#$BASE/current#g" "$REL/config/cron/dwalliance" > /etc/cron.d/dwalliance
[ "$DNS_ENABLE" = 1 ] || sed -i '/dw-dns-build/d' /etc/cron.d/dwalliance
chmod 644 /etc/cron.d/dwalliance
svc enable cron

# ---------------------------------------------------------------- first admin
if [ "$SKIP_ADMIN" -eq 0 ] && [ -n "$ADMIN_EMAIL" ]; then
  log "Creating the first super admin ($ADMIN_EMAIL)"
  "$REL/cli/dw-run" php "$REL/cli/dw-bootstrap-admin.php" --email="$ADMIN_EMAIL" --first="$ADMIN_FIRST" --last="$ADMIN_LAST" \
     --company="$COMPANY" --app-host="$APP_HOST" --domain="$DOMAIN" || warn "admin bootstrap failed; re-run install.sh after fixing the error above"
fi
# The first admin's brand owns the platform's own affiliate program (read by lib/dw_config.php; never printed).
if [ -s "$ETC/admin-brand-code" ] && [ ! -s "$ETC/platform-brand" ]; then
  install -m 640 -o root -g www-data "$ETC/admin-brand-code" "$ETC/platform-brand"
fi

# ---------------------------------------------------------------- signup lists
log "Building the signup network lists (Tor, VPN/datacenter, countries)"
info "country rule: $(python3 -c 'import json,sys; s=json.load(open(sys.argv[1])).get("signup",{}); print(s.get("country","block"), s.get("blocked_countries") or s.get("countries") or "")' "$DATA/trust/config.json" 2>/dev/null) (edit $DATA/trust/config.json; not legal advice, see docs/signup-countries.md)"
"$REL/cli/dw-run" php "$REL/cli/dw_iplists.php" 2>&1 | sed 's/^/    /' || warn "could not build the signup lists now; cron retries every 6 hours (signups are not blocked by a missing list)"

# ---------------------------------------------------------------- HTTPS
if [ "$HTTPS" = 1 ]; then
  log "Requesting Let's Encrypt certificates"
  "$REL/cli/dw-cert" add "$DOMAIN" "www.$DOMAIN" "$APP_HOST" --email "$CERTBOT_EMAIL" || warn "certbot failed: point DNS for $DOMAIN, www.$DOMAIN and $APP_HOST at $PUBLIC_IP, then run: dw-cert add $DOMAIN www.$DOMAIN $APP_HOST"
fi

# ---------------------------------------------------------------- check
log "Checking"
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $APP_HOST" http://127.0.0.1/ || true)
info "http://$APP_HOST/ -> HTTP $code"
[ "$DNS_ENABLE" = 1 ] && info "dig @$DNS_IP $APP_HOST -> $(dig +short +time=2 +tries=1 @"$DNS_IP" "$APP_HOST" A 2>/dev/null | head -1)"
cat <<EOF

DW Alliance $VERSION is installed.
  App/API:     http://$APP_HOST/   (point DNS for $APP_HOST, $DOMAIN and www.$DOMAIN at $PUBLIC_IP)
  Settings:    $CONF   Secrets: $ENVF
  Admin brand: emailed to $ADMIN_EMAIL; also saved in $ETC/admin-brand-code (root only)
  Sign in:     POST {"email","brand"} to /session/ (the session id is emailed), or use the magic link at https://$DOMAIN/session/login
  HTTPS:       dw-cert add $DOMAIN www.$DOMAIN $APP_HOST     (and dw-cert add <customer-domain> www.<customer-domain>)
  DNS:         $( [ "$DNS_ENABLE" = 1 ] && echo "tinydns on $DNS_IP:53 serves the zones in crm.dns (rebuilt every minute)" || echo "disabled" )
Re-run this installer any time; it is safe to repeat.
EOF
