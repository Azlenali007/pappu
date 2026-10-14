-- Migration 010: Real Payment Gateways Implementation (Razorpay, PhonePe, PayPal, Binance Pay, Cashfree)
-- SMM Panel Production Migration

ALTER TABLE `transactions` MODIFY COLUMN `gateway_txn_id` VARCHAR(191) NULL DEFAULT NULL;

-- Insert Cashfree if missing
INSERT INTO `payment_methods` (`name`, `code`, `type`, `icon`, `min_amount`, `max_amount`, `fee_percent`, `instructions`, `config_data`, `status`, `mode`, `sort_order`)
VALUES (
    'Cashfree Payments',
    'cashfree',
    'automatic',
    '💳',
    100.0000,
    100000.0000,
    0.00,
    'Fast and secure payments via UPI, Credit/Debit cards, Net Banking & Wallets powered by Cashfree Payments.',
    '{"app_id":"","secret_key":"","mode":"sandbox"}',
    'inactive',
    'test',
    7
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `type` = VALUES(`type`);
