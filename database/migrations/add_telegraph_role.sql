-- Migration: Add 'telegraph' role to users table
-- Date: 2026-04-13

ALTER TABLE users MODIFY COLUMN role
  ENUM('accountant','sales','production','manager','developer','driver','telegraph') NOT NULL;
