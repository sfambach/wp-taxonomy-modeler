# Paket · Änderungstabellen — Arbeitsliste

```text
[ ] TASK-001  Schritt 6: das Journal schrumpft
```

[D-536](../../NewConcept/90-decision-log.md) hat entschieden, dass die alte Zeile **ist** der
Vorher-Zustand. Gemessen tragen **9 490 von 23 578** Zeilen weiter einen `before_state`, und
`changelog.version` ist in **25** Zeilen gefüllt. **Beschlossen, nicht gebaut.**

```text
[ ] TASK-002  Schritt 7: das Zurückspringen selbst
```

Der kleinste der Schritte, und der letzte offene aus [D-536](../../NewConcept/90-decision-log.md).

```text
[ ] TASK-003  Die Schattentabellen bekommen die Änderungsgruppe
```

[D-575](../../NewConcept/90-decision-log.md). Gegenstück zu TASK-013 in
[`modelltabellen/`](../modelltabellen/tasks.md) — **beides ist ein Arbeitsstück**: die Spalte
verschwindet dort und entsteht hier, und dazwischen darf nichts verlorengehen.

## Rückgängig über die Gruppe im Schatten — [D-601](../../NewConcept/90-decision-log.md)

```text
[ ] TASK-A01  change_group_id an die vier Schattentabellen
[ ] TASK-A02  before_state und after_state aus changelog entfernen
[ ] TASK-A03  changelog einmal leeren — der Altbestand wird nicht umgestellt
[ ] TASK-A04  Waechter: jede Schattenzeile nennt ihre Gruppe, und zu jeder
              Gruppe im Buch gibt es Schattenzeilen
```

**Die Reihenfolge ist zwingend:** *A01 vor A02 — solange die Gruppe nicht im Schatten steht, ist der
Text die **einzige** Quelle, nicht nur die schlechtere. Gemessen: `changelog.version` ist bei
**30 475 von 30 565** Zeilen `NULL`.*

⚠️ **A03 ist erlaubt, weil er es gesagt hat:** *«wir können das Changelog einmal löschen»*, begründet
mit *«wir sind noch nicht produktiv»*. **Nach dem ersten echten Betrieb wäre dieselbe Aufgabe eine
andere** — dann ginge Geschichte verloren, die jemand braucht.

⚠️ **Was A04 zusätzlich prüfen sollte, weil es heute nicht stimmt:** *von 90 Buchzeilen mit Fassung
finden **86** ihre Schattenzeile — vier nicht, alle vier `multiplicity set`. **Der Schatten hat also
Lücken**, wo geändert wurde, ohne dass eine Fassung entstand. Ohne diese Prüfung wäre Rückgängig
still unvollständig.*
