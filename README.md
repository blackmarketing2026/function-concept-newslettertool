# Email Marketing System

Selbstentwickeltes, eigenstaendiges Email-Marketing-Tool fuer Shared Hosting.
Vollstaendige Spezifikation: [DEVELOPMENT_1.md](DEVELOPMENT_1.md).

PHP 8.2+/8.3, MySQL 8.0+/MariaDB 10.6+, Queue-basierter Versand ueber Cronjobs,
DKIM/SPF/DMARC, DSGVO-konform (Double Opt-in, Audit-Log, Recht auf Loeschung),
Tag-basierte Segmentierung als zentrales Steuerungselement.

## Setup

```bash
composer install
cp .env.example .env
# .env mit echten DB-/SMTP-Zugangsdaten ausfuellen

php setup-db.php          # Datenbankschema anlegen
php verify-hosting.php    # Hosting-Voraussetzungen pruefen
```

### Admin-Zugang anlegen

```bash
php -r "echo password_hash('dein-passwort', PASSWORD_DEFAULT), PHP_EOL;"
# Ergebnis als ADMIN_PASSWORD_HASH in .env eintragen
```

Danach: `https://deine-domain.de/admin/` (Login mit `ADMIN_USERNAME` / dem Klartext-Passwort).

### DKIM einrichten

```bash
php -r "require 'vendor/autoload.php'; require 'src/Support/Bootstrap.php';
\$app = App\Support\Bootstrap::boot();
\$signer = App\Email\DkimSigner::fromConfig(\$app->appConfig());
\$result = \$signer->generateKeyPair();
echo \$result['public_key_dns'], PHP_EOL;"
```

Den ausgegebenen Wert als TXT-Record `<selector>._domainkey.<domain>` im DNS
veroeffentlichen (Selector/Domain siehe `DKIM_SELECTOR` / `DKIM_DOMAIN` in `.env`).

## Cronjobs (Server-Crontab)

```bash
*/5 * * * *  /usr/bin/php /pfad/zu/app/cli/queue-worker.php send --batch=50 >> logs/send.log 2>&1
*/30 * * * * /usr/bin/php /pfad/zu/app/cli/queue-worker.php retry >> logs/retry.log 2>&1
0 2 * * *    /usr/bin/php /pfad/zu/app/cli/queue-worker.php bounce-handler >> logs/bounce.log 2>&1
0 3 * * 0    /usr/bin/php /pfad/zu/app/cli/queue-worker.php cleanup --days=30 >> logs/cleanup.log 2>&1
0 * * * *    /usr/bin/php /pfad/zu/app/cli/queue-worker.php health-check >> logs/health.log 2>&1
```

Lokal simulieren: `time php cli/queue-worker.php send`

## Tests

```bash
composer test                    # Unit-Tests (keine DB noetig)

# Integration-Tests (benoetigen eine MySQL-Testdatenbank):
mysql -u root -e "CREATE DATABASE email_marketing_test;"
mysql -u root email_marketing_test < database/schema.sql
TEST_DB_DATABASE=email_marketing_test TEST_DB_USERNAME=root \
  php vendor/bin/phpunit tests/Integration

# Load-Test (3.000 Emails, dauert laenger):
TEST_DB_DATABASE=email_marketing_test TEST_DB_USERNAME=root \
  php vendor/bin/phpunit --group slow tests/Integration/LoadTest.php
```

## Projektstruktur

```
cli/queue-worker.php        Cronjob-Entry-Point (send/retry/bounce-handler/cleanup/health-check)
config/                     app.php, database.php, email-queue.php
database/schema.sql         Vollstaendiges DB-Schema (18 Tabellen)
public/admin/               Vanilla-PHP Admin-GUI (Dashboard, Tags, Kontakte, Kampagnen, Newsletter, Templates, Einstellungen)
public/api/                 REST-API (Bearer-Token Auth)
public/track/               Open-/Click-Tracking
public/unsubscribe.php      1-Click Unsubscribe (RFC 8058)
src/Queue/                  QueueWorker, Mailer (PHPMailer-Wrapper)
src/Email/                  DkimSigner, HeaderManager, ComplianceValidator, BounceHandler
src/Tags/                   TagManager (zentrales Steuerungselement)
src/Gdpr/                   ConsentManager (Double Opt-in, Loeschung)
src/Audit/                  AuditLogger
tests/Unit/                 Reine Logik-Tests (kein DB-Zugriff)
tests/Integration/          QueueWorkerTest, LoadTest (benoetigen Test-DB)
scripts/performance-report.php   Phase-5 Performance-Report
PRODUCTION-CHECKLIST.md     Go-Live Checkliste
```

## Naechste Schritte

Fortschritt und offene Punkte: [claude/DEVELOPMENT-PROGRESS.md](claude/DEVELOPMENT-PROGRESS.md).
