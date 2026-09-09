# Einstellungen — was alles möglich ist

**2026-09-10, sein Wort:** *«ich glaube es ist wichtig, dass wir aufschreiben, was alles möglich ist,
um dann neu und frei zu entscheiden, wie wir es umsetzen.»*

**Diese Seite empfiehlt nichts.** Je Frage stehen alle Antworten nebeneinander, die das Modell und die
Tabellen hergeben — auch die, die wir schon einmal verworfen haben, und die, die wir nie gebaut haben.
Eine Spalte sagt, was davon heute steht, mit dem Beschluss, der es trägt. Was er wählt, wird ein Beschluss
im Buch (`PR-3`); die Formen auf [`zielbild-einstellungen.md`](zielbild-einstellungen.md) sind vier
Wege durch diesen Raum, nicht der Raum.

Die Fragen hängen zusammen; am Ende steht, welche Antwort welche andere ausschliesst.

---

## 1 · Was ist eine Einstellung?

| Antwort | heisst | heute | schliesst aus / kostet |
|---|---|---|---|
| **eine Kante**, wie ein Feld | Erklärung = Kante `Knoten --name--> Ziel`, Wert = Wertzeile an dieser Kante; der Zieltyp sagt das Format | **so** ([D-529](NewConcept/90-decision-log.md)) | jede Erklärung braucht einen Zielknoten (`Boolean`, `Integer`, `Orientation`, …) |
| **ein Schlüssel im Kode** | ein Aufzählungsfall (`SettingKey`) mit Form und Format; im Modell nur Werte unter dem Schlüssel | war bis [D-529](NewConcept/90-decision-log.md) so (`settings`-Tabelle mit `key`, [D-579](NewConcept/90-decision-log.md) gefallen) | keine Erklärung durch den Modellierer; ein neuer Schlüssel ist ein Kode-Schritt |
| **beides: der Kode erklärt, das Gerüst schreibt die Kante** | die Klasse nennt ihre Schlüssel, das Gerüst legt die Kanten an, Werte wie bei «Kante» | **halb**: 8 von 19 Kanten stehen am kodierten Knoten, aber keine Klasse erklärt sie ([Zielbild, Form D](zielbild-einstellungen.md)) | der Modellierer erklärt nichts |
| **eine Spalte** an `nodes` oder `relations` | `read_only`, `renderer` als Spalten der Tabelle | gab es (`relations.settings_record_id`, `hide`) — `hide` ist heute noch eine Spalte, der Rest fiel ([D-579](NewConcept/90-decision-log.md), [D-684](NewConcept/90-decision-log.md)) | keine Vererbung, kein Überschreiben, keine neuen ohne Schema-Änderung |
| **eine Kante, die ein Datensatz ist** | die Einstellung zeigt auf einen Satz mit eigenen Feldern (Teil) | war [D-583](NewConcept/90-decision-log.md) für den Renderer, gefallen mit [D-684](NewConcept/90-decision-log.md) | ein Satz je Wahl, Waisen bei Umwahl |

## 2 · Wer erklärt eine Einstellung?

| Antwort | heisst | heute | schliesst aus / kostet |
|---|---|---|---|
| **der Kode** (die Klasse des Typs, des Renderers, des Konverters) | `IntType` sagt: `min`, `max`, `step` als Ganzzahl | **nein** — keine Klasse tut es; `SettingKey` weiss Form und Format | der Modellierer kann keine erfinden |
| **das Gerüst** | Skripte und Saat legen die Kanten an | **so** — alle 19 aus 15 Gerüsten und Skripten | zwei Wahrheiten: das Gerüst weiss, was der Kode braucht, der Kode weiss es nicht |
| **der Modellierer** | Kantenart `setting` in der Maske, Feld ↔ Einstellung umstellen | **möglich** ([D-618](NewConcept/90-decision-log.md), [D-699](NewConcept/90-decision-log.md)), **nie benutzt** — keine der 19 Kanten kam von Hand | Einstellungen, die kein Kode liest — «eine Zeile, die niemand liest» |
| **gemischt**: Modell-Eigenschaften vom Kode-Knoten, eigene vom Modellierer | `renderer`, `read_only` aus dem Kode; `exponent`, `factor` aus dem Gerüst der Einheiten; Eigenes von Hand | **de facto so**, ohne Regel | die Regel, welche Sorte wo wohnt, fehlt |

## 3 · Wo steht die Erklärung im Modell?

| Antwort | heisst | heute |
|---|---|---|
| **an der Wurzel** — jeder Knoten hat sie | `renderer`, `read_only`, `validator`, `position` | **so** für diese vier |
| **am Typknoten** — jede Verwendung des Typs hat sie | `min`, `max`, `step` an `Integer`; `display_size` an `Text` | **so** für diese |
| **am Renderer-Knoten** — jeder, der ihn wählt, hat sie | `orientation` an `compact`; `converter`, `label_role` am Behälter `Renderer` | **so**, teils zu breit ([INF-041](neues-konzept-eingang.md)) |
| **an einem Zwischenknoten** — eine Gruppe hat sie | `with_label` an `render with label` | **so** für diese eine |
| **nirgends im Modell** — nur der Kode kennt sie | Werte unter einem Schlüssel, ohne Kante | war so bis [D-529](NewConcept/90-decision-log.md) |
| **an der Wurzel, aber nur gezeichnet, wo der Kode sie nennt** | alle Schlüssel als Wurzelkanten, der gewählte Renderer sagt, welche Zeilen erscheinen | **nein** ([Zielbild, Form E](zielbild-einstellungen.md)) |

## 4 · Sind Renderer, Konverter, Validatoren Knoten?

| Antwort | heisst | heute | schliesst aus / kostet |
|---|---|---|---|
| **ja, mit Kanten** — modelliert wie alles andere | 23 Knoten mit Klasse im Ast `Settings`, ihre Einstellungen als Kanten, die Wahl ein Verweis | **so** ([D-511](NewConcept/90-decision-log.md), [D-557](NewConcept/90-decision-log.md), [D-684](NewConcept/90-decision-log.md)) | die Hülle, in der die Fehler von heute sassen; ein Ast, den niemand von Hand pflegt |
| **ja, aber nur als Hülle** — Knoten ohne eigene Kanten, für Beschriftung und Verweis | der Name kommt vom Knoten, die Eigenschaften vom Kode | **nein** | zwei Orte für einen Renderer |
| **nein** — Namen aus der Registratur | die Wahl ist ein Text, das Angebot `eligibleFor()` ([D-603](NewConcept/90-decision-log.md)) | **nein** — war vor [D-583](NewConcept/90-decision-log.md) so | Beschriftungen der Renderer werden Software-Strings (`AR-2`); die Rollen (`Label roles`) bleiben Knoten, sie sind Modell ([D-151](NewConcept/90-decision-log.md)) |

## 5 · Wo wohnen die Werte?

| Antwort | heisst | heute |
|---|---|---|
| **im Einstellungssatz des Knotens** (`settings`, `relation_id = 0`) | `Kontakt: renderer, read_only` | **so** ([D-704](NewConcept/90-decision-log.md)) |
| **im Satz Knoten × Kante** (`relation_id > 0`) | `Kontakt × Strasse: display_size = 40` — die Kante schlägt den Knoten | **so** ([D-667](NewConcept/90-decision-log.md)), 3 Sätze |
| **im Satz des Renderers** (Teil hinter der Wahl) | `orientation` im Satz, auf den die Wahl zeigt | war [D-583](NewConcept/90-decision-log.md), gefallen |
| **im eigenen Wert eines Typs** (`relation_id = 0` im Satz des Typknotens) | `integer_min = -9223…` als eigener Wert des Grenzknotens | **so** ([D-673](NewConcept/90-decision-log.md), [D-707](NewConcept/90-decision-log.md)) |
| **als Spalte** | `relations.hide` | **so** für `hide` allein |
| **als Vorgabe im Kode** | `IntType::min()` | **nein** — heute sind es Knoten |

## 6 · Wo darf überschrieben werden?

| Antwort | heisst | heute |
|---|---|---|
| **nur am Knoten** | anders = ein Kind mit eigener Wahl | so für `renderer` ([D-643](NewConcept/90-decision-log.md)) |
| **am Knoten und an der Kante** | `Hausnummer / Int: max = 999` | **so** für alles ausser `renderer` ([D-611](NewConcept/90-decision-log.md), [D-685](NewConcept/90-decision-log.md)) |
| **auch an einer geerbten Kante, vom Kind aus** | `Gramm × Präfix: allowed`, `Kind × Kante: position` | **so** ([D-697](NewConcept/90-decision-log.md), [D-698](NewConcept/90-decision-log.md)) |
| **je Eigenschaft anders** | Werteinstellungen an der Kante, Zeichnung nur am Knoten | **so**, ohne benannte Regel ([Zielbild, Form C](zielbild-einstellungen.md)) |
| **nirgends** — nur der Typ sagt es | jede Abweichung ist ein neuer Typknoten | **nein**; sein Wort: «das hört sich nach vielen knoten an» |

## 7 · Was tut die Kette bei mehreren Antworten?

| Antwort | heisst | heute |
|---|---|---|
| **näher schlägt ferner** | Kante, Knoten, Vorfahren, Typ — der erste Treffer gilt | **so** ([D-602](NewConcept/90-decision-log.md), [D-707](NewConcept/90-decision-log.md)) |
| **näher ergänzt ferner** | die Zeilen sammeln sich: Typ, Knoten, Kante — in dieser Reihenfolge | **nein**; sein Beispiel: to-upper am Knoten, sortieren und zusammenfassen an der Kante |
| **je Eigenschaft**: einwertig ersetzt, mehrwertig sammelt | `renderer` ersetzt, `converter` sammelt | **nein** |
| **mit Abwahl** — ein geerbter Eintrag wird an einer Stelle gestrichen | ein Haken «hier nicht» als eigene Zeile | **nein**; das Gegenstück «hier überschreibe ich» gibt es ([D-689](NewConcept/90-decision-log.md)) |
| **geerbt = gesperrt**, überschreiben nur mit Haken | | **so** ([D-687](NewConcept/90-decision-log.md)–[D-689](NewConcept/90-decision-log.md)) |
| **unzulässig geerbt = automatisch ersetzt** | ein geerbter Renderer, der hier nicht zeichnen kann, fällt auf den Typ-Standard | **so** ([D-688](NewConcept/90-decision-log.md)) |

## 8 · Wie wird «mehrfach» gespeichert?

| Antwort | heisst | heute |
|---|---|---|
| **mehrere Zeilen an einer Kante**, geordnet über `position` | `validator = range`, `validator = shape` | **so** ([D-530](NewConcept/90-decision-log.md)); `validator` ist `0..*`, `converter` `0..1` |
| **ein Teil je Eintrag** — die Zeile zeigt auf einen eigenen Satz | wenn ein Eintrag selbst Eigenschaften hat | **so** für zusammengesetzte **Daten** ([D-541](NewConcept/90-decision-log.md)), nie für Einstellungen |
| **eine Liste in einem Wert** | `'to-upper,sort,collapse'` als Text | **nein**; nichts liest so etwas |

## 9 · Eine Eigenschaft, die selbst Eigenschaften hat (der Renderer und seine)

| Antwort | heisst | heute |
|---|---|---|
| **der Renderer ist ein Knoten mit Kanten** | `compact --orientation--> Orientation`; die Werte im Satz des Knotens unter der Wahl | **so** |
| **flach: alle Schlüssel an der Wurzel, der Kode weiss die Zugehörigkeit** | `orientation` ist eine Wurzelkante, gezeichnet nur bei `compact`/`table` | **nein** ([Zielbild, Form E](zielbild-einstellungen.md)) |
| **ein Teil je Wahl** | die Wahl zeigt auf einen Satz mit den Eigenschaften | war [D-583](NewConcept/90-decision-log.md), gefallen |
| **gar nicht: der Renderer entscheidet selbst** | `reference` zeigt das Symbol, wenn es eines gibt; die Tabelle steht waagerecht | **nein** ([Zielbild, Wahl B](zielbild-einstellungen.md)); 10 Werte gingen in den Schatten |

## 10 · Woher kommt das Angebot einer Einstellung?

| Antwort | heisst | heute |
|---|---|---|
| **die Kinder des Ziels** | `label_role` → die Rollen; `orientation` → horizontal, vertical | **so** ([D-540](NewConcept/90-decision-log.md)) |
| **die Registratur, nach Typ** | `converter` → was zu Int passt | **so** für `converter` und `renderer` ([D-603](NewConcept/90-decision-log.md)), nicht für `validator` |
| **eine Liste am Knoten** | `allowed`: welche Präfixe Gramm erlaubt | **so** ([D-697](NewConcept/90-decision-log.md)) |
| **das Geschwisterfeld** | `prefix` bietet, was die gewählte `einheit` erlaubt | **so** ([D-697](NewConcept/90-decision-log.md)) |
| **leeres Angebot: tot / offen / keine Zeile** | | **tot** in der Einstellungsseite, **offen** unter der Renderer-Wahl — sein Wort: «feld ist offen, weil falsche converter angezeigt werden» |

## 11 · Wo stehen die Vorgaben eines Typs?

| Antwort | heisst | heute |
|---|---|---|
| **in Grenzknoten** mit eigenem Wert | `integer_min`, `integer_max`, `decimal_min`, `decimal_max` | **so** ([D-707](NewConcept/90-decision-log.md)) |
| **im Satz des Typknotens** | `Integer: min = …` als eigener Wert | war bis Fassung 42 so (an der Kante) |
| **im Kode** | `IntType::defaults()` | **nein** |

## 12 · Wo sieht und ändert der Modellierer eine Einstellung?

| Antwort | heisst | heute |
|---|---|---|
| **Tabelle «Settings» am Knoten** | eine Zeile je Einstellung, Haken vorn, Wert, Herkunft | **so** ([D-666](NewConcept/90-decision-log.md), TASK-090) |
| **Einstellungsbereich unter der Feldzeile** | aufklappbar, die Werte an der Kante | **so** ([D-666](NewConcept/90-decision-log.md)) |
| **die Zeilen des Renderers eingerückt unter der Wahl** | `converter`, `with_label`, `label_role` | **so** |
| **ein eigener Bildschirm je Knoten** | | **nein** |
| **nur lesend, ändern im Kode** | | **nein** — widerspricht seinem «kante schlägt knoten» |

---

## Was zusammenhängt

- **1 «Schlüssel im Kode» oder «Kode erklärt»** schliesst **2 «der Modellierer»** aus.
- **4 «keine Knoten»** verlangt **3 «an der Wurzel»** oder **«nirgends»** für die Renderer-Eigenschaften, und **9 «flach»** oder **«gar nicht»**.
- **7 «ergänzt»** verlangt **8 «mehrere Zeilen»** und eine Antwort auf die Abwahl.
- **6 «je Eigenschaft anders»** verlangt eine Liste, welche Eigenschaft welcher Sorte ist — die steht auf keiner Seite, sie wäre der Beschluss.
- **10 «keine Zeile bei leerem Angebot»** nimmt die Zusage «eine Wahl ohne Inhalt ist ein totes Steuerelement» zurück (`einstellungen-check`, Abschnitt 7).
- **11 «im Kode»** nimmt die Wanderung aus Fassung 42 zurück (die vier Grenzknoten in den Schatten).

*Nichts auf dieser Seite ist eine Empfehlung. Gemessen ist die Spalte «heute».*
