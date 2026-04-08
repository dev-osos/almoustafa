-- Migration: Add 'with_shipping_company' status to tasks table
-- Date: 2026-04-03
-- Description: Adds 'مع شركة الشحن' status for telegraph orders

-- If the status column is ENUM, add the new value.
-- If it is VARCHAR, this statement is safe to skip (no-op on VARCHAR columns).
-- Run this only if ALTER fails with "invalid enum value".

-- For VARCHAR columns (no change needed):
-- The column already accepts any string value.

-- For ENUM columns (if applicable):
-- ALTER TABLE `tasks`
-- MODIFY COLUMN `status` ENUM(
--     'pending','received','in_progress','completed',
--     'with_delegate','with_driver','with_shipping_company',
--     'delivered','returned','cancelled'
-- ) DEFAULT 'pending';
