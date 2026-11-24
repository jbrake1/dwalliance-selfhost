<?php
// Self-host configuration, read by lib/DwConfig.php. build.py installs this file as app/lib/dw_config.php;
// it is the only file that differs from the hosted service's code (see docs/RELEASING.md).
//
// Per-install values come from /etc/dwalliance/dwalliance.env (DW_<KEY>, written by install.sh), which
// override everything here. Change them by re-running install.sh with new options, or edit
// dwalliance.env and run: install.sh (or restart php-fpm). Passwords live only in dwalliance.env.
//
// The "DW Alliance" name and logo are trademarks of DW Alliance LLC and are not licensed with the code
// (TRADEMARKS.md). The defaults below are neutral; install.sh sets your own brand.
return [
    // Databases (single host)
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => 'dwalliance',
    'DB_USER' => 'dwalliance',
    'DNS_DB_HOST' => '127.0.0.1',
    'DNS_DB_NAME' => 'crm',
    // crm.dns uses a zero rr_timestamp for non-SOA rows (tinydns convention); MySQL 8's default
    // NO_ZERO_DATE would refuse them, so the DNS connection drops NO_ZERO_* from the 8.x default.
    'DNS_DB_SQL_MODE' => 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
    'DB_TIMEZONE' => 'UTC',

    // Outgoing mail: the local Postfix. Empty HELO = this server's host name.
    'SMTP_HOST' => '127.0.0.1',
    'SMTP_PORT' => '25',
    'SMTP_HELO' => '',

    // Network: no reverse proxy is trusted unless listed; loopback is always internal.
    'TRUSTED_PROXIES' => '',
    'INTERNAL_NETS' => '',
    'PUBLIC_IP' => '203.0.113.10',

    // People
    'ADMIN_EMAIL' => '',
    'NETWORK_TEST_EMAILS' => '',

    // Branding (install.sh: --brand-name, --brand-legal-name, --brand-mark, --domain, --app-host)
    'BRAND_NAME' => 'My Platform',
    'BRAND_LEGAL_NAME' => 'My Platform',
    'BRAND_MARK' => 'MP',
    'PLATFORM_DOMAIN' => 'example.com',
    'APP_HOST' => 'app.example.com',
    'SUPPORT_EMAIL' => 'support@example.com',
    'LOGO_URL' => 'https://example.com/assets/email/dw-logo.png',
    // The first admin's brand owns the platform affiliate program (file written by install.sh, root:www-data 640).
    'PLATFORM_BRAND_CODE' => trim((string)@file_get_contents('/etc/dwalliance/platform-brand')),

    // Page examples: a showcase store for the store guide (empty = no mention) and the example site in forms.
    'STORE_SHOWCASE_DOMAIN' => '',
    'STORE_SHOWCASE_NAME' => '',
    'EXAMPLE_SITE' => 'example.com',

    // Affiliate terms (install.sh: --terms-company, --terms-site, --terms-contact, --terms-jurisdiction).
    // The default terms are a starting point, not legal advice: have your own lawyer review them.
    'AFFILIATE_TERMS_COMPANY' => '',
    'AFFILIATE_TERMS_SITE' => '',
    'AFFILIATE_TERMS_CONTACT' => '',
    'AFFILIATE_TERMS_JURISDICTION' => '',
    'AFFILIATE_TERMS_NOTICE' => 'Before you use these terms, have your own lawyer review them. The default wording is a starting point, not legal advice.',

    // Restock test-mode guard: the database that counts as live.
    'RESTOCK_LIVE_DB_NAME' => 'dwalliance',
];
