-- Se till att aktivera foreign keys i SQLite
PRAGMA foreign_keys = ON;


-- ===========================================
-- 1) TABELL: invoice (faktura-huvud)
-- ===========================================
CREATE TABLE invoice (
    guid TEXT PRIMARY KEY,
    currency TEXT,
    language TEXT,
    external_reference TEXT,
    description TEXT,
    comment TEXT,
    invoice_date TEXT,             -- Format "YYYY-MM-DD"
    address TEXT,
    "number" INTEGER NOT NULL,
    contact_name TEXT,
    show_lines_incl_vat INTEGER NOT NULL CHECK (show_lines_incl_vat IN (0,1)),  
    total_excl_vat REAL NOT NULL,
    total_vatable_amount REAL NOT NULL,
    total_incl_vat REAL NOT NULL,
    total_non_vatable_amount REAL NOT NULL,
    total_vat REAL NOT NULL,
    invoice_template_id TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TEXT NULL,  -- NULL = aktiv, ej NULL = borttagen
    status TEXT NOT NULL CHECK (status IN ('Draft', 'Booked', 'Paid', 'OverPaid', 'Overdue')),
    contact_guid TEXT,
    payment_date TEXT,
    payment_status TEXT,
    payment_condition_number_of_days INTEGER NOT NULL,
    payment_condition_type TEXT,
    fik_code TEXT,
    deposit_account_number INTEGER,
    mail_out_status TEXT,
    latest_mail_out_type TEXT,
    is_sent_to_debt_collection INTEGER NOT NULL CHECK (is_sent_to_debt_collection IN (0,1)),
    is_mobile_pay_invoice_enabled INTEGER NOT NULL CHECK (is_mobile_pay_invoice_enabled IN (0,1)),
    is_penso_pay_enabled INTEGER NOT NULL CHECK (is_penso_pay_enabled IN (0,1)),

    -- Nya kolumner som matchar API:
    reminder_fee REAL CHECK (reminder_fee >= 0),
    reminder_interest_rate REAL CHECK (reminder_interest_rate >= 0)
);



-- ===========================================
-- 2) TABELL: invoice_lines (faktura-rader)
-- ===========================================
CREATE TABLE invoice_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_guid TEXT NOT NULL,

    -- Fält enligt InvoiceLinesReadModel:
    product_guid TEXT,            
    description TEXT NOT NULL,     -- Beskrivning av fakturaraden
    comments TEXT,                
    quantity REAL NOT NULL,        -- Antal (float)
    account_number INTEGER NOT NULL,  -- Konto kopplat till fakturaraden
    account_name TEXT NOT NULL,    -- Namn på kontot (ej NULL)
    unit TEXT NOT NULL,            -- Enhet, ex: hours, parts, km etc.
    discount REAL NOT NULL CHECK (discount >= 0 AND discount <= 100),  
    line_type TEXT NOT NULL CHECK (line_type IN ('Product', 'Text')),
    
    -- Nya fält för moms
    vat_code TEXT,                 -- Moms-kod (ex: "U25", "I25", "none")
    vat_rate REAL NOT NULL CHECK (vat_rate >= 0 AND vat_rate <= 1),  -- Momssats i decimalform (ex: 0.25)

    -- Pris- och totalvärden
    base_amount_value REAL NOT NULL,            -- Enhetspris exkl. moms
    base_amount_value_incl_vat REAL NOT NULL,   -- Enhetspris inkl. moms
    total_amount REAL NOT NULL,                 -- Totalbelopp exkl. moms
    total_amount_incl_vat REAL NOT NULL,        -- Totalbelopp inkl. moms

    -- Spårbarhet
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, -- När raden skapades
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, -- Senaste uppdatering

    FOREIGN KEY (invoice_guid) REFERENCES invoice(guid)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);



-- ===========================================
-- 3) TABELL: payments (enskilda betalningar)
--    Exempel för PaymentReadModel
--    Justera kolumnerna efter dina behov!
-- ===========================================
CREATE TABLE payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_guid TEXT NOT NULL,  -- koppling till invoice

    -- Exempel på fält för PaymentReadModel:
    payment_date TEXT,           -- t.ex. "YYYY-MM-DD"
    payment_amount REAL NOT NULL,
    payment_method TEXT,         -- bank, kort, swish etc.
    comments TEXT,

    FOREIGN KEY (invoice_guid) REFERENCES invoice(guid)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ===========================================
-- 4) TABELL: ledger_item_lines
--    För LedgerItemLineModelV2
-- ===========================================
CREATE TABLE ledger_item_lines (
    -- Modellen anger "id" som unikt text-id
    id TEXT NOT NULL,

    description TEXT,
    amount REAL NOT NULL,  -- belopp i DKK
    account_number INTEGER,
    account_vat_code TEXT,
    balancing_account_number INTEGER,
    balancing_account_vat_code TEXT,
    is_payment_for_voucher_id TEXT, 

    -- Om du vill tvinga affärslogiken att 
    -- "antingen är is_payment_for_voucher_id satt eller 
    --  account_number – men inte båda", kan du 
    -- använda en CHECK. Exempel nedan kräver
    -- att de inte krockar:
    CHECK (
      is_payment_for_voucher_id IS NULL
      OR account_number IS NULL
    ),

    -- Primärnyckel på id
    PRIMARY KEY (id)
);

-- ============================================
-- TABELL: account (kontoplan)
-- ============================================
CREATE TABLE account (
    -- Obligatoriskt kontonummer (unikt), ex 1000, 2020, 55100
    accountNumber INTEGER NOT NULL,

    -- Fält enligt den samlade dokumentationen:
    publicStandardNumber INTEGER,          -- Mappning till publikt standardkonto (t.ex. 1010)
    name TEXT NOT NULL,                    -- Namn på kontot (tidigare NULL, nu NOT NULL för att säkerställa namn)
    vatCode TEXT NOT NULL,                 -- Moms-kod, t.ex. "U25", "I25", "none"
    category TEXT,                         -- T.ex. "Turnover", "Variable Expenses", "Property", etc.
    categoryName TEXT,                     -- T.ex. "Salg", "Administration", ...
    isHidden INTEGER NOT NULL 
        CHECK (isHidden IN (0,1)) 
        DEFAULT 0,                         -- TRUE/FALSE i SQLite blir 1/0
    isDefaultSalesAccount INTEGER NOT NULL
        CHECK (isDefaultSalesAccount IN (0,1))
        DEFAULT 0,                         -- Anger om kontot är “default sales account”
    isDefault INTEGER NOT NULL
        CHECK (isDefault IN (0,1))
        DEFAULT 0,                         -- För deposit accounts: om detta är ett “default deposit account”

    -- Bank-/depositionskolumner för deposit accounts 
    bankRegistrationNumber TEXT DEFAULT NULL,  -- T.ex. "1234" (Clearingnr i DK)
    bankAccountNumber TEXT DEFAULT NULL,       -- T.ex. "123456789"
    bankSwiftNumber TEXT DEFAULT NULL,         -- T.ex. "DK123456789"
    bankIbanNumber TEXT DEFAULT NULL,          -- T.ex. "DK123456789"

    -- Nytt fält för att hantera om kontot är aktivt eller arkiverat
    isActive INTEGER NOT NULL
        CHECK (isActive IN (0,1))
        DEFAULT 1,  -- Standard är att kontot är aktivt

    -- Nytt fält för att lagra datum när kontot skapades
    createdAt TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),

    -- Nytt fält för att lagra senaste uppdateringstid
    updatedAt TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),

    -- Primärnyckel på accountNumber
    PRIMARY KEY (accountNumber)
);



-- ===========================================
-- TABELL: accounting_year
-- ===========================================
CREATE TABLE accounting_year (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- Länka bokföringsår till en organisation (om du har en organizations-tabell)
    organization_id TEXT NOT NULL,
    -- ex. FOREIGN KEY (organization_id) REFERENCES organization(guid)
    --     ON DELETE CASCADE
    --     ON UPDATE CASCADE,

    -- name, from_date, to_date kan vara NULL enligt dokumentationen
    name TEXT,
    from_date TEXT,
    to_date TEXT,

    -- salarySumTaxStateEnum är obligatorisk och har tre giltiga värden
    salary_sum_tax_state_enum TEXT NOT NULL
        CHECK (salary_sum_tax_state_enum IN ('None','OnlySalarySum','MixedActivities'))
);


CREATE TABLE attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NOT NULL,
    document_guid TEXT NOT NULL,  
    file_guid TEXT,
    file_name TEXT,
    document_type TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (organization_id) REFERENCES organization(id) ON DELETE CASCADE ON UPDATE CASCADE
);


CREATE TABLE contacts (
    -- Organisationens ID som kontakter hör till
    organization_id TEXT NOT NULL,
    
    -- Unikt ID för kontakten (UUID, men lagras som TEXT i SQLite)
    contact_guid TEXT NOT NULL,

    -- Möjliga fält (enligt “Create contact”/“Get contact”):
    external_reference TEXT,    -- Max 128 tecken om du vill
    name TEXT NOT NULL,         -- obligatorisk
    street TEXT,
    zip_code TEXT,
    city TEXT,
    country_key TEXT NOT NULL,  -- "DK", "SE", etc.
    phone TEXT,
    email TEXT,
    webpage TEXT,
    att_person TEXT,            -- Kontaktperson om företaget är en firma
    vat_number TEXT,            -- Momsnummer (CVR)
    ean_number TEXT,            -- EAN / GLN-nummer
    se_number TEXT,             -- SE-nummer
    p_number TEXT,              -- P-nummer
    payment_condition_type TEXT,    -- "Netto", "NettoCash", "CurrentMonthOut", ...
    payment_condition_number_of_days INTEGER,
    is_person INTEGER NOT NULL CHECK (is_person IN (0,1)),   -- boolean i SQLite
    is_member INTEGER NOT NULL CHECK (is_member IN (0,1)),   -- boolean i SQLite
    member_number TEXT,         -- Används om is_member = 1
    use_cvr INTEGER NOT NULL CHECK (use_cvr IN (0,1)),       -- boolean i SQLite
    company_type_key TEXT,      -- "PrivateLimitedCompany", "SoleProprietorship", ...
    invoice_mail_out_option_key TEXT, -- "VAT", "GLN", "SE", "P", eller null
    created_at TEXT,            -- datum/tid (t.ex. "2021-12-02T09:15:00Z")
    updated_at TEXT,
    deleted_at TEXT,            -- Anger om kontakten är raderad
    is_debitor INTEGER NOT NULL CHECK (is_debitor IN (0,1)), -- boolean
    is_creditor INTEGER NOT NULL CHECK (is_creditor IN (0,1)),-- boolean
    company_status TEXT,        -- "normal", "active", "underBankruptcy", ...
    vat_region_key TEXT,        -- "DK", "EU", "World", ev. null

    -- Primärnyckel på (organization_id, contact_guid) 
    -- om du vill förhindra att samma guid används i olika organisationer.
    -- Eller enbart contact_guid om du garanterar global unikalhet:
    PRIMARY KEY (organization_id, contact_guid)

    -- Om du har en tabell "organization" kan du lägga till en foreign key:
    -- FOREIGN KEY (organization_id) REFERENCES organization(id)
    --    ON DELETE CASCADE
    --    ON UPDATE CASCADE
);

CREATE TABLE contact_notes (
    -- Unikt ID för noten
    note_guid TEXT NOT NULL,

    -- Organisation och kontakt
    organization_id TEXT NOT NULL,
    contact_guid TEXT NOT NULL,

    -- Innehåll i noten
    text TEXT NOT NULL,      -- Själva noteringen/meddelandet
    note_date TEXT NOT NULL, -- Datum/tid för noten (t.ex. "2021-01-01T12:00:00Z")

    author_name TEXT,
    author_email TEXT,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT,

    -- Primärnyckel (ex. note_guid), men man kan 
    -- även använda (organization_id, note_guid) om 
    -- man vill vara helt unik per organisation:
    PRIMARY KEY (note_guid),

    -- Länka gärna till contacts(organization_id, contact_guid)
    FOREIGN KEY (organization_id, contact_guid) 
        REFERENCES contacts(organization_id, contact_guid)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


CREATE TABLE entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- För att veta vilken organisation posterna hör till:
    organization_id TEXT NOT NULL,

    -- Fält enligt EntryReadModel:
    account_number INTEGER NOT NULL,   -- T.ex. 55000
    account_name TEXT,                 -- Namnet på kontot
    entry_date TEXT,                   -- Datum (Format "YYYY-MM-DD" eller "YYYY-MM-DDTHH:MM:SSZ")
    voucher_number INTEGER,            -- Verifikatnummer
    voucher_type TEXT,                 -- T.ex. "Invoice", "CashVoucher" etc.
    description TEXT,
    vat_type TEXT,                     -- T.ex. "25%", "U25", ...
    vat_code TEXT,                     -- T.ex. "I25", "U25", ...
    amount REAL NOT NULL,              -- Pengabelopp (positive, negative, etc.)
    entry_guid TEXT,                   -- Unik identifierare (uuid)
    contact_guid TEXT,                 -- Koppling till en kontakt (om tillämpligt)

    -- Enligt dokumentationen kan type vara "Normal", "Primo" eller "Ultimo"
    entry_type TEXT
        CHECK (entry_type IN ('Normal','Primo','Ultimo')),

    -- Exempel på en tidsstämpel om du vill veta när posten lagrades
    created_at TEXT DEFAULT (datetime('now')),

    -- Foreign key om du har en "contacts"-tabell och vill koppla contact_guid
    -- FOREIGN KEY (contact_guid) REFERENCES contacts(contact_guid)
    --    ON DELETE CASCADE
    --    ON UPDATE CASCADE,

    -- Likaså för "organization_id" om du har en "organization"-tabell:
    -- FOREIGN KEY (organization_id) REFERENCES organization(id)
    --    ON DELETE CASCADE
    --    ON UPDATE CASCADE
);


CREATE TABLE files (
    -- Primärnyckel: filens guid (t.ex. "web0crmck5v0ndcx...") i textformat
    file_guid TEXT NOT NULL,

    -- För att veta vilken organisation filen tillhör
    organization_id TEXT NOT NULL,

    -- Exempel på metadata
    name TEXT,        -- Filens ursprungliga namn (exkl. extension)
    extension TEXT,   -- Filändelse, ex "pdf", "png", "csv"
    size INTEGER,     -- Filstorlek i byte
    uploaded_at TEXT NOT NULL,   -- T.ex. "2021-12-02T09:15:00Z"
    note TEXT,        -- Ev. kommentar/anteckning
    number_of_pages INTEGER,   -- Antal sidor om det är en PDF m.m.
    uploaded_by TEXT, -- Den som laddade upp
    file_status TEXT NOT NULL DEFAULT 'Unused'
        CHECK (file_status IN ('All','Used','Unused')),

    -- Om du vill spara en referens till var filen faktiskt ligger
    -- (s3-url, local disk path etc.)
    file_location TEXT,

    -- Exempel på en kolumn om du vill "soft-delete" filer:
    deleted_at TEXT,

    PRIMARY KEY (file_guid)

    -- Foreign key om du har en "organization"-tabell:
    -- FOREIGN KEY (organization_id) REFERENCES organization(id)
    --    ON DELETE CASCADE
    --    ON UPDATE CASCADE
);

CREATE TABLE file_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_guid TEXT NOT NULL,
    linked_type TEXT NOT NULL,   -- T.ex. "Invoice", "TradeOffer", ...
    linked_guid TEXT NOT NULL,   -- T.ex. invoiceGuid
    FOREIGN KEY (file_guid) REFERENCES files(file_guid)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


CREATE TABLE organizations (
    -- Id av typen INT, används som PRIMARY KEY
    id INTEGER NOT NULL,

    name TEXT,        -- Namn på organisationen
    type TEXT,        -- "VIP" eller "default" (eller annat)
    
    -- Booleans i SQLite får oftast vara INTEGER + CHECK
    is_pro INTEGER CHECK (is_pro IN (0,1)),
    is_paying_pro INTEGER CHECK (is_paying_pro IN (0,1)),

    -- is_vat_free är required => NOT NULL
    is_vat_free INTEGER NOT NULL CHECK (is_vat_free IN (0,1)),

    email TEXT,
    phone TEXT,
    street TEXT,
    city TEXT,
    zip_code TEXT,
    att_person TEXT,

    -- is_tax_free_union är required => NOT NULL
    is_tax_free_union INTEGER NOT NULL CHECK (is_tax_free_union IN (0,1)),

    vat_number TEXT,
    country_key TEXT,  -- "DK", "SE" etc.
    website TEXT,

    -- Markera pk
    PRIMARY KEY (id)
);

CREATE TABLE manual_voucher (
    -- Unikt GUID för varje verifikation (typ TEXT i SQLite)
    guid TEXT NOT NULL,

    -- Vilken organisation tillhör?
    organization_id TEXT NOT NULL,

    -- Nummer på verifikationen (kan vara null eller genereras)
    voucher_number INTEGER,  

    -- Datum på verifikationen
    voucher_date TEXT,  -- "YYYY-MM-DD" format 

    -- Summor (enligt dokumentation: "Obsolete" men du kan ändå lagra dem om du vill)
    total_incl_vat REAL NOT NULL DEFAULT 0,
    total_excl_vat REAL NOT NULL DEFAULT 0,

    -- Filens GUID om en fil är kopplad till denna voucher
    file_guid TEXT,

    -- Koppling till en annan voucher om detta är en kreditnota
    credit_note_for TEXT,  -- guid till annan voucher

    -- Status: Draft, Editing eller Booked
    status TEXT CHECK (status IN ('Draft','Editing','Booked')),

    -- T.ex. lines är en array i API:et. Du kan antingen spara dem i 
    -- en separat "manual_voucher_line"-tabell (se nedan) 
    -- eller i JSON-form i kolumnen. Rekommenderat: separat tabell.

    -- Tidstämpel (serverns concurrency-säkring)
    timestamp TEXT,

    -- Externt referensfält
    external_reference TEXT,  -- [0..128] tecken

    -- Om verifikationen är bokad, kan den vara bokad av viss “typ” och “username”
    booked_by_type TEXT,      -- t.ex. "API", "Web", "System"
    booked_by_username TEXT,  -- namnet/användaren
    booking_time TEXT,        -- "YYYY-MM-DD" eller "YYYY-MM-DDTHH:MM:SSZ"

    -- Om du vill hantera “offset entry” vid radering (bokförd voucher),
    -- kan du lägga till en kolumn is_offset INTEGER NOT NULL CHECK (is_offset IN (0,1)) DEFAULT 0,
    -- eller en "deleted_at" om du vill markera radens borttagning.

    PRIMARY KEY (guid)

    -- Om du vill knyta organization_id till en "organization"-tabell:
    -- FOREIGN KEY (organization_id) REFERENCES organizations(id)
    --   ON DELETE CASCADE
    --   ON UPDATE CASCADE,
    --
    -- Om du vill knyta file_guid till "files(file_guid)":
    -- FOREIGN KEY (file_guid) REFERENCES files(file_guid)
    --   ON DELETE SET NULL
    --   ON UPDATE CASCADE
);



CREATE TABLE products (
    -- Primärnyckel: Produktens guid (UUID-liknande sträng)
    product_guid TEXT NOT NULL,

    -- Vilken organisation produkten tillhör
    organization_id TEXT NOT NULL,

    -- Produktnummer (frivilligt enligt doc, men ofta använt)
    product_number TEXT,

    -- Namn/beskrivning av produkten
    name TEXT,

    -- Baspris exkl. moms (krävs)
    base_amount_value REAL NOT NULL,

    -- Baspris inkl. moms
    base_amount_value_incl_vat REAL NOT NULL DEFAULT 0,  

    -- Kvantitet (krävs)
    quantity REAL NOT NULL,  

    -- Kontonummer (t.ex. int 1000, 1010)
    account_number INTEGER NOT NULL,

    -- Enhet (hours, parts, day, etc.)
    unit TEXT NOT NULL,   -- “non-empty”

    -- Extern referens, [0..128] tecken
    external_reference TEXT,

    -- Kommentar [0..128] tecken
    comment TEXT,

    -- Tidsstämplar
    created_at TEXT NOT NULL,     -- “2019-08-24T14:15:22Z”
    updated_at TEXT NOT NULL,     -- “2019-08-24T14:15:22Z”
    deleted_at TEXT,              -- null om ej raderad

    -- Totalsummor (kan räknas på flygande fot men API visar dem)
    total_amount REAL NOT NULL DEFAULT 0,           -- exkl. moms = base_amount_value * quantity
    total_amount_incl_vat REAL NOT NULL DEFAULT 0,  -- inkl. moms = base_amount_value_incl_vat * quantity

    -- Primärnyckel
    PRIMARY KEY (product_guid)

    -- Foreign key om du har en “organization”-tabell:
    -- FOREIGN KEY (organization_id) REFERENCES organizations(id)
    --     ON DELETE CASCADE
    --     ON UPDATE CASCADE
);

CREATE TABLE purchase_credit_note (
    -- Primärnyckel: credit note guid (UUID-liknande sträng i SQLite)
    guid TEXT NOT NULL,

    -- Vilken organisation tillhör (om du har multi-tenant)?
    organization_id TEXT NOT NULL,

    -- Valfri koppling till en existerande faktura/voucher?
    credit_note_for TEXT,    -- guid (UUID) till en annan purchase voucher

    -- En fil kan vara kopplad (filens guid)
    file_guid TEXT,

    -- Ett löpnummer för credit note (tex "7" i exemplet)
    credit_note_number INTEGER,  -- Kan vara null om den inte är bokförd ännu

    -- Kontaktens guid (krävs för booking, men kan vara tom i draft)
    contact_guid TEXT,

    -- Datum för kreditnotan (ex. "YYYY-MM-DD")
    date TEXT,  

    -- Valuta (DKK, EUR, etc.)
    currency TEXT,

    -- Tidstämpel (används för concurrency/senaste version)
    timestamp TEXT,

    -- Status: “Draft”, “Paid”, “Editing”, “Booked”, “Overdue” eller “Overpaid”
    status TEXT CHECK (status IN (
      'Draft','Paid','Editing','Booked','Overdue','Overpaid'
    )),

    -- Bokningsuppgifter (vem bokade, när, hur)
    booked_by_type TEXT,     -- t.ex. "Web", "API"...
    booked_by_username TEXT, -- t.ex. "john.doe@email.com"
    booking_time TEXT,       -- Ex: "2022-05-02"

    -- Primärnyckel
    PRIMARY KEY (guid)

    -- Eventuella foreign keys:
    -- FOREIGN KEY (organization_id) REFERENCES organizations(id)
    --   ON DELETE CASCADE
    --   ON UPDATE CASCADE,
    -- FOREIGN KEY (file_guid) REFERENCES files(file_guid)
    --   ON DELETE SET NULL
    --   ON UPDATE CASCADE
);

CREATE TABLE purchase_credit_note_line (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Knyts till purchase_credit_note(guid)
    credit_note_guid TEXT NOT NULL,

    -- Exempel på fält i en PurchaseVoucherLineCreateModel:
    description TEXT,
    quantity REAL NOT NULL,
    unit TEXT,               -- t.ex. "hours", "parts", etc.
    account_number INTEGER NOT NULL,
    base_amount_value REAL NOT NULL,
    base_amount_value_incl_vat REAL NOT NULL,
    total_amount REAL NOT NULL,
    total_amount_incl_vat REAL NOT NULL,

    -- Kanske moms-kod etc. om nödvändigt
    vat_code TEXT,

    FOREIGN KEY (credit_note_guid) REFERENCES purchase_credit_note(guid)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


CREATE TABLE purchase_voucher_credit_payment (
    guid TEXT PRIMARY KEY, -- Unikt ID för betalningen

    -- Knyts till en purchase voucher eller credit note
    purchase_voucher_guid TEXT NOT NULL,

    -- Externt referens-ID, tex. ordernummer från en webshop
    external_reference TEXT,

    -- Datum då betalningen genomfördes
    payment_date TEXT NOT NULL,  -- Format: YYYY-MM-DD

    -- Beskrivning, t.ex. "Payment of invoice"
    description TEXT NOT NULL,

    -- Betalningsbelopp (måste vara ≠ 0)
    amount REAL NOT NULL CHECK(amount <> 0),

    -- Konto betalningen gick in på (kan vara NULL om via kreditnota)
    deposit_account_number INTEGER,

    -- Valuta (standard är DKK)
    currency TEXT DEFAULT 'DKK',

    -- Växelkurs mot DKK (standard är 100 om DKK)
    exchange_rate REAL NOT NULL DEFAULT 100,

    -- Typ av betalning (ex: "Invoice" eller "CreditNote")
    payment_class TEXT,

    -- Knyts till relaterad voucher (ex. en annan kreditnota)
    related_voucher_guid TEXT,

    -- Tidstämpel för versionering/concurrency (ex. "00000000020A5EA8")
    timestamp TEXT NOT NULL,

    -- Eventuell utländsk valuta-belopp (om betalat i annan valuta)
    amount_in_foreign_currency REAL,

    -- Foreign keys (om du vill ha referenssäkerhet)
    FOREIGN KEY (purchase_voucher_guid) REFERENCES purchase_voucher(guid)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    FOREIGN KEY (related_voucher_guid) REFERENCES purchase_voucher(guid)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);


CREATE TABLE purchase_voucher (
    guid TEXT PRIMARY KEY,  -- Unikt ID för fakturan

    -- Datum och betalningsinfo
    voucher_date TEXT NOT NULL,  -- Datum för fakturan (YYYY-MM-DD)
    payment_date TEXT,  -- Betalningsdatum (YYYY-MM-DD, endast för kreditköp)
    
    -- Bokföringsstatus
    status TEXT NOT NULL CHECK(status IN ('Draft', 'Paid', 'Editing', 'Booked', 'Overdue', 'Overpaid')), 
    
    -- Om det är ett kontant- eller kreditköp
    purchase_type TEXT NOT NULL CHECK(purchase_type IN ('cash', 'credit')), 

    -- Depåkonto för kontantköp
    deposit_account_number INTEGER,

    -- Region (DK, EU, World) endast för kontantköp
    region_key TEXT CHECK(region_key IN ('DK', 'EU', 'World')), 

    -- Externt referens-ID, tex. ordernummer från en webshop
    external_reference TEXT,  

    -- Valuta & växelkurs
    currency_key TEXT DEFAULT 'DKK',
    exchange_rate REAL DEFAULT 100 CHECK(exchange_rate > 0),  -- Växelkurs får inte vara negativ

    -- Bokningsuppgifter
    booked_by_type TEXT,
    booked_by_username TEXT,
    booking_time TEXT,  -- När bokningen gjordes

    -- Kontaktinformation (används vid kreditköp)
    contact_guid TEXT,

    -- Filkoppling
    file_guid TEXT,

    -- Versionshantering
    timestamp TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- Sätt automatisk timestamp

    -- Spårbarhet
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- FK-referenser
    FOREIGN KEY (contact_guid) REFERENCES contact(guid) ON DELETE SET NULL,
    FOREIGN KEY (file_guid) REFERENCES file(file_guid) ON DELETE SET NULL
);


CREATE TABLE purchase_voucher_line (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Koppling till en specifik faktura
    purchase_voucher_guid TEXT NOT NULL,

    -- Artikelbeskrivning
    description TEXT NOT NULL,

    -- Konto och kategorisering
    account_number INTEGER NOT NULL,
    account_name TEXT NOT NULL,  -- Lägg till kontonamn

    -- Kvantitet
    quantity REAL NOT NULL DEFAULT 1 CHECK(quantity > 0),  -- Förhindra negativa kvantiteter

    -- Pris exklusive moms
    base_amount_value REAL NOT NULL CHECK(base_amount_value >= 0),

    -- Pris inklusive moms
    base_amount_value_incl_vat REAL NOT NULL CHECK(base_amount_value_incl_vat >= 0),

    -- Valuta och totalsummor
    total_amount REAL NOT NULL CHECK(total_amount >= 0),
    total_amount_incl_vat REAL NOT NULL CHECK(total_amount_incl_vat >= 0),

    -- Moms & kategorier
    vat_type TEXT,
    vat_code TEXT,
    vat_rate REAL NOT NULL CHECK(vat_rate >= 0 AND vat_rate <= 1),  -- Spara momssatsen som decimal

    -- Spårbarhet
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Kopplingar
    FOREIGN KEY (purchase_voucher_guid) REFERENCES purchase_voucher(guid) 
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


CREATE TABLE purchase_voucher_totals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Koppling till faktura
    purchase_voucher_guid TEXT NOT NULL,

    -- Summering av rader
    total_amount REAL NOT NULL CHECK(total_amount >= 0),
    total_amount_incl_vat REAL NOT NULL CHECK(total_amount_incl_vat >= 0),

    -- Momsinformation
    vat_amount REAL NOT NULL CHECK(vat_amount >= 0),

    -- Spårbarhet
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- FK
    FOREIGN KEY (purchase_voucher_guid) REFERENCES purchase_voucher(guid)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


CREATE TABLE reminder (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- Koppling till faktura
    voucher_guid TEXT NOT NULL,  -- Unikt ID för fakturan
    organization_id TEXT NOT NULL,  -- Företagets ID
    
    -- Påminnelseinformation
    timestamp TEXT NOT NULL,  -- Versionshantering
    date TEXT NOT NULL,  -- Datum för påminnelsen
    title TEXT,  -- Rubrik på påminnelsen
    description TEXT,  -- Beskrivning av påminnelsen
    is_draft BOOLEAN NOT NULL DEFAULT 1,  -- Är utkast (inte bokad)
    is_deleted BOOLEAN NOT NULL DEFAULT 0,  -- Är raderad (logiskt borttagen)
    number INTEGER NOT NULL,  -- Påminnelsenummer (1, 2 eller 3 per faktura)

    -- Påminnelseavgifter och varningar
    with_debt_collection_warning BOOLEAN NOT NULL DEFAULT 0,  -- Inkassovarning
    debt_collection_notice_text TEXT,  -- Text för inkassovarning
    
    with_fee BOOLEAN NOT NULL DEFAULT 0,  -- Om påminnelsen har en avgift
    fee_amount REAL NOT NULL DEFAULT 0,  -- Påminnelseavgift
    fee_amount_text TEXT,  -- Text som beskriver påminnelseavgiften

    with_interest_fee BOOLEAN NOT NULL DEFAULT 0,  -- Om ränta ska läggas på
    interest_amount REAL DEFAULT 0,  -- Räntans storlek
    interest_amount_text TEXT,  -- Text som beskriver räntan

    with_compensation_fee BOOLEAN NOT NULL DEFAULT 0,  -- Om kompensationsavgift används
    compensation_fee_amount REAL NOT NULL DEFAULT 0,  -- Kompensationsavgiftens storlek
    compensation_fee_amount_text TEXT,  -- Text som beskriver kompensationsavgiften
    compensation_fee_available BOOLEAN NOT NULL DEFAULT 0,  -- Indikerar om kompensationsavgift kan läggas till

    -- Summerade avgifter och betalningsstatus
    accumulated_fees_and_interest_amount REAL NOT NULL DEFAULT 0,  -- Totala avgifter och räntor från tidigare påminnelser
    accumulated_fees_and_interest_amount_text TEXT,  -- Text för ackumulerade avgifter och räntor

    invoice_total_incl_vat_amount REAL NOT NULL DEFAULT 0,  -- Fakturatotal inklusive moms
    invoice_total_incl_vat_amount_text TEXT,  -- Text för fakturatotal inkl. moms

    paid_amount REAL NOT NULL DEFAULT 0,  -- Redan betalt belopp
    paid_amount_text TEXT,  -- Text för betalt belopp

    reminder_total_incl_vat_amount REAL NOT NULL DEFAULT 0,  -- Totalt belopp för påminnelsen inkl. moms
    reminder_total_incl_vat_amount_text TEXT,  -- Text för totalbeloppet inkl. moms

    -- FK-referenser
    FOREIGN KEY (voucher_guid) REFERENCES invoice(guid) ON DELETE CASCADE
);


CREATE TABLE reminder_email_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    
    reminder_id INTEGER NOT NULL,  -- Koppling till påminnelse
    sent_at TEXT NOT NULL,  -- Datum och tid då mejlet skickades
    recipient_email TEXT NOT NULL,  -- Mottagarens e-postadress
    email_subject TEXT NOT NULL,  -- Ämne på mejlet
    email_body TEXT NOT NULL,  -- Innehållet i mejlet (HTML/text)
    
    -- FK-referenser
    FOREIGN KEY (reminder_id) REFERENCES reminder(id) ON DELETE CASCADE
);


CREATE TABLE reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- Koppling till organisation och räkenskapsår
    organization_id TEXT NOT NULL,  -- Företagets ID
    accounting_year TEXT NOT NULL,  -- Räkenskapsåret (ex: 2023)
    
    -- Rapporttyp (saldo, resultat, primo, balans)
    report_type TEXT NOT NULL CHECK (report_type IN ('saldo', 'result', 'primo', 'balance')),  
    
    -- Kontoinformation
    account_name TEXT,  -- Namn på kontot
    account_number INTEGER NOT NULL,  -- Kontonummer
    amount REAL NOT NULL,  -- Totalbelopp

    -- Valfria filter från API
    show_zero_account BOOLEAN DEFAULT NULL,  
    show_account_no BOOLEAN DEFAULT NULL,  
    include_summary_account BOOLEAN DEFAULT 1,  
    include_ledger_entries BOOLEAN DEFAULT 0,  
    show_vat_type BOOLEAN DEFAULT 0  
);


-- ===========================================
-- TABELL: credit_note (kreditnota-huvud)
-- ===========================================
CREATE TABLE credit_note (
    guid TEXT PRIMARY KEY,                            -- UUID för kreditnotan
    currency TEXT,                                    -- Valuta (t.ex. DKK, EUR, USD)
    language TEXT,                                    -- Språk ('da-DK', 'en-GB')
    external_reference TEXT,                          -- Extern referens
    description TEXT,                                 -- Beskrivning
    comment TEXT,                                     -- Kommentar
    credit_note_date TEXT NOT NULL,                   -- Datum för skapande (YYYY-MM-DD)
    address TEXT,                                     -- Kundadress
    "number" INTEGER NOT NULL,                        -- Unikt löpnummer
    contact_name TEXT,                                -- Kundens namn
    contact_guid TEXT,                                -- Kundens GUID i Dinero
    show_lines_incl_vat INTEGER NOT NULL CHECK (show_lines_incl_vat IN (0,1)),  
    total_excl_vat REAL NOT NULL,                     -- Totalt belopp exkl. moms
    total_vatable_amount REAL NOT NULL,               -- Totalt momsbelagt belopp
    total_incl_vat REAL NOT NULL,                     -- Totalt belopp inkl. moms
    total_non_vatable_amount REAL NOT NULL,           -- Momsfritt belopp
    total_vat REAL NOT NULL,                          -- Momsbelopp
    invoice_template_id TEXT,                         -- Mall för utskrift
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,  
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,  
    deleted_at TEXT NULL,                             -- Mjuk radering
    status TEXT NOT NULL CHECK (status IN ('Draft', 'Booked')),  
    payment_date TEXT,                                -- Betalningsdatum
    payment_status TEXT CHECK (payment_status IN ('Draft', 'Booked', 'Paid', 'OverPaid', 'Overdue')),
    payment_condition_number_of_days INTEGER NOT NULL,
    payment_condition_type TEXT,                      -- Netto, NettoCash, CurrentMonthOut
    fik_code TEXT,                                    -- FIK-kod för betalning
    deposit_account_number INTEGER,                   -- Konto där betalningen registreras
    mail_out_status TEXT,                             -- Status för e-postutskick
    latest_mail_out_type TEXT,                        -- Typ av senaste utskick
    is_sent_to_debt_collection INTEGER NOT NULL CHECK (is_sent_to_debt_collection IN (0,1)),
    is_mobile_pay_invoice_enabled INTEGER NOT NULL CHECK (is_mobile_pay_invoice_enabled IN (0,1)),
    is_penso_pay_enabled INTEGER NOT NULL CHECK (is_penso_pay_enabled IN (0,1)),
    
    -- Koppling till den ursprungliga fakturan
    credit_note_for TEXT,                             -- GUID för fakturan som kreditnotan baseras på
    FOREIGN KEY (credit_note_for) REFERENCES invoice(guid) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE
);

-- ===========================================
-- TABELL: credit_note_lines (rader för kreditnota)
-- ===========================================
CREATE TABLE credit_note_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    credit_note_guid TEXT NOT NULL,                   -- Koppling till kreditnotan

    -- Fält enligt InvoiceLinesReadModel:
    product_guid TEXT,                                -- GUID till en produkt
    description TEXT,                                 -- Beskrivning
    comments TEXT,                                    -- Extra kommentar
    quantity REAL NOT NULL,                           -- Antal
    account_number INTEGER NOT NULL,                  -- Konto för bokföring
    unit TEXT NOT NULL,                               -- Enhet (timmar, delar etc.)
    discount REAL NOT NULL CHECK (discount >= 0 AND discount <= 100),  
    line_type TEXT NOT NULL CHECK (line_type IN ('Product', 'Text')),  
    account_name TEXT,                                
    base_amount_value REAL NOT NULL,                 
    base_amount_value_incl_vat REAL NOT NULL,        
    total_amount REAL NOT NULL,                      
    total_amount_incl_vat REAL NOT NULL,             

    FOREIGN KEY (credit_note_guid) REFERENCES credit_note(guid)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ===========================================
-- TABELL: trade_offer (offert-huvud)
-- ===========================================
CREATE TABLE trade_offer (
    guid TEXT PRIMARY KEY,                           -- UUID för offerten
    currency TEXT,                                   -- Valuta (DKK, EUR, USD, etc.)
    language TEXT,                                   -- Språk ('da-DK', 'en-GB')
    external_reference TEXT,                         -- Extern referens
    description TEXT,                                -- Beskrivning av offerten
    comment TEXT,                                    -- Kommentar
    offer_date TEXT NOT NULL,                        -- Datum för skapande (YYYY-MM-DD)
    address TEXT,                                    -- Kundadress
    "number" INTEGER NOT NULL,                       -- Unikt löpnummer
    contact_name TEXT,                               -- Kundens namn
    contact_guid TEXT NOT NULL,                      -- GUID till kunden i Dinero
    show_lines_incl_vat INTEGER NOT NULL CHECK (show_lines_incl_vat IN (0,1)),  
    total_excl_vat REAL NOT NULL,                    -- Totalt belopp exkl. moms
    total_vatable_amount REAL NOT NULL,              -- Totalt momsbelagt belopp
    total_incl_vat REAL NOT NULL,                    -- Totalt belopp inkl. moms
    total_non_vatable_amount REAL NOT NULL,          -- Momsfritt belopp
    total_vat REAL NOT NULL,                         -- Momsbelopp
    invoice_template_id TEXT,                        -- Mall för utskrift
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,  
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,  
    deleted_at TEXT NULL,                            -- Mjuk radering
    status TEXT NOT NULL CHECK (status IN ('Draft', 'Invoiced', 'CustomerAccepted', 'CustomerDeclined', 'UserAccepted', 'UserDeclined')),  
    mail_out_status TEXT,                            -- Status för utskick
    latest_mail_out_type TEXT,                       -- Typ av senaste utskick
    
    -- Om offerten genererat fakturor
    generated_vouchers TEXT,                         -- Lista av genererade fakturor (som JSON eller CSV-sträng)
    
    FOREIGN KEY (contact_guid) REFERENCES contacts(guid)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- ===========================================
-- TABELL: trade_offer_lines (offert-rader)
-- ===========================================
CREATE TABLE trade_offer_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    trade_offer_guid TEXT NOT NULL,                  -- Koppling till offert

    -- Fält enligt TradeOfferLineReadModel:
    product_guid TEXT,                               -- GUID till en produkt
    description TEXT,                                -- Beskrivning
    comments TEXT,                                   -- Extra kommentar
    quantity REAL NOT NULL,                          -- Antal
    account_number INTEGER NOT NULL,                 -- Konto för bokföring
    unit TEXT NOT NULL,                              -- Enhet (timmar, delar etc.)
    discount REAL NOT NULL CHECK (discount >= 0 AND discount <= 100),  
    line_type TEXT NOT NULL CHECK (line_type IN ('Product', 'Text')),  
    account_name TEXT,                               -- Kontonamn
    base_amount_value REAL NOT NULL,                 
    base_amount_value_incl_vat REAL NOT NULL,        
    total_amount REAL NOT NULL,                      
    total_amount_incl_vat REAL NOT NULL,             

    FOREIGN KEY (trade_offer_guid) REFERENCES trade_offer(guid)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ===========================================
-- TABELL: vat_type (Moms-typer)
-- ===========================================
CREATE TABLE vat_type (
    vat_code TEXT PRIMARY KEY,                   -- Kortkod för moms (ex: "U25", "I25", "none")
    name TEXT NOT NULL,                          -- Beskrivning av momstypen (ex: "25% moms", "Momsfri försäljning")
    vat_rate REAL NOT NULL CHECK (vat_rate >= 0) -- Momssats i procent (ex: 25.0, 12.0, 0.0)
);


CREATE TABLE purchase_voucher_totals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Koppling till faktura
    purchase_voucher_guid TEXT NOT NULL,

    -- Summering av rader
    total_amount REAL NOT NULL CHECK(total_amount >= 0),
    total_amount_incl_vat REAL NOT NULL CHECK(total_amount_incl_vat >= 0),

    -- Momsinformation
    vat_amount REAL NOT NULL CHECK(vat_amount >= 0),

    -- Spårbarhet
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- FK
    FOREIGN KEY (purchase_voucher_guid) REFERENCES purchase_voucher(guid)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ============================================
-- TABELL: webhook_events (Tillgängliga events)
-- ============================================
CREATE TABLE webhook_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL UNIQUE,   -- T.ex. "invoice.created", "contact.updated"
    description TEXT NOT NULL        -- Beskrivning av eventet
);

-- ============================================
-- TABELL: webhook_subscriptions (Prenumerationer)
-- ============================================
CREATE TABLE webhook_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id TEXT NOT NULL,   -- ID för organisationen
    event_id TEXT NOT NULL,          -- Vilket event organisationen prenumererar på
    uri TEXT NOT NULL,               -- URL dit webhook ska skickas
    created_at TEXT DEFAULT (DATETIME('now')),  -- Tidsstämpel för prenumerationen

    -- Skapar referens till webhook_events
    FOREIGN KEY (event_id) REFERENCES webhook_events(event_id) 
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

