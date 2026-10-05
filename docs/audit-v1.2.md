# Audit des Bestands gegen «Semantische Paketarchitektur und Arbeitsregeln» v1.2

**Stand 2026-09-01.** Kein Code geändert, kein Commit. Jede Zahl in diesem Bericht ist gemessen; wo
ich etwas nicht messen konnte, steht `OPEN` und nicht meine Vermutung.

**Zum Dokument selbst zuerst:** v1.2 hat die Befunde des Prüfberichts zu v1.1 aufgenommen. Es ist
**kürzer** geworden (20 Abschnitte, keine doppelten Nummern), §6 führt `LEGACY` als Übergang ein,
§13 hält `CD`/`DC` ausdrücklich als eigenen Gegenstandsbereich, §14 schützt die strengere Testregel,
§10 kennzeichnet die Renderer-Aussage als Arbeitshypothese. **Die fünf Hauptbefunde sind damit
behoben.** Ein Restfehler: **§3.1 verweist für die Migrationsklausel auf «§13», die Migration ist
aber §6.**

---

## A · Ruleset / bestehende Regeln

**STATUS:** `FACT`

**BEFUND:** 306 Regeln in 16 Buchstabenräumen, verteilt über 15 Dateien. **272 werden irgendwo wieder
zitiert, 35 nie.** `CLAUDE.md` — die einzige Datei, die wie ein Regelsatz aussieht — enthält **32,
also rund 8 %.**

| Raum | Regeln | Wo |
|---|---|---|
| `C` Modellkern | 111 | `10-domain-core.md` |
| `R` Renderer | 76 | `30-renderer.md` |
| `M` Migration | 22 | `70-migration.md` |
| `P` Persistenz | 14 | `50-wordpress-persistence.md` |
| `PR CD AR DC` | 32 | `CLAUDE.md` |
| übrige (`K I V A B U S Q`) | 51 | sechs weitere Dateien |

**BEGRÜNDUNG:** Gemessen mit `scripts/dev/rules-index.php`; das erzeugte Verzeichnis liegt unter
`docs/NewConcept/02-rules-index.md` und ein Wächter hält es aktuell.

**AUSWIRKUNG:** v1.2 §12 regelt, wie Regeln **künftig** entstehen. **Für die 274 vorhandenen
Fachregeln nennt v1.2 keinen Ort.** Nach §6.1 sind sie `LEGACY`. Sie müssen entweder den künftigen
Paketen zugeordnet oder als Historie eingeordnet werden — sonst wiederholt sich genau der Zustand,
den v1.2 beheben soll.

**ENTSCHEIDUNG ERFORDERLICH:** **JA**

### A-1 · Zuordnung der 32 Regeln aus `CLAUDE.md`

| Regel | Nach v1.2 |
|---|---|
| `PR-1` Quelle der Wahrheit | **ersetzt** durch §16 |
| `PR-1b` **Altlast geerntet und geschlossen** | **bleibt — kein Gegenstück in v1.2** |
| `PR-2` Lücke wird zur Entscheidung | ersetzt durch §5, §18 |
| `PR-3` nichts entschieden ohne Eintrag | ersetzt durch §4, verbessert |
| `PR-4` unklar bleibt unklar | ersetzt durch §3.1, §19 |
| `PR-5` Konzept **zuerst aus seinen Aussagen** | **bleibt — §15 verbietet nur, dass die KI entscheidet; die positive Pflicht fehlt** |
| `PR-6` Dokumentationsstil | entfällt (geringes Gewicht) |
| `PR-7` wahrheitsgemäß berichten | ersetzt durch §9, §19 |
| `PR-8` Regelhygiene für die Regeldatei | ersetzt durch §17 |
| `PR-9` **beide Läufe grün vor jedem Commit** | **bleibt, durch §14 ausdrücklich geschützt** |
| `PR-10` **nachlesen und zitieren** | **bleibt — kein Gegenstück** |
| `PR-11` **keine unbewachte Textumwandlung** | **bleibt — kein Gegenstück** |
| `PR-12` **Wächter, Leser, Daten** | **bleibt — kein Gegenstück** |
| `CD-1` … `CD-12` | **bleiben** — §13 hält sie ausdrücklich |
| `AR-1`, `AR-2` | **bleiben** — kein Gegenstück in v1.2 |
| `DC-1` … `DC-5` | **bleiben** — §13 nennt `DC` ausdrücklich |

**Ergebnis: 8 ersetzt, 1 entfällt, 23 bleiben.** Davon **fünf ohne jedes Gegenstück in v1.2**:
`PR-1b`, `PR-5`, `PR-10`, `PR-11`, `PR-12`, sowie `AR-1` und `AR-2`.

**VORSCHLAG (`PROPOSED`):** v1.2 bekommt einen Abschnitt «übernommene verbindliche Regeln», in dem
diese sieben mit `ACTIVE` stehen — nicht als Text wiederholt, sondern als Verweis. Begründung: §13
schützt `CD`/`DC` schon; `PR-1b`, `PR-5`, `PR-10`, `PR-11`, `PR-12`, `AR-1`, `AR-2` fallen sonst
durch, weil sie weder Code-Standard noch Prozess im Sinne von v1.2 sind.

---

## B · Entscheidungen

**STATUS:** `FACT`

**BEFUND:** **562 Entscheidungen. 519 davon (92 %) tragen keine Statusmarkierung.** Markiert sind:
29 als überholt, 17 als verworfen, 3 als Vorschlag.

**BEGRÜNDUNG:** Gezählt über `90-decision-log.md`.

**AUSWIRKUNG:** Nach §3.1 und §6.1 sind alle 562 zunächst `LEGACY`. **Das ist richtig und es ist
gefährlich zugleich**: darunter liegen die Entscheidungen, auf denen der gesamte gebaute Zustand
steht. §6.2 fordert ausdrücklich, dass dabei nichts unabsichtlich verschwindet.

**ENTSCHEIDUNG ERFORDERLICH:** **JA**

### B-1 · Kandidaten für sofortiges `ACTIVE`

Diese sechs tragen den gebauten Zustand. **Zwei davon sind durch Messung belegt, nicht nur
dokumentiert:**

| Entscheidung | Inhalt | Vorgeschlagener Status | Belegt durch |
|---|---|---|---|
| `D-009` | WordPress am Rand, moderner Kern (`CD-1`) | **`ACTIVE` / `FACT`** | **gemessen: kein WordPress-Aufruf in `src/Core`** — siehe F |
| `D-342` | beide Prüfläufe grün vor jedem Commit (`PR-9`) | **`ACTIVE`** | gemessen: 460 Kernprüfungen laufen; §14 schützt die Regel |
| `D-338` | `10-domain-core.md` gesperrt, Produktionscode erlaubt | `ACTIVE` | der gesamte Bau von Paket 1–7 steht darauf |
| `D-007` / `D-019` | Modell in eigenen Tabellen (`AR-1`) | `ACTIVE` | gemessen: 12 Tabellen, keine Nutzung von Posts/Terms |
| `D-020` | nichts Benutzersichtbares fest verdrahtet (`AR-2`) | `ACTIVE` | Labels je Sprache in der Tabelle `labels` |
| `D-169` / `D-170` | zwei Prüfläufe, WordPress **um** den Kern | `ACTIVE` | `tests/README.md`, beide Läufe existieren |

### B-2 · Kandidaten für `SUPERSEDED` bzw. `REJECTED`

| | |
|---|---|
| **`D-133`** | trägt schon «Überholt durch `D-232`» → `SUPERSEDED`. **Aber `D-134` beruft sich weiter darauf** (siehe E-1). |
| **`D-529`** hat `settings` als Basistabelle abgelöst | die Tabelle `settings` existiert noch → der Umbau ist begonnen, nicht fertig |
| **108 geltende Stellen** berufen sich auf zurückgenommene Entscheidungen | jede einzelne ist ein `SUPERSEDED`, das noch als Grund zitiert wird |

### B-3 · Was nur Annahme war

**STATUS:** `FACT` (der Befund), `INFERRED` (die Einordnung)

**BEFUND:** In den **ersten hundert Entscheidungen** ist **3 %** ein Satz des Eigentümers zuzuordnen;
im letzten Block 90 %. Diese ersten hundert werden am meisten zitiert: **267 Verweise aus 186
späteren Entscheidungen, 212 Nennungen im Quelltext.**

**BEGRÜNDUNG:** `scripts/dev/foundation-weight.php`. **Ein fehlendes Zitat ist ein Hinweis, kein
Urteil** — das frühe Buch zitierte generell nicht. Es sagt, **wo nachzufragen ist.**

**AUSWIRKUNG:** Das Fundament ist der Teil, der am wenigsten auf bestätigte Aussagen zurückführbar
ist und am meisten getragen wird. Nach §3.1 sind das `INFERRED`, nicht `CONFIRMED`.

**ENTSCHEIDUNG ERFORDERLICH:** **JA** — in der Reihenfolge, die `foundation-weight.php` ausgibt.

---

## C · Legacy-Bestand

**STATUS:** `FACT`

**BEFUND:** Drei getrennte Altbestände, gemessen:

| | Grösse | Heutiger Status |
|---|---|---|
| `legacy-code/` | **4387 KB** | läuft nicht mehr, `PR-1b` |
| `docs/legacy/` | **601 KB** | eingefroren, geerntet, `PR-1b` |
| `docs/NewConcept/` | **2688 KB** | **war bisher die Quelle der Wahrheit** |

**BEGRÜNDUNG:** `du -sk`.

**AUSWIRKUNG:** Die ersten beiden sind bereits sauber behandelt — **`PR-1b` ist der einzige Grund
dafür**, und v1.2 hat kein Gegenstück. **Ohne `PR-1b` wird `docs/legacy/` wieder zitierfähig.**

Der dritte ist der eigentliche Fall: nach §6.1 wird `docs/NewConcept/` mit der Übernahme
**`LEGACY`**. Das ist folgerichtig und es ist der grösste Einzelposten der Migration.

**ENTSCHEIDUNG ERFORDERLICH:** **JA** — `PR-1b` als `ACTIVE` übernehmen, und festlegen, ob
`docs/NewConcept/` wie `docs/legacy/` behandelt wird: **einfrieren, ernten, schliessen.**

---

## D · Paketstruktur

**STATUS:** `FACT` (Ist-Zustand), `OPEN` (Zuordnung)

**BEFUND:** Heute zwei oberste Bereiche:

```text
src/Core         121 Dateien, 19177 Zeilen
  Converter  Exception  Model  Renderer  Repository  Service  Validator
src/WordPress     23 Dateien, 10165 Zeilen
  Admin  Persistence
```

**AUSWIRKUNG — zwei Stellen, an denen der Schnitt von v1.2 §2 nicht auf den Bau passt:**

**D-1 · Der Renderer liegt heute *im* Kern.** `src/Core/Renderer` mit **51 Klassen**. v1.2 §2 führt
«Renderer / UI-Abstraktion» als eigenes Paket **hinter** Domain/Core. Entweder zieht der Renderer aus
dem Kern aus, oder die Paketgrenze verläuft nicht an der Ordnergrenze.

**D-2 · Datenbank und Plattformadapter liegen heute in einem Ordner.** `src/WordPress/Persistence`
enthält beides: die technische Speicherung und den WordPress-Adapter. v1.2 §2 trennt «Datenbank»,
«Persistenz-/Datenzugriff» und «Plattformadapter» in drei Stufen.

**D-3 · Die Richtung der Abhängigkeit ist weiter nicht festgelegt.** §2 zeichnet
`Datenbank ↓ … ↓ WordPress` — das liest sich als Schichtenstapel. Der Arbeitsauftrag §14 sagt
`Core ↓ abstrakte Schnittstelle ↓ WordPress-Adapter` — das ist die Umkehrung. **Heute im Code gilt
das Zweite:** der Kern erklärt Repository-Schnittstellen, `src/WordPress/Persistence` erfüllt sie.

**D-4 · Fünf Dateien je Paket sind viel.** §2 nennt `package.md tasks.md bugs.md inbox.md history.md`.
Bei sechs Paketen sind das 30 Dateien. **Vorschlag (`PROPOSED`):** eine Datei entsteht erst, wenn sie
Inhalt hat; `package.md` ist die einzige Pflicht.

**ENTSCHEIDUNG ERFORDERLICH:** **JA** für D-1, D-2, D-3.

---

## E · Datenmodell

**STATUS:** `FACT`

**BEFUND:** **12 Tabellen**, aus `Schema.php` gelesen, nicht erinnert:

```text
LIVE_TABLES:  nodes  relations  records  record_values
weitere:      identities  settings  labels  changelog
Schatten:     vier *_history-Tabellen
```

### E-1 · `record_values.path` — der Fall, den der Arbeitsauftrag §13 nennt

**STATUS:** `FACT` (Befund), `PROPOSED` (Folge)

**BEFUND:** **Alle 183 Wertzeilen haben `path === edge_id`.** Die zusammengesetzte Form hat **keinen
einzigen Bestand.** Die Spalte steht wegen `D-134` da — «required by `D-133`'s flattening» — und
**`D-133` wurde am Tag danach durch `D-232` abgelöst.**

**BEGRÜNDUNG:** Gemessen; `scripts/dev/superseded-check.php` findet die Berufung.

**AUSWIRKUNG:** Genau der Fall aus §13 des Arbeitsauftrags: *«Oder ist er lediglich aus einer früheren
Implementierungsannahme entstanden?»* — **Ja.** Der Eigentümer hat zudem mehrfach gesagt, dass «Pfad»
nicht zu seinem Wortschatz gehört; er meinte Ids in einzelnen Tabellenzeilen.

**VORSCHLAG:** `record_values.path` → `REJECTED`, Entfernung in der Reihenfolge `PR-12`: **Wächter,
Leser, Daten.** Vorher zu prüfen ist Zeile 109 der Arbeitsliste — ob eine Komposition mit
Multiplizität 1 immer ihren eigenen Datensatz bekommt.

**ENTSCHEIDUNG ERFORDERLICH:** **JA**

### E-2 · `nodes.path` steht auf einer falsch zitierten Entscheidung

**BEFUND:** `50-wordpress-persistence.md` nennt für `nodes.path` **`D-014`**. Richtig ist **`D-082`**,
und die trägt `agreed (proposal)`.

**AUSWIRKUNG:** Nach §3.1 ist das **`PROPOSED`, nicht `ACTIVE`** — eine Spalte im Datenmodell steht
auf einem Vorschlag, der als Entscheidung zitiert wird.

**ENTSCHEIDUNG ERFORDERLICH:** **JA**

### E-3 · `settings` ist eine halb abgeschlossene Konzeptänderung

**BEFUND:** `D-529` hat entschieden: eine Einstellung ist ein Feld, also eine Kante. Die Tabelle
`settings` existiert weiter. **Das ist ein Umbau im Gang, kein Widerspruch** — aber nach §5 muss der
Übergang sichtbar sein und einen Zielzustand haben.

**ENTSCHEIDUNG ERFORDERLICH:** **NEIN** (läuft), **aber als Aufgabe zu führen.**

---

## F · Core / Framework-Grenzen

**STATUS:** `FACT`

**BEFUND:** **`src/Core` enthält keinen einzigen WordPress-Aufruf.** Geprüft gegen 20 typische
Funktionen (`add_action`, `get_option`, `current_user_can`, `esc_html`, `wpdb`, `__()` und weitere),
Kommentare herausgerechnet. **Kein Treffer.**

**BEGRÜNDUNG:** Eigene Messung während dieses Audits.

**AUSWIRKUNG:** **Das ist die einzige Architekturgrenze dieses Projekts ohne einen einzigen
Verstoss — und der Grund ist nachweisbar nicht die Formulierung der Regel, sondern dass ein Prüflauf
beim ersten Verstoss scheitert.** Das ist der stärkste vorhandene Beleg für §11.

`CD-1` und `D-009` können damit sofort `ACTIVE` werden, gestützt auf eine Messung.

### F-1 · Eine Abweichung, die festgehalten werden muss

**STATUS:** `FACT`

**BEFUND:** Das Escapen der Ausgabe geschieht **im Kern**, nicht am Rand: `RenderResult::escape()`
benutzt `htmlspecialchars()`, ausdrücklich weil `CD-1` dem Kern `esc_html()` verbietet.

**AUSWIRKUNG:** `CD-5` schreibt «escape on output» am **Rand** vor. Tatsächlich ist der Schritt im
Kern erfüllt, mit dokumentiertem Grund. **Das ist kein Verstoss, aber es steht so nirgends** — und
eine spätere Prüfung würde es als Lücke lesen.

**ENTSCHEIDUNG ERFORDERLICH:** **JA** (klein) — `CD-5` präzisieren: der Escape-Schritt liegt im Kern,
und `RenderResult` ist die einzige Stelle, die HTML buchstabiert.

---

## G · Renderer

**STATUS:** `FACT` (Ist-Zustand), `PROPOSED` (§10)

**BEFUND:** **51 Renderer-Klassen.** `render()` gibt **nicht** `string` zurück, sondern ein
`RenderResult`. Darin:

```text
public readonly string $markup      fertiges, escapetes HTML
public readonly array  $usedEdges   welche Kanten eingegangen sind
public readonly mixed  $condition   unter Purpose::Search der Filter
```

**AUSWIRKUNG — und das ist die Antwort auf §10:** Die Naht für die Arbeitshypothese **existiert
schon**: der Renderer gibt ein Objekt zurück, und neben dem Markup stehen bereits **strukturierte
Angaben** (`usedEdges`, `condition`). **Aber `markup` ist HTML.** Die Hypothese «plattformunabhängige
UI-Beschreibung» ist **nicht gebaut**.

Der Umbau wäre: `markup: string` durch eine Struktur ersetzen. Betroffen: **51 Klassen und 76
`R`-Regeln.** `CD-8` («Darstellung gibt Zeichenketten zurück») müsste mit geändert werden.

**Der Status in v1.2 §10 ist damit korrekt.** Ich schlage die Änderung **nicht** vor, solange die
Kosten nicht entschieden sind — sie ist gross und der heutige Zustand funktioniert.

**ENTSCHEIDUNG ERFORDERLICH:** **JA**, aber nicht dringend. Zwei Fragen: bleibt der Renderer im Kern
(D-1)? Und wird die Hypothese verfolgt?

---

## H · Event-System

**STATUS:** `FACT`

**BEFUND:** **Keine einzige Datei** in `src/` heisst Event, Listener, Action oder Dispatcher. **Das
Ereignissystem ist nicht gebaut.**

**BEGRÜNDUNG:** Dateisuche über `src/`.

**AUSWIRKUNG:** Damit ist es der einzige Bereich **ohne Altlast** — hier kann v1.2 von Anfang an
gelten, ohne Migration. Die offenen Punkte des Arbeitsauftrags §16 und §17 (Event-Adresse, Herkunft,
Rückkopplungsschleifen) sind echte offene Fragen und nirgends beantwortet.

Grundlage sind `docs/uebergabe/konzept-kompakt.md` und `antwort-ereignisse.md`, wo Abschnitt 7
ausdrücklich Arbeitshypothese ist.

**ENTSCHEIDUNG ERFORDERLICH:** **NEIN** — noch nicht. Erst Konzept, dann Bau.

---

## I · Änderungs-/Versionssystem

**STATUS:** `FACT`

**BEFUND:** Es existiert und wird benutzt. `src/WordPress/Persistence/Shadow.php`, verdrahtet in
`Restore`, `Schema` und drei Repositories. Vier Schattentabellen, eine `changelog`-Tabelle.

**AUSWIRKUNG:** Der Arbeitsauftrag §18 verlangt, das bestehende System nicht durch ein zweites
event-basiertes zu ersetzen. **Der Befund stützt das:** das Vorhandene läuft, ist bewacht
(`shadow-shape-check.php`) und der Entwurf stammt vom Eigentümer.

Offen sind zwei Schritte: das Journal schrumpft, und das Zurückspringen selbst.

**Ein ungeprüfter Punkt:** `records_history` steht auf 0 Zeilen. Vermutlich richtig, weil kein
Prüflauf einen Knoten **mit** Datensätzen geleert hat — **aber ungeprüft.** `STATUS: OPEN`.

**ENTSCHEIDUNG ERFORDERLICH:** **NEIN.** `PROPOSED`: Ereignisse **speisen** die Änderungs-Engine,
sie ersetzen sie nicht.

---

## J · Sicherheitsregeln

**STATUS:** `FACT`

**BEFUND — die Regel ist erfüllt, und zwar durch ein einziges Tor:**

```text
handlePost()          die EINZIGE oeffentliche Methode, die POST liest
  Zeile 3337          current_user_can(Plugin::CAPABILITY)
  Zeile 3343          check_admin_referer(...)
  dahinter            10 private save*-Methoden
```

Alle zehn Schreibwege (`saveRecord`, `saveField`, `saveLabels`, `saveNodePage`, `saveFieldRows`,
`savePartValues`, `saveSettingValues`, `saveKind`, `saveSettings`, `saveNodePage`) sind **privat** und
nur über dieses Tor erreichbar. Registrierte Hooks: `admin_menu`, `admin_post_taxmod_node`, zwei
weitere `admin_post_`, `admin_init`, `admin_print_styles-`.

**BEGRÜNDUNG:** Eigene Messung: alle Methoden gesucht, die `$_POST`/`$_REQUEST` lesen, und geprüft,
welche öffentlich sind.

**AUSWIRKUNG — der eigentliche Befund:** **Die Regel ist erfüllt, aber unbewacht.** Wird eine neue
öffentliche Methode angelegt, die POST liest, oder ein weiterer `admin_post_`-Hook direkt auf eine
`save*`-Methode gelegt, ist das Tor umgangen — **und nichts wird rot.**

Zwei Anzeige-Helfer (`hidden()`, `circumstance()`) lesen POST ausserhalb des Tors. Das ist
zulässig — sie schreiben nicht —, gehört aber in die Kategorie `SICHTBAR`, nicht `BEWACHT`.

**ENTSCHEIDUNG ERFORDERLICH:** **JA** — Wächter `M-1`, siehe M. Die Regeln `CD-5` und `CD-6` bleiben
unverändert `ACTIVE`; §13 schützt sie.

---

## K · Testregeln

**STATUS:** `FACT`

**BEFUND:**

| | |
|---|---|
| Kern | **460 Prüfungen, 5257 Zusagen, grün** |
| Rand | rund 60 Skripte `scripts/dev/*-check.php` |
| rot | **sechs Läufe** — `doc-reach`, `materialise`, `node-kind`, `orphans`, `renderer-choice`, `scaffold` |
| abgestürzt | **einer** — `record-on-any-node`, `NotYetStorable` über «exponent» |

**BEGRÜNDUNG:** Beide Läufe im Rahmen dieses Audits ausgeführt.

**AUSWIRKUNG:** Alle sieben sind **vor** diesem Audit rot und hängen an einer Ursache: `persistent`
hat keine Kante im Modell (Zeile 103 der Arbeitsliste). **Wer sie vorfindet, hat sie nicht
verursacht.**

`PR-9` verlangt beide Läufe grün vor jedem Commit. **Das ist heute nicht erfüllt** — und wird
toleriert, weil die sieben eine bekannte, benannte Ursache haben. Nach v1.2 §14 muss dieser
Ausnahmezustand einen Status bekommen, statt Gewohnheit zu sein.

**ENTSCHEIDUNG ERFORDERLICH:** **JA** — entweder Zeile 103 vorziehen, oder die sieben ausdrücklich
als bekannter Ausnahmezustand mit Frist führen.

---

## L · Dokumentationsprobleme

**STATUS:** `FACT`

**BEFUND:**

| | |
|---|---|
| `docs/NewConcept/` gesamt | **2688 KB** |
| `90-decision-log.md` | **868 KB**, 562 Entscheidungen |
| davon die Entscheidungen selbst | 244 KB (36 %), Median **404 Zeichen** |
| davon Herleitung des Assistenten | **442 KB (64 %)** |
| offene Fragen | **143 Nummern**, 72 Zeilen mit Status `open` |
| `CLAUDE.md` behauptet | «8 still say open» — **veraltet** |

**AUSWIRKUNG:** v1.2 §1 und §4 treffen das genau: die Entscheidung und ihr kurzer Grund bleiben, die
Herleitung geht in die Historie. **Gerechnet fällt damit das, was bei jeder Orientierung mitgelesen
wird, von 868 auf rund 244 KB** — ohne dass eine Entscheidung verschwindet.

Der Median von 404 Zeichen zeigt: **die Entscheidungen selbst sind angemessen kurz.** Das Problem ist
ausschliesslich die Herleitung darunter.

**ENTSCHEIDUNG ERFORDERLICH:** **JA** — ob die 442 KB in eine Historie umziehen oder wegfallen.

---

## M · Mögliche Wächter

**STATUS:** `PROPOSED`

Nach Nutzen geordnet, nicht nach Aufwand. §11.2 verlangt wenige starke.

| | Wächter | Bewacht | Warum dieser |
|---|---|---|---|
| **M-1** | **Keine öffentliche Methode und kein `admin_post_`-Hook liest POST ausserhalb des Tors** | `CD-5` | Der einzige Wächter dieser Liste, dessen Fehlen eine **Sicherheitslücke** erzeugt statt Verwirrung. Siehe J. |
| **M-2** | **Erklärte Paketabhängigkeiten gegen tatsächliche Importe** | §11.1 | Die drei häufigsten Fehler dieses Projekts — gebaut und nicht angeschlossen, zweimal gebaut, ein Leser an der alten Quelle — sind alle drei genau diese Abweichung. Findet den Schaden **vorher**. |
| **M-3** | Kern ohne Framework | `CD-1` | **Existiert schon** und ist der Grund, dass diese Grenze nie verletzt wurde. |
| **M-4** | Jede Entscheidung trägt genau einen Status; `SUPERSEDED` nennt einen existierenden `ACTIVE`-Nachfolger | §3.1, §4 | Macht die Migration aus B überhaupt prüfbar. |
| **M-5** | Keine `ACTIVE`-Aussage zitiert eine `SUPERSEDED` | §5, §12.2 | **Zwei Drittel existieren** als `superseded-check.php`, Decke 108, darf nur fallen. |
| **M-6** | Jede `TASK-`/`BUG-`Nummer aus einer Commit-Nachricht steht in einer Liste | §7 | «Später bearbeiten» heisst nicht vergessen — prüfbar gemacht. |
| **M-7** | Kein `INF-` ohne Kategorie älter als *n* Tage | §7.2 | dito für die Inbox. |
| **M-8** | Regelzahl darf nicht ohne Entscheidung steigen | §12, §17 | **Existiert** als `rules-index-check.php`. |

**Nicht bewachbar und daher `KONVENTION`:** §8.3 (Nachfragen), §9 (Erkenntnisstatus), §15
(Zusammenarbeit), §18 (Arbeitsablauf). **Das ist Ehrlichkeit, kein Mangel** — ein Regelsatz, in dem
Konventionen wie Wächter aussehen, erzeugt das Gefühl von Kontrolle.

---

## N · Offene Konzeptfragen

**STATUS:** `OPEN`

**Aus dem Bestand, unbeantwortet und dem Eigentümer vorliegend:**

| | |
|---|---|
| **N-1** | `label_role` — Variante a, b oder c (`OQ-134`) |
| **N-2** | Gehören min/max in den Validator? |
| **N-3** | Der Löschknopf am Datensatz: darf ein Teil allein gelöscht werden? |
| **N-4** | Gehören `factor`/`offset` an die Basiseinheiten statt an «ohne Präfix»? |

**Neu aus diesem Audit:**

| | |
|---|---|
| **N-5** | Bleibt der Renderer im Kern, oder wird er ein eigenes Paket? (D-1) |
| **N-6** | Werden Datenbank, Datenzugriff und Plattformadapter getrennt? (D-2) |
| **N-7** | Erklärt der Kern die Schnittstellen, die die Persistenz erfüllt — oder liegt der Kern auf der Datenbank? (D-3) |
| **N-8** | Wird die Renderer-Hypothese aus §10 verfolgt? Kosten: 51 Klassen, 76 `R`-Regeln, `CD-8` |
| **N-9** | Wer trägt den Status in die 562 Entscheidungen ein, und in welcher Reihenfolge? |
| **N-10** | Wird `docs/NewConcept/` eingefroren und geerntet, wie `docs/legacy/` es wurde? |
| **N-11** | `records_history` steht auf 0 — richtig oder ungeprüfte Lücke? |
| **N-12** | Die sieben roten Randläufe: Zeile 103 vorziehen oder als befristeten Ausnahmezustand führen? |

---

## Widersprüchliche Anforderungen — aufgelistet, nicht entschieden

§23 des Arbeitsauftrags verlangt, Widersprüche aufzulisten statt einen stillschweigend zu bevorzugen.

| | Widerspruch |
|---|---|
| **1** | **§2 gegen Arbeitsauftrag §14.** §2 zeichnet `Datenbank ↓ … ↓ WordPress` (Schichtenstapel, Kern auf der Datenbank). §14 sagt `Core ↓ Schnittstelle ↓ WordPress-Adapter` (Umkehrung). Heute gilt im Code das Zweite. |
| **2** | **§3.1 verweist auf «§13», gemeint ist §6.** Querverweisfehler. |
| **3** | **§2 (fünf Dateien je Paket) gegen §12 (möglichst wenige Regeln und Artefakte).** Bei sechs Paketen 30 Dateien. |
| **4** | **§10 gegen `CD-8`.** §10 hält die plattformunabhängige Beschreibung offen; `CD-8` verlangt heute Zeichenketten. Solange §10 `PROPOSED` ist, kein Konflikt — bei Bestätigung ist `CD-8` mit zu ändern. |
| **5** | **§14 gegen den Ist-Zustand.** `PR-9` verlangt beide Läufe grün; sieben sind rot. Die Regel ist richtig, der Zustand weicht ab — nach §16 ist der Konflikt sichtbar zu machen, was hiermit geschieht. |

---

## Zusammenfassung

```text
BEZUG
Audit des Bestands gegen v1.2, Bereiche A bis N.

ERGEBNIS
v1.2 ist uebernahmefaehig. Die fuenf Hauptbefunde aus v1.1 sind behoben.
Der Bestand ist besser als seine Dokumentation: die eine Grenze, die
gemessen nie verletzt wurde (Kern ohne WordPress), ist genau die mit
einem Waechter. Die Sicherheitsregel ist erfuellt, aber unbewacht.

GEAENDERT
Nichts. Kein Code, kein Commit.

WICHTIGSTE BEFUNDE
519 von 562 Entscheidungen (92 %) haben keinen Status -> alle LEGACY.
src/Core enthaelt keinen einzigen WordPress-Aufruf - gemessen.
Ein Tor sichert alle 10 Schreibwege, aber nichts bewacht das Tor.
Der Renderer gibt schon ein Objekt zurueck, darin aber fertiges HTML.
Das Ereignissystem ist nicht gebaut - der einzige Bereich ohne Altlast.
record_values.path hat in 183 Zeilen keinen einzigen Bestand.
Sieben Randlaeufe sind rot, alle aus einer Ursache, alle vorbestehend.

REGELN
8 ersetzt, 1 entfaellt, 23 bleiben. Fuenf ohne Gegenstueck in v1.2:
PR-1b, PR-5, PR-10, PR-11, PR-12 - dazu AR-1 und AR-2.

ENTSCHEIDUNGEN ZUR BESTAETIGUNG
B-1  sechs Entscheidungen sofort ACTIVE, zwei davon messungsbelegt
A-1  die sieben Regeln ohne Gegenstueck als ACTIVE uebernehmen
C    PR-1b behalten, sonst wird docs/legacy wieder zitierfaehig
M-1  Waechter fuer das Sicherheitstor - hoechste Prioritaet
M-2  Waechter fuer erklaerte gegen tatsaechliche Abhaengigkeiten

OFFENE FRAGEN
N-1 bis N-12, davon N-5 bis N-8 neu aus diesem Audit.

NAECHSTER SCHRITT
M-1 bauen. Er ist der einzige Punkt dieses Audits, dessen Fehlen eine
Sicherheitsluecke erzeugt statt Verwirrung - und er braucht keine
Konzeptentscheidung.
```
