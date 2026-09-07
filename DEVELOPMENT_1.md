# Email Marketing System - Technical Development Documentation

**Projekt:** Email Marketing MD Programm  
**Version:** 1.0 - Complete Specification  
**Datum:** 2026-09-07  
**Status:** 🟢 Ready for Development  
**Zielgruppe:** Entwickler, Architekten, DevOps

---

## 📑 Inhaltsverzeichnis

1. [Projektübersicht](#projektübersicht)
2. [Tech-Stack & Infrastruktur](#tech-stack--infrastruktur)
3. [Kernarchitektur](#kernarchitektur)
4. [Anti-Spam & Deliverability](#anti-spam--deliverability)
5. [GUI & Features](#gui--features)
   - [GUI-Struktur](#gui-struktur)
   - [Tag-System (Kern)](#tag-system-kernparadigma)
   - [Newsletter-Versand](#newsletter-versand)
   - [Kampagnen-Builder](#kampagnen-builder)
   - [Kampagnen-Statistiken](#kampagnen-statistiken)
   - [Einstellungen & Compliance](#einstellungen--compliance)
   - [Kontakt Import/Export](#kontakt-importexport)
6. [Datenmodell & Datenbankschema](#datenmodell--datenbankschema)
7. [API-Spezifikationen](#api-spezifikationen)
8. [Implementierungsfahrplan](#implementierungsfahrplan)

---

## Projektübersicht

### Zweck & Anforderungen

**Email Marketing System** für Shared Hosting – ein selbstentwickeltes, eigenständiges Tool zur Verwaltung und zum Versand von E-Mails. Das System wird initial bei einem All-Inclusive Domainprovider installiert und für 1.000–3.000 Emails/Monat optimiert, mit Skalierungspotenzial auf 100.000+/Monat.

**Kernanforderungen:**
- ✅ **Mega stabil** – höchste Zuverlässigkeit & Fehlertoleranz
- ✅ **Eigenständig** – komplette Kontrolle über Code & Infrastruktur
- ✅ **Skalierbar** – von 1.000 auf 10.000+ Emails ohne Rewrite
- ✅ **DSGVO-konform** – Double Opt-in, Audit Logs, Datenschutz
- ✅ **Performance-optimiert** – für Shared Hosting mit begrenzten Ressourcen

---

## Tech-Stack & Infrastruktur

### Hosting & Umgebung

| Aspekt | Anforderung |
|--------|-------------|
| **Plattform** | All-Inclusive Domainprovider (beliebige PHP-Version) |
| **PHP** | **PHP 8.3+** (oder 8.2 als Fallback) |
| **Datenbank** | MySQL 8.0+ oder MariaDB 10.6+ |
| **Speicher** | Unbegrenzt (hosterabhängig) |
| **Cronjobs** | ✅ Muss verfügbar sein (5-Minuten-Intervalle) |
| **SSH Access** | Empfohlen für Setup & Debugging |
| **SMTP** | Externe (Mailgun, SendGrid) oder Lokal |

### Warum PHP 8.3?

**Technische Vorteile:**
- 🚀 JIT Compiler für 20–30% bessere Performance
- 🛡️ Type Safety mit Readonly Properties & Constructor Promotion
- ⚡ Fibers für asynchrone Operations
- 🔒 Verbesserte Security Defaults
- 📦 Besseres Error Handling mit Named Arguments
- 🗑️ Optimiertes Memory Management
- ✅ LTS bis 2026+ – guter langfristiger Support

**Performance-Implikation:** Schnellere Versand-Durchläufe, bessere Ressourcennutzung auf Shared Hosting, zuverlässigere Cronjobs.

### Dependencies (Composer)

```json
{
  "require": {
    "php": "^8.2",
    "monolog/monolog": "^3.0",
    "vlucas/phpdotenv": "^5.0",
    "phpmailer/phpmailer": "^6.8",
    "symfony/console": "^6.0",
    "symfony/process": "^6.0",
    "phpstan/phpstan": "^1.10"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.0",
    "mockery/mockery": "^1.6"
  }
}
```

---

## Kernarchitektur

### Queue-basierter Email-Versand

**Problem:** Shared Hosting kann nicht 10.000 Emails gleichzeitig versenden.  
**Lösung:** Datenbank-Queue + Cronjob-Worker mit Rate Limiting

#### Versand-Workflow

```
1. Admin erstellt Campaign / Newsletter
   ↓
2. Kontakte werden in Queue-Tabelle eingefügt (pending)
   ↓
3. Cronjob startet alle 5 Minuten
   ↓
4. Worker liest pending Emails aus Queue (Status: processing)
   ↓
5. Versendet 50 Emails pro Durchlauf (konfigurierbar)
   ↓
6. Aktualisiert Status (sent/failed/bounced)
   ↓
7. Retry-Logic für fehlgeschlagene Emails (max. 3x)
   ↓
8. Bounce/Complaint Handling integriert
```

#### Email Queue Tabelle

```sql
CREATE TABLE email_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html LONGTEXT,
    body_text TEXT,
    template_id INT,
    sender_email VARCHAR(255),
    
    -- Status Tracking
    status ENUM('pending', 'processing', 'sent', 'failed', 'bounced', 'complained') DEFAULT 'pending',
    attempt_count INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    next_retry DATETIME,
    sent_at DATETIME,
    error_message TEXT,
    
    -- Tracking IDs für Analytics
    tracking_id VARCHAR(64) UNIQUE,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- KRITISCHE INDIZES (Performance!)
    KEY idx_status_retry (status, next_retry),
    KEY idx_campaign (campaign_id),
    KEY idx_created (created_at),
    KEY idx_email (recipient_email),
    KEY idx_tracking (tracking_id),
    
    FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Email Queue Log Tabelle (Debugging & Monitoring)

```sql
CREATE TABLE email_queue_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    queue_id BIGINT NOT NULL,
    event ENUM('queued', 'started', 'sent', 'failed', 'bounced', 'retrying', 'complained') NOT NULL,
    details JSON,
    error_code VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    KEY idx_queue (queue_id),
    KEY idx_event (event),
    KEY idx_created (created_at),
    
    FOREIGN KEY(queue_id) REFERENCES email_queue(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Cronjob-Schedule

**5 spezialisierte Cronjobs für verschiedene Aufgaben:**

```bash
# Cronjob #1: Hauptversand (alle 5 Minuten)
*/5 * * * * /usr/bin/php /path/to/app/cli/queue-worker.php send --batch=50 >> /var/log/email-queue-send.log 2>&1

# Cronjob #2: Retry fehlgeschlagener Emails (alle 30 Minuten)
*/30 * * * * /usr/bin/php /path/to/app/cli/queue-worker.php retry >> /var/log/email-queue-retry.log 2>&1

# Cronjob #3: Bounce/Complaint Handling (täglich um 2 Uhr)
0 2 * * * /usr/bin/php /path/to/app/cli/queue-worker.php bounce-handler >> /var/log/bounce-handler.log 2>&1

# Cronjob #4: Cleanup alte Logs (wöchentlich)
0 3 * * 0 /usr/bin/php /path/to/app/cli/queue-worker.php cleanup --days=30 >> /var/log/cleanup.log 2>&1

# Cronjob #5: Health Check (stündlich)
0 * * * * /usr/bin/php /path/to/app/cli/queue-worker.php health-check >> /var/log/health-check.log 2>&1
```

### Versand-Parameter

```php
// config/email-queue.php
return [
    // Versand-Konfiguration
    'batch_size' => 50,                    // 50 Emails pro Cronjob-Durchlauf
    'max_retries' => 3,                    // 3 Versuche bevor permanent failed
    'retry_delay' => 3600,                 // 1 Stunde warten vor Retry
    'max_concurrent_sending' => 1,         // Seriell (nicht parallel)
    'send_timeout' => 30,                  // 30 Sekunden Timeout pro Email
    
    // Rate Limiting (ISP-Grenzen beachten)
    'rate_limit' => [
        'per_minute' => 50,                // Max 50 Emails/Minute
        'per_hour' => 1000,                // Max 1.000 Emails/Stunde
        'per_day' => 5000,                 // Max 5.000 Emails/Tag
    ],
    
    // Engagement-basiertes Versenden
    'engagement_tier_order' => [
        'hot' => 30,                       // Letzte 30 Tage aktiv → zuerst
        'warm' => 90,                      // Letzte 90 Tage aktiv
        'cold' => 999,                     // Älter → zuletzt
    ],
    
    // Monitoring & Alerts
    'monitoring' => [
        'alert_threshold_failures' => 0.1, // Alert wenn >10% fehlschlagen
        'check_interval' => 300,           // Health Check alle 5 Minuten
        'alert_email' => 'admin@example.com'
    ]
];
```

### Error Handling & Resilience

**Graceful Degradation:**
- Wenn SMTP fehlt → Emails in DB speichern, später versuchen
- Wenn Cronjob abends abgebrochen → automatisch am nächsten Morgen fortsetzen
- Circuit Breaker Pattern → bei >30% Fehlerrate abbrechen & monitoren

**Retry Logic:**
```
Attempt 1: Sofort
Attempt 2: Nach 1 Stunde (3600s)
Attempt 3: Nach 6 Stunden (21600s)
Final Fail: Status = 'failed', Admin benachrichtigen
```

---

## Anti-Spam & Deliverability

### SPF / DKIM / DMARC (DNS-Level)

Das System MUSS automatisch DNS-Record-Generierung und -Validierung unterstützen:

```dns
SPF Record:
v=spf1 include:sendgrid.net ~all

DKIM Signing:
Privater Key lokal speichern
Jede Email mit DKIM-Signatur versehen
Public Key im DNS veröffentlichen

DMARC Policy:
v=DMARC1; p=quarantine; rua=mailto:admin@domain.de
```

### Required Email Headers

**Diese Header MÜSSEN in jeder Email vorhanden sein:**

```
List-Unsubscribe: <https://example.com/unsubscribe?token=XXX>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
DKIM-Signature: v=1; a=rsa-sha256; ...
X-Mailer: Email-Marketing-System/1.0
X-Priority: 3 (Normal)
Return-Path: bounce-handler@example.com
```

### Content-Validierung vor Versand

Vor dem Versand MUSS folgendes geprüft werden:

```php
Validierungen:
- ✅ Legitimer Unsubscribe Link vorhanden?
- ✅ Keine Spam-Keywords? (viagra, casino, etc.)
- ✅ From/Reply-To Header korrekt gesetzt?
- ✅ Keine verdächtigen/verkürzten Links?
- ✅ DKIM signiert?
- ✅ List-Unsubscribe Header gesetzt?
- ✅ Footer mit Impressum & Datenschutz vorhanden?
- ✅ Recipient nicht auf Suppression List?
```

### Engagement-basiertes Versenden

**Segmentierung nach Engagement-Level (verringert Bounce-Rate):**

1. **Hot Kontakte:** Letzte 30 Tage aktiv (geöffnet/geklickt) → ZUERST versenden
2. **Warm Kontakte:** Letzte 90 Tage aktiv → ZWEITE Gruppe
3. **Cold Kontakte:** Inaktiv >90 Tage → LETZTE Gruppe

→ ISP-Limits beachten & Sender-Reputation schützen

### Bounce & Complaint Management

```php
// Bounce Handling
switch($bounce_type) {
    case 'soft':
        // Vorübergehend – Retry nach 1h, 6h, 24h
        enqueue_retry($queue_id, 'soft_bounce');
        break;
        
    case 'hard':
        // Permanent (Invalid, Rejected, etc.)
        mark_suppressed($email, 'hard_bounce');
        update_contact_status($email, 'bounced');
        break;
}

// Complaint Handling (Spam-Report)
if ($complaint_detected) {
    mark_suppressed($email, 'complained');
    update_contact_status($email, 'complained');
    log_audit('Spam complaint received', $email);
    // NICHT erneut kontaktieren!
}

// Monitoring KPIs
- Bounce Rate < 3% (ideal: < 1%)
- Complaint Rate < 0.1% (Gesetz)
- Monitore ISP-Limits pro Domain/IP
```

### DSGVO Compliance

- ✅ **Double Opt-in** – erzwingbar per Setting
- ✅ **Audit Log** – Wer, Wann, Welche Email, Welche Action
- ✅ **Consent Tracking** – Zustimmung & Widerspruch speichern
- ✅ **Einfache Abmeldung** – 1-Click Unsubscribe in jedem Mail-Footer
- ✅ **Datenschutzerklärung** – Verlinkt in jedem Mail-Footer
- ✅ **Impressum** – In jedem Mail-Footer (rechtlich bindend!)
- ✅ **Newsletter-Kennzeichnung** – Auto-erkannt via Tags
- ✅ **Recht auf Löschung** – Datensatz löschen auf Anfrage
- ✅ **Audit Trail** – 24 Monate speichern

---

## GUI & Features

### GUI-Struktur

**7 Hauptabschnitte im Admin Dashboard:**

```
Dashboard (Übersicht & KPIs)
├── Aktuelle Versend-Statistiken
├── Queue-Status & Cronjob Health
├── Letzte Kampagnen Performance
└── Warnungen & Fehler

Tags & Segmente (ZENTRALE STEUERUNG)
├── Tag-Management (Erstellen, Bearbeiten, Löschen)
├── Auto-Tagging Regeln
├── Tag-basierte Segmente
└── Tag-Lifecycle Tracking

Kontakte
├── Kontakt-Liste (Suche, Filter, Bulk-Actions)
├── Kontakt-Details (Profil, History, Tags)
├── CSV Import (Wizard mit Preview)
└── CSV Export (Flexible Spaltenauswahl)

Kampagnen
├── Kampagnen-Übersicht (Status, Performance)
├── Kampagnen-Editor (Email-Sequenzen, Delays)
├── Kampagnen-Statistiken (Per-Email Tracking)
└── Campaign Report (Detaillierte Analyse)

Newsletter (Quick-Send)
├── Template Auswahl
├── Tag-basierte Empfänger-Auswahl
├── Send Options (Schedule, Rate Limit)
└── Live-Tracking Dashboard

Templates
├── Template-Liste (Übersicht)
├── Template-Editor (HTML/Text, Variablen)
├── Vorschau (Desktop/Mobile)
└── Variable Manager ({{first_name}}, {{company}}, etc.)

Einstellungen
├── Email-Konten verwalten (SMTP, Absender)
├── Datenschutzerklärung & Impressum
├── Footer-Vorlagen (Auto-Generated)
├── Webhook-Konfiguration
├── API Keys & Token
└── Audit Log
```

### Tag-System (Kernparadigma)

**Tags sind das zentrale Steuerungselement des Systems** – nicht nur für Organisationen, sondern für Automatisierung, Kontrolle und alle Entscheidungen.

#### Tag-Lifecycle

```
1. TAGGING (Kontakt erhält Tag)
   - Manuell via UI
   - Automatisch via Rule/Trigger
   - Via Import-Prozess
   ↓
2. TAG ACTIVE (Tag wirkt sich aus)
   - Trigger: Automation starten
   - Control: Newsletter-Versand steuern
   - Suppress: Kontakt ausschließen
   ↓
3. UNTAGGING (Tag wird entfernt)
   - Manuell via UI
   - Automatisch via Rule
   - Zeit-basiert (z.B. nach 30 Tagen inaktiv)
   ↓
4. TAG AUDIT (Alles geloggt)
   - Wer hat Tag hinzugefügt/entfernt
   - Wann & Warum
   - Automatisch oder manuell
```

#### Tag-Typen & Beispiele

**1. Newsletter-Tags**
```
- newsletter-subscriber     # Basis: Newsletter abonniert
- newsletter-active         # In den letzten 30 Tagen aktiv
- newsletter-cold           # Inaktiv > 90 Tage
- newsletter-unsubscribed   # Abgemeldet
```

**2. Segment-Tags**
```
- segment:vip              # VIP Kunden
- segment:trial            # Trial-User
- segment:free             # Kostenlose Nutzer
- segment:premium          # Premium Kunden
```

**3. Engagement-Tags**
```
- engaged:high             # >5 Öffnungen/Monat
- engaged:medium           # 2-5 Öffnungen/Monat
- engaged:low              # <2 Öffnungen/Monat
- engaged:unengaged        # Nie geöffnet
```

**4. Status-Tags**
```
- status:hard-bounce       # Hard Bounce (Invalid)
- status:soft-bounce       # Soft Bounce (Temporarily Rejected)
- status:complained        # Spam-Beschwerde
- status:unconfirmed       # Double Opt-in nicht bestätigt
- status:suppressed        # Auf Suppression List
```

**5. Campaign-Tags**
```
- campaign:product-launch  # Teilnehmer Produkteinführung
- campaign:webinar         # Webinar Angemeldet
- campaign:ebook           # E-Book heruntergeladen
```

**6. Custom Tags**
```
- customer:premium         # Premium Kunde
- customer:bulk-buyer      # Großabnehmer
- industry:tech            # Industrie: Tech
- location:de              # Standort: Deutschland
```

#### Tag-Trigger-Beispiele

```
AUTOMATISCHES TAGGING:

Trigger: Email geöffnet
└→ Aktion: Tag "engaged:high" hinzufügen

Trigger: Email NICHT geöffnet nach 90 Tagen
└→ Aktion: Tag "engaged:unengaged" hinzufügen

Trigger: Newsletter abgemeldet
└→ Aktion: Tags entfernen: newsletter-subscriber, newsletter-active
└→ Aktion: Tag "newsletter-unsubscribed" hinzufügen

Trigger: Hard Bounce empfangen
└→ Aktion: Tag "status:hard-bounce" hinzufügen
└→ Aktion: Auf Suppression List (kein Versand)

Trigger: Spam-Beschwerde
└→ Aktion: Tag "status:complained" hinzufügen
└→ Aktion: Sofort auf Suppression List
└→ Aktion: Audit Log: "Complaint received"
```

### Newsletter-Versand

**One-Click Manual Newsletter zu Tag-basierten Gruppen**

#### 3-Schritt Workflow

**Schritt 1: Template Auswahl**
```
- Dropdown mit allen verfügbaren Templates
- Preview (Desktop/Mobile)
- Subject Line & Preview Text
- Sender Email Auswahl (welches Email-Konto?)
```

**Schritt 2: Tag-basierte Empfänger Auswahl**
```
- Multi-Select Tag Filter
- Operatoren: AND, OR, NOT
- Beispiel: "newsletter-subscriber AND engaged:high AND NOT status:hard-bounce"
- Live-Vorschau: "X Kontakte werden erreicht"
- Segmentation nach Engagement-Tier (Hot/Warm/Cold)
```

**Schritt 3: Versend-Optionen**
```
- [ ] Sofort versenden
- [ ] Zeitgesteuert versenden (Datum/Uhrzeit)
- [ ] Rate Limiting: X Emails/Minute (default: 50)
- [ ] Engagement-Tier-Versand (Hot zuerst)
- [ ] Test-Email an Admin vor Versand
- Bestätigung: "Send Newsletter to 5.234 contacts?"
```

#### Live-Tracking Dashboard

**Während des Versands:**

```
Newsletter Versand: 5.234 Empfänger
├── Status
│   ├── Versendet: 2.451 (47%)
│   ├── In Queue: 2.783 (53%)
│   ├── Fehlgeschlagen: 15 (0.3%)
│   └── ETA: 15 Minuten (basierend auf 50/min)
├── Fehler
│   ├── Bounce: 2 (sofort auf Suppression List)
│   ├── SMTP Error: 13 (Retry nach 1h)
│   └── Invalid Email: 0
└── Live Graph
    ├── Versand-Rate (Emails/min)
    ├── Fehler-Rate (%)
    └── Queue-Länge
```

#### Post-Send Report

```
Newsletter Report: "Product Update August 2026"
├── Allgemein
│   ├── Versendet: 5.234 Emails
│   ├── Zugestellt: 5.219 (99.7%)
│   ├── Abgesprungen: 15 (0.3%)
│   └── Versand-Dauer: 2h 18min
├── Engagement
│   ├── Geöffnet: 1.248 (23.8%)
│   ├── Geklickt: 342 (6.5%)
│   ├── Abgemeldet: 12 (0.2%)
│   └── Spam-Beschwerde: 1 (0.02%)
├── Nach Tags
│   ├── engaged:high → 67% Öffnungsrate
│   ├── engaged:medium → 21% Öffnungsrate
│   ├── engaged:low → 8% Öffnungsrate
│   └── engaged:unengaged → 2% Öffnungsrate
└── Time-of-Open
    └── Graphik: Öffnungszeiten über den Tag verteilt
```

---

## 🚀 Development Workflow mit Claude Code & VS Code

### Collaborative Development Strategy (Token-Effizient)

Dieses Kapitel beschreibt, wie Claude Code / VS Code Agent mit Steven effizient zusammenarbeitet, um das System aufzubauen – mit minimaler Token-Verschwendung und maximalem Output.

### Grundprinzipien

**1. Modular-First Architektur**
- Jede Phase ist eigenständig, ohne externe Abhängigkeiten
- Jede Datei/Modul hat einen klaren Zweck
- Keine Verzweigungen oder Blockers zwischen Phasen

**2. Token-Sparend Arbeiten**
- Code wird DIREKT in Git committed, nicht im Chat diskutiert
- Nur fertige, lauffähige PRs/Commits
- Keine umfangreichen Code-Reviews in der Chat – Autotests prüfen
- Minimale Kontextübergaben zwischen Sessions

**3. Asynchrones Development**
- Sessions können unterbrochen & fortgesetzt werden
- Alle Decisions & Fortschritt in Projekt-Docs gespeichert
- Git History = Wahrheitsquelle für Implementierungsstand

### Phase-by-Phase Development Plan

#### Phase 1: Hosting Setup & DB (Woche 1)

**Claude's Role:**
```php
// 1. Automatisiertes Setup-Skript generieren
- GenerateFile: setup-db.php (komplett mit SQL)
- GenerateFile: .env.example (Konfigurationsvorlage)
- GenerateFile: docker-compose.yml (optional für lokales Testing)

// 2. Validierungs-Script
- GenerateFile: verify-hosting.php (testet PHP 8.3, MySQL, Cronjobs)
```

**Steven's Actions:**
```
1. setup-db.php ausführen auf Hosting
2. verify-hosting.php prüft alle Requirements
3. .env editieren mit echten Credentials
4. Screenshot/Log in #dev-log hochladen
```

**Handoff:** Nach Verification → Commit mit Message:
```
[Phase 1] Database & Infrastructure Setup ✅
- MySQL Schema erstellt (4 Haupttabellen + Indizes)
- Hosting verifiziert (PHP 8.3, Cronjobs aktiv)
- .env konfiguriert

Ready for Phase 2: Core Queue System
```

---

#### Phase 2: Queue Worker & Cronjobs (Woche 2)

**Claude's Role:**
```php
// File-by-File, jede Datei ist komplett lauffähig

GenerateFile: src/Queue/QueueWorker.php
  - Main Loop: pending → processing → sent
  - SMTP Connection mit Error Handling
  - Retry Logic integriert

GenerateFile: src/Queue/Mailer.php
  - PHPMailer Wrapper
  - DKIM/SPF Header Management
  - Logging für jede Email

GenerateFile: cli/queue-worker.php
  - Entry Point für Cronjobs
  - Argument Parsing (send, retry, bounce-handler)
  - Lock Files gegen Doppel-Execution

GenerateFile: tests/QueueWorkerTest.php
  - Unit Tests für Queue Logic
  - 100 Fake-Emails Versand-Test
  - Error Scenario Tests

// Configuration
GenerateFile: config/email-queue.php
  - Alle Parameter aus Spec
  - Comments für jede Einstellung
```

**Steven's Actions:**
```
1. Alle 5 Files in /app/src, /app/cli, /app/config uploaden
2. tests/ ausführen: php vendor/bin/phpunit
3. Manueller Test: 10 Test-Emails versenden
4. Cronjob aktivieren (*/5 Minuten)
5. Nach 15 Min: Queue-Status checken
```

**Validation Checklist (in Git Commit):**
- [ ] QueueWorker versendet 50 Emails pro Run
- [ ] Fehlgeschlagene Emails landen in Retry-Queue
- [ ] Log-Dateien werden geschrieben
- [ ] Cronjob läuft ohne Fehler

---

#### Phase 3: Admin API & Dashboard (Woche 3)

**Claude's Role:**
```php
// REST API (Endpoints nach Spec)

GenerateFile: src/Api/CampaignController.php
  - POST /api/campaigns (Create)
  - GET /api/campaigns/{id} (Read)
  - PUT /api/campaigns/{id} (Update)
  - POST /api/campaigns/{id}/send (Send Queue Eintrag)

GenerateFile: src/Api/ContactController.php
  - CRUD + Search + Tags
  - POST /api/contacts/import (CSV Upload Handler)

GenerateFile: src/Api/TemplateController.php
  - CRUD + Preview Generation

GenerateFile: src/Api/AuthMiddleware.php
  - Bearer Token Validation
  - Permission Checks

// Web Frontend (Minimal, Functional First)

GenerateFile: public/admin/index.php
  - Simple Router: /campaigns, /contacts, /templates, /settings
  - No Framework (vanilla PHP + HTML)
  - Mobile-responsive Bootstrap CSS

GenerateFile: public/admin/campaigns.php
  - Campaign Tabelle (List, Edit, Send)
  - Integrates QueueWorker API

GenerateFile: public/admin/contacts.php
  - Contact Tabelle + CSV Import Form
  - Tag Management UI

// Tests
GenerateFile: tests/ApiTest.php
  - API Endpoint Tests
  - Authentication Tests
```

**Steven's Actions:**
```
1. Files deployen
2. API testen (Postman oder curl)
3. Web-Admin öffnen: https://example.com/admin
4. Campaign erstellen & versenden
5. Queue sollte automatisch verarbeitet werden
```

---

#### Phase 4: Anti-Spam & Compliance (Woche 4)

**Claude's Role:**
```php
// Email Authentication & Validation

GenerateFile: src/Email/DkimSigner.php
  - Private Key laden
  - DKIM-Signature Header erzeugen

GenerateFile: src/Email/HeaderManager.php
  - List-Unsubscribe Header
  - DMARC/SPF Hints
  - Content-Validation vor Versand

GenerateFile: src/Email/ComplianceValidator.php
  - Footer vorhanden?
  - Unsubscribe Link valid?
  - Keine Spam-Keywords?

GenerateFile: src/Email/BounceHandler.php
  - Hard Bounce Detection
  - Complaint Detection
  - Suppression List Update

// DSGVO Audit
GenerateFile: src/Audit/AuditLogger.php
  - Alle Changes loggen
  - User + Timestamp + Action

GenerateFile: src/Gdpr/ConsentManager.php
  - Double Opt-In Token Generation
  - Consent Tracking
```

**Steven's Actions:**
```
1. DKIM Key generieren (openssl commands in README)
2. DNS Records hinzufügen (SPF, DKIM, DMARC)
3. Settings eingeben: Impressum URL, Datenschutz URL
4. Test-Email versenden → Header prüfen
5. Spam Score testen mit mail-tester.com
```

---

#### Phase 5: Load Testing & Launch (Woche 5)

**Claude's Role:**
```php
// Performance Testing

GenerateFile: tests/LoadTest.php
  - 3.000 Emails in Queue einfügen
  - Performance Messungen
  - Cronjob Durchsatztest

GenerateFile: scripts/performance-report.php
  - Durchschnittliche Versand-Zeit pro Email
  - Queue durchgesetzt Statistik
  - Database Query Performance
  - Memory Usage Report

// Production Checklist
GenerateFile: PRODUCTION-CHECKLIST.md
  - Security: HTTPS, Password Hashing, CORS
  - Performance: DB Indizes, Caching
  - Monitoring: Error Alerts, Queue Monitoring
  - Backups: Database Backup Strategy
```

**Steven's Actions:**
```
1. Load Test ausführen
2. Performance Report reviewen
3. Alle Production Checklist Items abhaken
4. Backup-Strategy implementieren
5. Go Live!
```

---

### Token-Effiziente Collaboration Rules

**Für Claude Code Agent:**

```
✅ DO:
- Komplette, lauffähige Dateien generieren (kein Snippet-Code)
- Unit Tests mitliefern (für Validierung)
- Klare Commit Messages mit Checklist
- Dependencies + Installation Steps in README
- Nur auf bereits gelesene Dateien referenzieren (nicht neu lesen!)

❌ DON'T:
- Code im Chat diskutieren (generieren & committen)
- Umfangreiche Code Reviews (Autotests machen das)
- Zu viele kleine PRs (lieber größere, thematische PRs)
- Kontextübergaben erzwingen (Project Docs sind Quelle der Wahrheit)
- Datei mehrfach Read-Operationen (Caching in Session-Memory)
```

**Für Steven:**

```
✅ DO:
- Nur Feedback geben auf fertige Implementierungen
- Früh testen & Issues melden (Github Issues)
- PRs schnell Approven für Momentum
- Dokumentation / Decisions in Project Docs updaten

❌ DON'T:
- Mid-Implementation Feedback geben (blockiert Session)
- Requierements mitten in Phase ändern
- Code selbst editieren (nur Reviews + Feedback)
```

---

### Git Workflow (Minimal)

```bash
# Start of each Phase
git checkout -b phase-X-feature-name

# Per Feature/Module
git commit -m "[Phase X] Feature Name ✅
- Implementation detail 1
- Implementation detail 2
- Tests included

Validation: [checklist items]
"

# End of Phase
git push origin phase-X-feature-name
# → PR auf main mit Checklist
# → Steven approves
# → Merge + Tag
git tag -a v0.X.0 -m "Phase X Complete"
```

---

### Session Continuity (Kontextübergaben)

**Am Ende jeder Session:**

Update: `claude/DEVELOPMENT-PROGRESS.md`

```markdown
## Current Status

**Completed Phases:**
- [x] Phase 1: Database & Infrastructure (completed 2026-09-10)

**Current Work:**
- [ ] Phase 2: Queue Worker (started 2026-09-10, 40% done)
  - ✅ QueueWorker.php (done)
  - ✅ Mailer.php (done)
  - ⏳ cli/queue-worker.php (in progress)
  - ⏳ Tests (not started)

**Next Steps:**
1. Finish cli/queue-worker.php
2. Write comprehensive tests
3. Manual testing on staging

**Known Issues:**
- SMTP rate limiting needs optimization (TODO: Phase 2 refinement)
- Database migration strategy not yet defined (defer to Phase 3)

**Files to Read on Next Session:**
- src/Queue/Mailer.php (context for next work)
- config/email-queue.php
```

**Session Start-Routine:**
```
1. Read: claude/DEVELOPMENT-PROGRESS.md (Quick Status)
2. Read: Active Files (Mailer.php, config, tests)
3. Continue from "In Progress" point
4. No re-reading of completed phases
```

---

### Häufige Fragen & Patterns

**Q: Was wenn Steven Code ändern möchte?**
A: Er erstellt Issue mit Feedback → Claude erstellt neuen Branch & PR → Review Cycle.

**Q: Wie lange jede Phase?**
A: 1 Woche real-world Zeit = 1-2 Development Sessions à ~2-3 Stunden.

**Q: Was ist "lauffähig"?**
A: Code kann deployed werden ohne weitere Änderungen. Tests pass. Keine Compilier-Fehler.

**Q: Cronjobs testen lokal?**
A: Ja, mit `time` Command: `time php cli/queue-worker.php send`. Simuliert Cronjob.

---

**Dokumentversion:** 1.0  
**Letzte Aktualisierung:** 2026-09-07  
**Status:** 🟢 Bereit für Entwicklung

Die vollständige Version mit allen Abschnitten (Kampagnen-Builder, Statistiken, Settings, Import/Export, Datenmodell, API und Implementierungsfahrplan) findest du in der Download-Datei.
