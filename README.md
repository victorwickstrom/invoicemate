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
Autentisering sker via JSON Web Tokens (JWT).  För att kunna kalla
de flesta endpoints behöver du först skapa en användare och sedan
logga in för att erhålla en token.

### Skapa användare

Första användaren i en organisation kan skapas utan autentisering.  När
användare redan finns krävs att den som skapar nya användare har
rollen `admin`.

```
POST /v1/{organizationId}/users

{
  "username": "admin",
  "password": "hemligt",
  "role": "admin"
}
```

### Logga in

```
POST /login

{
  "username": "admin",
  "password": "hemligt"
}
```

Svaret innehåller ett JWT (under `token`) som du sedan skickar i
`Authorization`‑headern:

```
Authorization: Bearer <ditt_jwt>
```

JWT:n innehåller `user_id`, `organization_id` och `role` och gäller i
24 timmar.  Alla requests förutom användarskapande och
inloggning kräver giltig token.  Middleware säkerställer att
tokenorganisationen matchar den organisation som anges i URL:en och
att användaren har rätt roll (t.ex. endast admin får bokföra,
radera eller ta backup).

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

Det finns även stöd för att exportera bokföringsdata i SAF‑T‑format. Endpoints låter dig hämta en XML‑fil med kontoplan, journalposter och kontakter för en organisation.  Den exporterade filen valideras mot ett XSD‑schema innan den returneras:

```
GET /v1/{organizationId}/saft/export
```

Valfria query‑parametrar:

* **from**: Startdatum i formatet `YYYY‑MM‑DD`.
* **to**: Slutdatum i formatet `YYYY‑MM‑DD`.
* **year**: Fyra‑siffrigt år (t.ex. `2024`).  Om både `from` och
  `to` utelämnas och `year` anges filtreras exporterade poster till
  hela det angivna året.  Parametern `year` översätts automatiskt till
  `from=YYYY‑01‑01` och `to=YYYY‑12‑31`.

Om du anger `from` och/eller `to` filtreras exporterade poster till att
endast omfatta det angivna datumintervallet.  Med parametern
`year` kan du enkelt exportera ett helt räkenskapsår.  API:et bygger
en SAF‑T‑fil, validerar den mot XSD och returnerar den som en
nedladdningsbar bilaga.

Exempel:

```bash
# Ladda ner SAF‑T‑fil för hela året 2024 via year‑parametern
curl -o saft_2024.xml "http://localhost:8080/v1/123/saft/export?year=2024"

# Ladda ner SAF‑T‑fil för en specifik period
curl -o saft_period.xml "http://localhost:8080/v1/123/saft/export?from=2024-01-01&to=2024-03-31"
```

## 📁 Migrations

Ytterligare tabeller finns i `migrations/`. Exempelvis skapas en revisionsloggtabell med:

```bash
sqlite3 database.sqlite < migrations/2025_08_05_audit_log.sql
```

## 🔐 Filkryptering och backup

Alla filer som laddas upp via API:et (såväl bilagor som övriga filer)
krypteras på servern innan de sparas.  Krypteringen använder
AES‑256‑CBC och en nyckel som hämtas från miljövariabeln
`FILE_ENCRYPTION_KEY`.  Om den inte är satt används en statisk
utvecklingsnyckel.  Vid nedladdning dekrypteras filen och returneras
i klartext.  Endast autentiserade användare med tillgång till rätt
organisation kan hämta sina filer.

Exempel på att sätta krypteringsnyckeln och JWT‑hemlighet i ett
Unix‑shell:

```bash
export FILE_ENCRYPTION_KEY="superhemlignyckel1234567890"
export JWT_SECRET="myjwtsecret"
```

Backup‑endpoints finns under `/v1/{organizationId}/admin/backup` och
kräver att du är inloggad med rollen `admin`.  Följande operationer
stödjs:

- `POST /v1/{organizationId}/admin/backup/db` – tar en backup av
  SQLite‑databasen och roterar äldre backupper (behåller de fem
  senaste).
- `POST /v1/{organizationId}/admin/backup/uploads` – skapar en zip
  med alla uppladdade filer (i krypterad form) och lagrar den i
  backups‑katalogen.  Endast de tre senaste zip‑filerna sparas.
