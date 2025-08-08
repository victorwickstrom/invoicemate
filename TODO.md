
# ✅ Invoicemate – TODO för efterlevnad av dansk bokföringslag

## ✅ Redan uppfyllt
- [x] Bokföring av transaktioner (kontoplan, verifikationer, huvudbok)
- [x] Digitala bilagor med koppling till verifikationer
- [x] Momsregistrering på radnivå (inkl. momsberäkning)
- [x] Betalningsregistrering (kund/leverantör)
- [x] Grundläggande transaktionsspår (GUID, verifikatnummer)
- [x] SAF-T import

---

## 🧩 Kvar att göra

### 📄 Verifikationer & spårbarhet
- [ ] Automatisk verifikationsnummer-tilldelning
- [ ] Transaktionsbalanskontroll (debet = kredit)

### 🔐 Dataintegritet & säkerhet
- [ ] Implementera ändringslogg (audit trail)
- [ ] Inför användar- och rollhantering
- [ ] Stöd för periodlåsning (t.ex. låsta räkenskapsår)

### ☁️ Backup och dataskydd
- [ ] Backup-rutin för SQLite-databas
- [ ] Backup av bilagor (uploads/)
- [ ] Kryptering och filskydd

### 📤 SAF-T export (krav enligt lagen)
- [ ] Fullständig SAF-T-exportfunktion (XML enligt Erhvervsstyrelsen)
- [ ] Validera SAF-T-fil mot XSD
- [ ] Låt användare generera SAF-T per räkenskapsår

### 📊 Valfria förbättringar (bör-krav)
- [ ] Automatisk PDF-arkivering av utställda fakturor
- [ ] Momsrapport (summering in-/utgående moms)
- [ ] Kontroll att alla bokförda poster har bilaga
