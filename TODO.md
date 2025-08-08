# TODO

This file lists remaining tasks to make Invoicemate fully compliant with the Danish Bookkeeping Act and the SAF‑T standard.

## Remaining tasks

### Verifikationer & spårbarhet

*Klart:* Sekventiell numrering är nu implementerad för manuella verifikationer, kundfakturor **och inköpsverifikationer**. Bokningen tilldelar nästa lediga nummer vid bokföring.

- [x] Automatisk tilldelning av verifikationsnummer för manuala verifikationer, kundfakturor och inköpsverifikationer.
- [x] Validera att varje verifikation balanserar (debet = kredit) innan den bokförs.

### Dataintegritet och säkerhet

*Pågående:* En enklare audit-logg skrivs vid bokning av fakturor, manuella- och inköpsverifikationer. Fullständig audit-trail för alla ändringar och användarspecifikation saknas fortfarande.

- [ ] Implementera en fullständig ändringslogg (audit trail) som loggar alla INSERT/UPDATE/DELETE i relevanta tabeller.
- [ ] Införa användar- och rollhantering med persistent `users`‑tabell och dynamiska roller.
- [x] Införa stöd för periodlåsning så att bokslut eller låsta perioder inte kan ändras.

### Backup och dataskydd

*Klart:* Backup‑rutter finns nu i API:t. En admin‑endpoint tillåter backup av databasen och uppladdade filer samt gallrar gamla backupper.

- [x] Upprätta automatiska backup‑rutiner för SQLite‑databasen.
- [x] Säkerhetskopiera bilagor (uploads/) regelbundet, gärna till en separat lagringslösning.
- [ ] Implementera kryptering eller annan filskyddsmekanism för känsliga filer och data.

### SAF‑T‑export

- [x] Implementera fullständig SAF‑T‑export enligt Erhvervsstyrelsens specifikation (Header, MasterFiles inkl. momskoder, GeneralLedgerEntries och SourceDocuments (SalesInvoices, PurchaseInvoices, Payments) med periodfiltrering).
- [ ] Validera SAF‑T‑filen mot det officiella XSD‑schemat innan den levereras.
- [ ] Tillhandahåll testfiler och automatisk generering av SAF‑T‑filer per räkenskapsår eller valfri period.

### Valfria förbättringar

- [ ] Automatisk arkivering av PDF‑versioner av utställda fakturor som bilagor.
- [ ] Implementera momsrapporter som sammanställer in‑ och utgående moms per period.
- [ ] Kontrollera att alla bokförda poster alltid har kopplade bilagor när så krävs.
