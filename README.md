# Invoicemate

Invoicemate is a lightweight accounting system built with Slim 4 and SQLite. It supports voucher posting with sequential numbering, audit logging, JWT‑based authentication, period locking, encrypted attachments, SAF‑T (DK) export, PDF archiving of invoices and VAT reporting matching SKAT's TastSelv fields.

## Requirements

- PHP 8.0 or higher
- Composer

## Installation

Clone the repository and install dependencies:

```bash
composer install
```

Create a `.env` file in the project root and set the following variables:

```
UPLOADS_DIR=/path/to/uploads
UPLOADS_KEY=your-32-byte-secret
JWT_SECRET=your-jwt-signing-key
```

Run database migrations:

```bash
mkdir -p data
sqlite3 data/database.sqlite < sql.txt
for f in migrations/*.sql; do sqlite3 data/database.sqlite < "$f"; done
```

## Running the application

Start the built‑in PHP server:

```bash
php -S localhost:8080 index.php
```

The API will be available at `http://localhost:8080`.

## Endpoints

* `POST /v1/auth/login` – Authenticate with email and password.
* `GET /v1/me` – Return current user claims.
* `GET /v1/{org}/reports/vat?from=YYYY-MM-DD&to=YYYY-MM-DD` – Return VAT report.
* `GET /v1/{org}/saft/export?from=YYYY-MM-DD&to=YYYY-MM-DD` – Export data as SAF‑T (DK) XML.
* `POST /v1/{org}/admin/backup` – Run database and uploads backup (admin only).
* `GET /v1/{org}/invoices/{id}/pdf` – Download encrypted PDF of invoice.

See `invoiceRoutes.php` for invoice CRUD endpoints.