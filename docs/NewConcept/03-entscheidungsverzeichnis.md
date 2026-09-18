# 03 · Entscheidungsverzeichnis — was heute gilt

**Das Erste, was man liest, wenn man wissen will, was gilt.** Nach Sachfrage geordnet, nicht nach
Datum. Je Zeile: die Frage, die **heute gültige** Entscheidung, und darunter klein die von ihr
**abgelösten** — durchgestrichen, damit sichtbar ist, dass es sie gab und dass sie nicht mehr zählen.

⚠️ **Warum es das gibt, und der Anlass ist gemessen.** *Am 2026-09-05 wurden an einem Abend dreimal
überholte Entscheidungen als geltend zitiert — der «fehlende» Installationsbildschirm, die
sprachneutrale Zeile, die offene Rollenfrage. **Jedes Mal wurde eine Entscheidung gefunden, nur die
falsche**, weil eine spätere sie berichtigt hatte. Das Protokoll ist chronologisch und sagt nicht,
was heute gilt* ([D-645](90-decision-log.md), [`PR-10`](../../CLAUDE.md)).

⚠️ **Dies ist eine Ansicht, keine Quelle.** *Entschieden ist, was in
[90 Entscheidungslog](90-decision-log.md) steht (`PR-3`) — hier steht **nur**, welche Zeile dort
heute die geltende ist. Bei Widerspruch gewinnt das Log, und diese Datei ist falsch. Sie erweitert
das eingefrorene Konzept nicht (`PR-1`): sie zeigt darauf.*
Bewacht von `scripts/dev/decision-index-check.php`.

---

## Modelltabellen

| Frage | gilt heute | abgelöst |
|---|---|---|
| Wo liegt das Modell? | [D-007](90-decision-log.md) — eigene Tabellen dieses Plugins | ~~D-567~~ *(Vorschlag, die Basistabellen zu benennen — verworfen)* |
| Wie liegt ein Datensatz in den Tabellen? | [D-577](90-decision-log.md), [D-578](90-decision-log.md) — `node_record` / `relation_record`, kein Pfad, gilt für alle Kantenarten | ~~D-083~~ ~~D-133~~ ~~D-232~~ ~~D-527~~ ~~D-530~~ |
| Wo liegt ein zusammengesetzter Wert in einem Datensatz, und wie werden es mehrere? | [D-759](90-decision-log.md) — in einem eigenen Teil-Satz, auf den der Besitzer zeigt; mehrere Teile sind mehrere Wertzeilen, `position` ordnet; Zeilen anhängen und entfernen (folgt [D-577](90-decision-log.md)) | ~~D-741~~ ~~D-742~~ *(innere Werte flach im Satz des Besitzers)* |
| Wie zeichnet sich ein zusammengesetzter Knoten als Ganzes? | [D-758](90-decision-log.md) — Komplex-Renderer `complex`: feste Angaben, einfache Felder als Formular, komplexe `≤1` als Formular, `>1` als Tabelle, tiefer als Zusammenfassung | — |
| Wem gehört ein Datensatz? | [D-667](90-decision-log.md) — dem Knoten, und wo er einer Verwendungsstelle gehört, nennt er ihre `relation_id`; `path` fällt | — |
| Wem gehört eine Wertzeile? | [D-673](90-decision-log.md) gilt nur noch für **Datensätze**: einem Feld; Einstellungen liegen seit [D-712](90-decision-log.md) in `settings_value` am Knoten | ~~D-673~~ *(als Fach für Einstellungen)* |
| Wo liegen die Einstellungen einer Verwendungsstelle? | [D-712](90-decision-log.md) — als Zeile am Zielknoten mit `kante_id`; die Kante hat keine eigenen | ~~D-667~~ *(Satz der Kante)* ~~D-643~~ |
| Welche Kantenarten gibt es? | [D-715](90-decision-log.md) — zwei Klassen: `aggregation`, `composition`; `setting` fällt | ~~D-639~~ ~~D-161~~ ~~D-193~~ ~~D-526~~ ~~D-587~~ ~~D-591~~ ~~D-592~~ |
| Wer bestimmt die Kantenart? | [D-618](90-decision-log.md), [D-621](90-decision-log.md) — der Benutzer; `nodes.field_type` fällt, kein Ast-Automatismus | ~~D-161~~ ~~D-606~~ |
| Was geschieht mit den Werten, wenn eine Kante ihre Art wechselt? | [D-699](90-decision-log.md) — Werte wandern mit, **Benutzersätze nicht**: der Benutzer wird gewarnt, bestätigt, die Sätze gehen in den Schatten und die Einstellung beginnt leer | ~~D-690~~ *(nur die Form je Richtung; die Richtung «Werte wandern mit» gilt weiter)* |
| Ist Vererbung eine Kante? | [D-581](90-decision-log.md) — nein: `nodes.parent_node_id` mit `nodes.sort_order` | ~~D-012~~ |
| Was heisst «wird mitgelöscht»? | [D-639](90-decision-log.md) — die Kantenart sagt es, und sie sagt es über **Datensätze**; bei einfachem Typ fest ([D-588](90-decision-log.md)) | ~~D-587~~ ~~D-591~~ ~~D-592~~ |
| Wie hängt eine Einstellung am Knoten? | [D-712](90-decision-log.md) — als Zeile in `settings_value`, komplexe Werte als `settings_object`; keine Kante, keine Spalte | ~~D-642~~ ~~D-582~~ ~~D-583~~ ~~D-584~~ ~~D-586~~ |
| Hat eine Kante eigene Einstellungen? | [D-712](90-decision-log.md) — nein; sie überschreibt jeden Knotenwert einzeln. `multiplicity` und `read_only` sind Spalten der Kante ([D-713](90-decision-log.md), [D-714](90-decision-log.md)) | ~~D-643~~ ~~D-586~~ |
| Wie wird eine Einstellung aufgelöst? | [D-712](90-decision-log.md) — Kante → Zielknoten → Vertrag der Klasse; keine Vorfahren | ~~D-602~~ ~~D-617~~ ~~D-079~~ ~~D-401~~ ~~D-404~~ ~~D-616~~ |
| Welche Einstellungen bietet eine Verwendungsstelle an? | [D-712](90-decision-log.md) — die Attribute aus dem Vertrag der Zielklasse | ~~D-668~~ ~~D-611~~ |
| Wie heissen die Äste? | [D-188](90-decision-log.md) — `Model`, `Compositions`, `Primitives`; Begriffe englisch ([D-187](90-decision-log.md)) | ~~D-185~~ |
| Welcher Knoten trägt Datensätze? | [D-522](90-decision-log.md) — der, der Felder hat; nicht sein Ast | ~~D-139~~ ~~D-183~~ |
| Welche Klasse trägt ein Knoten? | [D-716](90-decision-log.md), [D-733](90-decision-log.md) — genau eine, beim Anlegen vergeben und seither wechselbar; die Vaterklasse erlaubt Kindklassen und wählt vor; `Kategorie` überall | — |
| Was geschieht mit den heutigen Einstellungswerten und den Renderer-Knoten? | [D-717](90-decision-log.md), [D-718](90-decision-log.md) — es beginnt leer; die Knoten unter `Settings` fallen, die Rollen bleiben | — |
| Was unterscheidet Benutzer-, Vorgabe- und Beispieldaten? | [D-524](90-decision-log.md) — `records.kind` mit drei Zuständen; Sichtbarkeit im Frontend regelt [D-241](90-decision-log.md) | ~~D-521~~ *(die Rücknahme)* |
| Was trägt die Identität? | [D-436](90-decision-log.md) — `id`, `version` **und** `name`, für Knoten wie Kante; als Tabelle ([D-339](90-decision-log.md)) | ~~D-080~~ |
| Woran erkennt man Ausgeliefertes? | [D-194](90-decision-log.md) — an der Herkunft, nicht an einer Marke «ist Vorlage»; zwei Marken am gesäten Knoten ([D-174](90-decision-log.md)) | ~~D-121~~ ~~D-122~~ |
| Trägt ein Datensatz seine Modellversion? | [D-210](90-decision-log.md) — ja, und Auflösen berührt nur, was wirklich in Konflikt stand | ~~D-060~~ |
| Gibt es eine Pflicht-Angabe? | [D-405](90-decision-log.md) — nein: eine Untergrenze von eins **ist** die Pflicht | ~~D-311~~ |
| Darf eine Verwendungsstelle eine Angabe weiten? | [D-411](90-decision-log.md) — ja, frei in beide Richtungen; nur `min` und `max` nicht ([D-468](90-decision-log.md)) | ~~D-088~~ ~~D-310~~ ~~D-312~~ |
| Woher bekommt ein Feld die Grenzen seines Besitzertyps? | [D-516](90-decision-log.md) — `min` und `max` sind Spezialisierungen unter `Integer`; kein Typ «wie der Besitzer» | ~~D-504~~ ~~D-515~~ |
| Gibt es `persistent`? | [D-538](90-decision-log.md) — nein, ersatzlos gefallen: dass ein Wert nicht im Benutzerdatensatz landet, sagt die Kantenart | ~~D-373~~ ~~D-377~~ ~~D-460~~ ~~D-508~~ |
| Wo steht der Exponent eines Präfixes? | [D-378](90-decision-log.md) — als Feld an `Prefixes`, der Wert im Vorgabesatz des einzelnen Knotens; `factor` und `offset` ebenso ([D-559](90-decision-log.md)) | ~~D-372~~ |
| Ist `range` ein eigener Typ? | [D-328](90-decision-log.md) — ja: der Gliedtyp wird einmal gewählt, beide Felder folgen | ~~D-320~~ |
| Wie liegt eine Datei im Modell? | [D-229](90-decision-log.md) — als gewöhnlicher Typ, der seinen MIME-Typ kennt; heisst `Link` ([D-322](90-decision-log.md), [D-323](90-decision-log.md)); im heutigen Modell [D-761](90-decision-log.md): eigene Klasse unter `Model`, Mediathek-Id als einfacher Typ, Dateiarten als Konstanten, leere Liste erlaubt alles | ~~D-211~~ ~~D-287~~ *(leere Liste als Konflikt)* |
| Wo steht, womit ein Prozessor kompatibel ist? | [D-769](90-decision-log.md) — in einem eigenen Knoten `PC › Kompatibilität` (Art, von, zu, zu Familie, Einschränkung); am Satz nur ein Feld der Art «Sprung», das dorthin führt und nach «von = dieser Satz» filtert | ~~D-767~~ *(zwei Felder «befehlskompatibel zu» / «pinkompatibel zu» am Prozessor)* |
| Wo stehen Registerbreite, Datenbus und Adressbus eines Prozessors? | [D-771](90-decision-log.md) — am Satz seiner Familie in `PC › Prozessor-Familien`; Familien mit verschiedenen Busbreiten sind geteilt | ~~D-765~~ *(drei Felder am Vater `Prozessoren`)* |
| Wo stehen Bauteile, Konstanten und Stücklisten der Hardware-Projekte? | [D-775](90-decision-log.md) — unter `Hardware Project`, wie bei `PC`: `Projekte` (mit «Project Name»), `Electronic Parts`, `Constants › Elektronik`; `Part List Item` bleibt unter `Compositions` | ~~D-772~~ *(Konstanten unter `Primitives › Constants`)* |
| Wo liegt die Kompatibilitätsliste, die PC und Bauteile teilen? | [D-778](90-decision-log.md) — unter `Model`, weil sie beiden gehört | ~~D-769~~ *(unter `PC`)* |
| Wie sieht der Dialog der Satzauswahl aus? | [D-805](90-decision-log.md) — Baum der Knoten links, Liste der Sätze des gewählten Knotens rechts, Baum abschaltbar | ~~D-791~~ *(Schritt 1: Sätze als Blätter im Baum)* |
| Wie zeigt die Tabelle ein Teil mit mehreren Sätzen (1..n)? | [D-825](90-decision-log.md) — nur die Anzahl der Sätze als Link auf den Satz der Zeile; ausgebreitet zeigt es `complex` | ~~D-801~~ *(eingeschachtelte Teiltabelle in der Zelle)* |
| Wo steht «darunter einfügen» in einer Teilzeile? | [D-831](90-decision-log.md) — erst «+», dann der Mülleimer | ~~D-830~~ *(«+» hinter dem Mülleimer)* |
| Wo steht der Link eines Projekts? | [D-836](90-decision-log.md) — am Projekt, immer die originale GitHub-Seite | ~~D-833~~ *(«Quelle» an der Revision)* |
| Welche Art haben «Platinen» und «Revisionen»? | [D-837](90-decision-log.md) — Verweise auf Sätze (Aggregation); Positionen bleiben Komposition | ~~D-833~~, ~~D-834~~ *(«Komposition»)* |
| Wo stehen Beschreibung und Links eines Projekts? | [D-840](90-decision-log.md) — als Felder am Projekt, Links 0..* | *(vorher: eigener Kindknoten «Projekt Beschreibung»)* |
| Wie wird ein mehrfaches Feld gezeichnet? | [D-842](90-decision-log.md) — je Wert eine Zeile, darunter eine leere; fuer jede Feldart gleich | ~~D-811~~ *(mehrfacher Text als ein Feld mit Komma)* |
| Wie wird die Vorbelegung eines Verweises eingestellt? | [D-844](90-decision-log.md) — als Vergleichspaare «Feld hier ↔ Feld am angebotenen Satz», je Paar filter oder sort; seit [D-845](90-decision-log.md) je Paar eine gewählte Zusatzfunktion «Vorbelegung» | ~~D-791~~ *(Schritt 3: getrennte Listen `preset_field`, `preset_source`, ein `preset_mode`)* |
| Wo hängen Vorbelegung, «Mehrere hinzufügen» und die Prüfungen beim Speichern? | [D-845](90-decision-log.md) — als Zusatzfunktionen in der Liste `addons` an Knoten oder Kante, jede mit eigenen Feldern; was sie an anderen Knoten bedingen, erscheint nur dort | *(vorher: Felder jeder Kategorie und eine eigene Registratur der Validatoren)* |
| Wie sieht ein Medienfeld aus? | [D-846](90-decision-log.md) — Linkfeld, rechts ein Datei-Symbol zum Hochladen; angezeigt die aus der Datei gerechnete Beschreibung | *(vorher: nacktes Dateifeld, angezeigt der Dateiname)* |
| Wie sehen Knöpfe aus? | [D-847](90-decision-log.md) — Symbol, wo es eines gibt, rechts vom Feld, Tooltip mit Namen | — |
| Wie sieht ein Auswahlfeld aus? | [D-848](90-decision-log.md) — überall dasselbe, an einer Stelle gebaut; ohne Eintrag oder mit nur einem ausgegraut ([D-380](90-decision-log.md)) | — |
| Wie ist der Einstellungsbereich überschrieben? | [D-849](90-decision-log.md) — Kategorie, darunter wofür (Every node, Jump field, Renderer, Function) | — |
| Wie wird ein gesetzter Satzverweis bedient? | [D-851](90-decision-log.md) — Wert und Mülleimer; angezeigt ist er ein Link auf seinen Satz ([D-852](90-decision-log.md)) | *(vorher: die Schnellsuche aus [D-792](90-decision-log.md) stand auch neben einem gesetzten Verweis)* |
| Wann wird gespeichert? | [D-854](90-decision-log.md), [D-860](90-decision-log.md) — beim Verlassen des Feldes, bei Haken beim Verlassen der Gruppe, im Dialog mit «OK»; dann ohne Speichern-Knopf; umschaltbar in der Konfiguration | *(D-854 liess den Knopf stehen)* |
| Was ist ein Link im Modell? | [D-855](90-decision-log.md) — ein `Medium` aus Adresse und Beschriftung | *(vorher: nur die Adresse als Medientyp, [D-843](90-decision-log.md))* |
| Was ist eine Schnittstelle, und worauf verweist ein Steckplatz? | [D-862](90-decision-log.md) — ein Satz unter «Schnittstellen»; der Vater trägt die vergleichbaren Felder, der Steckplatz verweist auf einen Erweiterungsbus | *(vorher: Konstanten «Steckplatzarten» und die Auswahl «Schnittstellen»)* |
| Wann öffnet ein Klick einen Satz in der Vorschau? | [D-803](90-decision-log.md) — nur ein Klick in die eigene Satzzeile, nie aus einem Dialog; ein im Satzdialog gewählter Satz steht sofort im Öffner | ~~D-788~~ *(«eine Zelle enthält irgendwo einen Satzlink» — traf die ganze Seite)* |
| Wie wählt man die Glieder einer Verweisliste (erlaubte Einheiten, `summary_fields`)? | [D-799](90-decision-log.md) — Liste der gewählten mit ▲/▼ und ✕, darunter ein Auswahlfeld mit «+» | ~~D-732~~ *(ein Haken je Kandidat)* |
| Wo wird ein Datensatz bearbeitet? | [D-785](90-decision-log.md) — in der Vorschau oben; die Tabelle unten zeigt nur an, ihre Satznummer öffnet den Satz oben | ~~D-653~~ *(Bearbeiten und Speichern in der Zeile)* |
| Wie steht eine gewählte Einheit da? | [D-780](90-decision-log.md) — als ihr Zeichen (Ω, W, %, m), über `label_role = symbol` an `Base units` und `Prefixes`; die Vorschau hat keine eigenen Scrollbalken mehr | ~~D-777~~ *(nur der Scrollbalken der Vorschau)* |
| Welche Kennzeichnung trägt ein Bauteil im Katalog? | [D-779](90-decision-log.md) — nur den allgemeinen «Typ» (74LS688, LM7905); Hersteller und Bestellnummer gehören in den kaufmännischen Teil | ~~D-772~~ *(«Hersteller» und «Teilenummer» an `Electronic Parts`)* |
| Braucht es einen Zyklenwächter? | [D-497](90-decision-log.md) — nein: ein Feld auf ein Modell ist eine Aggregation, und die verweist, statt abzusteigen | ~~D-100~~ *(nur seine Kompositions-Hälfte)* |
| Erbt ein Einstellungsknoten sich selbst? | [D-607](90-decision-log.md) — nur die Kante, die auf ihn selbst zeigt, ist gesperrt; sichtbar gekennzeichnet ([D-608](90-decision-log.md)) | ~~D-605~~ |
| Wann entsteht ein Datensatz? | [D-609](90-decision-log.md) — beim ersten Schreiben; eine leere Wertzeile wird nicht geschrieben ([D-610](90-decision-log.md)) | — |
| Welche Arten von Datensatz gibt es? | [D-653](90-decision-log.md) — drei: `default`, `user`, `example`; die Art wird beim Anlegen gewählt ([D-651](90-decision-log.md)), ein leerer `default` besteht nicht |
| Was unterscheidet `default` und `example`? | [D-654](90-decision-log.md) — der `default` ist eine **Vorbelegung** und greift in jede Eingabe ein, das `example` wird nur gezeigt |
| Welche Art schlägt der Bildschirm vor? | [D-675](90-decision-log.md) — an einem einfachen Datentyp `example` für einen **Wert**, `default` für eine **Einstellung**; an einer Verwendungsstelle [D-702](90-decision-log.md) — ein **Vorgabesatz**, weil dort Einstellungen liegen und keine Benutzerdaten | ~~D-674~~ *(`user` an der Verwendungsstelle — von D-702 berichtigt)* |
| Wie liest ein Bildschirm einen Schiebeschalter zurück? | [D-708](90-decision-log.md) — nach seinem **Wert**, nie nach seinem Dasein: der Schalter schickt eine verborgene `0` vor sich her, also ist das Feld immer gesetzt | — |
| Wie findet man einen Knoten des Einheitengerüsts? | [D-709](90-decision-log.md) — über die **Notiz** (`taxmod_unit_node_<name>`), die das Gerüst je Knoten hinterlegt; nie über den Namen | [D-613](90-decision-log.md), [D-510](90-decision-log.md) |
| Gibt es einen Testast für Wächterknoten? | [D-710](90-decision-log.md) — **nein**; die Klammer dreht jeden Lauf zurück, es bleibt kein Rest | nimmt [D-614](90-decision-log.md) zurück |
| Was sagt die Renderer-Diagnose? | [D-711](90-decision-log.md) — **je Zelle**, welcher Renderer gezeichnet hat: eine Zeile je Satz, ein Eintrag je Feld; nur im Entwicklermodus, neben dem Formular | [D-705](90-decision-log.md) |
| Kann man in der Vorschau eingeben? | [D-652](90-decision-log.md) — nein, sie bleibt Vorschau; ~~[D-651](90-decision-log.md) wollte sie umbauen~~ |
| Was wird beim Anlegen aus den Vorgaben? | [D-533](90-decision-log.md), [D-534](90-decision-log.md) — echte Kopien, zwei Rückfragen über den Wert | ~~D-531~~ ~~D-532~~ |
| Wie steht ein Verweis auf ein anderes Ding? | [D-597](90-decision-log.md) — **eine** Spalte plus Raum, kein echter Fremdschlüssel; ebenso `labels.owner_id` ([D-641](90-decision-log.md)) | — |
| Wo liegt die Geschichte? | [D-537](90-decision-log.md) Schattentabellen · [D-536](90-decision-log.md) Version je Zeile · [D-601](90-decision-log.md) Rückgängig über die Änderungsgruppe | ~~D-427~~ (Journal als Heimat) |
| Was wandert beim Parken mit? | [D-575](90-decision-log.md) — in den Schatten, mit der Änderungsgruppe; [D-619](90-decision-log.md) — die Wertzeilen mit | ~~D-123~~ (Kennzeichen in der lebenden Tabelle) |
| Trägt jede Zeile eine Version? | [D-634](90-decision-log.md) — ja, und sie kann nicht weggelassen werden; `labels` auch ([D-640](90-decision-log.md)) | — |
| Gibt es eine `settings`-Tabelle? | [D-579](90-decision-log.md) — nein, gestrichen; es gibt nur Felder ([D-506](90-decision-log.md)) | ~~D-011~~ ~~D-084~~ ~~D-505~~ ~~D-529~~ |
| Wo steht die Multiplizität? | [D-528](90-decision-log.md) — Spalte an der Kante, nicht überschreibbar; Standard `1` ([D-434](90-decision-log.md)), `validator` ist `0..*` ([D-560](90-decision-log.md)) | ~~D-086~~ ~~D-351~~ ~~D-379~~ |
| Was ist `hide`? | [D-590](90-decision-log.md) — `nodes.hide` versteckt im Baum; nicht-Zeichnen sagt ein Renderer; `relations.hide` fällt | ~~D-399~~ ~~D-426~~ ~~D-448~~ ~~D-449~~ ~~D-453~~ ~~D-456~~ ~~D-457~~ ~~D-464~~ ~~D-467~~ |
| Wie kommen `validator` und `read_only` an einen Knoten? | [D-593](90-decision-log.md) — als Einstellungskanten wie der Renderer; frei setzbar ([D-461](90-decision-log.md)) | ~~D-409~~ |
| Ist ein einfacher Typ ein Knoten oder eine Klasse? | [D-620](90-decision-log.md) — beides, und es ist **dasselbe**: die Klasse ist die des Knotens ([D-484](90-decision-log.md)) | ~~D-036~~ |
| Darf ein benutzter Knoten gelöscht werden? | [D-604](90-decision-log.md) — nur nach Rückfrage; bleibende Verweise sind am Feld sichtbar | — |

## Renderer

| Frage | gilt heute | abgelöst |
|---|---|---|
| Was gibt ein Renderer zurück? | [D-596](90-decision-log.md), [D-623](90-decision-log.md) — der Kern eine **Beschreibung**, der Rand macht HTML daraus | ~~D-021~~ ~~D-463~~ ~~D-465~~ ~~D-563~~ |
| Welche Form hat die Beschreibung? | [D-628](90-decision-log.md), [D-629](90-decision-log.md) — eine Objektstruktur je **Seite**; JSON nur, wo WordPress es verlangt | — |
| Wer legt den Vertrag fest? | [D-624](90-decision-log.md) — eine Oberklasse, Kern-Renderer und Rand-Gegenstück; zuerst im Admin-Menü | — |
| Wie viele Renderer hat ein Knoten? | [D-584](90-decision-log.md) — genau **einen**; der Renderer ist selbst ein Knoten und darf hierarchisch sein | ~~D-217~~ ~~D-224~~ ~~D-236~~ ~~D-502~~ ~~D-548~~ |
| Wo wird der Renderer gewählt? | [D-647](90-decision-log.md) — eigener Renderer für die Wahl, Liste aus der Registratur, sprachabhängig beschriftet, gespeichert wird der Verweis | ~~D-433~~ |
| Wie wird ein Verweis im Feld gewählt? | [D-727](90-decision-log.md) — **ein** Wähler `chooser` mit dem Schalter `dialog` (Vorgabe aus) und `display_size`; eine flache Ebene ist ein Auswahlfeld. Welches Label der Gewählte zeigt, sagt die Konstante selbst ([D-728](90-decision-log.md)) | ~~D-108~~ ~~D-244~~ |
| Bekommt jeder Renderer einen Knoten? | [D-648](90-decision-log.md) — nur, was ein Feld des Modells zeichnen kann; interne nicht. [D-644](90-decision-log.md): die Renderer-Knoten verlieren die Marke | — |
| Wo hängt der Konverter? | [D-585](90-decision-log.md) — Einstellung am Basisknoten `Renderer`, an jeden Renderer vererbt; `DisplayOption` entfällt ([D-594](90-decision-log.md)) | ~~D-277~~ ~~D-514~~ ~~D-539~~ |
| Wofür taugt ein Konverter oder Renderer? | [D-603](90-decision-log.md) — `handles()` im Kode, nicht im Modell; der Typ schränkt ein | — |
| Wie wird eine Einstellung gezeichnet? | [D-546](90-decision-log.md) — immer als Tabelle; Datensätze ebenso ([D-552](90-decision-log.md), [D-553](90-decision-log.md)) | — |
| Wie viele Darstellungen gibt es? | [D-626](90-decision-log.md) — **eine**: Vorschau zeichnet wie das Frontend, später auch die Blöcke | ~~D-278~~ ~~D-547~~ (als Darstellungsfrage) |
| Was ist ein Block? | [D-625](90-decision-log.md) — ein Gutenberg-Block; er bekommt einen Knoten und holt sich dessen Datensätze | — |
| Von der Multiplizität zum Auswahlfeld? | [D-562](90-decision-log.md) — ein Begriff, die *Choice*, an **einer** Stelle gerechnet; was zur Wahl steht, sagt [D-540](90-decision-log.md) | — |
| Welche Renderer werden angeboten? | [D-481](90-decision-log.md), [D-482](90-decision-log.md), [D-483](90-decision-log.md) — der Anspruch liegt beim Renderer, nie am Knoten | — |
| Wie wird ein Benutzerverweis gezeichnet? | [D-649](90-decision-log.md) — eigener Renderer: der Name auf dem Schirm, die Id im Datensatz, beim Anlegen vom Rand vorbelegt; der Typ ist [D-330](90-decision-log.md) | — |
| Wo liegen Renderer, Konverter, Validator im Baum? | [D-557](90-decision-log.md) — im Ast `Settings`, und die Saat sät dorthin | ~~D-511~~ |
| Braucht die Vorschau eine Sonderregel? | [D-096](90-decision-log.md) — nein, sie ruft zeichnen zweimal auf | ~~D-095~~ |
| Ersetzt ein Konverter den Wert oder schmückt er ihn? | [D-226](90-decision-log.md) — freie Wahl; die Umkehrbarkeit entscheidet nur, ob hineingeschrieben werden darf | ~~D-225~~ |

## Ereignisse

| Frage | gilt heute | abgelöst |
|---|---|---|
| Wo ist die Naht zwischen Rand und Kern? | [D-627](90-decision-log.md) — der Rand stellt fest und schickt ein Ereignis hinein, ohne WordPress-Eigenheit darin | — |
| Ist das Protokoll ein Ereignisspeicher? | [D-627](90-decision-log.md) — nein, ein abschaltbarer Mechanismus zur Fehlersuche; auch das Änderungsbuch ist es nicht ([D-631](90-decision-log.md)) | — |
| Wie werden Ereignisse geschnitten? | [D-631](90-decision-log.md) — Knoten, Kante, Knoten-Datensatz, Kanten-Datensatz; die Akt-Klammer bleibt ([D-348](90-decision-log.md)) | — |
| Welche Auslöser gibt es? | [D-633](90-decision-log.md) — drei Formen; die enge Auswahl trifft der Listener Provider | — |
| Eigene Schnittstellen oder ein Paket? | [D-632](90-decision-log.md) — PSR-14 als Paket | — |
| Wie werden Fehler gezeigt? | [D-632](90-decision-log.md) — alle sammeln, die Oberfläche entscheidet die Form; ein Fehler blockt, eine Warnung nicht ([D-288](90-decision-log.md)) | — |
| In welchen Schritten wird umgebaut? | [D-638](90-decision-log.md) — alles auf einmal, Renderer und Ereignisse zusammen ([D-630](90-decision-log.md)); `PR-2` ist dafür eingeschränkt ([D-635](90-decision-log.md)) | ~~D-637~~ (die Knotenseite zuerst) |
| Woran wird die Vollständigkeit gemessen? | [D-636](90-decision-log.md) — ein zweiter Zeichner baut dieselbe Seite allein aus der Beschreibung. Nicht gleiches HTML | — |

## Beschriftungen

| Frage | gilt heute | abgelöst |
|---|---|---|
| Wo stehen Beschriftungen? | [D-580](90-decision-log.md) — `labels` und `label_texts`; Knoten und Kanten verweisen mit `label_id` | ~~D-019~~ |
| Ist der Name sprachabhängig? | [D-646](90-decision-log.md) — **ja**, er zieht zu den Texten je Sprache | ~~D-020~~ ~~D-580~~ (Name im Sprachunabhängigen) |
| Ist `symbol` sprachabhängig? | [D-645](90-decision-log.md), [D-646](90-decision-log.md) — ja, fünfte Spalte in `label_texts` | ~~D-580~~ (Symbol im Sprachunabhängigen) |
| Gibt es eine sprachneutrale Zeile? | [D-387](90-decision-log.md) — nein: eine Sprache **ist** die neutrale, und ungepflegtes fällt auf die Standardsprache zurück ([D-645](90-decision-log.md)) | ~~D-020~~ |
| Welche Rollen hat eine Beschriftung? | [D-598](90-decision-log.md) — vier Spalten `form`, `table`, `select`, `help`; Rollen als Zeilen sind geparkt. Dazu `symbol` und `name` ([D-646](90-decision-log.md)) | ~~D-151~~ ~~D-196~~ ~~D-209~~ ~~D-263~~ ~~D-264~~ ~~D-386~~ |
| Was passiert beim Umbenennen? | [D-053](90-decision-log.md) — der Verweis bleibt, nur der Wortlaut ändert sich; protokolliert ([D-489](90-decision-log.md)), versioniert ([D-640](90-decision-log.md)) | — |

## Oberfläche

| Frage | gilt heute | abgelöst |
|---|---|---|
| Wer speichert — das Feld oder die Seite? | [D-392](90-decision-log.md) — die Seite; Feldzeilen ([D-551](90-decision-log.md)) und Beschriftungen ([D-488](90-decision-log.md)) gehen mit | — |
| Wie legt man einen Knoten an? | [D-730](90-decision-log.md) — ein «+» im Baum und im Kopf; ein Dialog fragt Name und Klasse, anlegen oder abbrechen; die Klasse aus dem Erlaubten | — |
| Darf die Klasse eines Knotens wechseln? | [D-733](90-decision-log.md) — ja, auf eine Klasse, die der Vater erlaubt und die jedes Kind erlaubt; Einstellungen, die der neue Vertrag nicht erklärt, fallen weg | ~~D-716~~ (der Satz «danach fest») |
| Wie wählt man einen Knoten? | [D-589](90-decision-log.md) — der Dialog wählt und schliesst; der gewählte Knoten steht in einem gesperrten Feld neben dem Anlegen-Knopf | ~~D-108~~ ~~D-244~~ ~~D-392~~ (für diesen Fall) |
| Was steuert die Baumansicht? | [D-615](90-decision-log.md) — Wurzelknoten, Default-Knoten, Default-Ast, sonst nichts | ~~D-586~~ (Einstiegsast-Lesart) |
| Wer gewinnt beim Zuklappen? | [D-612](90-decision-log.md) — der Klick auf den Pfeil; der Vorrang der Auswahl gilt nur für ungeöffnete Äste | ~~D-480~~ (ausnahmslos) |
| Gibt es einen Installationsbildschirm? | [D-397](90-decision-log.md) — **ja, seit dem 2026-08-26**; dort stehen Entwicklermodus ([D-389](90-decision-log.md)) und Standardsprache ([D-645](90-decision-log.md)) | — |
| Wie entsteht eine Neuinstallation? | [D-600](90-decision-log.md) — künftig aus einem Abbild des gewachsenen Baums; bis dahin wird die laufende Installation angepasst | ~~D-119~~ (Saatgerüst als Weg) |
| Was steht im Datensatzblock? | [D-553](90-decision-log.md) — eine Zeile je Satz, «wovon · Record · Version»; keine Einstellungsspalten ([D-554](90-decision-log.md)) | — |
| Was räumt die Aufräumseite? | [D-247](90-decision-log.md) und [D-494](90-decision-log.md) — vier Quellen, darunter der Datensatz ohne Knoten ([D-485](90-decision-log.md)) | — |
| Gibt es eine Einstellungstafel unter der Feldzeile? | [D-666](90-decision-log.md) — ja, seit 2026-09-06: unter der Zeile, zugeklappt, und erst beim Aufklappen gelesen | ~~D-381~~ ~~D-385~~ ~~D-390~~ ~~D-517~~ ~~D-520~~ |
| Wann wird eine Liste von Häkchen geschrieben? | [D-299](90-decision-log.md), [D-300](90-decision-log.md) — eine Erlaubnisliste ist **ein** Wert und wird als Gruppe gespeichert | ~~D-298~~ |
| Wer darf was? | [D-292](90-decision-log.md) — Rollen sind eingebaut, der Administrator darf praktisch alles; Laufzeit-Erweiterung je Ast ([D-204](90-decision-log.md)) | — |

## Verfahren

| Frage | gilt heute | abgelöst |
|---|---|---|
| Wann ist etwas entschieden? | `PR-3` — wenn es im Log steht; und eine Entscheidung ist kein Wall ([D-222](90-decision-log.md)) | — |
| Gibt es ein Lock? | [D-565](90-decision-log.md) — nein: fertig, dann gebaut — oder veraltet, dann mit Grund ersetzt | ~~D-004~~ ~~D-338~~ |
| Gilt das Konzept in `docs/NewConcept/`? | [D-568](90-decision-log.md) — nein, es ist Steinbruch; neues geht nach `docs/neues-konzept-eingang.md` | ~~D-001~~ ~~D-006~~ |
| Was muss vor einem Commit grün sein? | [D-564](90-decision-log.md) — beide Läufe; eine Prüfung bewacht den **aktuellen** Soll-Zustand | ~~D-342~~ |
| Woran misst sich eine Spezifikation? | [D-566](90-decision-log.md) — an der Prüfbarkeit durch einen Menschen; das Immer-Gelesene hat eine Decke ([D-573](90-decision-log.md)) | ~~D-314~~ |
| Worauf darf eine Architekturregel stehen? | [D-569](90-decision-log.md) — auf einer Entscheidung **oder** auf einer Messung plus seiner Bestätigung | — |
| Muss nachgelesen werden? | [D-572](90-decision-log.md) — ja, mit Zitat; und der Ist-Stand im Kode ist keine Antwort auf eine Konzeptfrage ([D-645](90-decision-log.md)) | — |
| Wie findet ein Wächter seine Knoten? | [D-613](90-decision-log.md) — über die Id, nie über den Namen; eigene Knoten in einem Testast sind erlaubt ([D-614](90-decision-log.md)) | ~~D-022~~ (als Wächterpraxis) |
| Was heisst «bestätigt»? | [D-571](90-decision-log.md) — dass ein Satz von ihm dabeisteht; bewacht von `confirmed-quote-check.php` | — |

---

## Unklar — hier ist keine Kette zu lesen

⚠️ **Eine geratene Zeile wäre Schaden** (`PR-4`). *Diese vier sind gemessen offen und keine
Nachlässigkeit: es gibt zwei Zeilen und keine sagt, welche gewinnt.*

| Frage | was sich widerspricht |
|---|---|
| Erbt der Ast `Settings` von `Root`? | [D-545](90-decision-log.md) blockt es an der Astwurzel. [D-607](90-decision-log.md) sperrt nur noch die Kante auf sich selbst und nimmt [D-605](90-decision-log.md) zurück — ob D-545 daneben weiterhin gilt, sagt **keine** der drei. |
| ~~Darf eine Verwendungsstelle eine Einstellung des Zielknotens überschreiben?~~ | **Aufgelöst am 2026-09-06 durch [D-667](90-decision-log.md):** [D-611](90-decision-log.md) sagt ja, [D-643](90-decision-log.md) nahm den Träger, und der neue ist der Satz der Kante. *Steht bis zum Bau hier, damit die Lücke sichtbar bleibt: **entschieden, nicht gebaut** (TASK-002).* |
| Ist `symbol` übersetzbar? | [D-261](90-decision-log.md)/[D-262](90-decision-log.md): eine Marke, Vorgabe **nicht** übersetzbar. [D-645](90-decision-log.md): `symbol` **wird** sprachabhängig. Ob die Marke bleibt, ist nirgends gesagt. |
| In welcher Sprache wird dokumentiert? | [D-002](90-decision-log.md) sagt Englisch, [D-187](90-decision-log.md) Englisch für jeden Begriff. Seit dem 2026-08-28 sind Entscheidungen und Paketdokumente deutsch, und **nichts hebt D-002 auf**. |
