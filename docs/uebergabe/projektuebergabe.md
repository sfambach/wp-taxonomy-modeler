# Projektübergabe — an die nächste KI

**Stand 2026-09-01.** Dieses Blatt ist für eine KI geschrieben, die dieses Projekt übernimmt. Es
ersetzt kein Dokument. Es sagt, **in welcher Reihenfolge zu lesen ist, was am Bestand verdächtig ist,
und wie der Eigentümer arbeitet.**

Das Letzte ist der eigentliche Inhalt. Es stand bisher nur im Gedächtnis des vorigen Assistenten und
wäre sonst verloren. **Ohne Abschnitt 3 kostest du den Eigentümer dieselben Korrekturen noch einmal.**

---

## 0 · Die eine Regel, ohne die alles andere nichts hilft

**Antworte nie aus dem Gedächtnis, wenn die Frage lautet «was wurde entschieden».** Lies die Quelle
und **zitiere den Satz mit seiner `D-<nnn>`.** Das steht als `PR-10` in [`CLAUDE.md`](../../CLAUDE.md)
und ist dort **als Messung** begründet, nicht als Stilwunsch:

> *«every answer given from memory that day was wrong … Every answer given after a `grep` held — and
> twice it showed the **owner** his own error»*

Eine Gesprächszusammenfassung ist eine Erinnerung an das Geschehene, **nie ein Ersatz für die
Dokumente**.

Und die Verschärfung, die teuer war: **prüfe, wessen Satz du zitierst.** Ein Eintrag im
Entscheidungsbuch besteht aus seinem Zitat **plus der ⚠️-Herleitung des Assistenten**. Diese
Herleitung ist Beweismaterial, keine Entscheidung. Sie einmal gegen ihn zu zitieren kostete fünf
Worte Antwort: *«das waren nicht meine argumente sondern deine.»*

---

## 1 · Lesereihenfolge

| Schritt | Was | Warum |
|---|---|---|
| 1 | [`CLAUDE.md`](../../CLAUDE.md) — 135 Zeilen | Prozess (`PR`), Code (`CD`), Architektur (`AR`), Doku im Code (`DC`). **Alles darin ist erkauft**; jede ⚠️-Stelle nennt den Vorfall. |
| 2 | [`02-rules-index.md`](../NewConcept/02-rules-index.md) | **Alle 306 Regeln des Projekts an einer Stelle**, mit Fundort und Zitatzahl. Erzeugt, nicht gepflegt. Lies die Übersichtstabelle, nicht alle 306 — dann weisst du, welcher Raum für deine Aufgabe zuständig ist. |
| 3 | [`AGENTS.md`](../../AGENTS.md) — 167 Zeilen | Entwicklungsumgebung: Laragon auf Windows, SQLite auf der Cloud-VM, wie beide Prüfläufe starten. |
| 4 | Dieses Blatt, Abschnitt 3 und 4 | Wie er arbeitet, und was am Bestand verdächtig ist. |
| 5 | [`97-implementation-plan.md`](../NewConcept/97-implementation-plan.md), **die Arbeitsliste** | **Das ist die Reihenfolge der Arbeit** — nicht der Fahrplan darüber. Der Fahrplan ist *später, vielleicht*; die Liste ist *als Nächstes, in dieser Reihenfolge*. |
| 6 | [`01-glossary.md`](../NewConcept/01-glossary.md) | Der Wortschatz. Acht Begriffe darin gingen an einem einzigen Tag aneinander vorbei. |
| 7 | Der Schwanz von [`90-decision-log.md`](../NewConcept/90-decision-log.md) und [`91-open-questions.md`](../NewConcept/91-open-questions.md) | Die letzten Entscheidungen, die offenen Fragen. **Nicht das ganze Buch — siehe Abschnitt 4.** |
| 8 | [`datenmodell.md`](datenmodell.md) | Zwölf Tabellen, Spalte für Spalte, jede Zahl gemessen. |

Die übrigen Blätter hier ([`konzept-kompakt.md`](konzept-kompakt.md),
[`antwort-ereignisse.md`](antwort-ereignisse.md)) wurden geschrieben, um das **Ereigniskonzept** an
Dritte zu vergeben. Das Ereignissystem ist **nicht gebaut**.

---

## 2 · Was gebaut ist, und wie der Stand geprüft wird

Ein WordPress-Plugin, `wp-taxonomy-modeler`. Der Modellkern kennt **keine** WordPress-Funktion
(`CD-1`); WordPress greift von aussen hinein. Pakete 1–7 stehen und sind bewacht.

**Zwei Prüfläufe, beide grün, bevor irgendetwas committet wird** (`PR-9`):

- **Kern** unter PHPUnit, ohne WordPress. **Stand: 460 Prüfungen, 5257 Zusagen, grün.**
- **Rand** gegen eine echte Datenbank — die Skripte `scripts/dev/*-check.php`, **rund 60 Stück**.

**Am Rand sind sieben Läufe rot, und sie waren es vor dieser Übergabe.** Wer sie vorfindet, hat sie
nicht verursacht: `doc-reach`, `materialise`, `node-kind`, `orphans`, `renderer-choice`, `scaffold` —
und **ein Absturz**, `record-on-any-node`, eine `NotYetStorable`-Ausnahme über «exponent». Alle sieben
hängen an Zeile 103 der Arbeitsliste.

**Die Wächter sind das Verlässlichste an diesem Projekt.** Dokumente veralten still; ein Wächter geht
rot. Vier sind eigens gegen das Vergessen gebaut:

| | |
|---|---|
| `references-check` | Zitiert jemand eine `D-`/`OQ-`-Nummer, die nie geschrieben wurde? |
| `superseded-check` | Beruft sich jemand auf eine **zurückgenommene** Entscheidung? Decke 108, sie darf nur fallen. |
| `rules-index-check` | Gibt es eine Regel, die im Regelverzeichnis fehlt? |
| `foundation-weight` | Kein Wächter, eine Reihenfolge: die ersten hundert Entscheidungen nach Gewicht, mit der Frage «von ihm oder vom Assistenten?». |

---

## 3 · Wie der Eigentümer arbeitet

**Er diktiert per Spracherkennung, auf Deutsch.** Englische Fachwörter kommen verstümmelt an, seine
deutschen Sätze nicht. Gemessene Beispiele: *Rennrad* und *Vendora* = Renderer, *Fahrt* = Pfad,
*Textserie* = Textarea, *Schah* = char, *Buhl* = bool, *Menomax* = min/max, *Merkmalitatoren* =
Validatoren. **Rate nicht, was ein verstümmeltes Wort heisst — frag, wenn es die Sache ändert.** Aus
falsch geratenen Diktatwörtern sind hier Datenbankspalten entstanden.

| | Regel |
|---|---|
| **Antwort** | **Was er entscheiden oder korrigieren soll, steht in der ersten Zeile.** Er filtert, überliest sonst, und **kann dann nicht rechtzeitig eingreifen** — das ist sein eigentliches Problem, nicht die Länge. Seine Worte: *«Ich bin ja kein Computer … Ich muss filtern, sonst würde ich hier tagelang deine Texte lesen.»* |
| | **Antwort zuerst, in einem einfachen Satz.** Nie zur Pointe hinarbeiten. **Keine Aphorismen, keine Schlusssentenz** — genau dort liest er drei-, viermal. Fett nur für das, worauf er reagieren muss; sonst heisst fett nichts mehr. |
| **Keine Programmierausgaben** | **Kein SQL, keine Fehlermeldungen, keine Codeblöcke aus Werkzeugläufen, keine Datei-für-Datei-Listen.** Seine Worte: *«die Informationen sind nicht aussagend für mich und helfen mir auch nicht, weil Du das selbst behebst.»* Was bleibt: der Befund in Worten, die Zahl, wenn sie seine Entscheidung trägt, und der nächste Schritt. **Messen ja, das Messgerät zeigen nein.** |
| **Reihenfolge** | **Änderungen, die er mitten in der Arbeit postet, kommen hinten an die Arbeitsliste**, und die laufende Zeile wird fertig. Seine Worte: *«bitte absofort alle änderungen die ich poste hinten anhängen»* und *«ich möchte dass du immer erst fertig baust dann das nächste angehst auch wenn ich dazwischen brabbel»*. Nie stillschweigend umsortieren. |
| **Hindernisse** | **Nicht anhalten und fragen.** Zur nächsten offenen Zeile weitergehen, die Hindernisse sammeln, am Ende **als eine Liste** zeigen, jedes mit der Entscheidung, die es braucht. Seine Worte: *«wenn du auf ein problem stösst dann mit dem nächsten punkt weiter machen, die probleme sammeln und am ende zeigen».* |
| **Wortschatz** | **Knoten, Kante, Knoten-Datensatz, Kanten-Datensatz.** Keine erfundenen Synonyme. **«Feld», nicht «Attribut»** ([D-462](../NewConcept/90-decision-log.md)) — und er hat gebeten, ihn darauf hinzuweisen, wenn er selbst «Attribut» sagt: *«bitte weise mich darauf hin.»* Ein kurzer Nebensatz, kein Absatz, nicht zweimal in derselben Antwort. |
| **«Pfad»** | **Gehört nicht zu seinem Wortschatz.** Er sagte «der Pfad aus den einzelnen Ids» und meinte **Ids in einzelnen Tabellenzeilen**. Daraus wurde eine punktseparierte Zeichenkette und eine Datenbankspalte. Er musste das über Tage wiederholt korrigieren. |
| **Code zeigen** | Steht `$this` im gezeigten Code, **sage, welche Klasse das ist**; kommt die Methode geerbt, nenne beide und ein Beispielkind. |

### Das Muster, an dem die meiste Zeit verloren ging

Er hat es selbst benannt: *«du hast ein anderes Modell als ich im Kopf, und wenn ich was sage,
interpretierst du das anders.»*

1. **Klingt ein Satz nach einem neuen Ding, suche erst nach einer neuen Adresse auf ein vorhandenes.** *«Da brauchst du den Datensatz an der Kante»* hiess **ein Wert, der die Kante adressiert**. Daraus wurde ein Behälter gebaut, den es nicht gibt. **Sein Modell ist kleiner als das, was eine KI daraus macht.**
2. **Können zwei Angaben nie widersprechen, sind es keine zwei.** So fielen `mandatory` ([D-405](../NewConcept/90-decision-log.md) — die Multiplizität sagt es schon) und `persistent` ([D-538](../NewConcept/90-decision-log.md) — die Relationsart sagt es schon). **Beide Sätze kamen von ihm.**
3. **Sagt er «das machst du am Konzept vorbei»: nachlesen, nicht verteidigen.** Der Satz stand jedes Mal wörtlich im Konzept. Einmal kostete das 94 Zeilen und eine Stunde, weil ein zweiter Renderer-Wähler gebaut wurde, obwohl die Einstellungsseite genau dafür da ist (`R1`: **eine** Art, eine Sache zu tun).

### Vier Fallen, die je mindestens einen Abend gekostet haben

- **`$wpdb` schweigt.** Eine kaputte Abfrage liefert ein leeres Ergebnis, keinen Fehler. «Null Zeilen gefunden» kann «mein SQL war falsch» heissen — und ist so schon als Messung in einen Commit, eine offene Frage und eine Arbeitszeile gewandert. Nach jeder handgeschriebenen Abfrage `last_error` prüfen, besser die Repositories nehmen.
- **`preg_replace` gibt `null` zurück**, und `null` landet auf der Platte. So wurde eine Quelldatei auf **null Byte** geleert, bevor sie in git war. `php -l` meldet nichts — eine leere Datei hat keine Syntaxfehler. (`PR-11`)
- **`sed` frisst PHP-Namensräume.** `\C`, `\w`, `\M` sind Escape-Sequenzen, die Backslashes verschwinden. `use TaxmodCoreModelBranch;` ist gültiges PHP und fällt erst zur Laufzeit auf. Für alles mit Backslash das Editierwerkzeug nehmen.
- **Zieht ein Wert um, zieht der Leser zuerst — und eine Prüfung muss rot werden, wenn nur einer umgezogen ist.** Reihenfolge: **Wächter, Leser, Daten.** An einem Abend blieben sechs Leser an der alten Tabelle hängen; **jedes Mal funktionierte der Bildschirm weiter und zeigte still das Falsche**, und keine der 305 Prüfungen merkte es. (`PR-12`)

---

## 4 · Was am Bestand verdächtig ist

**Das Konzept ist 2,6 MB gross. Lies es nicht am Stück — du wirst es nicht behalten, sondern
erfinden.** Genau das ist hier passiert, und das Projekt hat die Diagnose selbst aufgeschrieben, in
`CLAUDE.md`, über den **vorigen** Anlauf:

> *«The previous rule set grew to **82 KB, most of it always-on**, and became **the main reason this
> project kept re-deciding the same questions**.»*

Gemessen am 2026-09-01:

| | |
|---|---|
| `90-decision-log.md` | **867 KB**, 562 Entscheidungen |
| davon die Entscheidungen selbst | 244 KB (**36 %**), Median 404 Zeichen |
| davon die ⚠️-Herleitung des Assistenten | **442 KB (64 %)** |
| `docs/NewConcept` gesamt | **2,6 MB** |

**Fünf konkrete Warnungen. Behandle diese Stellen nicht als seinen Willen:**

1. **Die ersten hundert Entscheidungen sind nur zu 3 % auf einen Satz von ihm zurückführbar** — und sie werden am meisten zitiert (267 Verweise aus 186 späteren Entscheidungen, 212 Nennungen im Quelltext). Im letzten Block sind es 90 %. Das frühe Buch zitierte generell nicht, deshalb ist ein Fehlen ein **Hinweis, kein Urteil** — aber es sagt, **wo nachzufragen ist**. `foundation-weight.php` gibt die Reihenfolge. Zeile 110, nicht getan.
2. **108 geltende Stellen berufen sich auf zurückgenommene Entscheidungen.** Ein Verweis auf eine zurückgenommene Entscheidung ist schlimmer als ein baumelnder: **er sieht gültig aus.**
3. **`record_values.path`** steht wegen [D-134](../NewConcept/90-decision-log.md) da — «required by D-133's flattening» —, und **D-133 wurde am Tag danach abgelöst**. Alle 183 Wertzeilen haben `path === edge_id`; die zusammengesetzte Form hat keinen einzigen Bestand. Zeile 109.
4. **`50-wordpress-persistence.md` zitiert für `nodes.path` D-014.** Richtig ist [D-082](../NewConcept/90-decision-log.md) — und die steht auf `agreed (proposal)`, nicht auf entschieden.
5. **306 Regeln sind nicht aussortiert.** Das Verzeichnis macht sie sichtbar, mehr nicht. **35 werden nie wieder zitiert**, und ob zwei Regeln dasselbe sagen, hat niemand geprüft. Das Aussortieren braucht **ihn** (`PR-4`) und ist der offene zweite Schritt.

---

## 5 · Was als Nächstes ansteht

Die Arbeitsliste in [`97-implementation-plan.md`](../NewConcept/97-implementation-plan.md) ist
maßgeblich. Oben auf liegen:

| Zeile | Was |
|---|---|
| 103 | `persistent` hat keine Kante im Modell — daran hängen die sechs roten Randläufe und der Absturz. |
| 104–107 | Renderer-Einstellung dort, wo der Renderer gewählt wird; `RepeatableRenderer` ist registriert, wird aber nie gewählt; ein Wort für die Auslieferung; der Kern gibt eine Beschreibung zurück. |
| 108 | `record_values.value_ref` braucht die Spalte, die seinen Id-Raum benennt ([D-164](../NewConcept/90-decision-log.md) hat das Mittel schon beschlossen). |
| 109, 110, 111 | Siehe Abschnitt 4: der Pfad, das Fundament, und das Buch als Verzeichnis. |

**Vier Entscheidungen warten auf ihn** und dürfen nicht ohne ihn getroffen werden (`PR-4`):

- `label_role` — Variante a, b oder c (OQ-134).
- Gehören min/max in den Validator?
- Der Löschknopf am Datensatz: darf ein Teil allein gelöscht werden?
- Gehören `factor`/`offset` an die Basiseinheiten statt an «ohne Präfix»?

**Nicht gebaut, an Dritte vergeben:** das Ereignissystem. Grundlage sind
[`konzept-kompakt.md`](konzept-kompakt.md) und [`antwort-ereignisse.md`](antwort-ereignisse.md), wo
Abschnitt 7 ausdrücklich eine **Arbeitshypothese** ist und kein Faktum — «der Kern beschreibt, der
Rand malt». Er wollte das so gekennzeichnet haben.

---

## 6 · Was der vorige Assistent falsch gemacht hat

Nicht als Zerknirschung, sondern weil es die wahrscheinlichste Art ist, in der du dieselbe Zeit
verlierst. **Jeder grosse Fehler hatte dieselbe Form: etwas erfunden und ihm zugeschrieben.**

Der gepunktete Pfad. Ein Renderer-Verhalten, das er «halluziniert» nennen musste. «Datensätze teilen
sich einen Id-Raum mit Knoten» als Widerspruch **gegen** ihn vorgetragen, mit Zitaten — er hatte
recht. D-014 statt D-082. Ein `node_label`-Typ für einen Wert, der nie gespeichert wird.

Es entsteht nicht aus Nachlässigkeit, sondern daraus, dass **eine gut geschriebene Beschreibung sich
liest, als wäre sie entschieden.** Deshalb gilt Abschnitt 0, und deshalb sind die 60 Wächter mehr wert
als die 2,6 MB Prosa.

**Er ist ein zuverlässiger Korrektor.** Sagt er, etwas sei nicht sein Wort oder nicht sein Konzept,
dann ist das so — auch wenn im Buch das Gegenteil zu stehen scheint. **Im Buch steht dann die
Herleitung des Assistenten.**
