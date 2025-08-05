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
├── Middleware/          # JWT-autentisering
├── invoiceRoutes.php    # Fakturaendpoints
├── dependencies.php     # DI-tjänster
└── routes.php           # Routing och middleware

migrations/
└── 2025_08_05_audit_log.sql
```
