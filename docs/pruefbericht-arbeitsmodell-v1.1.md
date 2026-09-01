# Prüfbericht — «Semantische Paketarchitektur und Arbeitsregeln» v1.1

**Stand 2026-09-01.** Geprüft gegen den bestehenden Regelsatz (`CLAUDE.md`, 32 Regeln) und die
306 Regeln des Projekts. **Am Projekt wurde nichts geändert und nichts committet.**

Alles hier ist `INFERRED` oder `PROPOSED` in der Terminologie des geprüften Dokuments. Keine
Feststellung dieses Berichts ist eine Entscheidung.

---

## Ergebnis

**v1.1 ist als Prozess- und Architekturmodell tragfähig und an mehreren Stellen besser als der
bestehende Regelsatz. Es darf `CLAUDE.md` aber nicht ersetzen, sondern nur ergänzen.** Drei Befunde
sind wesentlich:

| | Befund |
|---|---|
| **1** | **§28 «Ein fehlender Status bedeutet nicht `ACTIVE`» macht am Tag der Übernahme das gesamte bestehende Fundament nicht-normativ.** 562 Entscheidungen haben kein Statusfeld — darunter die, die den Modellkern gesperrt und die Kern/Rand-Trennung eingeführt hat. Die Regel ist richtig; ohne Übergangsklausel löscht sie die Grundlage des Projekts. |
| **2** | **Von den 32 Regeln in `CLAUDE.md` haben 19 in v1.1 keine Entsprechung**, darunter die beiden sicherheitsrelevanten (`CD-5` Reihenfolge am Rand, `CD-6` vorbereitete Abfragen) und die drei teuersten Prozessregeln (`PR-10`, `PR-11`, `PR-12`). Bei einer Ersetzung gehen sie verloren. |
| **3** | **§2.7 erklärt eine Arbeitshypothese zur Paketdefinition** — genau der Vorgang, den §32 verbietet. |

---

## A · Was v1.1 gegenüber v1.0 aufgenommen hat

Kurz, damit klar ist, was **nicht** mehr zu prüfen ist.

| | |
|---|---|
| **§27** | Wächter, mit den drei Stufen `BEWACHT / SICHTBAR / KONVENTION` und dem Satz *«Eine Grenze ohne Prüfung ist keine durchgesetzte Grenze, sondern eine Absicht.»* §27.2 nennt ausdrücklich «dokumentierte Paketabhängigkeiten gegen tatsächliche Imports» — das war der wertvollste Vorschlag und ist aufgenommen. |
| **§28** | Status und Gültigkeit, mit *«Ein fehlender Status bedeutet nicht `ACTIVE`»*. Siehe aber Befund 1. |
| **§29** (erstes) | Entscheidung und Herleitung: *«Ausführliche Diskussionen, Alternativen und historische Herleitungen gehören in die Historie.»* Trifft die gemessenen 442 KB Herleitung genau. |
| **§30, §32** | Der Umgang mit berührten Altentscheidungen, und das Abschlussprinzip *«Plausible Annahmen dürfen nicht unbemerkt zu Projektwahrheit werden.»* |
| **§27.3** | «Keine Wächterinflation» — richtig und in v1.0 nicht enthalten. |

**Nicht aufgenommen wurden**, jeweils ohne Begründung im Dokument: die Entdoppelung (`C-3`), das
Streichen der `Confidence`-Zahl (`C-4`), die Entdoppelung des Wortes «Paket» (`C-2`) und die drei
offenen Fragen `E-1` bis `E-3`.

---

## B · Widersprüche

### B-1 · Zwei §29, und die Reihenfolge ist verletzt

`§29 Entscheidung und Herleitung` **und** `§29 Versionierung dieses Rulesets` tragen dieselbe Nummer.
Die zweite steht ausserdem **nach §33**. Gemessen: 33 Abschnitte, höchste Nummer 33, eine doppelte.

**Wirkung:** ein Verweis «§29» ist ab jetzt zweideutig — in einem Dokument, dessen Zweck Eindeutigkeit
ist. Zusätzlich fehlt vor §33 der Trenner.

### B-2 · Drei Statuslisten, die nicht übereinstimmen

| Ort | Werte |
|---|---|
| **§5** | `PROPOSED OPEN ACTIVE SUPERSEDED REJECTED` |
| **§9** | `FACT CONFIRMED INFERRED PROPOSED OPEN` — **ohne** `REJECTED` |
| **§24.4** | `FACT CONFIRMED INFERRED PROPOSED OPEN REJECTED` |
| **§28** | alle acht zusammen |

**Drei Fragen bleiben offen, und jede ändert das Programm:**

1. Ist `CONFIRMED` dasselbe wie `ACTIVE`? §5 kennt nur `ACTIVE` als normativ, §28 listet beide.
2. **Ist `FACT` normativ?** §28 sagt, normative Wirkung habe nur, was der Status bestätigt — erklärt dann aber im Detailblock nur `ACTIVE`, `SUPERSEDED`, `REJECTED`, `PROPOSED`, `INFERRED`. **`FACT`, `CONFIRMED` und `OPEN` werden aufgezählt und nicht erklärt.**
3. Warum fehlt `REJECTED` in §9 und steht in §24.4? Zwei Listen für dasselbe, 15 Abschnitte auseinander.

⚠️ *Das ist nicht Pedanterie: `FACT` heisst «durch Code belegt». Wäre `FACT` normativ, wäre der
Ist-Zustand des Codes eine Vorgabe — und §19 sagt ausdrücklich das Gegenteil.*

### B-3 · §27 gegen §27.3

§27: *«Eine Grenze ohne Prüfung ist keine durchgesetzte Grenze, sondern eine Absicht.»*
§27.3: *«Nur relevante, dauerhafte und überprüfbare Grenzen sollen technisch bewacht werden.»*

Was ist dann eine bewusst unbewachte Grenze? Nach §27 eine Absicht, nach §27.3 legitim. **Die
Auflösung entscheidet im Moment der Leser** — also genau die Stelle, an der dieses Projekt bisher
verloren hat. §27 hat das Wort schon (`KONVENTION`); §27.3 verbindet sich nicht damit.

**Vorschlag:** eine unbewachte Grenze heisst nicht «Grenze», sondern `KONVENTION`, und wird in der
Paketdokumentation als solche geführt.

### B-4 · §19 gegen §28

§19 stellt «2. Aktuelle Paketdokumentation» **über** «3. Aktive Architekturentscheidungen». §28 sagt,
eine Aussage ohne Status sei nicht normativ. **Ein `package.md` besteht aber überwiegend aus Aussagen
ohne Status** (Zweck, Verantwortungsgrenze, Datenfluss, aktuelle Architektur — §4.1 bis §4.6).

Damit besteht die zweithöchste Autorität teilweise aus nicht-normativem Text. Was gewinnt: der Rang
oder der Status?

**Vorschlag:** §19 ordnet **Quellen**, nicht Aussagen. Innerhalb einer Quelle entscheidet der Status.

### B-5 · §2.7 macht eine Arbeitshypothese zur Paketdefinition

§2.7: *«Er erzeugt eine plattformunabhängige Beschreibung bzw. ein Rendering-Ergebnis, nicht direkt
WordPress-spezifische GUI-Logik.»*

Diese Aussage ist im Projekt ausdrücklich **als Hypothese** hinterlegt.
`docs/uebergabe/README.md` sagt über `antwort-ereignisse.md`:

> *«in Abschnitt 7 eine **Arbeitshypothese** (kein Faktum) zu «der Kern beschreibt, der Rand malt»»*

Und der Eigentümer hat das selbst so verlangt: *«übernimm das aber nicht als fakt eher als arbeit
hypothes»*.

**In v1.1 steht sie ohne Statuskennzeichnung als Definition eines Pakets.** Das ist der Vorgang, den
§32 des Dokuments verbietet: *«Plausible Annahmen dürfen nicht unbemerkt zu Projektwahrheit werden.»*

**Zusätzlich widerspricht sie dem gebauten Zustand.** `CD-8` verlangt heute, dass Darstellungscode
**Zeichenketten zurückgibt**; 76 `R`-Regeln und der gesamte Renderer sind darauf gebaut. Der Umbau auf
eine plattformunabhängige Beschreibung ist möglich und war der Gegenstand des Gesprächs — aber er ist
**nicht entschieden**, und die Kosten sind nicht benannt.

### B-6 · §2.5 setzt Architektur in einem unentschiedenen Bereich

§2.5 legt fest, dass Listener über Event-Typen gemappt werden und `supports()` zur Feinauswahl dienen
kann. **Das Ereignissystem ist nicht entschieden** — es wurde als Konzeptaufgabe an Dritte vergeben,
und die offenen Fragen dazu liegen in `antwort-ereignisse.md`. Nach §5/§28 müsste §2.5 `PROPOSED`
tragen.

### B-7 · §2.2 verletzt §24.1

§2.2 nennt die fachlichen Objekte *«Node / Record / Edge / weitere fachliche Objekte»*.

Der abgestimmte Wortschatz des Projekts ist **Knoten, Kante, Knoten-Datensatz, Kanten-Datensatz** —
vier unterscheidbare Dinge. **«Record» ist zwischen zwei davon zweideutig**, und «Edge» ist ein
fünfter Begriff für ein Ding, das schon zwei Namen hat.

§24.1 verlangt *«eine möglichst eindeutige gemeinsame Terminologie»*. §2.2 unterläuft sie im
Architekturkapitel.

### B-8 · §18 schwächt `PR-9` ab — eine Verschlechterung

| | |
|---|---|
| **`PR-9` heute** | *«Both test runs are green before anything is committed»* — absolut, ohne Ausnahme, mit Entscheidungsnummer. |
| **§18 in v1.1** | *«Änderungen sollen **nach Möglichkeit** mit Tests abgesichert werden.»* |

Wenn v1.1 `CLAUDE.md` ersetzt, wird aus einer harten Bedingung eine Empfehlung. **Das ist die
einzige Stelle im Dokument, an der eine bestehende Regel nicht ersetzt, sondern aufgeweicht wird** —
und sie betrifft die Sicherung, die das Projekt bisher am zuverlässigsten getragen hat.

### B-9 · Die Paketnummerierung widerspricht §16 — unverändert aus v1.0

`01 Database`, `02 Data Model`, `03 Core` liest sich als Stapel mit dem Core **auf** der Datenbank.
§16 sagt `Core → Interfaces → Adapter → Framework`. **Beides zusammen ergibt zwei verschiedene
Programme.** Heute im Code gilt das Zweite. Die Frage war in den Anmerkungen zu v1.0 als `E-1`
gestellt und ist unbeantwortet.

### B-10 · «Paket» bleibt doppelt belegt — unverändert aus v1.0

Sieben **Arbeitspakete** der Abarbeitungsliste gegen neun **Architekturpakete**. `CD-9` verbietet
genau das, und §24.1 ebenfalls.

---

## C · Redundanzen

**Gemessen: v1.1 ist 17 % länger als v1.0, und keine der sechs wörtlichen Wiederholungen wurde
entfernt.** Jede steht weiterhin genau zweimal:

| Wiederholter Block | Orte |
|---|---|
| «Widerspruch ist erwünscht. Blockade ist nicht erwünscht.» | §20, §33 |
| Erkenntnis / Annahme / Entscheidung, wörtlich identisch | §10, §24.5 |
| Die Ausgabestruktur `ERGEBNIS … NÄCHSTER SCHRITT` | §13/§14, §24.7 |
| «Keine versteckten Entscheidungen» samt Liste der acht Punkte | §13, §24.6 |
| «Confidence ersetzt …» | §9, §24.4 |
| Die Statuswerte | §9, §24.4, §28 — **dreifach** |

**Dazu drei neue Überlappungen durch die Erweiterung:**

- **§6 ≈ §20 ≈ §33** — wer entscheiden darf, jetzt an drei Stellen.
- **§7 ≈ §26 ≈ §30** — der Ablauf einer Konzeptänderung, jetzt an drei Stellen.
- **§22 ≈ §31** — das Zielbild; hier ist die Doppelung leichter, weil §22 das Diagramm und §31 den Fragenkatalog trägt.

**Das Dokument verletzt damit seinen eigenen §29 (zweites):** *«Ziel ist ausdrücklich Regelreduktion
statt Regelakkumulation … soll nach Möglichkeit die alte Regel entfernt und nicht zusätzlich erhalten
werden.»* Von v1.0 auf v1.1 wurde ausschliesslich hinzugefügt.

⚠️ *Das ist kein Formfehler. Genau dieser Vorgang — hinzufügen statt ersetzen — hat aus 82 KB
Regelwerk im vorigen Anlauf 306 Regeln in 16 Räumen gemacht. Er tritt hier bei Version 1.1 auf,
im Dokument, das ihn abstellen soll.*

---

## D · Fehlende Punkte — der bestehende Regelsatz gegen v1.1

Die 32 Regeln aus `CLAUDE.md`, jede einzeln geprüft.

| Regel | Inhalt | In v1.1 |
|---|---|---|
| `PR-1` | Quelle der Wahrheit | **besser** — §19 |
| `PR-1b` | Altlast geerntet und **geschlossen**, nie erweitert | **fehlt** |
| `PR-2` | Lücke im Konzept wird zur Entscheidung | abgedeckt — §7, §26, §30 |
| `PR-3` | Nichts entschieden ohne Eintrag mit Id | **besser** — §5, §6 |
| `PR-4` | Unklar bleibt unklar | abgedeckt — §4.9, §6, §32 |
| `PR-5` | Konzept **zuerst aus den Aussagen des Eigentümers** | **teilweise** — §6/§33 verbieten nur, dass die KI entscheidet; die positive Pflicht fehlt |
| `PR-6` | Dokumentationsstil, ein Diagramm je Sachverhalt | **fehlt** (geringes Gewicht) |
| `PR-7` | Wahrheitsgemäß berichten | abgedeckt — §32, §9 |
| `PR-8` | Regelhygiene gilt für die Regeldatei selbst | abgedeckt — §29 (zweites) |
| `PR-9` | **Beide Läufe grün vor jedem Commit** | **abgeschwächt** — §18, siehe B-8 |
| `PR-10` | **Nachlesen und zitieren, nie aus dem Gedächtnis** | **fehlt** |
| `PR-11` | Nie unbewachte Textumwandlung in eine Datei schreiben | **fehlt** |
| `PR-12` | **Zieht ein Wert um, zieht der Leser zuerst** | **fehlt** |
| `CD-1` | Kern ohne WordPress | **besser** — §2.3, §16, §27.1 |
| `CD-2` | `declare(strict_types=1)` als erste Zeile | **fehlt** |
| `CD-3` | PSR-4, kein `require_once` | **fehlt** |
| `CD-4` | Alles typisieren | **fehlt** |
| `CD-5` | **Rechte → Nonce → prüfen → säubern → handeln → escapen** | **fehlt — sicherheitsrelevant** |
| `CD-6` | Tabellenpräfix, `dbDelta`, Schemaversion, **nur vorbereitete Abfragen** | **fehlt — sicherheitsrelevant** |
| `CD-7` | Kein N+1 | **fehlt** |
| `CD-8` | Darstellung **gibt zurück**, kein `echo` | **fehlt**, und §2.7 steht dagegen |
| `CD-9` | Namen sagen, was das Ding ist | schwach — §15 «sprechende Namen» |
| `CD-10` | Ausnahmen im Kern, `WP_Error` am Rand, nie nacktes `false` | **fehlt** |
| `CD-11` | Semantische Version, alles im selben Commit | **fehlt** |
| `CD-12` | Blocknamensraum | **fehlt** (geringes Gewicht) |
| `AR-1` | Modell in **eigenen Tabellen**, nicht in Posts, Postmeta, Terms | **fehlt** — §2.1 beschreibt Persistenz technisch, verbietet aber nichts |
| `AR-2` | Nichts Benutzersichtbares fest verdrahtet; Softwaretext und Modell-Label teilen nie einen Mechanismus | **fehlt** |
| `DC-1` | Kommentare erklären **warum** | schwach — §15 |
| `DC-2` | Jede Klasse ein Satz Zweck plus Konzeptverweis | **fehlt** |
| `DC-3` | `@param` nur wo der Typ es nicht sagt | **fehlt** |
| `DC-4` | Diagramm im Docblock für schwer sichtbare Abläufe | **fehlt** |
| `DC-5` | `README.md` je Quellordner | **fehlt** |

**Bilanz: 6 abgedeckt, 4 besser, 1 teilweise, 1 abgeschwächt, 20 fehlen.**

### D-1 · Die Deutung dieser Bilanz

**Sie ist kein Mangel des Dokuments.** v1.1 ist ein **Prozess- und Architekturmodell**; `CD` und `DC`
sind ein **Code-Standard**. Zwei verschiedene Gegenstände. §15 ist der einzige Berührungspunkt und
besteht aus allgemeinen Gütewörtern («kleine Klassen», «sprechende Namen»), die nichts entscheiden.

**Der Befund lautet deshalb nicht «v1.1 ist unvollständig», sondern:**

> **v1.1 kann `CLAUDE.md` nicht ersetzen. Es ersetzt den `PR`-Teil und verbessert ihn. Der
> `CD`/`DC`-Teil — 17 Regeln — muss bestehen bleiben.**

### D-2 · Die zwei Lücken mit dem höchsten Risiko

**`CD-5` und `CD-6` sind die einzigen sicherheitsrelevanten Regeln des Projekts.** Rechteprüfung,
Nonce, Säubern, Escapen und vorbereitete Abfragen. §2.8 sagt über den Platform Adapter nur
*«Hier dürfen Plattform-APIs verwendet werden»* — ohne Reihenfolge und ohne Pflicht.

**Eine Lücke in einer Prozessregel erzeugt Verwirrung. Eine Lücke hier erzeugt eine Schwachstelle.**

### D-3 · Die drei teuersten Prozessregeln fehlen

| | Was sie gekostet hat |
|---|---|
| `PR-10` | Am 2026-08-25 war **jede** aus dem Gedächtnis gegebene Antwort falsch; jede nach einem Nachschlagen hielt. Die Regel ist die am besten gemessene des Projekts. |
| `PR-11` | Ein fehlgeschlagenes `preg_replace` gab `null` zurück, `null` wurde gespeichert, **eine Quelldatei auf null Byte geleert** — vor dem ersten Commit. |
| `PR-12` | Sechs Leser blieben an einer alten Tabelle hängen. **Der Bildschirm funktionierte weiter und zeigte still das Falsche**, und keine der 305 Prüfungen merkte es. |

§18 fragt zwar nach Seiteneffekten, kennt aber nicht die **Reihenfolge** «Wächter, Leser, Daten», die
der eigentliche Inhalt von `PR-12` ist.

### D-4 · Weitere fehlende Punkte ohne Vorbild im alten Regelsatz

| | |
|---|---|
| **F-1** | **Kein Übergang für die 562 statuslosen Entscheidungen.** Siehe Befund 1 im Ergebnis. Ohne Klausel sind `D-338` (Modellkern gesperrt) und `D-009` (Kern/Rand-Trennung) am Tag der Übernahme nicht mehr normativ. |
| **F-2** | **Nicht gesagt, was bei einem roten Wächter geschieht.** §27 führt Wächter ein, aber nicht die Folge. `PR-9` hatte sie: nichts wird committet. |
| **F-3** | **Wer die Wächter schreibt und pflegt**, und ob ein neues Paket ohne Wächter entstehen darf. §27.3 warnt vor Inflation, §27 verlangt Prüfbarkeit — die Regel für den Normalfall fehlt. |
| **F-4** | **Der Diktat-Wortschatz fehlt.** §24.1 nennt Spracherkennung abstrakt. Die verwertbare Hälfte sind die Wörter selbst — *Rennrad* = Renderer, *Fahrt* = Pfad, *Schah* = char und weitere. Aus falsch geratenen Diktatwörtern sind in diesem Projekt Datenbankspalten entstanden. |
| **F-5** | **Kein Ort für die vorhandenen 306 Regeln.** v1.1 beschreibt, wie Regeln künftig entstehen, sagt aber nicht, was mit den bestehenden geschieht — 111 im Modellkern, 76 beim Renderer. Fallen sie unter §28 («ohne Status nicht `ACTIVE`»), sind sie mit der Übernahme alle nicht mehr normativ. |
| **F-6** | **Keine Aussage zur Reihenfolge von Code- und Dokumentänderung.** §18 sagt, sie «gehören zusammen»; `PR-12` sagt, **der Leser zieht zuerst**. Bei einem Datenumzug ist «zusammen» nicht genug. |

---

## E · Offene Fragen aus dem Bericht zu v1.0, die unbeantwortet blieben

| | |
|---|---|
| **E-1** | Ist `Database` eine Schicht **unter** dem Core oder ein Adapter **hinter** einer Kernschnittstelle? Unverändert widersprüchlich, siehe B-9. |
| **E-2** | Wer trägt den Status in die 562 bestehenden Entscheidungen ein, und wann? §28 verschärft die Frage, statt sie zu beantworten. |
| **E-3** | Wie heissen die alten sieben Pakete künftig? Unverändert, siehe B-10. |

---

## F · Empfehlung

**Reihenfolge nach Risiko, nicht nach Aufwand.**

1. **Übergangsklausel zu §28**, sonst ist die Übernahme selbst der grösste Einzelschaden. Vorschlag: *bestehende Entscheidungen gelten als `OPEN`, bis sie beim Zitieren geprüft werden; ein `OPEN` darf nicht als Grundlage dienen, blockiert aber nicht den Bestand.*
2. **`CD` und `DC` bleiben in Kraft.** v1.1 ersetzt den `PR`-Teil, nicht den Code-Standard. Insbesondere `CD-5` und `CD-6` müssen wörtlich erhalten bleiben.
3. **`PR-9` nicht durch §18 ersetzen**, sondern §18 auf `PR-9`s Härte anheben.
4. **`PR-10`, `PR-11`, `PR-12` aufnehmen.** Drei Sätze, drei gemessene Vorfälle.
5. **§2.5 und §2.7 mit `PROPOSED` kennzeichnen**, bis Ereigniskonzept und Renderer-Umbau entschieden sind.
6. **Doppelte §29 auflösen**, Reihenfolge herstellen.
7. **Die sechs wörtlichen Wiederholungen zusammenführen** und die drei Statuslisten auf **eine** reduzieren, mit `FACT`, `CONFIRMED` und `OPEN` erklärt.
8. **`E-1` entscheiden**, bevor der Paketschnitt gilt.
9. **§2.2 auf den Projektwortschatz bringen.**

---

## Zusammenfassung nach §24.7

```text
ERGEBNIS
v1.1 ist als Prozess- und Architekturmodell tragfaehig und an vier Stellen
besser als der bestehende Regelsatz. Es darf CLAUDE.md nicht ersetzen,
sondern nur den PR-Teil.

GEAENDERT
Nichts. Kein Commit, keine Aenderung am Projekt.

WIDERSPRUECHE
10 Stueck. Schwerwiegend: doppelte §29; drei nicht uebereinstimmende
Statuslisten mit drei ungeklaerten Werten; §2.7 macht eine ausdrueckliche
Arbeitshypothese zur Paketdefinition; §18 schwaecht PR-9 ab.

REDUNDANZEN
Die sechs woertlichen Wiederholungen aus v1.0 sind unveraendert vorhanden,
drei neue Ueberlappungen sind hinzugekommen, das Dokument ist 17 Prozent
laenger. Es wurde ausschliesslich hinzugefuegt - entgegen seinem eigenen
Grundsatz der Regelreduktion.

FEHLENDE PUNKTE
Von 32 bestehenden Regeln fehlen 20, darunter die beiden
sicherheitsrelevanten (CD-5, CD-6) und die drei teuersten Prozessregeln
(PR-10, PR-11, PR-12). Dazu sechs Punkte ohne Vorbild, vor allem der
Uebergang fuer 562 statuslose Entscheidungen.

ENTSCHEIDUNGEN ZUR BESTAETIGUNG
Keine. Dieser Bericht entscheidet nichts.

OFFENE FRAGEN
E-1 Database: Schicht unter dem Core oder Adapter dahinter?
E-2 Wer setzt den Status in die 562 bestehenden Entscheidungen?
E-3 Wie heissen die alten sieben Pakete kuenftig?

NAECHSTER SCHRITT
Punkt 1 der Empfehlung, weil ohne Uebergangsklausel die Uebernahme selbst
das Fundament ungueltig macht. Danach Punkt 2 und 3.
```
