-- Ledger VAT table to summarise VAT-related amounts per entry
CREATE TABLE IF NOT EXISTS ledger_vat (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NOT NULL,
    date TEXT NOT NULL,
    vat_code TEXT NOT NULL,
    direction TEXT NOT NULL, -- output|input|reverse_output|reverse_input
    movement TEXT NOT NULL,  -- domestic|eu|non_eu or goods|services categories
    net_amount REAL NOT NULL,
    vat_amount REAL NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_ledger_vat_org_date ON ledger_vat (organization_id, date);