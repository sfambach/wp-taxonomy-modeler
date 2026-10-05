# Die Wächter, gemessen — was jeder anfasst, und wo zwei am selben Ort arbeiten

**2026-09-09, abends, auf seine Frage:** *«ich glaube wir müssen mal die sinnhaftigkeit der vielen
wächter hinterfragen wenn zwei auf der gleichen stelle arbeiten wäre es dann nicht einer?»* — und sein
Wort: *«und messung»*. **Nur gelesen, nichts geändert, nichts gestrichen.** Streichen oder
Zusammenlegen ist je Wächter ein sichtbarer Schritt (`PR-9`), und diese Datei ist die Grundlage dafür,
nicht der Schritt.

⚠️ **Was die Messung kann und was nicht:** *sie liest die Dateien, nicht die Bedeutung. «Zusagen» sind
gezählte Aufrufe von `check()` und Verwandten; «Tabellen» und «Knoten» sind die Namen, die im Kode
vorkommen; «Fläche» sagt, ob ein Lauf nur die Datenbank fragt, die Knotenseite zeichnet, einen Akt
abschickt oder einen der zwei anderen Bildschirme öffnet. Ob zwei Zusagen dasselbe **meinen**, sieht sie
nur am gleichen Wortlaut — und den gibt es selten.*

## Die Zahl

**1333 Zusagen in 62 Wächtern, 996 KB.** Zum Vergleich der Bestand vom 2026-09-06 in
[`waechter-bestand.md`](waechter-bestand.md), als vier Paketläufe mit 127 Zusagen fielen.

## Wo zwei am selben Ort arbeiten — nach Gegenstand gruppiert

*Die Gruppen sind von mir gebildet, an Fläche, Tabellen und Knoten. Sie sind der Vorschlag, wo
hinzusehen ist — keine Aussage, dass die Gruppe ein Wächter sein müsste.*

| Gegenstand | Wächter | Zusagen zusammen |
|---|---|---|
| **Renderer-Wahl und Einstellungstafel** | `package7` 118, `renderer-choice-mask` 110, `page-blocks` 60, `setting-write` 39, `setting-relation` 38, `preview` 34, `setting-lock` 25, `field-kind` 18, `rendering-scaffold` 13, `converter` 18, `setting-branch-relation` 16 | **489** |
| **Sätze, Werte, Schatten** | `restore` 23, `shadow-shape` 22, `parked-in-shadow` 20, `several-values` 25, `record-on-any-node` 19, `record-on-first-write` 14, `cleanup-screen` 44, `cleartrash` 16, `version` 10, `value-ref-space` 8, `journal-address` 12, `change-group` 6 | **219** |
| **Baum und Kanten** | `pakete` 66, `path` 46, `package3` 44, `composition` 23, `used-by` 23, `move-mask` 21, `inheritance-column` 13, `edge-class` 12, `dangling-reference` 11, `sort-order` 10, `collapsed-default` 32, `hide-abort` 4, `target-link` 5, `orphans` 3, `id-space` 14 | **327** |
| **Beschriftungen** | `label-space` 42, `labels-page-save` 23, `label-role` 8, `icon-button` 24, `field-hide` 10 | **107** |
| **Typen und Gerüst** | `unitvalue` 34, `simple-type` 16, `scaffold` 16, `seed-twice` 14, `type-binding` 19, `implemented-by` 6, `node-binding` 7, `multiplicity` 19, `form-membership` 12 | **143** |
| **Konfiguration und Betrieb** | `configuration-screen` 17, `silent-query` 18, `no-model-write` 13 | **48** |
| **Dokumente, ohne Datenbank** | `references`, `rules-index`, `concept-drift`, `confirmed-quote`, `superseded`, `always-on`, `decision-index` | — |

⚠️ **Die erste Gruppe ist die, die er meint.** *Elf Wächter, 489 Zusagen, mehr als ein Drittel des
Netzes — alle an der Renderer-Wahl, den Einstellungskanten und der Tafel. `package7` und
`renderer-choice-mask` sind zusammen 160 KB und tragen je über hundert Zusagen; beide zeichnen
dieselben fünf Renderer (`spinner`, `compact`, `form`, `table`, `plain`) an denselben Tabellen.
**Das ist gewachsen, nicht geplant:** jeder Fehlerbericht eines Tages bekam seinen Wächter, und der
nächste Tag den nächsten — `setting-write` (30.08.), `setting-relation` (05.09.), `renderer-choice-mask`
(06.09.), `setting-lock` und `field-kind` (09.09.).*

## Gleichlautende Zusagen — selten, und meist Aufräumen

Zehn Wortlaute stehen in mehr als einem Wächter, und acht davon sind das Aufräumen der eigenen Wiese
(«die wiese ist wieder weg», «der waechter laesst nichts zurueck», «scratch nodes are gone»). Nur zwei
sind Sachaussagen: *«die einstellungskante `renderer` ist aufgeschrieben»* in `renderer-choice-mask`
und `setting-write`, und *«all three members were drawn»* in `composition` und `unitvalue`.

**Der Befund daraus:** *die Überlappung liegt nicht im Wortlaut, sondern im Gegenstand. Zwei Wächter
sagen selten dasselbe — sie sagen Verschiedenes über dieselbe Stelle, und wer die Stelle ändert, macht
elf Läufe rot statt einen.*

## Was daraus folgen könnte — `PROPOSED`, seine Entscheidung

1. **Die erste Gruppe wird ein Lauf**, so wie `pakete` am 2026-09-06 vier Paketläufe ersetzt hat: *ein
   Weg durch die Einstellungen, wie ein Mensch ihn geht — erklären, wählen, erben, sperren,
   überschreiben, umstellen, zurücksetzen* — und die elf Dateien fallen, jede mit der Liste ihrer
   Zusagen, die entweder im neuen Lauf stehen oder mit Grund entfallen. **Das ist ein Tag Arbeit und
   ein sichtbarer Schritt je Wächter.**
2. **Die zweite und dritte Gruppe bleiben zunächst**: dort sind die Wächter klein, und die Tabellen, die
   sie teilen, sind die, die jeder teilt.
3. **Nicht angefasst:** die sieben ohne Datenbank — sie halten das Buch, nicht das Modell.

## Die Tabelle

| Wächter | Zusagen | KB | Fläche | Tabellen | Knoten | D- |
|---|---|---|---|---|---|---|
| `package7` | 118 | 67 | DB | relations changelog node_records relation_records nodes_named relations_named | spinner compact form table plain | 48 |
| `renderer-choice-mask` | 110 | 93 | DB Knotenseite POST | nodes relations labels changelog node_records relation_records node_records_history relation_records_history nodes_named relations_named | spinner plain form table compact | 38 |
| `pakete` | 66 | 26 | DB | nodes relations labels label_texts changelog node_records relation_records relations_history relation_records_history nodes_named relations_named |  | 24 |
| `page-blocks` | 60 | 45 | DB Knotenseite Cleanup | node_records relation_records nodes_named relations_named | form Integer Passiv table compact | 16 |
| `path` | 46 | 27 | DB | nodes relations labels label_texts changelog node_records relation_records nodes_history relation_records_history nodes_named | Renderer | 9 |
| `cleanup-screen` | 44 | 18 | DB Cleanup | nodes relations label_texts changelog node_records relation_records node_records_history |  | 6 |
| `package3` | 44 | 18 | DB | nodes relations changelog nodes_named relations_named | Prefixes Base units | 13 |
| `label-space` | 42 | 22 | DB | nodes relations labels label_texts changelog nodes_named |  | 6 |
| `setting-write` | 39 | 35 | DB POST | nodes relations node_records relation_records node_records_history relation_records_history nodes_named | Renderer spinner | 19 |
| `setting-relation` | 38 | 29 | DB | nodes relations node_records relation_records node_records_history relation_records_history nodes_named | Prefixes | 15 |
| `preview` | 34 | 32 | DB | nodes relations node_records relation_records nodes_named relations_named |  | 21 |
| `unitvalue` | 34 | 25 | DB |  | Einheitenwert Prefixes Base units | 13 |
| `collapsed-default` | 32 | 18 | DB Knotenseite | nodes relations labels changelog |  | 4 |
| `setting-lock` | 25 | 13 | DB Knotenseite POST |  | compact | 5 |
| `several-values` | 25 | 11 | DB | relations node_records relation_records relations_named |  | 3 |
| `icon-button` | 24 | 20 | DB | relations node_records |  | 2 |
| `composition` | 23 | 16 | DB | nodes |  | 4 |
| `labels-page-save` | 23 | 17 | DB Knotenseite POST | nodes relations labels label_texts changelog nodes_named | form table | 5 |
| `restore` | 23 | 11 | DB | nodes changelog node_records relation_records nodes_history node_records_history relation_records_history |  | 2 |
| `used-by` | 23 | 12 | DB POST | nodes relations labels changelog nodes_history relations_history nodes_named relations_named |  | 5 |
| `shadow-shape` | 22 | 11 | DB | node_records relation_records relation_records_history |  | 2 |
| `move-mask` | 21 | 15 | DB Knotenseite POST | nodes relations labels changelog node_records relation_records nodes_history nodes_named |  | 3 |
| `parked-in-shadow` | 20 | 11 | DB | nodes relations node_records relation_records nodes_history relations_history relation_records_history |  | 3 |
| `multiplicity` | 19 | 18 | DB POST | nodes relations node_records relation_records nodes_named |  | 6 |
| `record-on-any-node` | 19 | 13 | DB | nodes node_records relation_records | Prefixes | 4 |
| `type-binding` | 19 | 14 | DB | nodes_named |  | 4 |
| `converter` | 18 | 13 | DB | nodes relations labels nodes_named |  | 8 |
| `field-kind` | 18 | 8 | DB Knotenseite POST |  |  | 4 |
| `silent-query` | 18 | 9 | DB | nodes relations |  | 1 |
| `configuration-screen` | 17 | 7 | DB |  |  | 9 |
| `cleartrash` | 16 | 14 | DB | nodes relations label_texts changelog node_records nodes_named |  | 5 |
| `scaffold` | 16 | 12 | DB | relations changelog nodes_named relations_named |  | 7 |
| `setting-branch-relation` | 16 | 14 | DB POST | nodes relations nodes_named relations_named |  | 6 |
| `simple-type` | 16 | 13 | DB | node_records |  | 9 |
| `id-space` | 14 | 15 | DB | nodes relations labels label_texts changelog |  | 10 |
| `record-on-first-write` | 14 | 11 | DB | nodes relations labels changelog node_records relation_records node_records_history nodes_named |  | 6 |
| `seed-twice` | 14 | 16 | DB | nodes_named |  | 4 |
| `inheritance-column` | 13 | 16 | DB | nodes relations nodes_history relations_history |  | 3 |
| `no-model-write` | 13 | 8 | DB |  |  | 0 |
| `rendering-scaffold` | 13 | 21 | DB |  | Renderer plain | 5 |
| `edge-class` | 12 | 16 | DB |  |  | 7 |
| `form-membership` | 12 | 15 | DB | nodes relations | form | 3 |
| `journal-address` | 12 | 15 | DB | nodes relations labels changelog node_records relation_records nodes_named |  | 9 |
| `dangling-reference` | 11 | 10 | DB | nodes relations relation_records nodes_named |  | 4 |
| `field-hide` | 10 | 10 | DB Knotenseite | nodes relations labels changelog nodes_named |  | 2 |
| `sort-order` | 10 | 9 | DB | relations relations_history |  | 1 |
| `version` | 10 | 11 | DB | nodes relations labels changelog node_records relation_records nodes_history relations_history node_records_history relation_records_history nodes_named |  | 2 |
| `label-role` | 8 | 10 | DB | nodes relations label_texts |  | 8 |
| `value-ref-space` | 8 | 6 | DB | nodes node_records relation_records relation_records_history |  | 1 |
| `node-binding` | 7 | 11 | DB | nodes | Renderer | 2 |
| `change-group` | 6 | 6 | DB | nodes relations labels changelog nodes_named |  | 2 |
| `implemented-by` | 6 | 7 | DB | nodes nodes_history nodes_named |  | 3 |
| `target-link` | 5 | 5 | DB Knotenseite | nodes relations labels changelog nodes_named |  | 1 |
| `hide-abort` | 4 | 5 | DB | nodes relations labels changelog nodes_named |  | 2 |
| `orphans` | 3 | 6 | DB | labels |  | 6 |
| `always-on` | 0 | 5 | Kode/Dokumente |  |  | 2 |
| `concept-drift` | 0 | 6 | Kode/Dokumente |  |  | 4 |
| `confirmed-quote` | 0 | 5 | Kode/Dokumente |  |  | 4 |
| `decision-index` | 0 | 7 | Kode/Dokumente |  |  | 1 |
| `references` | 0 | 3 | Kode/Dokumente |  |  | 0 |
| `rules-index` | 0 | 1 | Kode/Dokumente |  |  | 0 |
| `superseded` | 0 | 13 | Kode/Dokumente |  |  | 22 |

Summe: 1333 Zusagen in 62 Wächtern, 996 KB

### Gleichlautende Zusagen
- «all three members were drawn» — composition, unitvalue
- «kein eigener knoten bleibt stehen» — dangling-reference, setting-branch-relation
- «die wiese ist wieder weg» — field-hide, label-space
- «jede beschriftung hat eine version» — id-space, label-space
- «der akt ist durchgelaufen» — move-mask, renderer-choice-mask, setting-branch-relation
- «der waechter laesst nichts zurueck» — move-mask, record-on-first-write, renderer-choice-mask
- «scratch nodes are gone» — package3, package7, scaffold
- «die lebende kantenzeile ist fort» — pakete, parked-in-shadow
- «die einstellungskante `renderer` ist aufgeschrieben» — renderer-choice-mask, setting-write
- «eine wertzeile als vorlage gefunden» — restore, shadow-shape

Doppelte Wortlaute: 10
