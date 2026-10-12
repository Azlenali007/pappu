-- Migration 009: Payment Gateway Enable/Disable Synchronization Architecture
-- SMM Panel Production Migration
--
-- 1. Updates payment_methods.status to VARCHAR(20) to reliably support
--    all standard status representations ('active', 'inactive', 'enabled', 'disabled', '1', '0')
--    without MySQL strict-mode ENUM truncation errors.
-- 2. Normalizes existing records to canonical 'active' and 'inactive' values.

ALTER TABLE `payment_methods` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active';

-- Normalize any non-standard values in MySQL using strict string comparison
UPDATE `payment_methods`
SET `status` = 'active'
WHERE LOWER(`status`) IN ('active', 'enabled', '1', 'true', 'yes');

UPDATE `payment_methods`
SET `status` = 'inactive'
WHERE LOWER(`status`) IN ('inactive', 'disabled', '0', 'false', 'no', '') OR `status` IS NULL;
