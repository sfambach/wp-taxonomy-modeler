# Paket · Datenbank — Fehlerliste

⚠️ *Ein Fehler verschwindet nicht dadurch, dass ein anderes Thema bearbeitet wird
([`arbeitsmodell.md`](../../arbeitsmodell.md) §7.2).*

---

```text
BUG-001
Status: OPEN
Beschreibung: 32 von 128 Knoten haben kein Feld, obwohl die Wurzel welche erklärt.
Betroffen:    record-on-any-node-check, Randlauf rot
```

**Am 2026-09-01 zum ersten Mal überhaupt gemessen.** Die Zusicherung stand in der Prüfung, **ist aber
nie gelaufen**: sie stürzte davor ab, weil sie einen Datensatzwert an der Einstellungskante `exponent`
schreiben wollte — was [D-538](../../NewConcept/90-decision-log.md) unmöglich macht. *Ein Absturz
überspringt die restlichen Zusicherungen.*

**Offen ist, ob es ein Fehler ist:** die 32 könnten legitim sein — Rahmenknoten, Papierkorb,
Datentypen. **Nicht geraten**, weil die falsche Antwort eine Bauaufgabe erfindet oder eine
verschweigt.

```text
BUG-002
Status: OPEN
Beschreibung: node-kind-check erwartet mindestens drei als Einstellung markierte Knoten, findet einen.
Betroffen:    node-kind-check, Randlauf rot
```

**Die unerledigte Hälfte von [D-529](../../NewConcept/90-decision-log.md)** — nicht alle
Einstellungsschlüssel sind Kanten geworden. **Endet bei `label_role`**, das auf eine Entscheidung des
Eigentümers wartet (`OQ-134`). Solange die offen ist, kann die Tabelle `settings` nicht fallen.

```text
BUG-003
Status: OFFEN, aber ungeprüft
Beschreibung: records_history steht auf 151 Zeilen, version und deleted tragen je einen einzigen Wert.
Betroffen:    Schattenmechanismus für records
```

*Legt nahe, dass der Schattenmechanismus für `records` nie wirklich gelaufen ist. **Vermutlich
richtig**, weil kein Prüflauf einen Knoten **mit** Datensätzen geleert hat — aber ungeprüft.*
