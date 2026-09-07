# Development Progress

## Current Status

**Completed Phases:**
- [x] Phase 1: Database & Infrastructure Setup (composer.json, .env.example, database/schema.sql mit 18 Tabellen, setup-db.php, verify-hosting.php)
- [x] Phase 2: Queue Worker & Cronjobs (QueueWorker, Mailer, cli/queue-worker.php, config/email-queue.php, Integration- und Load-Tests)
- [x] Phase 3: Admin API & Dashboard (REST-API mit Bearer-Auth, vanilla-PHP Admin-GUI: Dashboard, Tags, Kontakte, Kampagnen, Newsletter, Templates, Einstellungen)
- [x] Phase 4: Anti-Spam & Compliance (DkimSigner, HeaderManager, ComplianceValidator, BounceHandler, AuditLogger, ConsentManager)
- [x] Phase 5 (teilweise): LoadTest.php, scripts/performance-report.php, PRODUCTION-CHECKLIST.md

## Was wurde gebaut

- Vollstaendiges DB-Schema (`database/schema.sql`): contacts, tags, contact_tags, tag_rules, templates,
  email_accounts, campaigns, campaign_steps, email_queue, email_queue_log, email_events,
  suppression_list, consent_log, audit_log, contact_imports, webhooks, api_tokens, settings.
- Queue-basierter Versand mit Rate-Limiting, Engagement-Tier-Sortierung (hot/warm/cold),
  Retry-Backoff (sofort/1h/6h), Circuit Breaker bei >30% Fehlerrate, Lock-File gegen
  ueberlappende Cronjob-Laeufe, automatische Recovery von "stuck" processing-Eintraegen.
- DKIM-Signierung (RSA-Keypair-Generierung + PHPMailer-Integration), List-Unsubscribe
  Header (RFC 8058 One-Click), Pre-Send Compliance-Validierung (Spam-Keywords,
  Impressum/Datenschutz-Pflicht, Unsubscribe-Link-Pflicht).
- Tag-System als zentrales Steuerungselement: manuelles + automatisches Tagging,
  vollstaendiger Audit-Trail pro Tag-Aenderung, AND/OR/NOT Audience-Resolution fuer
  Kampagnen (`TagManager::resolveAudience()`).
- DSGVO: Double-Opt-in-Flow, Consent-Log, 1-Click-Unsubscribe, "Recht auf Loeschung"
  (`ConsentManager::eraseContact()`), Audit-Log mit konfigurierbarer Retention.
- REST-API (`public/api/`) fuer Contacts (CRUD, CSV Import/Export, Tags), Campaigns
  (CRUD, Send, Audience-Preview, Stats) und Templates (CRUD, Preview).
- Admin-GUI (`public/admin/`, vanilla PHP + Bootstrap 5 CDN, Session-Login): Dashboard
  mit KPIs/Queue-Health, Tag-Verwaltung, Kontakt-Liste mit CSV-Import, Kampagnen-Uebersicht
  mit Live-Stats, 3-Schritt Newsletter-Quick-Send, Template-Editor mit Preview,
  Einstellungen (Email-Konten, Webhooks, Compliance-URLs, Audit-Log).
- Tracking: Open-Pixel (`public/track/open.php`), Click-Redirect mit Open-Redirect-Schutz
  (`public/track/click.php`).
- Tests: `tests/Unit/` (ComplianceValidator, Router, DkimSigner - keine DB noetig),
  `tests/Integration/QueueWorkerTest.php` (100-Email-Szenario, Hard/Soft-Bounce,
  Retry-Backoff, Suppression, Health-Check-Recovery - benoetigt MySQL-Testdatenbank
  via `TEST_DB_*` Env-Vars), `tests/Integration/LoadTest.php` (3.000 Emails, Gruppe "slow").

## Naechste Schritte (offen aus Phase 3-6)

1. **Kampagnen-Builder fuer Sequenzen**: `campaign_steps` Tabelle existiert im Schema,
   aber kein GUI/API-Endpoint fuer mehrstufige Sequenzen mit Delays (nur Quick-Send
   Newsletter ist fertig implementiert).
2. **Auto-Tagging Rule Engine**: `tag_rules` Tabelle existiert im Schema, aber kein
   Cronjob/Worker, der die Trigger (z.B. "email_not_opened_days: 90") tatsaechlich
   auswertet und Tags automatisch setzt/entfernt.
3. **Webhook-Dispatching**: `webhooks` Tabelle + Admin-GUI zum Anlegen existieren,
   aber kein tatsaechlicher HTTP-Dispatch bei Events (email.sent, contact.unsubscribed, ...).
4. **Link-Rewriting fuer Click-Tracking**: `public/track/click.php` ist fertig, aber
   beim Queue-Befuellen (`CampaignController::send()`, `newsletter.php`) werden Links
   im Template-HTML noch nicht automatisch zu Tracking-Links umgeschrieben.
5. **API-Token-Verwaltung im GUI**: `api_tokens` Tabelle existiert, aktuell wird nur
   der einzelne `API_TOKEN` aus `.env` fuer Bearer-Auth genutzt (kein Multi-Token-UI).
6. **SMTP-Passwort-Verschluesselung**: `email_accounts.smtp_password_encrypted` wird
   aktuell mit `password_hash()` befuellt (nicht umkehrbar!) - fuer echten SMTP-Versand
   ueber mehrere Konten muss hier eine reversible Verschluesselung (z.B. `openssl_encrypt`
   mit `APP_KEY`) statt Hashing verwendet werden, bevor Mehrfach-Konten produktiv nutzbar sind.
7. **Composer install**: `vendor/` wurde in dieser Session nicht generiert (kein
   Internetzugriff/Composer-Lauf durchgefuehrt). Vor dem ersten Test-/Produktivlauf:
   `composer install` ausfuehren.
8. **CI-Pipeline**: keine GitHub Actions o.ae. eingerichtet fuer automatisches
   `phpunit` + `phpstan` bei jedem Push.

## Files to Read on Next Session

- `src/Queue/QueueWorker.php` (Kernlogik, Ausgangspunkt fuer Sequenzen/Rule-Engine)
- `database/schema.sql` (campaign_steps, tag_rules, webhooks - Tabellen fuer offene Punkte)
- `src/Support/Bootstrap.php` (Service-Container, hier neue Services registrieren)
