# Production Checklist

Vor dem Go-Live alle Punkte abhaken (siehe DEVELOPMENT_1.md Phase 5).

## Security

- [ ] `.env` existiert auf dem Server und ist NICHT im Web-Root oeffentlich erreichbar (ausserhalb `public/` oder per `.htaccess` geschuetzt)
- [ ] `APP_DEBUG=false` in Produktion
- [ ] `ADMIN_PASSWORD_HASH` gesetzt (mit `password_hash()`, nicht Klartext)
- [ ] `API_TOKEN` ist ein zufaelliger, langer String (>= 32 Zeichen)
- [ ] HTTPS erzwungen (SSL-Zertifikat aktiv, HTTP -> HTTPS Redirect)
- [ ] `setup-db.php` nach erfolgreichem Setup vom Server geloescht oder umbenannt
- [ ] `storage/dkim/private.key` hat Dateirechte 600 und ist ausserhalb des Web-Roots oder per `.htaccess` blockiert
- [ ] CORS-Header in `public/api/index.php` auf erlaubte Origins eingeschraenkt (falls Frontend extern)
- [ ] SQL-Injection: alle Queries nutzen Prepared Statements (durchgaengig der Fall in diesem Codebase)
- [ ] Session-Cookies: `session.cookie_secure=1`, `session.cookie_httponly=1` in `php.ini` bzw. `.htaccess`

## Performance

- [ ] Alle Indizes aus `database/schema.sql` sind angelegt (per `setup-db.php` automatisch)
- [ ] OPcache aktiv auf dem Hosting (PHP 8.3 empfohlen)
- [ ] `composer install --no-dev --optimize-autoloader` fuer Produktion ausgefuehrt
- [ ] Queue-Batch-Groesse (`batch_size`) an ISP-Limits des SMTP-Providers angepasst
- [ ] `scripts/performance-report.php` einmal ausgefuehrt, EXPLAIN-Plaene zeigen Index-Nutzung (kein "KEIN INDEX!")

## Monitoring

- [ ] Alle 5 Cronjobs aktiv und laufen fehlerfrei (siehe DEVELOPMENT_1.md Cronjob-Schedule)
- [ ] `ALERT_EMAIL` in `.env` gesetzt und Test-Alert erfolgreich empfangen
- [ ] Log-Rotation fuer `logs/app.log` eingerichtet (oder `cleanup`-Cronjob laeuft zuverlaessig)
- [ ] Health-Check-Endpoint `/api/health` von externem Uptime-Monitor ueberwacht

## Backups

- [ ] Automatisiertes taegliches MySQL-Dump-Backup eingerichtet (z.B. `mysqldump` per Cronjob)
- [ ] Backup-Retention definiert (empfohlen: 30 Tage rollierend)
- [ ] Restore-Prozess mindestens einmal getestet

## Deliverability / Anti-Spam

- [ ] SPF-Record im DNS gesetzt
- [ ] DKIM-Key generiert (`DkimSigner::generateKeyPair()`) und Public Key im DNS als TXT-Record veroeffentlicht
- [ ] DMARC-Policy im DNS gesetzt (`p=quarantine` oder staerker)
- [ ] Test-Email ueber mail-tester.com gesendet, Score >= 8/10
- [ ] Impressum- und Datenschutz-URL in Einstellungen hinterlegt

## DSGVO

- [ ] Double Opt-in aktiv (`DOUBLE_OPT_IN=true`)
- [ ] Audit-Log-Retention konfiguriert (`AUDIT_LOG_RETENTION_MONTHS`)
- [ ] "Recht auf Loeschung"-Prozess getestet (`ConsentManager::eraseContact()`)
- [ ] Unsubscribe-Link funktioniert in jeder Test-Email (1-Click + Footer-Link)

## Go-Live

- [ ] `verify-hosting.php` zeigt ausschliesslich ✅
- [ ] Test-Kampagne an interne Test-Liste (5-10 Adressen) erfolgreich versendet
- [ ] Live-Tracking Dashboard zeigt korrekte Zahlen waehrend des Test-Versands
- [ ] `git tag -a v1.0.0` gesetzt
