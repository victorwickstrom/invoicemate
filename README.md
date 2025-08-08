# Invoicemate

Ett Slim 4-baserat bokförings-API anpassat för dansk bogføringslovgivning.

## 🚀 Funktioner
- JWT-autentisering med Bearer-token
- Revisionsspårbarhet via `audit_log`-tabell
- Fakturahantering med loggning
- SQLite-stöd via PDO
- PSR-4, DI, PSR-7, Slim-routing

## 📦 Installation

```bash
composer install
touch database.sqlite
sqlite3 database.sqlite < sql.txt
php -S localhost:8080 -t public
```

## 🔐 Autentisering

Använd en Bearer-token i `Authorization`-headern:
```
Authorization: Bearer test
```

## 🧪 Testa databasanslutning

```bash
curl http://localhost:8080/test-db
```

## 🧾 Migrations

Ytterligare tabeller finns i `migrations/`.

```bash
sqlite3 database.sqlite < migrations/2025_08_05_audit_log.sql
```

## 📁 Struktur

```
src/
├── Middleware/          # JWT‑autentisering
├── invoiceRoutes.php    # Fakturaendpoints
├── dependencies.php     # DI‑tjänster
└── routes.php           # Routing och middleware
```

### 📥 SAF‑T‑import

Det finns nu stöd för att importera bokföringsdata i SAF‑T‑format. Du kan posta en XML‑fil via ett nytt endpoint:

```
POST /v1/{organizationId}/saft/import
```

Parametrar:

- **file** (multipart/form‑data): Själva SAF‑T‑filen i XML‑format.

API:et validerar att filen är korrekt XML och försöker läsa kontoplanen, journalposter och kontakter. Data importeras i en transaktion och svarar med JSON som visar hur många konton, poster och kontakter som importerats samt eventuella varningar.

Exempel:

```bash
curl -F "file=@/sökväg/till/saft.xml" http://localhost:8080/v1/123/saft/import
```

### 📤 SAF‑T‑export

Det finns även stöd för att exportera bokföringsdata i SAF‑T‑format. Endpoints låter dig hämta en XML‑fil med kontoplan, journalposter och kontakter för en organisation:

```
GET /v1/{organizationId}/saft/export
```

Valfria query‑parametrar:

- **from**: Startdatum i formatet `YYYY-MM-DD`.
- **to**: Slutdatum i formatet `YYYY-MM-DD`.

Om du anger `from` och/eller `to` filtreras exporterade poster till att endast omfatta det angivna datumintervallet. API:et bygger en SAF‑T‑fil och returnerar den som en nedladdningsbar bilaga.

Exempel:

```bash
# Ladda ner SAF‑T‑fil för hela året 2024
curl -o saft.xml "http://localhost:8080/v1/123/saft/export?from=2024-01-01&to=2024-12-31"
```

## 📁 Migrations

Ytterligare tabeller finns i `migrations/`. Exempelvis skapas en revisionsloggtabell med:

```bash
sqlite3 database.sqlite < migrations/2025_08_05_audit_log.sql
```
