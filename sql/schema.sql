-- DW Alliance self-host: full application schema (structure only, no data).
-- Equivalent to the base schema plus migrations 001-025 (link_tree). Safe to re-run:
-- every table is CREATE TABLE IF NOT EXISTS. Later migrations live in sql/migrations/ (026+).
-- Requires MySQL 8.0+ (utf8mb4_0900_ai_ci, JSON). Loaded by install.sh into $DW_DB_NAME.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `account_setup` (
  `id` varchar(50) NOT NULL,
  `remote_ip` varchar(50) DEFAULT NULL,
  `confirmation_id` varchar(6) NOT NULL,
  `email` varchar(100) NOT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `address` (
  `id` varchar(50) NOT NULL,
  `line_1` varchar(100) DEFAULT NULL,
  `line_2` varchar(100) DEFAULT NULL,
  `line_3` varchar(100) DEFAULT NULL,
  `city` varchar(30) DEFAULT NULL,
  `state` varchar(30) DEFAULT NULL,
  `zipcode` varchar(20) DEFAULT NULL,
  `address_type` enum('Billing','Shipping','Other') DEFAULT NULL,
  `country` varchar(20) DEFAULT NULL,
  `owner_id` varchar(50) DEFAULT NULL,
  `owner_type` enum('CMPNY','PRSN') DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_address_owner` (`owner_id`,`owner_type`),
  KEY `idx_address_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `address_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `line_1` varchar(100) DEFAULT NULL,
  `line_2` varchar(100) DEFAULT NULL,
  `line_3` varchar(100) DEFAULT NULL,
  `city` varchar(30) DEFAULT NULL,
  `state` varchar(30) DEFAULT NULL,
  `zipcode` varchar(20) DEFAULT NULL,
  `address_type` enum('Billing','Shipping','Other') DEFAULT NULL,
  `country` varchar(20) DEFAULT NULL,
  `owner_id` varchar(50) DEFAULT NULL,
  `owner_type` enum('CMPNY','PRSN') DEFAULT NULL,
  `created` datetime NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_address_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate` (
  `affiliate_id` varchar(50) NOT NULL,
  `code` varchar(16) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `company_id` varchar(50) DEFAULT NULL,
  `owner_person_id` varchar(50) NOT NULL,
  `owner_company_id` varchar(50) DEFAULT NULL,
  `commission_rate_override` decimal(5,2) DEFAULT NULL,
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY (`affiliate_id`),
  UNIQUE KEY `affiliate_person` (`person_id`),
  UNIQUE KEY `affiliate_code` (`code`),
  KEY `affiliate_company` (`company_id`),
  KEY `affiliate_owner_person` (`owner_person_id`),
  KEY `affiliate_owner_company` (`owner_company_id`),
  CONSTRAINT `fk_affiliate_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`),
  CONSTRAINT `fk_affiliate_owner_company` FOREIGN KEY (`owner_company_id`) REFERENCES `company` (`id`),
  CONSTRAINT `fk_affiliate_owner_person` FOREIGN KEY (`owner_person_id`) REFERENCES `person` (`id`),
  CONSTRAINT `fk_affiliate_person` FOREIGN KEY (`person_id`) REFERENCES `person` (`id`),
  CONSTRAINT `chk_affiliate_commission_override` CHECK (((`commission_rate_override` is null) or ((`commission_rate_override` >= 0.00) and (`commission_rate_override` <= 100.00))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_brand_setting` (
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `program_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `default_commission_percent` decimal(5,2) NOT NULL DEFAULT '20.00',
  `client_discount_percent` decimal(5,2) NOT NULL DEFAULT '20.00',
  `client_discount_months` smallint NOT NULL DEFAULT '12',
  `terms` text,
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `last_updated` datetime DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_affiliate_brand_setting_brand` (`brand`),
  CONSTRAINT `chk_affiliate_brand_default_percent` CHECK (((`default_commission_percent` >= 0.00) and (`default_commission_percent` <= 100.00))),
  CONSTRAINT `chk_affiliate_brand_discount_months` CHECK (((`client_discount_months` >= 0) and (`client_discount_months` <= 120))),
  CONSTRAINT `chk_affiliate_brand_discount_percent` CHECK (((`client_discount_percent` >= 0.00) and (`client_discount_percent` <= 100.00)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_brand_setting_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `program_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `default_commission_percent` decimal(5,2) NOT NULL DEFAULT '20.00',
  `client_discount_percent` decimal(5,2) NOT NULL DEFAULT '20.00',
  `client_discount_months` smallint NOT NULL DEFAULT '12',
  `terms` text,
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `last_updated` datetime DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `affiliate_brand_setting_history_id` (`id`),
  KEY `affiliate_brand_setting_history_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_channel` (
  `affiliate_channel_id` varchar(50) NOT NULL,
  `code` varchar(16) NOT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `type` enum('email_list','website','tiktok','facebook','youtube','other') NOT NULL,
  `handle` varchar(255) NOT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY (`affiliate_channel_id`),
  UNIQUE KEY `affiliate_channel_code` (`code`),
  KEY `affiliate_channel_affiliate` (`affiliate_id`),
  CONSTRAINT `fk_affiliate_channel_affiliate` FOREIGN KEY (`affiliate_id`) REFERENCES `affiliate` (`affiliate_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_channel_history` (
  `history_id` varchar(50) NOT NULL,
  `affiliate_channel_id` varchar(50) NOT NULL,
  `code` varchar(16) DEFAULT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `type` enum('email_list','website','tiktok','facebook','youtube','other') NOT NULL,
  `handle` varchar(255) NOT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `affiliate_channel_history_channel` (`affiliate_channel_id`),
  KEY `affiliate_channel_history_affiliate` (`affiliate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_commission` (
  `id` varchar(50) NOT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `owner_person_id` varchar(50) DEFAULT NULL,
  `owner_company_id` varchar(50) DEFAULT NULL,
  `affiliate_channel_id` varchar(50) DEFAULT NULL,
  `affiliate_subscription_id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `payment_id` varchar(50) DEFAULT NULL,
  `paypal_subscription_id` varchar(64) DEFAULT NULL,
  `gross` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `net_paid` decimal(10,2) NOT NULL,
  `rate` decimal(5,4) NOT NULL,
  `commission` decimal(10,2) NOT NULL,
  `status` enum('pending','approved','paid','reversed') NOT NULL DEFAULT 'pending',
  `reversed_at` datetime DEFAULT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `affiliate_commission_invoice` (`invoice_id`),
  KEY `affiliate_commission_affiliate` (`affiliate_id`,`status`),
  KEY `affiliate_commission_subscription` (`affiliate_subscription_id`),
  CONSTRAINT `fk_affiliate_commission_affiliate` FOREIGN KEY (`affiliate_id`) REFERENCES `affiliate` (`affiliate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_commission_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `owner_person_id` varchar(50) DEFAULT NULL,
  `owner_company_id` varchar(50) DEFAULT NULL,
  `affiliate_channel_id` varchar(50) DEFAULT NULL,
  `affiliate_subscription_id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `payment_id` varchar(50) DEFAULT NULL,
  `paypal_subscription_id` varchar(64) DEFAULT NULL,
  `gross` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `net_paid` decimal(10,2) NOT NULL,
  `rate` decimal(5,4) NOT NULL,
  `commission` decimal(10,2) NOT NULL,
  `status` enum('pending','approved','paid','reversed') NOT NULL DEFAULT 'pending',
  `reversed_at` datetime DEFAULT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `affiliate_commission_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_email_challenge` (
  `id` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `secret_hash` char(64) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `affiliate_id` varchar(50) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `expires` datetime NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `affiliate_email_challenge_email` (`email`),
  KEY `affiliate_email_challenge_hash` (`secret_hash`),
  KEY `affiliate_email_challenge_affiliate` (`affiliate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_history` (
  `history_id` varchar(50) NOT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `code` varchar(16) DEFAULT NULL,
  `person_id` varchar(50) NOT NULL,
  `company_id` varchar(50) DEFAULT NULL,
  `owner_person_id` varchar(50) DEFAULT NULL,
  `owner_company_id` varchar(50) DEFAULT NULL,
  `commission_rate_override` decimal(5,2) DEFAULT NULL,
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `affiliate_history_affiliate` (`affiliate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_ownership_transfer` (
  `id` varchar(50) NOT NULL,
  `code` varchar(16) NOT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `from_owner_person_id` varchar(50) NOT NULL,
  `from_owner_company_id` varchar(50) DEFAULT NULL,
  `to_owner_person_id` varchar(50) NOT NULL,
  `to_owner_company_id` varchar(50) DEFAULT NULL,
  `effective_at` datetime NOT NULL,
  `reason` enum('sale','inheritance','corporate_transfer','other') NOT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `requested_by` varchar(50) NOT NULL,
  `approved_by` varchar(50) NOT NULL,
  `approved_at` datetime NOT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `affiliate_ownership_transfer_code` (`code`),
  KEY `affiliate_ownership_transfer_affiliate` (`affiliate_id`,`effective_at`),
  KEY `affiliate_ownership_transfer_from` (`from_owner_person_id`),
  KEY `affiliate_ownership_transfer_to` (`to_owner_person_id`),
  KEY `fk_affiliate_ownership_transfer_from_company` (`from_owner_company_id`),
  KEY `fk_affiliate_ownership_transfer_to_company` (`to_owner_company_id`),
  CONSTRAINT `fk_affiliate_ownership_transfer_affiliate` FOREIGN KEY (`affiliate_id`) REFERENCES `affiliate` (`affiliate_id`),
  CONSTRAINT `fk_affiliate_ownership_transfer_from_company` FOREIGN KEY (`from_owner_company_id`) REFERENCES `company` (`id`),
  CONSTRAINT `fk_affiliate_ownership_transfer_from_person` FOREIGN KEY (`from_owner_person_id`) REFERENCES `person` (`id`),
  CONSTRAINT `fk_affiliate_ownership_transfer_to_company` FOREIGN KEY (`to_owner_company_id`) REFERENCES `company` (`id`),
  CONSTRAINT `fk_affiliate_ownership_transfer_to_person` FOREIGN KEY (`to_owner_person_id`) REFERENCES `person` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_ownership_transfer_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `code` varchar(16) NOT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `from_owner_person_id` varchar(50) NOT NULL,
  `from_owner_company_id` varchar(50) DEFAULT NULL,
  `to_owner_person_id` varchar(50) NOT NULL,
  `to_owner_company_id` varchar(50) DEFAULT NULL,
  `effective_at` datetime NOT NULL,
  `reason` enum('sale','inheritance','corporate_transfer','other') NOT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `requested_by` varchar(50) NOT NULL,
  `approved_by` varchar(50) NOT NULL,
  `approved_at` datetime NOT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `affiliate_ownership_transfer_history_id` (`id`),
  KEY `affiliate_ownership_transfer_history_affiliate` (`affiliate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_referral` (
  `id` varchar(50) NOT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `affiliate_channel_id` varchar(50) DEFAULT NULL,
  `code` varchar(16) NOT NULL,
  `commission_percent` decimal(5,2) NOT NULL DEFAULT '20.00',
  `source_type` enum('url_param','link','coupon','manual') NOT NULL DEFAULT 'url_param',
  `landing_url` varchar(1024) NOT NULL,
  `referrer` varchar(1024) DEFAULT NULL,
  `utm_source` varchar(100) DEFAULT NULL,
  `utm_medium` varchar(100) DEFAULT NULL,
  `utm_campaign` varchar(100) DEFAULT NULL,
  `utm_term` varchar(100) DEFAULT NULL,
  `utm_content` varchar(100) DEFAULT NULL,
  `visitor_token` char(32) NOT NULL,
  `ip_hash` char(64) NOT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  `converted_person_id` varchar(50) DEFAULT NULL,
  `converted_company_id` varchar(50) DEFAULT NULL,
  `converted_reference_type` enum('signup','order','subscription') DEFAULT NULL,
  `converted_reference_id` varchar(50) DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `affiliate_referral_affiliate` (`affiliate_id`),
  KEY `affiliate_referral_channel` (`affiliate_channel_id`),
  KEY `affiliate_referral_visitor` (`visitor_token`),
  KEY `affiliate_referral_code` (`code`),
  KEY `affiliate_referral_converted` (`converted_reference_type`,`converted_reference_id`),
  CONSTRAINT `fk_affiliate_referral_affiliate` FOREIGN KEY (`affiliate_id`) REFERENCES `affiliate` (`affiliate_id`),
  CONSTRAINT `fk_affiliate_referral_channel` FOREIGN KEY (`affiliate_channel_id`) REFERENCES `affiliate_channel` (`affiliate_channel_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_affiliate_referral_percent` CHECK (((`commission_percent` >= 0.00) and (`commission_percent` <= 100.00)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_referral_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `affiliate_channel_id` varchar(50) DEFAULT NULL,
  `code` varchar(16) NOT NULL,
  `commission_percent` decimal(5,2) DEFAULT NULL,
  `source_type` enum('url_param','link','coupon','manual') NOT NULL DEFAULT 'url_param',
  `landing_url` varchar(1024) NOT NULL,
  `referrer` varchar(1024) DEFAULT NULL,
  `utm_source` varchar(100) DEFAULT NULL,
  `utm_medium` varchar(100) DEFAULT NULL,
  `utm_campaign` varchar(100) DEFAULT NULL,
  `utm_term` varchar(100) DEFAULT NULL,
  `utm_content` varchar(100) DEFAULT NULL,
  `visitor_token` char(32) NOT NULL,
  `ip_hash` char(64) NOT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  `converted_person_id` varchar(50) DEFAULT NULL,
  `converted_company_id` varchar(50) DEFAULT NULL,
  `converted_reference_type` enum('signup','order','subscription') DEFAULT NULL,
  `converted_reference_id` varchar(50) DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `affiliate_referral_history_id` (`id`),
  KEY `affiliate_referral_history_affiliate` (`affiliate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_subscription` (
  `id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `paypal_subscription_id` varchar(64) DEFAULT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `affiliate_channel_id` varchar(50) DEFAULT NULL,
  `referral_id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `discount_source` enum('affiliate') NOT NULL DEFAULT 'affiliate',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `discount_months` smallint NOT NULL DEFAULT '0',
  `commission_percent` decimal(5,2) NOT NULL DEFAULT '20.00',
  `regular_unit` decimal(10,2) NOT NULL,
  `discounted_unit` decimal(10,2) NOT NULL,
  `regular_tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `discounted_tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `referred_plan_id` varchar(64) DEFAULT NULL,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `affiliate_subscription_invoice` (`invoice_id`),
  KEY `affiliate_subscription_paypal` (`paypal_subscription_id`),
  KEY `affiliate_subscription_affiliate` (`affiliate_id`),
  KEY `affiliate_subscription_referral` (`referral_id`),
  CONSTRAINT `fk_affiliate_subscription_affiliate` FOREIGN KEY (`affiliate_id`) REFERENCES `affiliate` (`affiliate_id`),
  CONSTRAINT `chk_affiliate_subscription_percent` CHECK (((`commission_percent` >= 0.00) and (`commission_percent` <= 100.00)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_subscription_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `paypal_subscription_id` varchar(64) DEFAULT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `affiliate_channel_id` varchar(50) DEFAULT NULL,
  `referral_id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `discount_source` enum('affiliate') NOT NULL DEFAULT 'affiliate',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `discount_months` smallint NOT NULL DEFAULT '0',
  `commission_percent` decimal(5,2) DEFAULT NULL,
  `regular_unit` decimal(10,2) NOT NULL,
  `discounted_unit` decimal(10,2) NOT NULL,
  `regular_tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `discounted_tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `referred_plan_id` varchar(64) DEFAULT NULL,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `affiliate_subscription_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_transfer_request` (
  `id` varchar(50) NOT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `to_email` varchar(255) NOT NULL,
  `to_company` varchar(100) DEFAULT NULL,
  `reason` enum('sale','inheritance','corporate_transfer','other') NOT NULL,
  `notes` text,
  `effective_at` datetime DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `requested_by` varchar(50) NOT NULL,
  `requested_via` varchar(80) NOT NULL,
  `decided_by` varchar(50) DEFAULT NULL,
  `decided_via` varchar(80) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_note` varchar(500) DEFAULT NULL,
  `transfer_id` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `affiliate_transfer_request_brand_status` (`brand`,`status`),
  KEY `affiliate_transfer_request_affiliate` (`affiliate_id`),
  CONSTRAINT `fk_affiliate_transfer_request_affiliate` FOREIGN KEY (`affiliate_id`) REFERENCES `affiliate` (`affiliate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `affiliate_transfer_request_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `affiliate_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `to_email` varchar(255) NOT NULL,
  `to_company` varchar(100) DEFAULT NULL,
  `reason` enum('sale','inheritance','corporate_transfer','other') NOT NULL,
  `notes` text,
  `effective_at` datetime DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL,
  `requested_by` varchar(50) NOT NULL,
  `requested_via` varchar(80) NOT NULL,
  `decided_by` varchar(50) DEFAULT NULL,
  `decided_via` varchar(80) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_note` varchar(500) DEFAULT NULL,
  `transfer_id` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `affiliate_transfer_request_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `app_setting` (
  `id` varchar(50) NOT NULL,
  `setting_name` varchar(50) NOT NULL,
  `setting_value` varchar(200) NOT NULL,
  `created` datetime NOT NULL,
  `created_by_person_id` varchar(50) NOT NULL,
  `updated` datetime NOT NULL,
  `updated_by_person_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_setting_name` (`setting_name`),
  CONSTRAINT `chk_app_setting_banner` CHECK ((((`setting_name` = _utf8mb4'banner_default') and (`setting_value` in (_utf8mb4'on',_utf8mb4'off'))) or ((`setting_name` = _utf8mb4'banner_style') and (`setting_value` in (_utf8mb4'A',_utf8mb4'B',_utf8mb4'C'))) or ((`setting_name` = _utf8mb4'watermark_default') and (`setting_value` in (_utf8mb4'on',_utf8mb4'off'))) or (`setting_name` not in (_utf8mb4'banner_default',_utf8mb4'banner_style',_utf8mb4'watermark_default'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `app_setting_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `setting_name` varchar(50) NOT NULL,
  `setting_value` varchar(200) NOT NULL,
  `created` datetime NOT NULL,
  `created_by_person_id` varchar(50) NOT NULL,
  `updated` datetime NOT NULL,
  `updated_by_person_id` varchar(50) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_app_setting_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `brand` (
  `id` varchar(50) NOT NULL,
  `code` varchar(10) NOT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dw_person_id` varchar(50) DEFAULT NULL,
  `dw_company_id` varchar(50) DEFAULT NULL,
  `banner_mode` enum('default','on','off') NOT NULL DEFAULT 'default' COMMENT 'staff: show or hide the DW banner; default = follow app_setting.banner_default',
  `banner_style` enum('default','A','B','C') NOT NULL DEFAULT 'default' COMMENT 'staff style when the owner has not set domain_setting.banner_style; default = follow app_setting.banner_style',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brand_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `brand_entitlement` (
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL COMMENT 'tenant brand that benefits (domain.code)',
  `domain_id` varchar(50) DEFAULT NULL COMMENT 'bare domain row; NULL = every domain of the brand (staff only)',
  `entitlement` varchar(30) NOT NULL COMMENT 'e.g. no_banner',
  `status` enum('ACTIVE','CANCELLED','REVOKED') NOT NULL DEFAULT 'ACTIVE',
  `starts_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_through` datetime DEFAULT NULL COMMENT 'NULL = no end (staff grant)',
  `grace_until` datetime DEFAULT NULL COMMENT 'paid_through + grace days; banner returns after this. NULL = no end',
  `source_brand` varchar(10) NOT NULL COMMENT 'seller brand (DW Alliance LLC)',
  `source_invoice_id` varchar(50) DEFAULT NULL,
  `source_invoice_product_id` varchar(50) DEFAULT NULL,
  `source_variant_id` varchar(50) DEFAULT NULL,
  `provider_ref` varchar(100) DEFAULT NULL COMMENT 'future PayPal subscription id',
  `note` varchar(200) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by_person_id` varchar(50) DEFAULT NULL,
  `updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by_person_id` varchar(50) DEFAULT NULL,
  `next_billing_time` datetime DEFAULT NULL COMMENT 'PayPal billing_info.next_billing_time, when known',
  `provider_status` varchar(32) DEFAULT NULL COMMENT 'PayPal subscription status: APPROVAL_PENDING, APPROVED, ACTIVE, SUSPENDED, CANCELLED, EXPIRED',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_be_invoice_line` (`source_invoice_product_id`),
  KEY `idx_be_lookup` (`brand`,`entitlement`,`status`,`grace_until`),
  KEY `idx_be_domain` (`domain_id`),
  KEY `idx_be_invoice` (`source_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `brand_entitlement_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `domain_id` varchar(50) DEFAULT NULL,
  `entitlement` varchar(30) NOT NULL,
  `status` enum('ACTIVE','CANCELLED','REVOKED') NOT NULL,
  `starts_at` datetime NOT NULL,
  `paid_through` datetime DEFAULT NULL,
  `grace_until` datetime DEFAULT NULL,
  `source_brand` varchar(10) NOT NULL,
  `source_invoice_id` varchar(50) DEFAULT NULL,
  `source_invoice_product_id` varchar(50) DEFAULT NULL,
  `source_variant_id` varchar(50) DEFAULT NULL,
  `provider_ref` varchar(100) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `created` datetime NOT NULL,
  `created_by_person_id` varchar(50) DEFAULT NULL,
  `updated` datetime NOT NULL,
  `updated_by_person_id` varchar(50) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `next_billing_time` datetime DEFAULT NULL,
  `provider_status` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_beh_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `brand_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `code` varchar(10) NOT NULL,
  `created` datetime NOT NULL,
  `dw_person_id` varchar(50) DEFAULT NULL,
  `dw_company_id` varchar(50) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `banner_mode` enum('default','on','off') NOT NULL DEFAULT 'default',
  `banner_style` enum('default','A','B','C') NOT NULL DEFAULT 'default',
  PRIMARY KEY (`history_id`),
  KEY `idx_brand_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `cart` (
  `id` varchar(50) NOT NULL,
  `session_id` varchar(50) DEFAULT NULL,
  `company_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cart_session_id` (`session_id`),
  KEY `idx_cart_company_id` (`company_id`),
  KEY `idx_cart_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `cart_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `session_id` varchar(50) DEFAULT NULL,
  `company_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_cart_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `cart_item` (
  `id` varchar(50) NOT NULL,
  `cart_id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `quantity` int NOT NULL,
  `notes` text,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `target_domain_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_item_line` (`cart_id`,`variant_id`),
  KEY `idx_cart_item_product_id` (`product_id`),
  KEY `idx_cart_item_variant_id` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `cart_item_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `cart_id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `quantity` int NOT NULL,
  `notes` text,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `target_domain_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_cart_item_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `category` (
  `id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `parent_id` varchar(50) DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `description` json DEFAULT NULL,
  `position` int NOT NULL DEFAULT '0',
  `last_updated_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `last_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category_company_id` (`company_id`),
  KEY `idx_category_parent_id` (`parent_id`),
  KEY `idx_category_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `category_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `parent_id` varchar(50) DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `description` json DEFAULT NULL,
  `position` int NOT NULL DEFAULT '0',
  `last_updated_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `last_updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_category_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `company` (
  `id` varchar(50) NOT NULL,
  `company_name` varchar(100) NOT NULL,
  `brand` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company_brand` (`brand`),
  KEY `idx_company_name` (`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `company_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `company_name` varchar(100) NOT NULL,
  `brand` varchar(10) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_company_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `data_access` (
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `brand` varchar(10) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by_person_id` varchar(50) DEFAULT NULL,
  `right_name` enum('SUPER_ADMIN','READ_PRIVATE','READ','WRITE','UPDATE','REPORT','REPORT_PRIVATE','CREATE','DELETE') NOT NULL,
  `module_name` enum('LOGIN','FILE','STORE','EVENT','DONATION','SURVEY','CRM','NOTE','DNS','DOMAIN','AFFILIATE') NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_data_access_person` (`person_id`),
  KEY `idx_data_access_brand` (`brand`),
  KEY `idx_data_access_module` (`module_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `data_access_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `brand` varchar(10) DEFAULT NULL,
  `created` datetime NOT NULL,
  `created_by_person_id` varchar(50) DEFAULT NULL,
  `right_name` enum('SUPER_ADMIN','READ_PRIVATE','READ','WRITE','UPDATE','REPORT','REPORT_PRIVATE','CREATE','DELETE') NOT NULL,
  `module_name` enum('LOGIN','FILE','STORE','EVENT','DONATION','SURVEY','CRM','NOTE','DNS','DOMAIN','AFFILIATE') NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_data_access_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `dns_history` (
  `history_id` varchar(50) NOT NULL,
  `rr_id` int unsigned NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `rr_type` enum('+','C','=','@','&','.','^','Z','''') NOT NULL,
  `rr_fqdn` varchar(100) NOT NULL,
  `rr_ip` varchar(15) NOT NULL,
  `rr_target` varchar(1024) NOT NULL,
  `rr_mx_dist` tinyint unsigned NOT NULL,
  `rr_ttl` mediumint unsigned NOT NULL,
  `rr_timestamp` datetime NOT NULL,
  `rr_email` varchar(255) NOT NULL,
  `rr_serial` int unsigned NOT NULL,
  `rr_refresh` int unsigned NOT NULL,
  `rr_retry` int unsigned NOT NULL,
  `rr_expire` int unsigned NOT NULL,
  `rr_minimum` int unsigned NOT NULL,
  `rr_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `domain` varchar(255) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `action` varchar(10) NOT NULL,
  `archived_at` datetime NOT NULL,
  PRIMARY KEY (`history_id`),
  KEY `rr_id` (`rr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `domain` (
  `id` varchar(50) NOT NULL,
  `domain_name` varchar(100) NOT NULL,
  `code` varchar(10) DEFAULT NULL,
  `company_id` varchar(50) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `watermark_mode` enum('default','on','off') NOT NULL DEFAULT 'default' COMMENT 'owner mark only: on/off, or default = follow app_setting.watermark_default',
  `watermark_config` json DEFAULT NULL COMMENT 'owner mark style; NULL = default text (domain name), diagonal, 22% opacity',
  PRIMARY KEY (`id`),
  KEY `idx_domain_code` (`code`),
  KEY `idx_domain_name` (`domain_name`),
  KEY `idx_domain_company` (`company_id`),
  KEY `idx_domain_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `domain_editor` (
  `id` varchar(50) NOT NULL,
  `domain_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by_person_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_domain_editor` (`domain_id`,`person_id`),
  KEY `idx_domain_editor_person` (`person_id`),
  KEY `idx_domain_editor_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `domain_editor_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `domain_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `created` datetime NOT NULL,
  `created_by_person_id` varchar(50) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_domain_editor_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `domain_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `domain_name` varchar(100) NOT NULL,
  `code` varchar(10) DEFAULT NULL,
  `company_id` varchar(50) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `watermark_mode` enum('default','on','off') NOT NULL DEFAULT 'default',
  `watermark_config` json DEFAULT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_domain_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `domain_setting` (
  `id` varchar(50) NOT NULL,
  `domain_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `setting_name` varchar(50) NOT NULL,
  `setting_value` varchar(200) NOT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by_person_id` varchar(50) DEFAULT NULL,
  `updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by_person_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_domain_setting` (`domain_id`,`setting_name`),
  KEY `idx_domain_setting_brand` (`brand`),
  CONSTRAINT `chk_domain_setting_banner_style` CHECK (((`setting_name` <> _utf8mb4'banner_style') or (`setting_value` in (_utf8mb4'A',_utf8mb4'B',_utf8mb4'C'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `domain_setting_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `domain_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `setting_name` varchar(50) NOT NULL,
  `setting_value` varchar(200) NOT NULL,
  `created` datetime NOT NULL,
  `created_by_person_id` varchar(50) DEFAULT NULL,
  `updated` datetime NOT NULL,
  `updated_by_person_id` varchar(50) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_domain_setting_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `email` (
  `id` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `owner_id` varchar(50) DEFAULT NULL,
  `owner_type` enum('CMPNY','PRSN') DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_owner` (`owner_id`,`owner_type`),
  KEY `idx_email_brand` (`brand`),
  KEY `idx_email_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `email_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `owner_id` varchar(50) DEFAULT NULL,
  `owner_type` enum('CMPNY','PRSN') DEFAULT NULL,
  `created` datetime NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_email_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `end_user_account_setup` (
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `email` varchar(100) NOT NULL,
  `email_normalized` varchar(100) NOT NULL,
  `remote_ip` varchar(45) NOT NULL,
  `code_hash` varchar(255) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL DEFAULT ((now() + interval 30 minute)),
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `verified_at` datetime DEFAULT NULL,
  `person_id` varchar(50) DEFAULT NULL,
  `company_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_person_brand` (`person_id`,`brand`),
  KEY `ix_email_time` (`email_normalized`,`created`),
  KEY `ix_ip_time` (`remote_ip`,`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `end_user_account_setup_history` (
  `history_id` varchar(50) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `email` varchar(100) NOT NULL,
  `email_normalized` varchar(100) NOT NULL,
  `remote_ip` varchar(45) NOT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL DEFAULT ((now() + interval 30 minute)),
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `verified_at` datetime DEFAULT NULL,
  `person_id` varchar(50) DEFAULT NULL,
  `company_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`history_id`),
  KEY `ix_email_time` (`email_normalized`,`created`),
  KEY `ix_ip_time` (`remote_ip`,`created`),
  KEY `ix_person_brand` (`person_id`,`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `end_user_product_search` (
  `id` varchar(50) NOT NULL,
  `word_id` int NOT NULL,
  `record_id` varchar(50) NOT NULL,
  `record_type` enum('FILE','PRODUCT','REVIEW','NOTE') NOT NULL,
  `brand` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_end_user_product_search_hit` (`word_id`,`record_id`,`record_type`,`brand`),
  KEY `idx_end_user_product_search_word_id` (`word_id`),
  KEY `idx_end_user_product_search_record` (`record_type`,`record_id`),
  KEY `idx_end_user_product_search_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `end_user_product_search_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `word_id` int NOT NULL,
  `record_id` varchar(50) NOT NULL,
  `record_type` enum('FILE','PRODUCT','REVIEW','NOTE') NOT NULL,
  `brand` varchar(10) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_end_user_product_search_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `end_user_words` (
  `id` int NOT NULL AUTO_INCREMENT,
  `word` varchar(250) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `last_created_from_id` varchar(50) DEFAULT NULL,
  `last_created_from` enum('FILE','PRODUCT','REVIEW','NOTE') DEFAULT NULL,
  `updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_end_user_words_brand_word` (`brand`,`word`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `end_user_words_history` (
  `history_id` varchar(50) NOT NULL,
  `id` int NOT NULL,
  `word` varchar(250) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `last_created_from_id` varchar(50) DEFAULT NULL,
  `last_created_from` enum('FILE','PRODUCT','REVIEW','NOTE') DEFAULT NULL,
  `updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_end_user_words_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `file_draft` (
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `replaces_id` varchar(50) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `via` enum('session','oauth') NOT NULL,
  `grant_id` varchar(50) DEFAULT NULL,
  `domain` varchar(253) DEFAULT NULL,
  `status` enum('DRAFT','PUBLISHED','DISCARDED') NOT NULL DEFAULT 'DRAFT',
  `preview_hash` char(64) DEFAULT NULL,
  `preview_expires` datetime DEFAULT NULL,
  `created` datetime NOT NULL,
  `published_at` datetime DEFAULT NULL,
  `published_file_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `file_draft_brand` (`brand`,`status`),
  KEY `file_draft_preview` (`preview_hash`),
  KEY `file_draft_replaces` (`replaces_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `file_draft_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `replaces_id` varchar(50) DEFAULT NULL,
  `created_by` varchar(50) NOT NULL,
  `via` enum('session','oauth') NOT NULL,
  `grant_id` varchar(50) DEFAULT NULL,
  `domain` varchar(253) DEFAULT NULL,
  `status` enum('DRAFT','PUBLISHED','DISCARDED') NOT NULL DEFAULT 'DRAFT',
  `preview_hash` char(64) DEFAULT NULL,
  `preview_expires` datetime DEFAULT NULL,
  `created` datetime NOT NULL,
  `published_at` datetime DEFAULT NULL,
  `published_file_id` varchar(50) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `file_draft_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `files` (
  `id` varchar(50) NOT NULL,
  `file_data` longblob,
  `file_name` varchar(100) DEFAULT NULL,
  `file_path` varchar(200) DEFAULT NULL,
  `file_type` enum('JPG','PNG','GIF','WEBP','SVG','HTML','CSS','JS','JSON','PDF','TXT','ICO','WOFF','WOFF2','MP4') NOT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `brand` varchar(10) DEFAULT NULL,
  `owner_id` varchar(50) DEFAULT NULL,
  `owner_type` enum('CMPNY','PRSN') DEFAULT NULL,
  `public_private` enum('PUBLIC','PRIVATE') NOT NULL DEFAULT 'PRIVATE',
  `watermark_mode` enum('default','on','off') NOT NULL DEFAULT 'default' COMMENT 'owner mark only: on/off, or default = follow domain then app_setting.watermark_default',
  PRIMARY KEY (`id`),
  KEY `idx_files_owner_id` (`owner_id`),
  KEY `idx_files_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `files_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `file_data` longblob,
  `file_name` varchar(100) DEFAULT NULL,
  `file_path` varchar(200) DEFAULT NULL,
  `file_type` enum('JPG','PNG','GIF','WEBP','SVG','HTML','CSS','JS','JSON','PDF','TXT','ICO','WOFF','WOFF2','MP4') NOT NULL,
  `created` datetime NOT NULL,
  `brand` varchar(10) DEFAULT NULL,
  `owner_id` varchar(50) DEFAULT NULL,
  `owner_type` enum('CMPNY','PRSN') DEFAULT NULL,
  `public_private` enum('PUBLIC','PRIVATE') NOT NULL DEFAULT 'PRIVATE',
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `watermark_mode` enum('default','on','off') NOT NULL DEFAULT 'default',
  PRIMARY KEY (`history_id`),
  KEY `idx_file_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `html_cache_purge` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime NOT NULL,
  `scope` enum('global','brand','file') NOT NULL,
  `brand` varchar(10) DEFAULT NULL,
  `file_id` varchar(50) DEFAULT NULL,
  `file_rel` varchar(300) DEFAULT NULL,
  `reason` varchar(80) NOT NULL DEFAULT 'bump',
  `created_by_person_id` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_html_cache_purge_scope_brand` (`scope`,`brand`),
  CONSTRAINT `chk_html_cache_purge_brand` CHECK ((((`scope` = _utf8mb4'global') and (`brand` is null) and (`file_id` is null)) or ((`scope` = _utf8mb4'brand') and (`brand` is not null) and (`file_id` is null)) or ((`scope` = _utf8mb4'file') and (`brand` is not null) and (`file_id` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `inventory_balance` (
  `product_id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `location_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `quantity_on_hand` int NOT NULL DEFAULT '0',
  `quantity_reserved` int NOT NULL DEFAULT '0',
  `updated` datetime(3) NOT NULL,
  PRIMARY KEY (`product_id`,`variant_id`,`location_id`),
  KEY `idx_inventory_balance_variant` (`brand`,`variant_id`),
  KEY `idx_inventory_balance_location` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `inventory_location` (
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'store' COMMENT 'store, warehouse, supplier, virtual, other',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `last_updated` datetime DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_location_name` (`brand`,`company_id`,`name`),
  KEY `idx_inventory_location_company` (`brand`,`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `inventory_location_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` varchar(20) NOT NULL,
  `is_active` tinyint(1) NOT NULL,
  `is_default` tinyint(1) NOT NULL,
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `last_updated` datetime DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_inventory_location_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `inventory_movement` (
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `company_id` varchar(50) NOT NULL COMMENT 'store that owns the location',
  `product_id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `location_id` varchar(50) NOT NULL,
  `quantity` int NOT NULL COMMENT 'signed: + in, - out; never 0',
  `movement_type` varchar(20) NOT NULL COMMENT 'receipt, sale, adjustment, transfer, return',
  `reference_type` varchar(30) DEFAULT NULL COMMENT 'e.g. invoice, opening, transfer, purchase_order',
  `reference_id` varchar(50) DEFAULT NULL,
  `unit_cost` decimal(12,4) DEFAULT NULL COMMENT 'USD per unit, optional',
  `notes` varchar(500) DEFAULT NULL,
  `lot_id` varchar(50) DEFAULT NULL COMMENT 'reserved for lots/expiry',
  `serial` varchar(100) DEFAULT NULL COMMENT 'reserved for serial numbers',
  `bin` varchar(50) DEFAULT NULL COMMENT 'reserved for bin locations',
  `created` datetime(3) NOT NULL,
  `created_by` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inventory_movement_stock` (`brand`,`variant_id`,`location_id`),
  KEY `idx_inventory_movement_location` (`location_id`,`created`),
  KEY `idx_inventory_movement_ref` (`reference_type`,`reference_id`),
  KEY `idx_inventory_movement_company` (`brand`,`company_id`,`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `invoice` (
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) DEFAULT NULL,
  `cart_id` varchar(50) DEFAULT NULL,
  `company_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `status` enum('PENDING','PAID','VOIDED','REFUNDED','REFUND_FAILED') NOT NULL DEFAULT 'PENDING',
  `subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `shipping` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `created_by` varchar(50) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paypal_subscription_id` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_person_id` (`person_id`),
  KEY `idx_invoice_cart_id` (`cart_id`),
  KEY `idx_invoice_company_id` (`company_id`),
  KEY `idx_invoice_brand` (`brand`),
  KEY `idx_invoice_status` (`status`),
  KEY `idx_invoice_paypal_subscription` (`paypal_subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `invoice_address` (
  `id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `address_id` varchar(50) DEFAULT NULL,
  `type` enum('BILLING','SHIPPING') NOT NULL,
  `line_1` varchar(100) DEFAULT NULL,
  `line_2` varchar(100) DEFAULT NULL,
  `line_3` varchar(100) DEFAULT NULL,
  `city` varchar(30) DEFAULT NULL,
  `state` varchar(30) DEFAULT NULL,
  `zipcode` varchar(20) DEFAULT NULL,
  `country` varchar(20) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `brand` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_address_type` (`invoice_id`,`type`),
  KEY `idx_invoice_address_address_id` (`address_id`),
  KEY `idx_invoice_address_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `invoice_address_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `address_id` varchar(50) DEFAULT NULL,
  `type` enum('BILLING','SHIPPING') NOT NULL,
  `line_1` varchar(100) DEFAULT NULL,
  `line_2` varchar(100) DEFAULT NULL,
  `line_3` varchar(100) DEFAULT NULL,
  `city` varchar(30) DEFAULT NULL,
  `state` varchar(30) DEFAULT NULL,
  `zipcode` varchar(20) DEFAULT NULL,
  `country` varchar(20) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL,
  `brand` varchar(10) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_invoice_address_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `invoice_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) DEFAULT NULL,
  `cart_id` varchar(50) DEFAULT NULL,
  `company_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `status` enum('PENDING','PAID','VOIDED','REFUNDED','REFUND_FAILED') NOT NULL DEFAULT 'PENDING',
  `subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `shipping` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `created_by` varchar(50) DEFAULT NULL,
  `date_created` datetime NOT NULL,
  `last_updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paypal_subscription_id` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_invoice_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `invoice_product` (
  `id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `product_name` varchar(200) NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `target_domain_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_product_invoice_id` (`invoice_id`),
  KEY `idx_invoice_product_product_id` (`product_id`),
  KEY `idx_invoice_product_variant_id` (`variant_id`),
  KEY `idx_invoice_product_target` (`target_domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `invoice_product_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `product_name` varchar(200) NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `target_domain_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_invoice_product_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `link_tree` (
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `path` varchar(400) NOT NULL,
  `target` varchar(2000) DEFAULT NULL,
  `status` smallint NOT NULL DEFAULT '301',
  `source` enum('auto','manual') NOT NULL DEFAULT 'auto',
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `updated` datetime NOT NULL,
  `updated_by` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `link_tree_brand_path` (`brand`,`path`),
  KEY `link_tree_brand_target` (`brand`,`target`(191)),
  CONSTRAINT `chk_link_tree_status` CHECK ((`status` in (301,302)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `markup_request` (
  `id` varchar(50) NOT NULL,
  `company_id` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `note_id` varchar(50) NOT NULL,
  `file_id` varchar(50) NOT NULL,
  `note_file_id` varchar(50) NOT NULL,
  `page_url` varchar(2000) NOT NULL,
  `viewport` varchar(40) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `selectors` json DEFAULT NULL,
  `status` enum('NEW','DONE','REJECTED') NOT NULL DEFAULT 'NEW',
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_markup_brand_created` (`brand`,`created`),
  KEY `idx_markup_person_created` (`person_id`,`created`),
  KEY `idx_markup_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `markup_request_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `company_id` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `note_id` varchar(50) NOT NULL,
  `file_id` varchar(50) NOT NULL,
  `note_file_id` varchar(50) NOT NULL,
  `page_url` varchar(2000) NOT NULL,
  `viewport` varchar(40) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `selectors` json DEFAULT NULL,
  `status` enum('NEW','DONE','REJECTED') NOT NULL DEFAULT 'NEW',
  `created` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_markup_request_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `note_files` (
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `note_id` varchar(50) NOT NULL,
  `file_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_note_files_note_file` (`note_id`,`file_id`),
  KEY `idx_note_files_file` (`file_id`),
  KEY `idx_note_files_person_brand` (`person_id`,`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `note_files_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `note_id` varchar(50) NOT NULL,
  `file_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_note_files_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `notes` (
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `note` text NOT NULL,
  `gps` json DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notes_person_brand_created` (`person_id`,`brand`,`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `notes_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `note` text NOT NULL,
  `gps` json DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_notes_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `oauth_grant` (
  `id` varchar(50) NOT NULL,
  `client_id` varchar(64) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `domain` varchar(253) NOT NULL,
  `scope` varchar(200) NOT NULL,
  `created` datetime NOT NULL,
  `last_used` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_grant_person` (`person_id`,`brand`),
  KEY `oauth_grant_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `oauth_grant_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `client_id` varchar(64) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `domain` varchar(253) NOT NULL,
  `scope` varchar(200) NOT NULL,
  `created` datetime NOT NULL,
  `last_used` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `oauth_grant_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `oauth_token` (
  `token_hash` char(64) NOT NULL,
  `kind` enum('code','access','refresh') NOT NULL,
  `grant_id` varchar(50) NOT NULL,
  `redirect_uri` varchar(500) DEFAULT NULL,
  `code_challenge` varchar(128) DEFAULT NULL,
  `created` datetime NOT NULL,
  `expires` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `oauth_token_grant` (`grant_id`),
  KEY `oauth_token_expires` (`expires`),
  CONSTRAINT `fk_oauth_token_grant` FOREIGN KEY (`grant_id`) REFERENCES `oauth_grant` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `status` enum('PENDING','COMPLETED','FAILED','REFUNDED') NOT NULL DEFAULT 'PENDING',
  `method` enum('PAYPAL','CARD','CASH','OTHER') NOT NULL,
  `provider_ref` varchar(100) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paypal_subscription_id` varchar(64) DEFAULT NULL COMMENT 'PayPal Billing Subscription id (I-…) when method is PAYPAL and this is a subscription payment',
  `refund_requested` datetime DEFAULT NULL COMMENT 'When a guarantee refund was first requested (set before PayPal is called; double-refund guard)',
  `refund_ref` varchar(64) DEFAULT NULL COMMENT 'PayPal refund id once PayPal confirmed the refund',
  `refund_failed` datetime DEFAULT NULL COMMENT 'PayPal could not make the guarantee refund (after retries): final, no refund given',
  `refund_note` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_provider_ref` (`provider_ref`),
  KEY `idx_payments_invoice_id` (`invoice_id`),
  KEY `idx_payments_company_id` (`company_id`),
  KEY `idx_payments_brand` (`brand`),
  KEY `idx_payments_status` (`status`),
  KEY `idx_payments_paypal_subscription` (`paypal_subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `payments_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `status` enum('PENDING','COMPLETED','FAILED','REFUNDED') NOT NULL DEFAULT 'PENDING',
  `method` enum('PAYPAL','CARD','CASH','OTHER') NOT NULL,
  `provider_ref` varchar(100) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paypal_subscription_id` varchar(64) DEFAULT NULL,
  `refund_requested` datetime DEFAULT NULL,
  `refund_ref` varchar(64) DEFAULT NULL,
  `refund_failed` datetime DEFAULT NULL,
  `refund_note` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_payments_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `paypal_webhook_event` (
  `id` varchar(50) NOT NULL,
  `event_id` varchar(64) NOT NULL COMMENT 'PayPal event id; unique for idempotency',
  `event_type` varchar(100) NOT NULL,
  `subscription_id` varchar(64) DEFAULT NULL,
  `sale_id` varchar(64) DEFAULT NULL,
  `invoice_id` varchar(50) DEFAULT NULL,
  `processed` tinyint(1) NOT NULL DEFAULT '0',
  `result` varchar(200) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(50) NOT NULL DEFAULT 'paypal_webhook',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_paypal_webhook_event_id` (`event_id`),
  KEY `idx_pwe_subscription` (`subscription_id`),
  KEY `idx_pwe_sale` (`sale_id`),
  KEY `idx_pwe_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `paypal_webhook_event_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `event_id` varchar(64) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `subscription_id` varchar(64) DEFAULT NULL,
  `sale_id` varchar(64) DEFAULT NULL,
  `invoice_id` varchar(50) DEFAULT NULL,
  `processed` tinyint(1) NOT NULL DEFAULT '0',
  `result` varchar(200) DEFAULT NULL,
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_pweh_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `person` (
  `id` varchar(50) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `brand` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contact_brand` (`brand`),
  KEY `idx_contact_last_name` (`last_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `person_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `created` datetime NOT NULL,
  `brand` varchar(10) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_contact_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product` (
  `id` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `active` tinyint NOT NULL DEFAULT '1',
  `last_updated_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `last_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `entitlements` set('no_banner','affiliate','store','watermark') DEFAULT NULL COMMENT 'internal DW entitlements granted when an invoice line for this product is PAID',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_brand_slug` (`brand`,`slug`),
  KEY `idx_product_brand` (`brand`),
  KEY `idx_product_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_category` (
  `id` varchar(50) NOT NULL,
  `category_id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `position` int NOT NULL DEFAULT '0',
  `brand` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_category_variant` (`category_id`,`variant_id`),
  KEY `idx_product_category_product_id` (`product_id`),
  KEY `idx_product_category_variant_id` (`variant_id`),
  KEY `idx_product_category_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_category_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `category_id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `position` int NOT NULL DEFAULT '0',
  `brand` varchar(10) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_product_category_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_company` (
  `id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_company` (`product_id`,`company_id`),
  KEY `idx_product_company_company_id` (`company_id`),
  KEY `idx_product_company_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_company_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_product_company_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_description` (
  `id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `description` text,
  `used_location` varchar(100) DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `last_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_description_location` (`product_id`,`used_location`),
  KEY `idx_product_description_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_description_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `description` text,
  `used_location` varchar(100) DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `last_updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_product_description_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `active` tinyint NOT NULL DEFAULT '1',
  `last_updated_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `last_updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `entitlements` set('no_banner','affiliate','store','watermark') DEFAULT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_product_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_images` (
  `id` varchar(50) NOT NULL,
  `associated_id` varchar(50) NOT NULL,
  `id_type` enum('PRODUCT','PRODUCT_VARIANT','CATEGORY') NOT NULL,
  `file_id` varchar(50) NOT NULL,
  `description` json DEFAULT NULL,
  `position` int NOT NULL DEFAULT '0',
  `last_updated_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `last_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_images_associated` (`id_type`,`associated_id`),
  KEY `idx_product_images_file_id` (`file_id`),
  KEY `idx_product_images_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_images_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `associated_id` varchar(50) NOT NULL,
  `id_type` enum('PRODUCT','PRODUCT_VARIANT','CATEGORY') NOT NULL,
  `file_id` varchar(50) NOT NULL,
  `description` json DEFAULT NULL,
  `position` int NOT NULL DEFAULT '0',
  `last_updated_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `last_updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_product_images_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_variant` (
  `id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` json DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `stock` int DEFAULT NULL,
  `position` int NOT NULL DEFAULT '0',
  `last_updated_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `last_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `billing_interval` enum('NONE','MONTH','YEAR') NOT NULL DEFAULT 'NONE' COMMENT 'NONE = one-time',
  `billing_interval_count` tinyint unsigned NOT NULL DEFAULT '1' COMMENT 'periods per unit, e.g. 1 MONTH',
  `paypal_plan_id` varchar(64) DEFAULT NULL COMMENT 'PayPal Billing Plan id (P-…) for this MONTH/YEAR variant; NULL = not a subscription',
  `restock_subject` varchar(200) DEFAULT NULL COMMENT 'restock email subject; NULL/empty = brand default',
  `restock_from` varchar(200) DEFAULT NULL COMMENT 'visible From (Name <email> or email); NULL/empty = brand default',
  `restock_body` text COMMENT 'restock email body (text). Placeholders: {{product_name}} {{product_url}} {{variant_name}} {{store_name}} {{unsubscribe_url}}',
  PRIMARY KEY (`id`),
  KEY `idx_product_variant_product_id` (`product_id`),
  KEY `idx_product_variant_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_variant_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` json DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `stock` int DEFAULT NULL,
  `position` int NOT NULL DEFAULT '0',
  `last_updated_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) NOT NULL,
  `last_updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `billing_interval` enum('NONE','MONTH','YEAR') NOT NULL DEFAULT 'NONE',
  `billing_interval_count` tinyint unsigned NOT NULL DEFAULT '1',
  `paypal_plan_id` varchar(64) DEFAULT NULL,
  `restock_subject` varchar(200) DEFAULT NULL,
  `restock_from` varchar(200) DEFAULT NULL,
  `restock_body` text,
  PRIMARY KEY (`history_id`),
  KEY `idx_product_variant_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `relation` (
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `company` varchar(50) NOT NULL,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(50) NOT NULL,
  `brand` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_relation_person_id` (`person_id`),
  KEY `idx_relation_company` (`company`),
  KEY `idx_relation_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `relation_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `company` varchar(50) NOT NULL,
  `created_on` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `brand` varchar(10) DEFAULT NULL,
  `action` varchar(10) NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `restock_brand_setting` (
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `restock_subject` varchar(200) DEFAULT NULL,
  `restock_from` varchar(200) DEFAULT NULL,
  `restock_body` text,
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `last_updated` datetime DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_restock_brand_setting_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `restock_brand_setting_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `restock_subject` varchar(200) DEFAULT NULL,
  `restock_from` varchar(200) DEFAULT NULL,
  `restock_body` text,
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `last_updated` datetime DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_restock_brand_setting_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `restock_event` (
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `detected_at` datetime NOT NULL,
  `status` enum('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_restock_event_status` (`status`,`detected_at`),
  KEY `idx_restock_event_variant` (`variant_id`,`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `restock_notification_history` (
  `id` varchar(50) NOT NULL,
  `notification_id` varchar(50) NOT NULL,
  `event` enum('signup','validated','restock_email_sent','bounce','unsubscribe','purchase') NOT NULL,
  `detail` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_restock_hist_notif` (`notification_id`,`created_at`),
  KEY `idx_restock_hist_event` (`event`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `restock_notifications` (
  `id` varchar(50) NOT NULL,
  `variant_id` varchar(50) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `email` varchar(100) NOT NULL,
  `validated` enum('YES','NO') NOT NULL DEFAULT 'NO',
  `confirm_token` char(64) NOT NULL,
  `poll_token` char(64) NOT NULL,
  `unsubscribe_token` char(64) NOT NULL,
  `created_at` datetime NOT NULL,
  `notified_at` datetime DEFAULT NULL,
  `unsubscribed` enum('YES','NO') NOT NULL DEFAULT 'NO',
  `unsubscribe_reason` enum('purchased','clicked') DEFAULT NULL,
  `bounced` enum('YES','NO') NOT NULL DEFAULT 'NO',
  `signup_host` varchar(255) NOT NULL DEFAULT '',
  `signup_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_restock_variant_email` (`variant_id`,`email`),
  UNIQUE KEY `uq_restock_confirm_token` (`confirm_token`),
  UNIQUE KEY `uq_restock_poll_token` (`poll_token`),
  UNIQUE KEY `uq_restock_unsubscribe_token` (`unsubscribe_token`),
  KEY `idx_restock_brand_product` (`brand`,`product_id`),
  KEY `idx_restock_send` (`variant_id`,`validated`,`unsubscribed`,`bounced`),
  KEY `idx_restock_email` (`email`),
  KEY `idx_restock_created` (`created_at`),
  KEY `idx_restock_signup_ip` (`signup_ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `restock_stock_state` (
  `variant_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `product_id` varchar(50) NOT NULL,
  `in_stock` tinyint(1) NOT NULL,
  `stock` int DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`variant_id`),
  KEY `idx_restock_stock_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `session` (
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `rights` json DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `brand` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_session_person` (`person_id`),
  KEY `idx_session_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `session_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `person_id` varchar(50) NOT NULL,
  `rights` json DEFAULT NULL,
  `created` datetime NOT NULL,
  `brand` varchar(10) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_session_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `shipment` (
  `id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `carrier` varchar(50) DEFAULT NULL,
  `service` varchar(100) DEFAULT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `tracking_url` varchar(500) DEFAULT NULL,
  `status` enum('PENDING','LABEL_CREATED','SHIPPED','IN_TRANSIT','DELIVERED','RETURNED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `shipping_cost` decimal(10,2) DEFAULT NULL,
  `invoice_address_id` varchar(50) DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shipment_invoice_id` (`invoice_id`),
  KEY `idx_shipment_company_id` (`company_id`),
  KEY `idx_shipment_brand` (`brand`),
  KEY `idx_shipment_status` (`status`),
  KEY `idx_shipment_tracking_number` (`tracking_number`),
  KEY `idx_shipment_invoice_address_id` (`invoice_address_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `shipment_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `invoice_id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `carrier` varchar(50) DEFAULT NULL,
  `service` varchar(100) DEFAULT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `tracking_url` varchar(500) DEFAULT NULL,
  `status` enum('PENDING','LABEL_CREATED','SHIPPED','IN_TRANSIT','DELIVERED','RETURNED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `shipping_cost` decimal(10,2) DEFAULT NULL,
  `invoice_address_id` varchar(50) DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL,
  `last_updated` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_shipment_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `shipment_item` (
  `id` varchar(50) NOT NULL,
  `shipment_id` varchar(50) NOT NULL,
  `invoice_product_id` varchar(50) NOT NULL,
  `quantity` int NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shipment_item_line` (`shipment_id`,`invoice_product_id`),
  KEY `idx_shipment_item_invoice_product_id` (`invoice_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `shipment_item_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `shipment_id` varchar(50) NOT NULL,
  `invoice_product_id` varchar(50) NOT NULL,
  `quantity` int NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created` datetime NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_shipment_item_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `signup_failure` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `node` varchar(64) NOT NULL DEFAULT '',
  `client_ip` varchar(45) NOT NULL DEFAULT '',
  `ip_country` varchar(8) DEFAULT NULL,
  `ip_category` varchar(16) NOT NULL DEFAULT 'none',
  `email_normalized` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `step` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '1 request code, 2 confirm, 0 other',
  `reason` varchar(40) NOT NULL,
  `http_status` smallint unsigned NOT NULL DEFAULT '0',
  `user_agent` varchar(255) DEFAULT NULL,
  `account_setup_id` varchar(50) DEFAULT NULL,
  `detail` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_signup_failure_created` (`created`),
  KEY `idx_signup_failure_ip` (`client_ip`,`created`),
  KEY `idx_signup_failure_email` (`email_normalized`,`created`),
  KEY `idx_signup_failure_reason` (`reason`,`created`),
  KEY `idx_signup_failure_setup` (`account_setup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Failed signups from all nodes (lib/DwSignupLog.php)';

CREATE TABLE IF NOT EXISTS `signup_failure_history` (
  `history_id` varchar(50) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `id` bigint unsigned NOT NULL,
  `created` datetime(3) NOT NULL,
  `node` varchar(64) NOT NULL,
  `client_ip` varchar(45) NOT NULL,
  `ip_country` varchar(8) DEFAULT NULL,
  `ip_category` varchar(16) NOT NULL,
  `email_normalized` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `step` tinyint unsigned NOT NULL,
  `reason` varchar(40) NOT NULL,
  `http_status` smallint unsigned NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `account_setup_id` varchar(50) DEFAULT NULL,
  `detail` json DEFAULT NULL,
  PRIMARY KEY (`history_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `site_login_code` (
  `code_hash` char(64) NOT NULL,
  `session_id` varchar(50) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `state_hash` char(64) NOT NULL,
  `expires` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`code_hash`),
  KEY `idx_site_login_code_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `site_session` (
  `jti` char(32) NOT NULL,
  `session_id` varchar(50) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `created` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`jti`),
  KEY `idx_site_session_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `store_setting` (
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `shipping_flat` decimal(10,2) DEFAULT NULL COMMENT 'flat shipping per order, USD',
  `free_shipping_over` decimal(10,2) DEFAULT NULL COMMENT 'subtotal at or above this ships free; NULL = never',
  `hi_get_rate` decimal(6,4) DEFAULT NULL COMMENT 'Hawaii GET percent for HI shipping addresses, e.g. 4.7120',
  `tax_shipping` tinyint(1) DEFAULT NULL COMMENT '1 = GET also applies to the shipping charge',
  `store_email` varchar(100) DEFAULT NULL COMMENT 'store copy of order emails',
  `paypal_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `last_updated` datetime DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_setting_company` (`brand`,`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `store_setting_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `shipping_flat` decimal(10,2) DEFAULT NULL,
  `free_shipping_over` decimal(10,2) DEFAULT NULL,
  `hi_get_rate` decimal(6,4) DEFAULT NULL,
  `tax_shipping` tinyint(1) DEFAULT NULL,
  `store_email` varchar(100) DEFAULT NULL,
  `paypal_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `created` datetime NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `last_updated` datetime DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_store_setting_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `telephone` (
  `id` varchar(50) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `line_type` enum('VOICE_TEXT','VOICE','TEXT','FAX','OTHER') NOT NULL,
  `owner_id` varchar(50) DEFAULT NULL,
  `owner_type` enum('CMPNY','PRSN') DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_telephone_owner` (`owner_id`,`owner_type`),
  KEY `idx_telephone_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `telephone_history` (
  `history_id` varchar(50) NOT NULL,
  `id` varchar(50) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `line_type` enum('VOICE_TEXT','VOICE','TEXT','FAX','OTHER') NOT NULL,
  `owner_id` varchar(50) DEFAULT NULL,
  `owner_type` enum('CMPNY','PRSN') DEFAULT NULL,
  `created` datetime NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `brand` varchar(10) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_telephone_history_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Installer bookkeeping: which schema files have been applied.
CREATE TABLE IF NOT EXISTS `dw_schema_migrations` (
  `version` varchar(64) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT IGNORE INTO `dw_schema_migrations` (`version`) VALUES
('001'),
('002'),
('003'),
('004'),
('005'),
('006'),
('007'),
('008'),
('009'),
('010'),
('011'),
('012'),
('013'),
('014'),
('015'),
('016'),
('017'),
('018'),
('019'),
('020'),
('021'),
('022'),
('023'),
('024'),
('025');

SET FOREIGN_KEY_CHECKS = 1;
