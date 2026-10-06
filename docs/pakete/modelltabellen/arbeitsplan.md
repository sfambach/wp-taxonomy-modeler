# Arbeitsplan bis zum Zwischenstand

**Zweck:** die offenen Baustellen abarbeiten, bis nichts mehr offen ist — und **vorher festlegen,
wann der Eigentümer gebraucht wird**. Danach kommt das Ereigniskonzept.

**Gemessen am 2026-09-05:** 31 offene Aufgaben (3 davon erledigt, nur noch nicht abgehakt),
6 rote Wächter, Kern 424 grün.

⚠️ **Die Regel dieses Plans:** *zwischen zwei Treffpunkten wird nicht gefragt.* Was unklar wird,
geht nach `inbox.md` und wartet auf den nächsten Treffpunkt — **es wird nicht erfunden** (`PR-4`)
und es hält die Arbeit nicht an (Parkplatzregel).

---

## Die Treffpunkte

| | Wann | Was er tut | Warum nur er |
|---|---|---|---|
| **T0** | vor Block A | **Entscheidungen** — die Liste unten | Modellfragen; `PR-3` |
| **T1** | nach Block C | **Ansehen** — der Baum ist umgezogen | nur er sieht, ob sein Modell stimmt |
| **T2** | nach Block D | **Ansehen** — die Oberfläche | nur er bedient sie |
| **T3** | Abschluss | **Abnahme** des Zwischenstands | dann beginnt das Ereigniskonzept |

Alles andere läuft ohne ihn. Nach jedem Block ein Bericht in drei Zeilen: was grün ist, was fiel,
was in die Inbox ging.

---

## T0 · Was jetzt entschieden werden muss

**T0 ist am 2026-09-05 gehalten worden.** Von vier Fragen blieb eine.

1. **`INF-014` — entschieden** ([D-617](../../NewConcept/90-decision-log.md)): an der Wurzel hängt
   der **Rückfall**, nicht die Regel für alles; jeder Knoten bestimmt seinen Vorgabe-Renderer selbst.
2. **«Wie entsteht eine Einstellungskante?» — die Frage war falsch gestellt.** *Er: «Wir haben ein
   Setting im Knoten, das den Renderer wählt.» Gemessen: die vier Wächter sind grün, geschrieben
   wird über die Spalte. Ich hatte einen Befund von vor TASK-020 weitergetragen.*
3. **`INF-013` — entschieden** ([D-618](../../NewConcept/90-decision-log.md)): der Benutzer legt die
   Kantenart fest, geraten wird nicht. *Ableiten später, «aber auch nur vielleicht».* → `TASK-053`.
4. **`INF-004`** — war am 2026-09-04 schon entschieden («erstmal alles zeigen»). Mein Fehler.

---

## Block A · Rot wird grün, ohne Umbau

`TASK-044` (Reset), `TASK-047` (zwei Kanten auf den Zweigkopf), `TASK-039`, `TASK-043`
und die sechs roten Wächter: `id-space`, `package6`, `package7`, `several-values`, `unitvalue`,
`value-ref-space`. **Die Waisen stammen aus abgestürzten Läufen**, nicht aus seinem Modell —
gelöscht wird nur, was eine geschriebene Entscheidung deckt.

*Kein Treffpunkt. Ende: alle Wächter grün.*

## Block B · Der Tabellenumbau

`TASK-001…003` (`path` fällt), `006`, `007`, `008`/`009` (Klasse am Knoten, 56 Optionen ablösen),
`010`, `012`, `013`, `014`/`015` (Umbenennungen), `016`. Läuft in mehreren Agenten parallel,
jeder mit einer gesperrten Dateiliste, **kein `git stash`**.

*Kein Treffpunkt.* Jede Wanderung schreibt ihren Schatten mit, damit sie rückgängig gemacht
werden kann.

## Block C · Das Modell

`TASK-018` (Vererbung wird `parent_node_id`), danach `TASK-032` (`relation_type` fällt),
`TASK-019` (Labels), `TASK-041`.

**→ T1: er sieht sich den Baum an.** Hier zieht seine Struktur um; ein Fehler wäre teuer und
fällt keinem Wächter auf, der die Struktur nicht kennt.

## Block D · Die Oberfläche

`TASK-033` (ein Auswahl-Renderer), `037` (Löschen fragt nach Verwendungen), `038`, `048`, `051`,
und der Renderer-Wähler aus T0/Frage 2.

**→ T2: er bedient sie.** Anzeigefehler sieht nur, wer hinsieht.

## Block E · Abschluss

Dokumente nachziehen, Regelverzeichnis, beide Läufe grün, die Inbox leer oder je Eintrag mit
Entscheidung. **→ T3: Abnahme, dann Ereigniskonzept.**
