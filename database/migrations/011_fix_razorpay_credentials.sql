-- Migration 011: Clean up placeholder credentials and ensure inactive status for unconfigured gateways
-- SMM Panel Production Migration

-- Reset placeholder credentials in payment_methods for Razorpay if matching default dummies
UPDATE `payment_methods`
SET `config_data` = JSON_SET(COALESCE(`config_data`, '{}'), '$.key_id', '', '$.key_secret', ''),
    `status` = 'inactive'
WHERE `code` = 'razorpay'
  AND (`config_data` LIKE '%rzp_test_YourKeyHere%' OR `config_data` LIKE '%YourSecretKeyHere%');

-- Reset placeholder credentials in settings table
UPDATE `settings`
SET `setting_value` = ''
WHERE `setting_key` IN ('razorpay_key_id', 'razorpay_key_secret')
  AND `setting_value` IN ('rzp_test_YourKeyHere', 'YourSecretKeyHere', 'YourKeyHere');
