# Anmerkungen zu «Semantische Paketarchitektur und Arbeitsregeln» v1.0

**Stand 2026-09-01.** Reaktion, Fragen und Änderungsvorschläge zu deinem Dokument, geschrieben zum
**Einarbeiten**. Ich habe nichts entschieden — alles hier ist `PROPOSED` in deiner eigenen
Terminologie.

**Empfehlung in einem Satz: dein Dokument wird der Rahmen, nicht mein Vorschlag.** Es ist an drei
Stellen nachweisbar besser (§ A). Es braucht drei Ergänzungen (§ B, C, D) und die Klärung von drei
Fragen (§ E), bevor es gilt.

---

## A · Wo dein Vorschlag meinen schlägt

Nicht als Höflichkeit, sondern weil du wissen sollst, was **nicht** geändert werden muss.

**A-1 · `package.md` / `history.md` statt «einfrieren und ernten».** Mein Vorschlag war ein
einmaliger Kraftakt und hätte beim nächsten Wachstum wieder von vorn begonnen. Deine Trennung gilt
**bei jeder Änderung**. Gleiches Ziel, wiederholbarer Mechanismus.

**A-2 · §19 korrigiert einen Fehler von mir.** Ich hatte behauptet, der Code sei die genaueste
Beschreibung des Modells «und er lügt nicht». Dein Satz ist richtig:

> *«Der Code beschreibt den aktuellen Ist-Zustand, aber nicht automatisch den gewünschten
> Soll-Zustand.»*

Mein Satz hätte jede Abweichung zwischen Absicht und Umsetzung zur Absicht erklärt — also genau den
Fehler zur Regel gemacht, der in diesem Projekt am häufigsten vorkam.

**A-3 · Der Status an der Entscheidung ist präziser als mein «dein Satz oder gar keiner».** Er trifft
messbar zwei Altlasten: **108 geltende Stellen berufen sich auf zurückgenommene Entscheidungen**, und
`50-wordpress-persistence.md` zitiert für `nodes.path` eine Entscheidung, die den Status
`agreed (proposal)` trägt — also nach deiner Terminologie **nicht** `ACTIVE`.

**A-4 · §23 Inbox ist besser als «hinten anhängen».** Deine Fassung trennt **erfasst** von
**angenommen**. Genau dort ging es schief: ein von dir gemeldeter Fehler wurde bei mir zur
Entwurfsentscheidung, weil «aufgenommen» und «beschlossen» derselbe Vorgang waren.

---

## B · Die eine strukturelle Lücke: nichts davon kann rot werden

Du schreibst, der Wächter sei dir immer wichtig gewesen — dann ist das hier der Kern zum Einarbeiten.

Alle 29 Abschnitte sind Verhaltensregeln. Dieses Projekt hat gemessen, was das wert ist: **306 Regeln
existieren, klar geschrieben — und `R1` wurde mehrfach gebrochen, während die Regel dastand.** Dein
§25 verlangt, eine Regel solle einen wiederkehrenden Fehler *verhindern*. Eine Regel, die man nur
stillschweigend verletzen kann, verhindert nichts; sie verteilt hinterher Schuld.

**Der Gegenbeweis steht in deinem Dokument:** die eine Grenze, die dieses Projekt **nie** verletzt hat,
ist «der Core kennt kein WordPress» — nicht weil sie besser formuliert war, sondern weil ein Lauf beim
ersten Verstoß scheitert.

### B-1 · Vorschlag: drei Stufen statt einer

Ich schlage vor, jeden deiner Abschnitte einer Stufe zuzuordnen. Das kostet dich eine Spalte und macht
sofort sichtbar, welche Regel trägt und welche eine Absichtserklärung ist.

| Stufe | Bedeutung | Beispiel aus deinem Dokument |
|---|---|---|
| **bewacht** | Ein Skript wird rot. Die Regel gilt, ob jemand sie liest oder nicht. | §5 Entscheidungsstatus, §2 Paketgrenzen, §4.4 Abhängigkeiten |
| **sichtbar** | Kein Skript, aber du siehst den Verstoß im Vorbeilesen. | §13/§24.7 Ausgabestruktur, §24.6 Kennzeichnungspflicht |
| **Konvention** | Nur guter Wille. Darf so heissen, aber nicht als Sicherung gezählt werden. | §15 Codequalität, §24.3 «Nachfragen ist erwünscht» |

**Der Zweck der dritten Stufe ist Ehrlichkeit.** Ein Regelsatz, in dem Konventionen und Wächter gleich
aussehen, erzeugt das Gefühl von Kontrolle, das dieses Projekt zweimal ruiniert hat.

### B-2 · Die Wächter, konkret — was ich bauen kann

Nummeriert, damit du sie einzeln übernehmen oder ablehnen kannst.

| | Wächter | Bewacht |
|---|---|---|
| **G-1** | Keine `ACTIVE`-Entscheidung und kein `package.md` zitiert eine `SUPERSEDED` oder `REJECTED`. | §5, §8 |
| **G-2** | Jede Entscheidung trägt **genau einen** Status aus der Liste. Fehlender oder unbekannter Status → rot. | §5 |
| **G-3** | Eine `SUPERSEDED` nennt ihren Nachfolger, und der existiert und ist `ACTIVE`. | §5 |
| **G-4** | Jede `TASK-`Nummer in einer Commit-Nachricht steht in einer Arbeitsliste. | §4.7, §12 |
| **G-5** | Jede `BUG-`Nummer in einer Commit-Nachricht steht in einer Fehlerliste. | §4.8, §11 |
| **G-6** | Ein `BUG` auf `FIXED` nennt den Commit **oder die Prüfung**, die es belegt. | §11 |
| **G-7** | Keine `OPEN`-Frage wird irgendwo als Begründung zitiert. | §4.9, §10 |
| **G-8** | Kein `INF-` ohne Kategorie älter als *n* Tage. | §23.1, §23.2 |
| **G-9** | Keine `inbox.md` wird als Quelle zitiert. | §23.1 |
| **G-10** | **Je Paketgrenze eine eigene Prüfung.** Eine Grenze ohne Prüfung ist keine Grenze, sondern eine Absicht. | §2, §21 |
| **G-11** | **Die in `package.md` erklärten Abhängigkeiten stimmen mit den wirklichen Importen überein.** | §4.4, §4.3 |
| **G-12** | Die Regelzahl des Rulesets darf nicht ohne Entscheidung steigen — Decke wie beim bestehenden `superseded-check`. | §29 |

⚠️ **G-11 ist der wertvollste, und ich sage auch warum.** *Die drei häufigsten Einzelfehler dieses
Projekts waren: **gebaut und nicht angeschlossen**, **zweimal gebaut**, und **ein Leser blieb an der
alten Quelle hängen**. Alle drei sind Abweichungen zwischen erklärter und tatsächlicher Abhängigkeit.
G-11 ist der einzige Wächter in dieser Liste, der sie **vor** dem Schaden findet — die anderen finden
Papierfehler. Wenn du nur einen übernimmst, diesen.*

⚠️ *`G-1`, `G-2`, `G-3` sind zu zwei Dritteln schon da: `scripts/dev/superseded-check.php` zählt heute
**108** solche Verweise und hat eine Decke, die nur fallen darf. Der Umbau ist klein.*

---

## C · Vier Änderungen im Text

**C-1 · Statuslos heisst nicht `ACTIVE`.** Vorgeschlagener Satz für §5:

> **Fehlt einer Entscheidung der Status, gilt sie nicht als `ACTIVE`.** Sie muss geprüft und
> eingeordnet werden, bevor sie als Grundlage dienen darf.

*Begründung: es gibt **562 Entscheidungen ohne Statusfeld**. Ohne diesen Satz sind sie von `ACTIVE`
nicht unterscheidbar — und genau so sind die 108 Verweise auf Zurückgenommenes entstanden.*

**C-2 · Das Wort «Paket» ist doppelt belegt.** Das Projekt hat sieben «Pakete»: das sind
**Arbeitspakete** der Abarbeitungsliste. Deine neun sind **Architekturpakete**. Zwei Bedeutungen für
ein Wort, das künftig überall steht — `CD-9` verbietet genau das. Vorschlag: deine heissen
**Pakete**, die alten werden zu **Bauabschnitten** umbenannt (oder ein Wort deiner Wahl), und die
Umbenennung passiert **in einem Commit**.

**C-3 · Sechs Blöcke stehen wörtlich zweimal drin, gemessen.** Erkenntnis/Annahme/Entscheidung ·
die Ausgabestruktur · «Widerspruch ist erwünscht. Blockade ist nicht erwünscht.» · «Confidence
ersetzt …» · «Keine versteckten Entscheidungen» · die Liste der acht Kennzeichnungspflichten.

§24 ist **15 %** des Dokuments und wiederholt größtenteils §§6, 9, 10, 13 (zusammen **12 %**).
Vorschlag: **§24 behalten als der Ort für Kommunikation, §§6, 9, 10, 13 dort auflösen** — oder
umgekehrt, aber nicht beides. §29 verlangt es ausdrücklich, und bei Version 1.0 ist es billig.

**C-4 · `Confidence: 0–100` streichen, die Statusliste behalten.** Du schreibst selbst, es sei keine
Wahrscheinlichkeit und ersetze weder Begründung noch Bestätigung. Dann tut die Zahl keine Arbeit und
lädt zu der Scheingenauigkeit ein, gegen die der Abschnitt geschrieben ist.

**`FACT / CONFIRMED / INFERRED / PROPOSED / OPEN / REJECTED` dagegen ist der beste Einzelbaustein des
Dokuments.** Er trifft genau die Unterscheidung, an der dieses Projekt am meisten Zeit verloren hat:
**dein Satz** gegen **meine Herleitung**. `CONFIRMED` heisst «er hat es gesagt», `INFERRED` heisst
«ich habe es geschlossen». Diese zwei waren im Entscheidungsbuch nicht unterscheidbar — und in **97 %
der ersten hundert Entscheidungen** fehlt dein Satz.

---

## D · Eine Lücke, die dich betrifft

§24.7 sagt: *«kurz bedeutet jedoch nicht, semantische Zusammenhänge wegzulassen.»*

**Unter genau diesem Satz sind 442 KB Herleitung entstanden — 64 % des Entscheidungsbuchs.** Der Satz
ist richtig, aber er braucht einen **Ort**, sonst ist er die Lizenz zum Zuwachsen.

Vorschlag als Ergänzung zu §24.7 oder §3:

> Die Begründung einer Entscheidung gehört nach `history.md`. In `package.md` steht die Entscheidung
> und ihr Grund in einem Satz. **Was bei jeder Orientierung mitgelesen wird, bleibt klein.**

*Gemessen: die Entscheidungen selbst sind 244 KB bei einem Median von **404 Zeichen** — also
angemessen. Die 442 KB sind ausschliesslich meine Herleitung darunter.*

---

## E · Offene Fragen — bitte du

**E-1 · Ist `Database` eine Schicht unter dem Core, oder ein Adapter hinter einer Kernschnittstelle?**

Deine Paketliste beginnt `01 Database`, `02 Data Model`, `03 Core` — das liest sich wie ein Stapel mit
dem Core **oben auf** der Datenbank. Dein §16 sagt das Gegenteil:

```text
Core  →  Interfaces  →  Adapter  →  Framework
```

**Beides zusammen ergibt zwei verschiedene Programme.** Heute im Code ist es das Zweite: `src/Core`
erklärt Repository-Schnittstellen, `src/WordPress/Persistence` erfüllt sie. Die Nummerierung schlägt
das Erste vor. Ich habe es **nicht** stillschweigend aufgelöst (§24.6).

**E-2 · Wer trägt den Status in die 562 bestehenden Entscheidungen ein, und wann?** Solange das Feld
fehlt, greift `G-2` nicht, und ohne `C-1` sind statuslose Entscheidungen von `ACTIVE` nicht zu
unterscheiden. Drei Wege: alle auf einmal, beim Anfassen, oder alle auf `OPEN` und beim Zitieren
prüfen.

**E-3 · Wie heissen die alten sieben Pakete künftig?** Siehe `C-2`.

---

## F · Was ich vorschlage zu tun, wenn du es einarbeitest

1. Dein Dokument kommt ins Projekt, `CLAUDE.md` wird darauf reduziert oder ersetzt.
2. Jede tragende Regel bekommt eine Stufe (`B-1`) und, wo möglich, ihren Wächter (`B-2`).
3. Ich baue `G-11` zuerst, dann `G-1` bis `G-3` auf dem vorhandenen `superseded-check`.
4. Der Paketschnitt wird gegen den vorhandenen Code geprüft, **nachdem** `E-1` entschieden ist — ich
   sage dir dann, wo er nicht passt, ohne ihn zu ändern.

---

## Zusammenfassung nach deinem §24.7

```text
ERGEBNIS
Dein Dokument wird der Rahmen. Es ist an vier Stellen besser als mein Vorschlag
und korrigiert in §19 einen Fehler von mir.

GEÄNDERT
Nichts. Dies ist eine Reaktion, keine Umsetzung.

NEUE ERKENNTNISSE
Sechs Bloecke stehen woertlich zweimal im Dokument; §24 wiederholt §§6,9,10,13.
Das Wort "Paket" ist im Projekt schon anders belegt.
In der Paketnummerierung steckt eine unentschiedene Architekturfrage.

VORSCHLAEGE ZUR BESTAETIGUNG
B-1  drei Stufen: bewacht / sichtbar / Konvention
B-2  zwoelf Waechter, G-11 zuerst
C-1  statuslos heisst nicht ACTIVE
C-2  "Paket" entdoppeln
C-3  sechs Wiederholungen zusammenfuehren
C-4  Confidence-Zahl streichen, Statusliste behalten
D    Herleitung gehoert nach history.md

OFFENE FRAGEN
E-1  Database: Schicht unter dem Core oder Adapter dahinter?
E-2  Wer setzt den Status in die 562 bestehenden Entscheidungen?
E-3  Wie heissen die alten sieben Pakete kuenftig?

NAECHSTER SCHRITT
Du arbeitest ein. Danach baue ich G-11, dann G-1 bis G-3.
Der Paketschnitt wird erst nach E-1 gegen den Code geprueft.
```
