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

```text
BUG-004
Status: OPEN
Beschreibung: Ein Einstellungsbehälter überlebt seinen Inhalt — und wird beim Löschen erst erzeugt.
Betroffen:    DataEntry::clearSettingAt, DataEntry::putSettingAt
```

**Zwei Fehler in einer Datei, beide am 2026-09-02 gefunden:**

**`clearSettingAt` legt an, bevor es prüft.** Die erste Zeile holt `defaultRecordOf($nodeId)` — und das
**erzeugt** den Datensatz, wenn keiner da ist. **Eine Seite zu speichern, auf der ein
Einstellungsfeld leer ist, legt damit einen Datensatz an.**

**Und ein geleerter Behälter bleibt stehen.** Der Wert wird entfernt, die `DisplayOption`-Zeile nicht.

⚠️ **Gemessen:** *von 93 `DisplayOption`-Datensätzen sind **64 leer**. 58 davon stammen aus dem
Umzug vom 2026-08-30 und sind einmaliger Rückstand — **aber alle sechs, die seither entstanden sind,
sind ebenfalls leer.** Es passiert weiter.*

⚠️ **Was das kostet:** *die Einstellungen belegen heute **213 von 392** Zeilen an Datensatzdaten.
Ohne die leeren Behälter wären es **90**. **123 Zeilen — 58 % — sind Hüllen ohne Inhalt.***

⚠️ *Ich hatte das zuerst «Anlegen auf Vorrat» genannt und daraus geschlossen, der Mechanismus sei
teuer. **Beides war falsch:** der Behälter entsteht korrekt beim Schreiben — er wird nur nie
aufgeräumt. **Zwei Stellen, keine Konzeptänderung.***
