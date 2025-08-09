-- Migration file generated on 2025‑08‑09 to introduce improvements
-- including voucher number support on purchase vouchers, period locking,
-- user and role management, audit logging and associated triggers.

-- 1) Ensure purchase vouchers have a sequential voucher_number column.
ALTER TABLE purchase_voucher
    ADD COLUMN IF NOT EXISTS voucher_number INTEGER;

-- 2) Introduce locking flag on accounting years so that periods can be closed.
ALTER TABLE accounting_year
    ADD COLUMN IF NOT EXISTS is_locked INTEGER DEFAULT 0;

-- 3) Create users table for authentication and role management.
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NOT NULL,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'user')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

-- 4) Create audit_log table to persist a complete audit trail of all data
-- modifications in core tables.  Organization_id and user_id columns allow
-- attribution of changes, table_name records the affected table, record_id
-- is the primary key value of the changed row and operation indicates
-- whether the change was an INSERT, UPDATE or DELETE.  The changed_data
-- column stores a JSON document containing either the new values (on
-- insert), old values (on delete) or both (on update).
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id TEXT,
    user_id INTEGER,
    table_name TEXT NOT NULL,
    record_id TEXT NOT NULL,
    operation TEXT NOT NULL CHECK (operation IN ('INSERT','UPDATE','DELETE','BOOK')),
    changed_data TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5) Triggers to automatically write audit log entries on INSERT, UPDATE
-- and DELETE operations against central tables.  These triggers capture
-- the organization_id directly from the row being modified.  User_id is
-- not available within SQLite triggers and is therefore logged as NULL;
-- application‑level routes should override this when performing
-- higher‑level actions such as booking.  The JSON functions are used to
-- serialise entire rows into a JSON document for later inspection.

-- Invoice audit triggers
CREATE TRIGGER IF NOT EXISTS audit_invoice_insert
AFTER INSERT ON invoice
BEGIN
  -- Use COALESCE for organization_id since the invoice table may not contain this column
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (COALESCE(NEW.organization_id, NULL), NULL, 'invoice', NEW.guid, 'INSERT', json_object(
    'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_invoice_update
AFTER UPDATE ON invoice
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (COALESCE(NEW.organization_id, NULL), NULL, 'invoice', NEW.guid, 'UPDATE', json_object(
    'old', json(OLD), 'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_invoice_delete
AFTER DELETE ON invoice
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (COALESCE(OLD.organization_id, NULL), NULL, 'invoice', OLD.guid, 'DELETE', json_object(
    'old', json(OLD)
  ));
END;

-- Manual voucher audit triggers
CREATE TRIGGER IF NOT EXISTS audit_manual_voucher_insert
AFTER INSERT ON manual_voucher
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (NEW.organization_id, NULL, 'manual_voucher', NEW.guid, 'INSERT', json_object(
    'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_manual_voucher_update
AFTER UPDATE ON manual_voucher
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (NEW.organization_id, NULL, 'manual_voucher', NEW.guid, 'UPDATE', json_object(
    'old', json(OLD), 'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_manual_voucher_delete
AFTER DELETE ON manual_voucher
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (OLD.organization_id, NULL, 'manual_voucher', OLD.guid, 'DELETE', json_object(
    'old', json(OLD)
  ));
END;

-- Purchase voucher audit triggers
CREATE TRIGGER IF NOT EXISTS audit_purchase_voucher_insert
AFTER INSERT ON purchase_voucher
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (NEW.organization_id, NULL, 'purchase_voucher', NEW.guid, 'INSERT', json_object(
    'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_purchase_voucher_update
AFTER UPDATE ON purchase_voucher
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (NEW.organization_id, NULL, 'purchase_voucher', NEW.guid, 'UPDATE', json_object(
    'old', json(OLD), 'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_purchase_voucher_delete
AFTER DELETE ON purchase_voucher
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (OLD.organization_id, NULL, 'purchase_voucher', OLD.guid, 'DELETE', json_object(
    'old', json(OLD)
  ));
END;

-- Entries audit triggers
CREATE TRIGGER IF NOT EXISTS audit_entries_insert
AFTER INSERT ON entries
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (NEW.organization_id, NULL, 'entries', NEW.entry_guid, 'INSERT', json_object(
    'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_entries_update
AFTER UPDATE ON entries
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (NEW.organization_id, NULL, 'entries', NEW.entry_guid, 'UPDATE', json_object(
    'old', json(OLD), 'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_entries_delete
AFTER DELETE ON entries
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (OLD.organization_id, NULL, 'entries', OLD.entry_guid, 'DELETE', json_object(
    'old', json(OLD)
  ));
END;

-- Contacts audit triggers
CREATE TRIGGER IF NOT EXISTS audit_contacts_insert
AFTER INSERT ON contacts
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (NEW.organization_id, NULL, 'contacts', NEW.contact_guid, 'INSERT', json_object(
    'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_contacts_update
AFTER UPDATE ON contacts
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (NEW.organization_id, NULL, 'contacts', NEW.contact_guid, 'UPDATE', json_object(
    'old', json(OLD), 'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_contacts_delete
AFTER DELETE ON contacts
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (OLD.organization_id, NULL, 'contacts', OLD.contact_guid, 'DELETE', json_object(
    'old', json(OLD)
  ));
END;

-- Users audit triggers
CREATE TRIGGER IF NOT EXISTS audit_users_insert
AFTER INSERT ON users
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (NEW.organization_id, NULL, 'users', NEW.id, 'INSERT', json_object(
    'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_users_update
AFTER UPDATE ON users
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (NEW.organization_id, NULL, 'users', NEW.id, 'UPDATE', json_object(
    'old', json(OLD), 'new', json(NEW)
  ));
END;

CREATE TRIGGER IF NOT EXISTS audit_users_delete
AFTER DELETE ON users
BEGIN
  INSERT INTO audit_log (organization_id, user_id, table_name, record_id, operation, changed_data)
  VALUES (OLD.organization_id, NULL, 'users', OLD.id, 'DELETE', json_object(
    'old', json(OLD)
  ));
END;