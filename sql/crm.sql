-- DNS records database used by /dns, /domain and lib/DwZone.php (Dw::pdoDns()).
-- One row per tinydns data line: rr_type is the tinydns line type.
--   +  A            rr_fqdn, rr_ip
--   =  A + PTR      rr_fqdn, rr_ip
--   C  CNAME        rr_fqdn, rr_target
--   @  MX           rr_fqdn, rr_target, rr_mx_dist (rr_ip optional)
--   .  NS + SOA     rr_fqdn, rr_target (rr_ip optional)
--   &  NS (deleg.)  rr_fqdn, rr_target
--   ^  PTR          rr_fqdn, rr_target
--   Z  SOA          rr_fqdn, rr_target (mname), rr_email, rr_serial, rr_refresh, rr_retry, rr_expire, rr_minimum
--   '  TXT          rr_fqdn, rr_target
-- cli/dw-dns-build.php turns these rows into /var/lib/dwalliance/tinydns/root/data and runs tinydns-data.
CREATE DATABASE IF NOT EXISTS `crm` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `crm`.`dns` (
  `rr_id` int unsigned NOT NULL AUTO_INCREMENT,
  `rr_type` enum('+','C','=','@','&','.','^','Z','''') NOT NULL,
  `rr_fqdn` varchar(100) NOT NULL,
  `rr_ip` varchar(15) NOT NULL DEFAULT '',
  `rr_target` varchar(1024) NOT NULL DEFAULT '',
  `rr_mx_dist` tinyint unsigned NOT NULL DEFAULT 0,
  `rr_ttl` mediumint unsigned NOT NULL DEFAULT 600,
  `rr_timestamp` datetime NOT NULL,
  `rr_email` varchar(255) NOT NULL DEFAULT '',
  `rr_serial` int unsigned NOT NULL DEFAULT 0,
  `rr_refresh` int unsigned NOT NULL DEFAULT 0,
  `rr_retry` int unsigned NOT NULL DEFAULT 0,
  `rr_expire` int unsigned NOT NULL DEFAULT 0,
  `rr_minimum` int unsigned NOT NULL DEFAULT 0,
  `rr_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `company_id` varchar(50) NOT NULL DEFAULT '',
  `domain` varchar(255) NOT NULL,
  `brand` varchar(10) NOT NULL DEFAULT '',
  PRIMARY KEY (`rr_id`),
  KEY `idx_dns_brand_domain` (`brand`, `domain`),
  KEY `idx_dns_domain` (`domain`),
  KEY `idx_dns_fqdn` (`rr_fqdn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
