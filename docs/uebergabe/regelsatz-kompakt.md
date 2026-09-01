# Der Regelsatz, kompakt — 306 Regeln auf einer Seite

**Stand 2026-09-01.** Auf Bitte des Eigentümers geschrieben, während wir über das Arbeitsmodell
sprachen. **Eine verdichtete Zusammenfassung zum Lesen und Entscheiden, keine Quelle.**

Vollständig, Zeile für Zeile, mit Fundort und Zitatzahl:
[`02-rules-index.md`](../NewConcept/02-rules-index.md). Bei jedem Widerspruch gilt die Datei, in der
die Regel wirklich steht.

⚠️ **Zwei Ehrlichkeiten vorweg.** *Die Kurzfassung von `CD-1` im erzeugten Verzeichnis ist
verstümmelt — der Erzeuger hat die falsche Zeile erwischt; hier unten steht sie richtig. Und **35 der
306 Regeln werden nie wieder zitiert**, sind also vermutlich toter Text. Was davon weg kann, ist
nicht entschieden (`PR-4`).*

---

## Ebene 1 — die 32 Regeln, die die Zusammenarbeit regeln

Sie stehen in [`CLAUDE.md`](../../CLAUDE.md), 135 Zeilen, und sind das, was bei jeder Aufgabe gilt.

### PR — Prozess (13)

| | |
|---|---|
| **PR-1** | Quelle der Wahrheit ist `docs/NewConcept/`. Sonst nichts. `docs/legacy/` ist ein Steinbruch: zitieren ja, erben nein. |
| **PR-1b** | Die Altlast ist geerntet und geschlossen. Jeder Fund liegt in einem Blatt. Sie wird **nie erweitert und nie als Vorlage benutzt**. |
| **PR-2** | `10-domain-core.md` ist gesperrt, Produktionscode ist erlaubt. Fehlt dem Dokument etwas, **wird das eine Entscheidung** — nie ein stiller Edit am Konzept. |
| **PR-3** | **Nichts ist entschieden, bevor es mit einer `D-<nnn>` im Entscheidungsbuch steht.** Im Chat entschieden heisst nicht entschieden. |
| **PR-4** | **Unklar bleibt unklar.** Alles Unentschiedene wird eine offene Frage. Nie eine Antwort erfinden, nie stillschweigend eine wählen, weil sie naheliegt. |
| **PR-5** | Das Konzept wird **zuerst aus den Aussagen des Eigentümers** geschrieben. Die Altlast danach, als Gegenprobe. |
| **PR-6** | Dokumentationsstil: ein kleines Diagramm je Sachverhalt, Erklärung darunter, Code nur wo das Detail es verlangt. |
| **PR-7** | **Wahrheitsgemäß berichten.** Ungeprüft heisst ungeprüft. Übersprungen heisst übersprungen. Nie eine plausible Rekonstruktion als Befund ausgeben. |
| **PR-8** | Regelhygiene gilt auch für `CLAUDE.md` selbst. |
| **PR-9** | **Beide Prüfläufe grün, bevor irgendetwas committet wird.** Jedes Paket hängt seine Prüfungen ins Netz. |
| **PR-10** | **Nachlesen, bevor du es sagst.** Keine Behauptung über das Konzept ohne Zitat daraus, mit seiner `D-<nnn>`. Erinnern ist nicht lesen. |
| **PR-11** | **Nie das Ergebnis einer Textumwandlung unbewacht in eine Datei schreiben.** Prüfen, dass die Ersetzung wirklich griff. |
| **PR-12** | **Zieht ein Wert um, zieht der Leser zuerst** — und eine Prüfung muss rot werden, wenn nur einer von beiden umgezogen ist. Reihenfolge: Wächter, Leser, Daten. |

### CD — Code (12)

| | |
|---|---|
| **CD-1** | **WordPress am Rand, modernes PHP im Kern.** Der Kern ruft **keine** WordPress-Funktion; er erklärt die Schnittstellen, die er braucht, und der Rand erfüllt sie. |
| **CD-2** | Jede PHP-Datei beginnt mit `<?php declare(strict_types=1);` als **erster Zeile**. Kein schliessendes `?>`. |
| **CD-3** | Klassenladen über Composer PSR-4. Kein `require_once` für Klassen. |
| **CD-4** | **Alles typisieren**, was typisiert werden kann. `mixed` braucht einen Grund im Kommentar. |
| **CD-5** | Am Rand, immer in dieser Reihenfolge: **Rechte prüfen → Nonce → prüfen → säubern → handeln → beim Ausgeben escapen.** Keine Ausnahme, auch nicht für Admin-Bildschirme. |
| **CD-6** | Eigene Tabellen mit `taxmod_`-Präfix, angelegt über `dbDelta()`, bewacht von einer gespeicherten Schemaversion. Nur vorbereitete Abfragen. |
| **CD-7** | **Kein N+1.** Kein SQL in einer Schleife. Baumdurchlauf wird **einmal** gelöst, an einer Stelle. |
| **CD-8** | Darstellungscode **gibt Zeichenketten zurück**. Kein `echo` in Renderern, Schleifen, Shortcodes, Hooks. |
| **CD-9** | **Namen sagen, was das Ding ist.** Umbenennen, wenn das Wort lügt. Keine Abkürzungen zum Nachschlagen, kein `data`/`info`/`manager`/`helper` als ganzer Name. |
| **CD-10** | Ausnahmen im Kern, am Rand übersetzt in `WP_Error`. Nie ein nacktes `false` als Fehlersignal. Nie eine Ausnahme unbehandelt verschlucken. |
| **CD-11** | Semantische Version. `MAJOR` bewegt sich nur für eine offizielle Auslieferung. Plugin-Kopf, Konstante, `package.json` **im selben Commit**. |
| **CD-12** | Gutenberg-Blöcke im `taxmod/`-Namensraum. Der lesbare **Titel** ist kein Token, sondern übersetzbarer Text. |

**Verboten:** eine Tatsache doppelt führen · nach Anzeigename, Label oder einem bestimmten Knoten
sonderbehandeln · Variablen in SQL interpolieren · Darstellungslogik in Domänenobjekten · auskommentierten
Code committen oder ein `TODO` ohne Eigentümer und Grund.

### AR — Architektur (2 — und mehr darf es nicht geben)

| | |
|---|---|
| **AR-1** | Das Modell liegt in **eigenen Tabellen** dieses Plugins, nicht in WordPress-Posts, -Postmeta, -Terms oder CPTs. Grundtabellen: nodes, relations, labels, records. |
| **AR-2** | **Nichts Benutzersichtbares ist fest verdrahtet.** Softwaretexte über die Textdomäne; die Namen selbst erstellter Knoten sind Labels im Modell, je Sprache. Die zwei teilen sich nie einen Mechanismus. |

⚠️ *Eine Architekturregel existiert **nur mit einer Entscheidungsnummer**. Fällt die Entscheidung, wird
die Regel im selben Commit gelöscht. Alles andere über das Modell ist **offen** und steht in
`91-open-questions.md`.*

### DC — Dokumentation im Code (5)

| | |
|---|---|
| **DC-1** | Kommentare erklären **warum**, nicht was. Wiederholt ein Kommentar den Code, fliegt einer von beiden — meist der Kommentar. |
| **DC-2** | Jede Klasse ein kurzer Docblock: **ein Satz Zweck**, plus das Konzeptdokument, das sie umsetzt. |
| **DC-3** | `@param`/`@return` nur, wo die Typangabe es nicht sagen kann — Feldformen, Einheiten, Bereiche. Nie als Echo der Signatur. |
| **DC-4** | Für einen Ablauf, der aus einer Datei nicht zu sehen ist, ein **kleines Diagramm im Docblock**. |
| **DC-5** | Jeder oberste Quellordner hat ein kurzes `README.md`: was hier lebt, wovon es nicht abhängen darf, wo sein Konzept steht. |

---

## Ebene 2 — die 274 Regeln, die die Software regeln

Diese stehen **nicht** in `CLAUDE.md`, sondern verstreut in den Konzeptdokumenten. **Das ist der
Befund, um den es beim Arbeitsmodell geht:** vor einer Aufgabe konnte niemand wissen, welche davon
gelten.

| Raum | Wofür | Anzahl | Steht in |
|---|---|---|---|
| **C** | Modellkern | **111** | `10-domain-core.md` |
| **R** | Renderer — wie gezeichnet wird | **76** | `30-renderer.md` |
| **M** | Migration | 22 | `70-migration.md` |
| **P** | Speicherung in WordPress | 14 | `50-wordpress-persistence.md` |
| **K** | Rechnen | 12 | `60-calculation.md` |
| **I** | Sprachen und Übersetzung | 10 | `40-i18n.md` |
| **V** | Vision und Umfang | 9 | `00-vision-and-scope.md` |
| **B** | Standardbaum — Inhalt | 8 | `01-standard-tree.md` |
| **A** | Standardbaum — Aufbau | 5 | `01-standard-tree.md` |
| **U** | Bedienung | 5 | `20-interaction.md` |
| **S** | Speicher | 1 | `90-decision-log.md` |
| **Q** | Aus der Altlast übernommene Frage | 1 | `03-legacy-inspiration.md` |
| | **Summe Ebene 2** | **274** | |

---

## Die Regelhygiene, die sich selbst nicht durchgesetzt hat

In `CLAUDE.md` steht am Ende ein eigener Abschnitt dagegen, und er ist zitierfähig, weil er die
Diagnose des **vorigen** Anlaufs enthält:

> *«The previous rule set grew to **82 KB, most of it always-on**, and became **the main reason this
> project kept re-deciding the same questions**.»*

Die fünf Sicherungen, die daraufhin aufgeschrieben wurden:

1. Architekturregeln nennen eine Entscheidung. Keine Ausnahme.
2. Keine Versionsnummern, Funktionsnamen, Dateipfade oder Knotennamen in Regeln.
3. `CLAUDE.md` bleibt unter ~250 Zeilen. Eine Regel hinzufügen heisst fragen, was rausfliegt.
4. Eine Regel, die nichts mehr ändert, wird **gelöscht**, nicht als Referenz behalten.
5. **Regeln beantworten keine offenen Fragen.** Widersprechen sich Regel und offene Frage, gewinnt die offene Frage und die Regel ist falsch.

⚠️ **Sicherung 3 hat gehalten — 135 Zeilen. Die anderen nicht, und der Grund ist Sicherung 3.** *Weil
`CLAUDE.md` klein bleiben musste, wanderten 274 Regeln in die Konzeptdokumente, wo keine Decke, keine
Hygiene und keine Löschpflicht für sie gilt. **Die Regel, die den Regelsatz klein hielt, hat ihn
verstreut.***
