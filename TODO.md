# TODO

This file lists remaining tasks to make Invoicemate fully compliant with the Danish Bookkeeping Act and the SAF‑T standard.

## Remaining tasks

### Verifikationer & spårbarhet

*Delvis klart:* sekventiell numrering har implementerats för manuella verifikationer och kundfakturor.
*Återstår:* Inköpsverifikationer saknar fortfarande ett löpnummerfält i databasschemat och behöver en separat implementation.

- [x] Automatisk tilldelning av verifikationsnummer för manuala verifikationer och kundfakturor.
- [ ] Inför numrering för inköpsverifikationer och säkerställ sekventiella nummer utan avbrott.
- [ ] Validera att varje verifikation balanserar (debet = kredit) innan den bokförs.

### Dataintegritet och säkerhet

- [ ] Implementera en ändringslogg (audit trail) som loggar alla ändringar (INSERT/UPDATE/DELETE) i relevanta tabeller, inklusive vem som gjort ändringen och när.
- [ ] Införa användar- och rollhantering så att åtkomst till bokföringsdata styrs enligt behörighet.
- [ ] Införa stöd för periodlåsning så att bokslut eller låsta perioder inte kan ändras.

### Backup och dataskydd

- [ ] Upprätta automatiska backup‑rutiner för SQLite‑databasen.
- [ ] Säkerhetskopiera bilagor (uploads/) regelbundet, gärna till en separat lagringslösning.
- [ ] Implementera kryptering eller annan filskyddsmekanism för känsliga filer och data.

### SAF‑T‑export

- [ ] Implementera fullständig SAF‑T‑export enligt Erhvervsstyrelsens specifikation. Exporten ska inkludera:
  - Header med företagsuppgifter.
  - MasterFiles: kontoplan (GeneralLedgerAccounts), kunder och leverantörer (Customer/Supplier), momskoder (VAT codes) och eventuellt artikelregister.
  - GeneralLedgerEntries: alla bokföringsposter för vald period med journaler, transaktioner och rader.
  - SourceDocuments: försäljningsfakturor, inköpsfakturor och betalningar, om det krävs i specifikationen.
- [ ] Validera SAF‑T‑filen mot det officiella XSD‑schemat innan den levereras.
- [ ] Tillhandahåll testfiler och automatisk generering av SAF‑T‑filer per räkenskapsår eller valfri period.

### Valfria förbättringar

- [ ] Automatisk arkivering av PDF‑versioner av utställda fakturor som bilagor.
- [ ] Implementera momsrapporter som sammanställer in‑ och utgående moms per period.
- [ ] Kontrollera att alla bokförda poster alltid har kopplade bilagor när så krävs.
