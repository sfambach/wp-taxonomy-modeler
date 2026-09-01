# Paket · Änderungstabellen

**Stand 2026-09-01.** Aktueller Soll-Zustand. Historie in [`history.md`](history.md), Offenes in
[`tasks.md`](tasks.md).

⚠️ **Dieses Paket entstand am 2026-09-01 durch Teilung.** *`Datenbank` umfasste zuerst alle zwölf
Tabellen und war bei zwei überarbeiteten schon fast an der Decke. Der Eigentümer entschied zu teilen
statt zu deckeln: **«B ist okay.»***

⚠️ **Noch nicht überarbeitet.** *Hier steht bisher der **Ist-Zustand** als Bestandsaufnahme, nicht der
Soll-Zustand. Was gilt, entsteht, wenn dieses Paket an der Reihe ist.*

---

## 1 · Zweck

**Wie eine Änderung erhalten bleibt.** Die vier Schattentabellen und das Änderungsbuch.

## 2 · Verantwortungsgrenze

**Gehört hinein:** die Schattentabellen · das Änderungsbuch · das Zurückspringen auf eine frühere
Version.

**Gehört nicht hinein:** die lebenden Modelltabellen selbst — die liegen in
[`modelltabellen/`](../modelltabellen/package.md).

## 3 · Die Tabellen, wie sie heute sind

| Tabelle | gemessen | |
|---|---|---|
| `nodes_history` | 7 175 | Spalten von `nodes` plus `deleted`, `archived_at` |
| `relations_history` | 8 760 | dito für `relations` |
| `records_history` | 151 | dito für `records` |
| `record_values_history` | 999 | dito für `record_values` |
| `changelog` | 23 578 | `owner_id`, `owner_kind`, `at`, `what`, `change_group_id`, `before_state`, `after_state`, `version` |

**Das Prinzip, entschieden:** nichts wird gelöscht — es wird zuerst in den Schatten geschrieben
([D-535](../../NewConcept/90-decision-log.md), [D-537](../../NewConcept/90-decision-log.md)).
**Die lebende Tabelle bleibt unverändert**: kein neuer Schlüssel, kein Löschkennzeichen, keine
geänderte Abfrage. *Eine vergessene Spalte in der lebenden Tabelle findet eine Prüfung; ein
vergessenes `WHERE is_live = 1` von fünfzig findet niemand.*

## 4 · Was hier nicht stimmt — Befunde, keine Vorgaben

⚠️ **Zwei beschlossene Mechanismen kommen in den Daten nicht vor:**

*[D-536](../../NewConcept/90-decision-log.md) sagt, jede Version bleibe erhalten und **das Journal
gruppiere nur noch**, welche Identität in welcher Version geändert wurde. Gemessen ist
`changelog.version` in **25 von 23 578** Zeilen gefüllt, und **9 490 Zeilen tragen weiter einen
`before_state`**, obwohl «die alte Zeile **ist** der Vorher-Zustand». **Das ist bekannte offene
Arbeit** — Schritt 6, «das Journal schrumpft».*

⚠️ **`changelog` ist die grösste Tabelle des Projekts** — 23 578 Zeilen gegen rund 680 lebende
Modellzeilen.

⚠️ **`records_history`: `version` und `deleted` tragen je einen einzigen Wert über 151 Zeilen.**
*Legt nahe, dass der Schattenmechanismus für `records` nie wirklich gelaufen ist — siehe
[`bugs.md`](bugs.md).*

## 5 · Abhängigkeiten

**Benutzt:** `$wpdb`. **Kennt** die Modelltabellen, weil ein Schatten die Spalten seiner lebenden
Tabelle trägt — **das ist die einzige Kopplung und sie ist beabsichtigt.**

⚠️ *Mit eigenen Id-Räumen je Tabelle ([`modelltabellen/`](../modelltabellen/package.md) §3.3) ist zu
klären, worauf `changelog.owner_id` künftig zeigt. **`owner_kind` löst es schon** — es ist die einzige
Spalte im Projekt, die ihren Id-Raum von jeher nennt.*
