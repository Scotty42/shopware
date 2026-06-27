# Order Integration — Backlog

Legende Typ: **ci** = CI/Infrastruktur · **feat** = neue Funktionalität · **test** = Abdeckung.

T1–T13 sind vollständig in `main` gemergt (git-Log als Archiv). Aktuelle Phase: CI-Matrix-Erweiterung.

| ID | Titel | Typ | Schwere | Branch |
|----|-------|-----|---------|--------|
| E2E1 | Shopware 6.6 HTTP-Integrationstests in CI | ci | hoch | `feat/e2e1-shopware-ci` ✅ |
| E2E2 | Shopware 6.7 Matrix-Leg in CI | ci | hoch | `ci/e2e-shopware-67-matrix` ✅ |
| E2E3 | PDO-Integrationstests (PdoWriteQueue + PdoReadProjection) | ci | mittel | `test/e2e3-pdo-integration` ✅ |
| E2E4 | CI-Matrix: Triage offener Flakiness | ci | niedrig | offen |
| E2E5 | Shopware 6.5 Kompatibilitätsanalyse | ci | mittel | offen |
| E2E6 | Shopware 6.8 Kompatibilitätsanalyse | ci | mittel | offen |
| E2E7 | CI-Matrix auf 6.5 erweitern (abhängig von E2E5) | ci | mittel | offen |
| E2E8 | CI-Matrix auf 6.8 erweitern (abhängig von E2E6) | ci | mittel | offen |

---

## E2E1 — Shopware 6.6 HTTP-Integrationstests in CI  ✅ gemergt (PR #61)

Live-E2E-Gate für Shopware 6.6 auf jedem PR: `setup-shopware@v2` spint 6.6.10.19 auf, installiert das Plugin, seeded via MySQL + Admin API, führt `create_test_order.sh` + `tests/api_test.sh` aus. Workflow `e2e-66.yml`. OAuth-Token mit `"scopes":"write"` (oauth2-server 8.x). IDs via `LOWER(HEX(id))` aus MySQL (raw 32-char hex — DAL-Validator lehnt UUID v7 mit Bindestrichen ab).

## E2E2 — Shopware 6.7 Matrix-Leg in CI  ✅ gemergt (PR #66)

Separater Workflow `e2e-67.yml` für 6.7.11.1. Eigener Badge in README. OAuth mit `"scope":"write"` (RFC 6749, oauth2-server 9.x). Storefront-Channel-Check auf `sales_channel_domain.url` statt `COUNT(*) FROM sales_channel` — `setup-shopware@v2` erstellt auch mit `install-storefront:false` einen headless-Channel.

## E2E3 — PDO-Integrationstests  ✅ gemergt (PR #54)

PHPUnit-Suite (`--testsuite PdoIntegration`) für `PdoWriteQueueRepository` und `PdoReadProjectionRepository` gegen echten PostgreSQL-17-Dienst in CI. GitHub-Actions-Service-Container (postgres:17). Tests prüfen Enqueue, Claim (SKIP LOCKED), Ack, Projection-Upsert/-Delete.

---

## E2E4 — CI-Matrix: Triage offener Flakiness  · ci · niedrig

**Ziel.** Bekannte Flakiness-Muster in den 6.6- und 6.7-Legs identifizieren, dokumentieren oder beheben (Setup-Timing, Seed-Reihenfolge, Shopware-Cache-Warm-up). Grundlage für stabile Matrix-Erweiterungen (E2E7, E2E8).

**Akzeptanz.** Badges 6.6 und 6.7 stabil grün auf `main`; Flakiness-Muster dokumentiert oder behoben.

---

## E2E5 — Shopware 6.5 Kompatibilitätsanalyse  · ci · mittel

**Ziel.** Feststellen, ob das Plugin ohne oder mit nur geringen Änderungen unter Shopware 6.5 lauffähig ist, bevor ein CI-Leg (E2E7) aufgebaut wird.

**Umfang.**
- PHP-Services, die das Plugin direkt aufruft (`CartService`, `OrderPersister`, `OrderConverter`, `StateMachineRegistry`, `MultiFilter`, `EntityRepository`), gegen die 6.5-API-Oberfläche prüfen.
- Breaking Changes identifizieren, die in 6.6 eingeführt wurden und vom Plugin genutzt werden (UUID v7-Generierung, oauth2-server-Version, DAL-Feld-Änderungen, Symfony-Version).
- Aktuellsten 6.5-Patch-Stand als Zielversionierung festlegen.
- `composer.json`-Constraint (`shopware/core`) auf Kompatibilität prüfen.

**Ergebnis.** Schriftliches Urteil: "kompatibel ohne Änderungen" · "kompatibel mit geringen Änderungen" · "nicht machbar". Urteil in `docs/` festhalten. Nur bei "kompatibel" E2E7 angehen.

---

## E2E6 — Shopware 6.8 Kompatibilitätsanalyse  · ci · mittel

**Ziel.** Feststellen, ob das Plugin ohne oder mit geringen Änderungen unter dem kommenden Shopware 6.8 LTS lauffähig ist, bevor ein CI-Leg (E2E8) aufgebaut wird.

**Umfang.**
- Dieselben PHP-Services wie in E2E5, aber gegen die bekannte 6.8-API-Oberfläche.
- Besonderes Augenmerk: Symfony-Version-Bump, DBAL-Änderungen, DAL-Änderungen (neue/umbenannte Felder, Repository-API), oauth2-server-Version (9.x → 10.x?), PHP-Mindestversion, State-Machine-API.
- Shopware 6.8-Changelog, Upgrade-Guide und `UPGRADE.md` im Shopware-Repo konsultieren.

**Ergebnis.** Schriftliches Urteil: "kompatibel ohne Änderungen" · "kompatibel mit geringen Änderungen" · "nicht machbar". Urteil in `docs/` festhalten. Nur bei "kompatibel" E2E8 angehen.

---

## E2E7 — CI-Matrix auf 6.5 erweitern  · ci · mittel  (Voraussetzung: E2E5 = "kompatibel")

**Ziel.** Neuer Workflow `e2e-65.yml` + Badge in README analog zu `e2e-66.yml` / `e2e-67.yml`.

**Umfang.**
- `setup-shopware@v2` mit dem in E2E5 festgelegten 6.5-Patch-Stand konfigurieren.
- Seed-Skript und OAuth-Token-Request auf 6.5-Besonderheiten anpassen (aus E2E5).
- Etwaige Compat-Shims im Plugin implementieren, ohne die 6.6- oder 6.7-Legs zu brechen.

**Akzeptanz.** Alle drei Legs (6.5, 6.6, 6.7) grün auf `main`; drei Badges in README.

---

## E2E8 — CI-Matrix auf 6.8 erweitern  · ci · mittel  (Voraussetzung: E2E6 = "kompatibel")

**Ziel.** Neuer Workflow `e2e-68.yml` + Badge in README analog zu den bestehenden Legs.

**Umfang.**
- `setup-shopware@v2` mit dem in E2E6 festgelegten 6.8-Stand konfigurieren (sobald stabile Version verfügbar).
- Seed-Skript und OAuth-Token-Request auf 6.8-Änderungen anpassen.
- Etwaige Compat-Shims implementieren, ohne bestehende Legs zu brechen.

**Akzeptanz.** Alle aktiven Legs grün auf `main`; Badge für 6.8 in README.
