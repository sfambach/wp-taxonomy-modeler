# Audit Gestaltung und Architektur — 2026-11-06

Gemessen gegen [`gestaltung-und-architektur.md`](gestaltung-und-architektur.md) ([D-1186](NewConcept/90-decision-log.md))
mit `php scripts/dev/design-guard.php` auf `main` aller Taxmod-Repos (Modeller 0.1.172, Lager 0.56.0,
Fertigung 0.0.15, Mail 0.6.3, eBay 0.0.69, DHL 0.0.12, GLS 0.0.3, JLCPCB 0.1.11, Mouser 0.1.0,
DPD 0.0.1, Hermes 0.0.2, PayPal 0.0.1). **113 Befunde** ohne `style=`-Attribute.

⚠️ **Nicht geprüft:** das Aussehen auf fambach.net (nur Code gemessen); ob jede Aktion zurücknehmbar
ist (V1); ob jedes `<select>` ein Baum sein müsste (A4); Multiplizitäten gegen Daten (A6).

## P1 · Eine Stelle je Tabelle (R1–R3)

| # | Befund | Wo | Umbau |
|---|---|---|---|
| P1.1 ✅ | Module greifen an der Tür vorbei in die Persistenz des Modellers (`WpdbRecordRepository`). | Lager `ModelGateway` (4), `Plugin` (2); Fertigung `ModelGateway` (1) | Modeller bietet eine öffentliche Tür (`Taxmod\WordPress\ModelDoor`) mit allem, was die Kopien heute können (`table`, `fields`, `tree`, `recordsMatching`, `recordsOf`, `nodesOf`, `records`, schreiben über `write-data`). |
| P1.2 ✅ | Dieselbe Tür zweimal kopiert, dazu `Table`. | Lager + Fertigung `ModelGateway`, `Core/Table` | Nach P1.1 löschen, beide Module nutzen die Tür des Modellers. |
| P1.3 | SQL auf Modelltabellen außerhalb der Persistenzschicht. | Modeller `RecordBlock` (3), `Admin/Tiles` (2, Kennzahl je Zeitraum), `SettingsScreen` (2), `WpMediaLibrary` (1), `ModelAbilities` (7), `Import/SourcePictures` (1) | Abfragen in die Repositories/`Query`-Schicht verschieben; Bildschirme fragen Methoden. |
| P1.4 | Transaktion mit Savepoint zweimal von Hand gebaut. | `ModelAbilities::apply`, `NodesScreen` (Satz speichern) | Eine `Transaction::run(callable)` in `Persistence`. |
| P1.5 ✅ | Ability direkt statt über eine Tür. | JLCPCB `ReorderPage` | Über die Tür aus P1.1. |
| P1.6 | Eigenes SQL, geduldet: Mail `LegacyProposals` (Altbestand, nur Umzug), DHL `Tracker` (liest WP-Transients). | | Mail: mit dem Altbestand löschen. DHL: über `get_transient`/eigene Option statt SQL. |

## P2 · Aus einem Guss (S1, S5, K1–K3)

| # | Befund | Wo | Umbau |
|---|---|---|---|
| P2.1 ✅ | Browser-`confirm()` statt des gemeinsamen Dialogs. | Lager `InboundPage`, `PurchasePage`, `ContactsPage`; eBay `ShippingScreen`; Modeller `SettingsScreen`, `InboxScreen` | `ListPage::dialogAction` (K3). |
| P2.2 ✅ 0.1.176 | Eigenes `<h1>` statt `ListPage::heading()`. Erledigt: alle Seiten über `heading()` (Erklärung hinter dem Fragezeichen über `hint:`), tote Fehlerseiten in Lager/Fertigung entfernt; Drucklatt (eigenes Dokument) ausgenommen; `G2` ist Prüflauf. | Lager 3 (Fehlerseiten), Fertigung 1, eBay 3, JLCPCB 1, Modeller 5 (Settings, Cleanup, Nodes, Backup) | `heading()`; Fehlerseite als gemeinsamer Baustein. |
| P2.3 | `<style>`/`<script>` in Seiten. | Lager `StockPage` (5), `LogisticsShell` (2); Modeller `NodesScreen`, `ChangeListImport`, `Plugin`; Fertigung/eBay nur Druckbögen (erlaubt) | Nach `assets/admin.css`/`admin.js` des Modellers. |
| P2.4 | `style="…"` statt Klassen. | Lager 206, Modeller 127, Fertigung 38, eBay 34, JLCPCB 4, DHL 3, Mail 1 | Schrittweise CSS-Klassen im Modeller; zuerst Knöpfe/Abstände (K1). |

## P3 · Code-Duplikate (R3)

| # | Befund | Wo | Umbau |
|---|---|---|---|
| P3.1 | HTTP-Schicht viermal kopiert (`HttpRequest`, `HttpResponse`, `HttpTransport`, `WpHttpTransport`). | eBay, DHL, GLS, JLCPCB | Einmal im Modeller (`Taxmod\Core\Http`, `Taxmod\WordPress\Http`). |
| P3.2 | Sendungsverfolgung DHL/GLS ähnlich gebaut (`Tracker`, `StoredAccess`, `Stage`, `Event`, `TrackingClient`), < 80 % gleich. | DHL, GLS | Gemeinsamen Tracking-Vertrag im Modeller prüfen, bevor DPD/Hermes API bekommen. |
| P3.3 | Sehr große Dateien. | Modeller `Rendering` 8 400, `NodesScreen` 8 215, `Schema` 5 039; Lager `StockPage` 3 421, `StockService` 2 377 Zeilen | Beim Anfassen teilen, nicht als eigener Umbau. |

**Stand 2026-11-06 vormittags:** P1.1, P1.2, P1.5 erledigt — Modeller 0.1.174 (`ModelDoor`), Lager 0.57.2,
Fertigung 0.0.17, JLCPCB 0.1.12. Das Gedächtnis gelesener Knoten gehört der Tür, nicht der Anfrage: ein
anfrageweites Gedächtnis machte `stock-check` rot (eine Schreibung über `taxmod/apply` blieb ungesehen). `Table`
bleibt je Modul (40 Zeilen Werteklasse), weil der Kern der Module ohne den Modeller getestet wird.

P2.1 erledigt: Modeller 0.1.175 (`ListPage::confirm`, auch die Sammelaktionen), Lager 0.57.3, eBay 0.0.70; Regel `G1` ist sauber.

## Reihenfolge

1. P1.1 + P1.2 (Modeller-Release, dann Lager + Fertigung) — größter Hebel, beendet Kopien.
2. P2.1 + P2.2 (sichtbar, klein) — danach `G1`/`G2` mit `--fail` in die Prüfläufe.
3. P1.3 + P1.4 (nur Modeller).
4. P3.1, dann P2.3/P2.4 schrittweise.

Laufende Threads im Lager und in der Fertigung bauen parallel: Umbau dort jeweils mit dem Thread
abgestimmt, nie in eine offene Änderung hinein.
