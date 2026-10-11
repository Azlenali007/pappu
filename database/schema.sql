-- =====================================================================
-- SMM PANEL DATABASE SCHEMA (MySQL 8.0+ / MariaDB 10.5+)
-- UTF8mb4 Full Unicode Support, Strict Foreign Keys, Performance Indexes
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `spin_wheel_audit_logs`;
DROP TABLE IF EXISTS `spin_spins`;
DROP TABLE IF EXISTS `spin_wheel_config_versions`;
DROP TABLE IF EXISTS `spin_wheel_outcomes`;
DROP TABLE IF EXISTS `spin_wheel_bets`;
DROP TABLE IF EXISTS `update_audit_logs`;
DROP TABLE IF EXISTS `migrations`;
DROP TABLE IF EXISTS `user_notification_reads`;
DROP TABLE IF EXISTS `user_announcement_dismissals`;
DROP TABLE IF EXISTS `announcements`;
DROP TABLE IF EXISTS `referral_commissions`;
DROP TABLE IF EXISTS `referrals`;
DROP TABLE IF EXISTS `payment_methods`;
DROP TABLE IF EXISTS `currencies`;
DROP TABLE IF EXISTS `ticket_messages`;
DROP TABLE IF EXISTS `tickets`;
DROP TABLE IF EXISTS `transactions`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `services`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `providers`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `settings`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. SETTINGS TABLE
CREATE TABLE `settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `value` TEXT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. USERS TABLE
CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(32) NULL,
  `balance` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `spent` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `role` ENUM('user', 'admin', 'support') NOT NULL DEFAULT 'user',
  `status` ENUM('active', 'suspended', 'banned') NOT NULL DEFAULT 'active',
  `api_key` VARCHAR(64) NOT NULL UNIQUE,
  `referral_code` VARCHAR(32) NULL UNIQUE,
  `referred_by` INT UNSIGNED NULL,
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_status` (`status`),
  INDEX `idx_users_referral_code` (`referral_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. PROVIDERS TABLE (External API providers)
CREATE TABLE `providers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `api_url` VARCHAR(255) NOT NULL,
  `api_key` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. CATEGORIES TABLE
CREATE TABLE `categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `platform` ENUM('instagram', 'youtube', 'telegram', 'facebook', 'tiktok', 'twitter', 'other') NOT NULL DEFAULT 'other',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_category_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. SERVICES TABLE
CREATE TABLE `services` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NULL,
  `provider_service_id` VARCHAR(64) NULL,
  `name` VARCHAR(255) NOT NULL,
  `rate_per_1000` DECIMAL(10, 4) NOT NULL,
  `original_rate` DECIMAL(10, 4) NULL,
  `min_quantity` INT UNSIGNED NOT NULL DEFAULT 10,
  `max_quantity` INT UNSIGNED NOT NULL DEFAULT 100000,
  `service_type` ENUM('default', 'custom_comments', 'package', 'poll') NOT NULL DEFAULT 'default',
  `speed` VARCHAR(100) DEFAULT 'Fast Delivery',
  `description` TEXT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE SET NULL,
  INDEX `idx_service_status` (`status`),
  INDEX `idx_service_provider_mapping` (`provider_id`, `provider_service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. ORDERS TABLE
CREATE TABLE `orders` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NULL,
  `provider_order_id` VARCHAR(64) NULL,
  `link` VARCHAR(512) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `charge` DECIMAL(10, 4) NOT NULL,
  `start_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `remains` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('pending', 'processing', 'in_progress', 'completed', 'partial', 'cancelled') NOT NULL DEFAULT 'pending',
  `mode` ENUM('manual', 'auto') NOT NULL DEFAULT 'auto',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE RESTRICT,
  INDEX `idx_orders_status` (`status`),
  INDEX `idx_orders_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. TRANSACTIONS TABLE
CREATE TABLE `transactions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED NULL,
  `type` ENUM('deposit', 'order', 'refund', 'bonus', 'admin_adjustment', 'spin_bet', 'spin_win') NOT NULL,
  `amount` DECIMAL(12, 4) NOT NULL,
  `gateway` VARCHAR(50) NOT NULL DEFAULT 'system',
  `gateway_txn_id` VARCHAR(120) NULL,
  `status` ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'completed',
  `note` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
  INDEX `idx_txn_type` (`type`),
  INDEX `idx_txn_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. TICKETS TABLE
CREATE TABLE `tickets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED NULL,
  `subject` VARCHAR(191) NOT NULL,
  `status` ENUM('open', 'in_progress', 'closed') NOT NULL DEFAULT 'open',
  `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_tickets_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. TICKET_MESSAGES TABLE
CREATE TABLE `ticket_messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. ANNOUNCEMENTS / POPUP NOTIFICATIONS TABLE
CREATE TABLE `announcements` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `type` ENUM('announcement', 'service', 'maintenance', 'offer', 'telegram', 'update', 'system') NOT NULL DEFAULT 'announcement',
  `badge_text` VARCHAR(64) DEFAULT 'Important Notice',
  `icon` VARCHAR(50) NULL,
  `message` TEXT NOT NULL,
  `btn_text` VARCHAR(100) NULL,
  `btn_link` VARCHAR(255) NULL,
  `target_audience` ENUM('all', 'active_users') NOT NULL DEFAULT 'all',
  `target_user_id` INT UNSIGNED NULL,
  `show_once` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
  `starts_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_announcements_status` (`status`),
  INDEX `idx_announcements_target_user` (`target_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. USER ANNOUNCEMENT DISMISSALS TABLE
CREATE TABLE `user_announcement_dismissals` (
  `user_id` INT UNSIGNED NOT NULL,
  `announcement_id` INT UNSIGNED NOT NULL,
  `dismissed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `announcement_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11b. USER NOTIFICATION READS TABLE (Notification Center)
CREATE TABLE `user_notification_reads` (
  `user_id` INT UNSIGNED NOT NULL,
  `announcement_id` INT UNSIGNED NOT NULL,
  `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `announcement_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11c. DATABASE MIGRATIONS TRACKING TABLE
CREATE TABLE `migrations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `migration` VARCHAR(255) NOT NULL UNIQUE,
  `batch` INT UNSIGNED NOT NULL DEFAULT 1,
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11d. APPLICATION UPDATE AUDIT LOGS TABLE
CREATE TABLE `update_audit_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `version_from` VARCHAR(50) NOT NULL,
  `version_to` VARCHAR(50) NOT NULL,
  `started_at` DATETIME NOT NULL,
  `completed_at` DATETIME NULL,
  `backup_status` ENUM('pending', 'success', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
  `backup_file` VARCHAR(255) NULL,
  `validation_status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `migration_status` ENUM('pending', 'success', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
  `install_status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `health_check_status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `rollback_status` ENUM('none', 'pending', 'success', 'failed') NOT NULL DEFAULT 'none',
  `status` ENUM('in_progress', 'success', 'failed', 'rolled_back') NOT NULL DEFAULT 'in_progress',
  `details` TEXT NULL,
  `error_message` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. REFERRALS TABLE
CREATE TABLE `referrals` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `referrer_id` INT UNSIGNED NOT NULL,
  `referred_id` INT UNSIGNED NOT NULL UNIQUE,
  `referral_code` VARCHAR(32) NOT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `total_commission` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referred_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_referrals_referrer` (`referrer_id`),
  INDEX `idx_referrals_code` (`referral_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. REFERRAL COMMISSIONS TABLE
CREATE TABLE `referral_commissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `referrer_id` INT UNSIGNED NOT NULL,
  `referred_id` INT UNSIGNED NOT NULL,
  `transaction_id` INT UNSIGNED NULL,
  `order_id` INT UNSIGNED NULL,
  `event_type` ENUM('first_deposit', 'deposit', 'first_order', 'order') NOT NULL DEFAULT 'deposit',
  `source_amount` DECIMAL(12, 4) NOT NULL,
  `commission_rate` DECIMAL(8, 2) NOT NULL,
  `commission_amount` DECIMAL(12, 4) NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
  `note` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referred_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_ref_comm_status` (`status`),
  INDEX `idx_ref_comm_referrer` (`referrer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. CURRENCIES TABLE (Dynamic Multi-Currency System)
CREATE TABLE `currencies` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(10) NOT NULL UNIQUE,
  `symbol` VARCHAR(10) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `exchange_rate` DECIMAL(14, 6) NOT NULL DEFAULT 1.000000,
  `is_base` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_currency_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. PAYMENT METHODS TABLE (Production Multi-Gateway Architecture)
CREATE TABLE `payment_methods` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `type` ENUM('automatic', 'manual') NOT NULL DEFAULT 'automatic',
  `icon` VARCHAR(10) NOT NULL DEFAULT '💳',
  `min_amount` DECIMAL(12, 4) NOT NULL DEFAULT 100.0000,
  `max_amount` DECIMAL(12, 4) NOT NULL DEFAULT 50000.0000,
  `fee_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
  `instructions` TEXT NULL,
  `config_data` TEXT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `mode` ENUM('test', 'live') NOT NULL DEFAULT 'test',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. PAYMENT VERIFICATION LOGS TABLE (Automated Reconciliation Audit Trail)
CREATE TABLE `payment_verification_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `transaction_id` INT UNSIGNED NULL,
  `gateway` VARCHAR(50) NOT NULL DEFAULT 'phonepe_business_qr',
  `utr_searched` VARCHAR(100) NULL,
  `amount_expected` DECIMAL(12, 4) NULL,
  `amount_found` DECIMAL(12, 4) NULL,
  `status` ENUM('matched_success', 'pending_no_match', 'amount_mismatch', 'duplicate_rejected', 'connection_error') NOT NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pvl_txn` (`transaction_id`),
  INDEX `idx_pvl_utr` (`utr_searched`),
  INDEX `idx_pvl_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SEED INITIAL CONFIGURATION & DEFAULT DATA
-- =====================================================================
INSERT INTO `settings` (`key`, `value`) VALUES
('site_name', 'SMM Panel'),
('site_tagline', 'Grow Your Social Media'),
('currency', '₹'),
('currency_code', 'INR'),
('app_version', '1.0.0'),
('mass_order_enabled', '1'),
('dripfeed_enabled', '1'),
('razorpay_key_id', 'rzp_test_YourKeyHere'),
('razorpay_key_secret', 'YourSecretKeyHere'),
('min_deposit', '100'),
('max_deposit', '50000'),
('maintenance_mode', '0'),
('referral_enabled', '1'),
('referral_commission_type', 'percentage'),
('referral_commission_rate', '5.00'),
('referral_trigger_event', 'every_deposit'),
('referral_min_deposit', '100'),
('referral_max_commission', '1000'),
('referral_default_status', 'approved');

-- Default Seed Currencies (INR Base, USD, EUR, GBP, BRL)
INSERT INTO `currencies` (`code`, `symbol`, `name`, `exchange_rate`, `is_base`, `status`) VALUES
('INR', '₹', 'Indian Rupee', 1.000000, 1, 'active'),
('USD', '$', 'US Dollar', 0.011600, 0, 'active'),
('EUR', '€', 'Euro', 0.010800, 0, 'active'),
('GBP', '£', 'British Pound', 0.009200, 0, 'active'),
('BRL', 'R$', 'Brazilian Real', 0.065000, 0, 'active');

-- Default Seed Payment Gateways (Razorpay, PayPal, PhonePe, Paytm, Binance Pay, PhonePe Business QR)
INSERT INTO `payment_methods` (`name`, `code`, `type`, `icon`, `min_amount`, `max_amount`, `fee_percent`, `instructions`, `config_data`, `status`, `mode`, `sort_order`) VALUES
('Razorpay', 'razorpay', 'automatic', '⚡', 100.0000, 50000.0000, 0.00, 'Automated instant checkout supporting UPI, Credit/Debit cards, Net Banking & Wallets.', '{"key_id":"rzp_test_YourKeyHere","key_secret":"","webhook_secret":"","mode":"test"}', 'active', 'test', 1),
('PayPal', 'paypal', 'automatic', '🅿️', 500.0000, 500000.0000, 3.50, 'Global payment checkout via PayPal account, Visa, MasterCard, and Amex.', '{"client_id":"","client_secret":"","webhook_id":"","mode":"sandbox"}', 'inactive', 'test', 2),
('PhonePe', 'phonepe', 'automatic', '🟣', 100.0000, 100000.0000, 0.00, 'Direct PhonePe UPI & QR automated payment with instant server callback.', '{"merchant_id":"","salt_key":"","salt_index":"1","mode":"sandbox"}', 'inactive', 'test', 3),
('Paytm', 'paytm', 'automatic', '📲', 50.0000, 100000.0000, 0.00, 'Paytm Gateway / UPI QR & Net Banking with automated verification.', '{"merchant_id":"","merchant_key":"","channel_id":"WEB","industry_type":"Retail","upi_id":"smmpanel@upi","mode":"staging"}', 'inactive', 'test', 4),
('Binance Pay', 'binance', 'automatic', '🟡', 500.0000, 1000000.0000, 1.00, 'Cryptocurrency checkout powered by Binance Pay (USDT, BTC, ETH, BUSD).', '{"api_key":"","secret_key":"","merchant_id":"","mode":"test"}', 'inactive', 'test', 5),
('PhonePe Business QR', 'phonepe_business_qr', 'manual', '🟣', 10.0000, 100000.0000, 0.00, 'Scan the PhonePe Business QR code with any UPI app (PhonePe, Google Pay, Paytm, etc.). After successful payment, enter the 12-digit UTR/Reference ID from your payment receipt.', '{"merchant_id":"","upi_id":"","qr_image_url":"","gmail_method":"imap","gmail_email":"","gmail_app_password":"","gmail_client_id":"","gmail_client_secret":"","gmail_refresh_token":"","sender_whitelist":"noreply@phonepe.com,merchant-care@phonepe.com","auto_verify_on_submit":1,"mode":"test"}', 'inactive', 'test', 6);

-- Initial Admin Account: username: admin / password: password123
-- Initial User Account: username: aaris / password: password123
INSERT INTO `users` (`id`, `username`, `email`, `password`, `phone`, `balance`, `spent`, `role`, `status`, `api_key`, `referral_code`, `referred_by`, `email_verified`) VALUES
(1001, 'admin', 'admin@smmpanel.local', '$2y$12$R.O44hC46gE8i1a0MvV9EeeYvA5f9V7Z3d.gO8Z3D/a6F5e9qLhQW', '+91 98765 00000', 50000.0000, 0.0000, 'admin', 'active', 'adm_839f284c17b44d28e71c9902', 'REFADMIN1001', NULL, 1),
(1024, 'Aaris Ali', 'aarisali@gmail.com', '$2y$12$R.O44hC46gE8i1a0MvV9EeeYvA5f9V7Z3d.gO8Z3D/a6F5e9qLhQW', '+91 98765 43210', 850.5000, 155.0000, 'user', 'active', 'usr_749e192a63d91b87d21c4301', 'REFAARIS1024', NULL, 1);

-- Default Categories
INSERT INTO `categories` (`id`, `name`, `platform`, `sort_order`, `status`) VALUES
(1, 'Instagram Followers [Real & Active]', 'instagram', 1, 'active'),
(2, 'Instagram Likes & Engagements', 'instagram', 2, 'active'),
(3, 'Instagram Views & Reels', 'instagram', 3, 'active'),
(4, 'YouTube Views [High Retention]', 'youtube', 4, 'active'),
(5, 'Telegram Members [Real & Instant]', 'telegram', 5, 'active'),
(6, 'TikTok Followers & Views', 'tiktok', 6, 'active'),
(7, 'Facebook Page Likes & Followers', 'facebook', 7, 'active'),
(8, 'Twitter (X) Followers & Retweets', 'twitter', 8, 'active');

-- Default Services
INSERT INTO `services` (`id`, `category_id`, `name`, `rate_per_1000`, `min_quantity`, `max_quantity`, `speed`, `description`, `status`) VALUES
(101, 1, 'Instagram Followers [Real & Active Followers - High Quality]', 35.0000, 1000, 1000000, 'Starts in 1-2 Hours', 'High quality, non-drop real active followers. Instant start with 30-day auto-refill.', 'active'),
(102, 2, 'Instagram Likes [High Quality - Instant Start]', 20.0000, 100, 500000, 'Instant Start', 'Ultra-fast delivery of premium likes from active accounts worldwide.', 'active'),
(103, 3, 'Instagram Views & Reels [High Retention]', 15.0000, 1000, 10000000, 'Instant Start', 'Viral video engagement booster for Instagram Reels and videos.', 'active'),
(104, 2, 'Instagram Custom Comments [Positive Real Text]', 50.0000, 10, 10000, 'Fast Delivery', 'Custom written comments in English or your language.', 'active'),
(201, 4, 'YouTube Views [Real Views - High Retention]', 12.0000, 1000, 5000000, 'Fast Delivery', 'Monetization-safe high watch time views from search and suggested videos.', 'active'),
(202, 4, 'YouTube Subscribers [Non-Drop & Real Channels]', 180.0000, 50, 50000, 'Starts in 2-4 Hours', 'Permanent non-drop YouTube channel subscribers with lifetime warranty.', 'active'),
(301, 5, 'Telegram Members [Real & Active Members - Instant Start]', 45.0000, 500, 200000, 'Instant Start', 'Telegram channel and group members. Real accounts with 0% drop guarantee.', 'active'),
(401, 6, 'TikTok Followers [Real & Active Accounts]', 65.0000, 100, 200000, 'Instant Start', 'High quality TikTok followers worldwide. Account profile must be set to public.', 'active'),
(501, 7, 'Facebook Page Likes & Followers [High Quality - Non Drop]', 80.0000, 100, 500000, 'Instant Start', 'Worldwide page likes and followers with 30 days refill guarantee.', 'active'),
(601, 8, 'Twitter (X) Followers [Real Looking Accounts]', 95.0000, 100, 100000, 'Starts in 1 Hour', 'Active Twitter profiles with bio & avatars. Safe, stable and high quality.', 'active');

-- Default Initial Orders (#1024)
INSERT INTO `orders` (`id`, `user_id`, `service_id`, `link`, `quantity`, `charge`, `start_count`, `remains`, `status`, `created_at`) VALUES
(10254, 1024, 101, 'https://instagram.com/aarisali', 1000, 35.0000, 4200, 200, 'processing', '2025-05-12 16:32:00'),
(10253, 1024, 201, 'https://youtube.com/watch?v=smmGrowth123', 5000, 120.0000, 1240, 0, 'completed', '2025-05-11 18:10:00'),
(10252, 1024, 301, 'https://t.me/techgrowthindia', 2000, 90.0000, 1500, 120, 'processing', '2025-05-10 13:45:00'),
(10251, 1024, 102, 'https://instagram.com/p/C67890123', 1000, 20.0000, 850, 0, 'completed', '2025-05-09 19:20:00');

-- Default Transactions (#1024)
INSERT INTO `transactions` (`user_id`, `type`, `amount`, `gateway`, `gateway_txn_id`, `status`, `note`, `created_at`) VALUES
(1024, 'deposit', 500.0000, 'Razorpay', 'pay_rzp_8941720', 'completed', 'Added funds via Razorpay UPI', '2025-05-12 16:12:00'),
(1024, 'order', -35.0000, 'system', 'ord_10254', 'completed', 'Order #10254: Instagram Followers', '2025-05-12 16:32:00'),
(1024, 'deposit', 200.0000, 'Razorpay', 'pay_rzp_6291054', 'completed', 'Added funds via Razorpay Cards', '2025-05-10 11:20:00'),
(1024, 'order', -120.0000, 'system', 'ord_10253', 'completed', 'Order #10253: YouTube Views', '2025-05-10 18:15:00');

-- Default Support Tickets
INSERT INTO `tickets` (`id`, `user_id`, `order_id`, `subject`, `status`, `priority`, `created_at`) VALUES
(1024, 1024, 10254, 'Order not started yet', 'open', 'high', '2025-05-12 11:20:00'),
(1023, 1024, NULL, 'Payment issue with QR code', 'in_progress', 'medium', '2025-05-10 18:15:00'),
(1022, 1024, 10253, 'Service delivery speed query', 'closed', 'low', '2025-05-08 15:40:00'),
(1021, 1024, 10251, 'Wrong quantity query', 'closed', 'low', '2025-05-06 13:10:00');

-- Default Announcements (Shows modern popup on User Dashboard)
INSERT INTO `announcements` (`id`, `title`, `type`, `badge_text`, `message`, `btn_text`, `btn_link`, `target_audience`, `show_once`, `status`) VALUES
(1, 'Welcome to SMM Panel! Join Our Official Telegram Channel', 'telegram', 'Official Telegram', 'Get instant order updates, exclusive flash discount codes, and priority support in our official Telegram community. Join thousands of active creators today!', 'Join Telegram Channel', 'https://t.me/smmpanelofficial', 'all', 1, 'active'),
(2, '⚡ Special Offer: 10% Extra Balance on All UPI & Crypto Deposits', 'offer', 'Special Offer', 'Top up your account balance today and automatically receive an extra 10% bonus credit on all instant UPI and USDT deposits over ₹500.', 'Add Funds Now', '/user/add-funds.php', 'all', 1, 'inactive');

-- 14. SPIN WHEEL TABLES
CREATE TABLE IF NOT EXISTS `spin_wheel_bets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bet_amount` DECIMAL(12, 4) NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_spin_bet_amount` (`bet_amount`),
  INDEX `idx_spin_bet_status` (`is_enabled`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `spin_wheel_outcomes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bet_id` INT UNSIGNED NOT NULL,
  `reward_type` ENUM('jackpot', 'reward', 'money_back', 'no_reward') NOT NULL DEFAULT 'reward',
  `label` VARCHAR(80) NOT NULL,
  `reward_amount` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `probability` DECIMAL(6, 3) NOT NULL DEFAULT 0.000,
  `icon_key` VARCHAR(40) NOT NULL DEFAULT 'trophy',
  `color_bg` VARCHAR(30) NOT NULL DEFAULT '#3b82f6',
  `color_text` VARCHAR(30) NOT NULL DEFAULT '#ffffff',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bet_id`) REFERENCES `spin_wheel_bets`(`id`) ON DELETE CASCADE,
  INDEX `idx_outcome_bet_order` (`bet_id`, `display_order`),
  INDEX `idx_outcome_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `spin_wheel_config_versions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bet_id` INT UNSIGNED NOT NULL,
  `version_hash` VARCHAR(64) NOT NULL,
  `outcomes_snapshot` JSON NOT NULL,
  `total_probability` DECIMAL(6, 3) NOT NULL DEFAULT 100.000,
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_spin_cfg_bet` (`bet_id`),
  INDEX `idx_spin_cfg_hash` (`version_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `spin_spins` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid` VARCHAR(64) NOT NULL UNIQUE,
  `user_id` INT UNSIGNED NOT NULL,
  `bet_id` INT UNSIGNED NOT NULL,
  `bet_amount` DECIMAL(12, 4) NOT NULL,
  `outcome_id` INT UNSIGNED NULL,
  `reward_type` ENUM('jackpot', 'reward', 'money_back', 'no_reward') NOT NULL,
  `outcome_label` VARCHAR(80) NOT NULL,
  `reward_amount` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `segment_index` INT UNSIGNED NOT NULL DEFAULT 0,
  `final_angle` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
  `config_version_id` INT UNSIGNED NULL,
  `bet_transaction_id` INT UNSIGNED NULL,
  `reward_transaction_id` INT UNSIGNED NULL,
  `status` ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'completed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`bet_id`) REFERENCES `spin_wheel_bets`(`id`) ON DELETE RESTRICT,
  INDEX `idx_spins_user_created` (`user_id`, `created_at`),
  INDEX `idx_spins_reward_status` (`reward_type`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `spin_wheel_audit_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT UNSIGNED NULL,
  `action` VARCHAR(60) NOT NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_spin_audit_admin` (`admin_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Spin Wheel Seed Settings
INSERT INTO `settings` (`key`, `value`) VALUES
('spin_wheel_enabled', '1'),
('spin_wheel_max_reward_limit', '1000.0000'),
('spin_wheel_cooldown_seconds', '3')
ON DUPLICATE KEY UPDATE `key` = `key`;

INSERT INTO `spin_wheel_bets` (`id`, `bet_amount`, `is_enabled`, `display_order`) VALUES
(1, 1.0000, 1, 1),
(2, 5.0000, 1, 2)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

INSERT INTO `spin_wheel_outcomes` (`id`, `bet_id`, `reward_type`, `label`, `reward_amount`, `probability`, `icon_key`, `color_bg`, `color_text`, `is_enabled`, `display_order`) VALUES
(1, 1, 'jackpot', '₹30 JACKPOT', 30.0000, 0.500, 'trophy', '#7c3aed', '#ffffff', 1, 0),
(2, 1, 'no_reward', 'TRY AGAIN', 0.0000, 35.000, 'cross', '#1e293b', '#94a3b8', 1, 1),
(3, 1, 'reward', '₹2 Reward', 2.0000, 15.000, 'coin', '#2563eb', '#ffffff', 1, 2),
(4, 1, 'money_back', 'MONEY BACK', 1.0000, 25.000, 'refresh', '#059669', '#ffffff', 1, 3),
(5, 1, 'reward', '₹5 Reward', 5.0000, 4.500, 'gift', '#d97706', '#ffffff', 1, 4),
(6, 1, 'no_reward', 'BETTER LUCK', 0.0000, 15.000, 'cross', '#334155', '#94a3b8', 1, 5),
(7, 1, 'reward', '₹10 Reward', 10.0000, 2.000, 'sparkles', '#4f46e5', '#ffffff', 1, 6),
(8, 1, 'money_back', '₹1 Return', 1.0000, 3.000, 'refresh', '#0d9488', '#ffffff', 1, 7),
(9, 2, 'jackpot', '₹80 JACKPOT', 80.0000, 0.500, 'trophy', '#7c3aed', '#ffffff', 1, 0),
(10, 2, 'no_reward', 'TRY AGAIN', 0.0000, 30.000, 'cross', '#1e293b', '#94a3b8', 1, 1),
(11, 2, 'reward', '₹10 Reward', 10.0000, 15.000, 'coin', '#2563eb', '#ffffff', 1, 2),
(12, 2, 'money_back', 'MONEY BACK', 5.0000, 25.000, 'refresh', '#059669', '#ffffff', 1, 3),
(13, 2, 'reward', '₹20 Reward', 20.0000, 5.000, 'gift', '#d97706', '#ffffff', 1, 4),
(14, 2, 'no_reward', 'BETTER LUCK', 0.0000, 15.000, 'cross', '#334155', '#94a3b8', 1, 5),
(15, 2, 'reward', '₹50 Reward', 50.0000, 1.500, 'sparkles', '#4f46e5', '#ffffff', 1, 6),
(16, 2, 'money_back', '₹5 Return', 5.0000, 8.000, 'refresh', '#0d9488', '#ffffff', 1, 7)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

