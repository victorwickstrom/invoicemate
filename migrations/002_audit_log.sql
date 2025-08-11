-- Audit log table for tracking changes
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NOT NULL,
    user_id INTEGER,
    table_name TEXT NOT NULL,
    record_id INTEGER,
    action TEXT NOT NULL,
    changes_json TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_audit_log_org_created
    ON audit_log (organization_id, created_at);