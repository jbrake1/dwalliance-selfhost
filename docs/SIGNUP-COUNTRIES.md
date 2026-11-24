# Signup countries

Refs #79. `/account_setup` can refuse new signups by network location (`lib/DwSignup.php`). The
country data is DB-IP Lite (CC-BY 4.0, https://db-ip.com), refreshed monthly by `dw_iplists.php`
from cron. Settings go in the `"signup"` object of `/var/lib/app/data/trust/config.json`.

| `country` | Meaning |
|---|---|
| `"block"` | Only the `countries` list may sign up. **The hosted service uses this with `["US"]`. It is unchanged.** |
| `"deny"` | Everyone may sign up except `blocked_countries` (ISO 3166 codes) and `blocked_regions`. This is the self-host default. |
| `"allow"` | No country rule. |

`blocked_regions` entries are `"CC:Region"`, matched case-insensitively against the region (stateprov)
column of the DB-IP Lite city file. Region data is about 85 MB compressed. It is downloaded only when
`blocked_regions` is not empty, and the lists are rebuilt when they change. Region names with no match
are logged and listed under `unmatched_regions` in `iplists/country.json`.

## Self-host default list

**This is not legal advice.** The default is a starting point based on the US OFAC
comprehensive (country-wide or region-wide) sanctions programs, checked against OFAC's pages in
November 2026. Sanctions change often. Each operator must decide, with
their own counsel, which countries and regions they may serve, and keep the list current.

- Countries: Cuba (`CU`), Iran (`IR`), North Korea (`KP`).
- **Syria (`SY`) is not blocked.** OFAC ended its comprehensive Syria program on July 1, 2025
  (E.O. 14312), removed the Syrian Sanctions Regulations on August 25, 2025, and rescinded Syria's
  State Sponsor of Terrorism designation on August 24, 2026. List-based sanctions on specific people
  remain, and US export controls (Commerce, EAR) still apply to some items. Sources:
  https://ofac.treasury.gov/faqs/1220 and
  https://ofac.treasury.gov/sanctions-programs-and-country-information/paarss
- Regions of Ukraine: Crimea (including Sevastopol), and the so-called Donetsk and Luhansk People's
  Republic regions. DB-IP Lite files some of these addresses under `RU`, so the default list uses the
  region names as DB-IP spells them (checked against the November 2026 file) under both codes:
  `UA:Crimea`, `UA:Sebastopol City`, `UA:Donetsk`, `UA:Luhansk`, `RU:Crimea`, `RU:Sebastopol City`,
  `RU:Luhansk`. After each refresh, check `unmatched_regions` in `iplists/country.json` in case DB-IP
  renames a region.
  - The OFAC Ukraine-related program covers only the parts of Donetsk and Luhansk named in the
    executive order, not whole oblasts. A whole-oblast block is broader than the sanctions themselves.
- **Russia and Belarus are deliberately not blocked.** US sanctions on them are sectoral and
  list-based (specific people, companies, banks and activities), not a country-wide ban on all
  dealings. Whether you can serve users there depends on your business, payment processors and
  jurisdiction. Decide this with counsel; the software does not guess.

## Limits

- IP geolocation is approximate, and VPNs and proxies can get around it. The Tor and VPN/datacenter
  blocks help but are not complete.
- Signup checks do **not** screen names against the OFAC SDN list or any other list of sanctioned
  people. Operators who need that must add it.
- The rule runs at signup only. It does not touch existing accounts or browsing.
- If the country data cannot be read, the check fails open and writes a log line.
