-- Add voucher_number column to purchase_voucher and index
ALTER TABLE purchase_voucher ADD COLUMN voucher_number INTEGER;

CREATE UNIQUE INDEX IF NOT EXISTS idx_purchase_voucher_number
    ON purchase_voucher (organization_id, voucher_number);