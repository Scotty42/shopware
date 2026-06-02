# Order Integration — Backlog

Abgeleitet aus der Architektur-/Code-Analyse vom 2026-06-02 (Shopware 6
self-hosting / Order Integration Plugin). Jeder Task ist als eigener Branch
umgesetzt und als separater PR gegen `main` gedacht.

Legende Typ: **bug** = Fehlverhalten · **spec-drift** = OpenAPI verspricht
mehr als der Code hält · **doc** = Doku ↔ Realität · **test** = Abdeckung.

| ID | Titel | Typ | Schwere | Branch |
|----|-------|-----|---------|--------|
| T1 | "Option C"-Namenskollision Konzept ↔ README | doc | mittel | `docs/t1-option-c-naming` |
| T2 | Idempotency-Key auf Mutating-Endpoints erzwingen | spec-drift | hoch | `feat/t2-idempotency` |
| T3 | If-Match / ETag optimistic concurrency (412) | spec-drift | hoch | `feat/t3-if-match` |
| T4 | `sort`-Parameter implementieren/validieren | spec-drift | mittel | `feat/t4-sort` |
| T5 | `salesChannelId`-Filter implementieren | spec-drift | niedrig | `feat/t5-saleschannel-filter` |
| T6 | `customerId` UUID-vs-Hex Drift | spec-drift | niedrig | `fix/t6-customerid-uuid` |
| T7 | Soft-Delete verschluckt illegale Transition | bug | hoch | `fix/t7-soft-delete` |
| T8 | POST /orders ohne Adressen/Gast | bug | mittel | `fix/t8-order-create-address` |
| T9 | OrderMapper `last()` vs `first()` Delivery | bug | mittel | `fix/t9-delivery-consistency` |
| T10 | DeliveryController 422 ohne problem+json | bug | niedrig | `fix/t10-delivery-422-contenttype` |
| T11 | HTTP-Schicht nicht in CI getestet | test | mittel | `test/t11-http-coverage` |

---

## T1 — "Option C"-Namenskollision Konzept ↔ README  · doc · mittel
**Problem.** `docs/order-api-concept.md` definiert *Option C* = eigenständiger
Facade-Service mit Read-Projection (CQRS) + Write-Queue. Die `README.md` labelt
hingegen das **In-Process-Plugin** ebenfalls als "Option C — Plugin inside
Shopware". Derselbe Name steht für zwei verschiedene Architekturen.
**Ziel.** Begriffe entkoppeln: das Plugin als eigene Option (D) benennen,
Konzept-Option-C klar als Zukunfts-/Infra-Achse markieren, Querverweis fixen.
**Akzeptanz.** README und Konzept verwenden "Option C" konsistent für genau
eine Sache; das Plugin hat einen eindeutigen eigenen Namen.

## T2 — Idempotency-Key erzwingen  · spec-drift · hoch
**Problem.** OpenAPI markiert `Idempotency-Key` auf POST/PUT/PATCH/DELETE als
*required*; der Code liest den Header nirgends. Retries können doppelte
Bestellungen erzeugen — genau das in Konzept §5.2 beschriebene Risiko.
**Ziel.** `IdempotencyService` (Key-Validierung UUIDv4, Body-SHA-256,
replay/conflict/new-Entscheidung) + Store-Interface + 409 bei Key-Reuse mit
abweichendem Body. Verdrahtung in den Mutating-Controllern.
**Akzeptanz.** Gleicher Key + gleicher Body → gecachte Antwort; gleicher Key +
anderer Body → 409; fehlender Key → 400. Unit-Tests für die Kernlogik.

## T3 — If-Match / ETag optimistic concurrency  · spec-drift · hoch
**Problem.** `OrderMapper::etagFor()` erzeugt ETags und Controller geben sie
zurück, aber **kein** Endpoint wertet `If-Match` aus → kein 412. Konzept §4/§5.5
fordert If-Match auf PUT/PATCH/DELETE.
**Ziel.** `EtagComparator` + `PreconditionFailedException` (412) + `PreconditionRequiredException`
(428 optional), Auswertung in PATCH/PUT/DELETE.
**Akzeptanz.** Passendes If-Match → Durchlauf; abweichendes → 412; Unit-Tests
für Vergleich (weak/strong, Wildcard `*`).

## T4 — `sort`-Parameter  · spec-drift · mittel
**Problem.** OpenAPI definiert `sort=(createdAt|updatedAt|orderNumber):(asc|desc)`;
der List-Endpoint ignoriert ihn (fest `createdAt desc`). Validator prüft ihn nicht.
**Ziel.** `sort` in `QueryValidator` gegen Whitelist validieren und im Controller
anwenden, `id` als deterministischer Tiebreaker, Cursor-kompatibel.
**Akzeptanz.** Gültige `sort`-Werte sortieren; ungültige → 422; Unit-Tests.

## T5 — `salesChannelId`-Filter  · spec-drift · niedrig
**Problem.** In den GET-Params dokumentiert, in `list()` nicht implementiert.
**Ziel.** Filter ergänzen + Hex/UUID-Validierung im Validator.
**Akzeptanz.** Filter wirkt; ungültiger Wert → 422; Unit-Test.

## T6 — `customerId` UUID-vs-Hex  · spec-drift · niedrig
**Problem.** OpenAPI: `format: uuid` (mit Bindestrichen); `QueryValidator`
verlangt 32-Hex. Kanonischer UUID → 422.
**Ziel.** Validator akzeptiert beide Formen und normalisiert auf 32-Hex;
OpenAPI-Beschreibung angleichen.
**Akzeptanz.** Beide Formate akzeptiert und identisch normalisiert; Unit-Tests.

## T7 — Soft-Delete verschluckt illegale Transition  · bug · hoch
**Problem.** `OrderController::delete()` fängt *jede* `InvalidTransitionException`,
um Re-Cancel idempotent zu machen. Folge: DELETE auf z. B. `completed` liefert
204, obwohl nicht storniert wurde — Erfolg ohne Wirkung.
**Ziel.** Aktuellen Order-State prüfen: bereits `cancelled` → 204 idempotent;
sonst Transition ausführen und illegale Transition als 409 propagieren.
**Akzeptanz.** Re-Delete eines stornierten Auftrags → 204; Delete eines nicht
stornierbaren → 409; Tests.

## T8 — POST /orders ohne Adressen/Gast  · bug · mittel
**Problem.** Create validiert nur `salesChannelId` + `lineItems`. Ohne
Customer-Kontext/Adressen kann die Persistierung scheitern oder eine
adresslose Order entstehen; Gast-Bestellungen sind ungedeckt.
**Ziel.** Eingangs-Validierung: entweder `customer.id` (registriert) ODER
vollständige `billingAddress` (+ Gastdaten) verlangen; sonst klare 422.
Validierungslogik unit-testbar in einen Validator extrahieren.
**Akzeptanz.** Fehlender Kontext → 422 mit JSON-Pointer; Unit-Tests.

## T9 — OrderMapper `last()` vs `first()`  · bug · mittel
**Problem.** `extractDeliveryStatus()` nimmt `last()`, `mapShippingAddress()`
nimmt `first()`. Bei Split-Shipments (Phase 3) widersprechen sich die Felder.
**Ziel.** Eine "primäre Delivery"-Auswahl zentralisieren und überall verwenden.
**Akzeptanz.** `deliveryStatus` und `shippingAddress` stammen aus derselben
Delivery; Unit-Tests mit Mehrfach-Delivery.

## T10 — DeliveryController 422 ohne problem+json  · bug · niedrig
**Problem.** `DeliveryController::setStatus()` gibt den Inline-422 ohne
`Content-Type: application/problem+json` zurück — abweichend vom Rest.
**Ziel.** Über `ValidationException` (zentraler ExceptionSubscriber) führen, damit
Header & Shape einheitlich sind.
**Akzeptanz.** 422 trägt `application/problem+json` und `errors[]`; konsistent.

## T11 — HTTP-Schicht nicht in CI  · test · mittel
**Problem.** CI testet nur 3 Logik-Klassen. Controller, OrderCreationService,
OrderPatchService, DeliveryController sind nur über die Bash-Integrationstests
(lebendes Shopware nötig) abgedeckt — nicht in CI.
**Ziel.** Pure Controller-/Mapping-Logik extrahieren und unit-testen; einen
optionalen Integrations-CI-Job dokumentieren/scaffolden.
**Akzeptanz.** Neue Unit-Tests laufen in CI; Integrations-Job ist beschrieben.
