# Semantische Paketarchitektur und Arbeitsregeln

**Version:** 1.2  
**Status:** Finaler Vorschlag / Arbeitsvertrag zwischen Benutzer und KI  
**Zweck:** Verbindliche Grundlage für die Entwicklung, Dokumentation und Zusammenarbeit zwischen Benutzer und KI.

**Vom Eigentümer geschrieben.** Ins Projekt übernommen am 2026-09-01. Zwei Änderungen gegenüber seiner Fassung, beide hier benannt statt eingearbeitet-und-verschwiegen: der Querverweis in §3.1 zeigte auf §13 und meint §6; und in den Ablauf von §5 ist der Schritt **Wächter, Leser, Daten** eingehängt, der vorher die eigene Regel `PR-12` war.

---

# 1. Zweck

Das Projekt wird in semantische Pakete gegliedert. Jedes Paket besitzt eine klar definierte Verantwortung, Schnittstellen und einen dokumentierten aktuellen Soll-Zustand.

Ziel ist:

- geringe Kopplung,
- klare Verantwortlichkeiten,
- Portierbarkeit,
- nachvollziehbare Änderungen,
- kontrollierte Zusammenarbeit zwischen Mensch und KI,
- und ein dauerhaft eindeutig erkennbarer aktueller Projektzustand.

Die Dokumentation soll nicht möglichst viel historische Information konservieren. Sie soll möglichst eindeutig beantworten:

```text
Was gilt jetzt?
Was ist offen?
Was ist falsch?
Was wurde entschieden?
Was wurde verworfen?
Was ist als Nächstes zu tun?
```

> **Die Dokumentation soll nicht erzählen, was wir einmal gedacht haben. Sie soll zeigen, was wir heute für richtig halten.**


# 2. Semantische Pakete

Ein semantisches Paket kapselt einen fachlich oder technisch zusammengehörigen Verantwortungsbereich.

Beispielhafte Pakete:

```text
Datenbank
    ↓
Persistenz-/Datenzugriff
    ↓
Domain/Core
    ↓
Renderer / UI-Abstraktion
    ↓
Plattformadapter
    ↓
WordPress / HTML / JavaScript
```

Diese Struktur ist ein Vorschlag und darf im Rahmen einer ausdrücklich bestätigten Konzeptänderung geändert werden.

Ein Paket darf mit anderen Paketen kommunizieren. Die Grenzen dienen der Kapselung, nicht der künstlichen Verhinderung notwendiger Kommunikation.

Jedes Paket soll nach Möglichkeit besitzen:

```text
package.md
tasks.md
bugs.md
inbox.md
history.md
```

Die konkrete Dateistruktur darf angepasst werden, sofern die semantischen Funktionen erhalten bleiben.


# 3. Aktueller Soll-Zustand

Das aktuelle Paketdokument beschreibt ausschließlich den gegenwärtig gültigen Soll-Zustand.

Historische, verworfene oder ersetzte Aussagen gehören nicht als aktive Vorgaben in das aktuelle Dokument.

Eine Aussage ist nicht allein deshalb gültig, weil sie:

- in einem alten Chat steht,
- in einer alten Entscheidung auftaucht,
- in einer historischen Dokumentversion steht,
- oder schon lange im Projekt existiert.

> **Alter ist kein Gültigkeitsstatus.**

## 3.1 Statuswerte

Für Aussagen, Entscheidungen und Vorschläge können insbesondere folgende Zustände verwendet werden:

```text
FACT
CONFIRMED
INFERRED
PROPOSED
OPEN
ACTIVE
REJECTED
SUPERSEDED
```

Bedeutung:

- `FACT` – überprüfbarer Sachverhalt.
- `CONFIRMED` – vom Benutzer bzw. verantwortlichen Projektbeteiligten bestätigt.
- `INFERRED` – aus vorhandenen Informationen hergeleitet, aber nicht bestätigt.
- `PROPOSED` – Vorschlag.
- `OPEN` – noch nicht entschieden bzw. geklärt.
- `ACTIVE` – aktuell gültige Entscheidung oder Vorgabe.
- `REJECTED` – bewusst verworfen.
- `SUPERSEDED` – durch eine spätere Entscheidung ersetzt.

> **Ein fehlender Status bedeutet nicht `ACTIVE`.**

Diese Regel gilt für den migrierten Bestand erst nach Abschluss der Migration; siehe §6.


# 4. Entscheidungen

Eine Entscheidung verändert den aktuellen Soll-Zustand.

Eine Entscheidung soll mindestens enthalten:

- Entscheidung,
- kurzen Grund,
- gegebenenfalls relevante Auswirkungen.

Ausführliche Diskussionen, Alternativen und Herleitungen gehören in die Historie.

Beispiel:

```text
ACTIVE

Entscheidung:
Der Core bleibt frameworkunabhängig.

Grund:
Das Projekt soll später in andere Frameworks oder
Programmiersprachen portierbar bleiben.
```

## 4.1 Keine versteckten Entscheidungen

Architekturentscheidungen dürfen nicht aus einer beiläufigen Formulierung oder einer bloßen Implementierung entstehen.

Insbesondere sind explizit zu kennzeichnen:

- neue Interfaces,
- neue Abhängigkeiten,
- neue Paketgrenzen,
- Änderungen bestehender Semantik,
- Änderungen des Datenmodells,
- Änderungen der Event-Semantik,
- Änderungen der Persistenz,
- Entfernung bestehender Funktionalität.

Eine KI darf einen Vorschlag machen, aber eine nicht bestätigte Entscheidung nicht als `ACTIVE` behandeln.


# 5. Konzeptänderungen

Eine Konzeptänderung ist **kein normaler Projektzustand**.

Sie tritt ein, wenn erkannt wurde, dass:

- eine bisherige Richtung falsch ist,
- eine bessere Architektur gefunden wurde,
- eine bisherige Grenze nicht mehr sinnvoll ist,
- oder eine bestehende Annahme geändert werden muss.

Eine Konzeptänderung ist ein kontrollierter Übergang von einem gültigen Soll-Zustand zu einem neuen Soll-Zustand.

Ablauf:

```text
Erkenntnis / Problem
        ↓
Analyse
        ↓
Vorschlag
        ↓
Gegenargumente / Auswirkungen
        ↓
Entscheidung
        ↓
Wächter zuerst, dann der Leser, dann die Daten
        ↓
aktuellen Soll-Zustand ändern
        ↓
alte Aussagen bereinigen
        ↓
betroffene Pakete prüfen
        ↓
Tests / Validierung
        ↓
neuer stabiler Soll-Zustand
```


Zur Reihenfolge **Wächter, Leser, Daten**: zieht ein Wert um, zieht der Leser zuerst, und es muss eine Prüfung geben, die rot wird, wenn nur einer von beiden umgezogen ist.

> **Eine Wanderung, bei der die Daten vorangehen, bleibt grün und zeigt still das Falsche.**

Dieser Schritt ersetzt die frühere eigene Regel `PR-12`. Er wurde an einem Abend sechsmal erkauft: sechs Leser blieben an der alten Tabelle hängen, der Bildschirm lief weiter, und keine von 305 Prüfungen bemerkte es.

Eine Konzeptänderung darf bestehende:

- Regeln,
- Entscheidungen,
- Paketgrenzen,
- Schnittstellen,
- Implementierungen

ändern oder ersetzen.

Alte Vorgaben dürfen nicht nur deshalb weiter gelten, weil sie früher beschlossen wurden.

Nach einer Konzeptänderung müssen betroffene aktuelle Dokumente bereinigt werden. Ersetzte Aussagen werden als `SUPERSEDED` oder `REJECTED` gekennzeichnet bzw. in die Historie überführt.



# 6. Migration

Eine Migration ist **kein normaler Projektzustand**.

Sie ist ein kontrollierter Übergang von einem bestehenden Systemzustand in einen neuen dokumentierten Zustand.

Das gilt insbesondere für die Einführung dieses Rulesets.

Der bestehende Bestand darf nicht blind nach den neuen Regeln interpretiert werden.

## 6.1 Übergang des Altbestands

Bis zur abgeschlossenen Klassifizierung gilt der bisherige Entscheidungs- und Regelbestand als:

```text
LEGACY
```

`LEGACY` bedeutet:

- historisch vorhanden,
- noch nicht vollständig klassifiziert,
- nicht automatisch `ACTIVE`,
- darf aber nicht ohne Prüfung als bedeutungslos behandelt werden.

Für den Übergang sind die tatsächlich weiterhin gültigen Grundlagen explizit zu identifizieren.

Erst nach ihrer Bestätigung werden sie als `ACTIVE` übernommen.

Nicht mehr gültige oder überholte Aussagen werden entsprechend als `SUPERSEDED`, `REJECTED` oder historisch klassifiziert.

## 6.2 Keine stillschweigende Migration

Eine KI darf aus der bloßen Existenz einer alten Aussage keine aktive Vorgabe erzeugen.

Ebenso darf die Einführung dieses Rulesets nicht dazu führen, dass bestehende Architektur- oder Sicherheitsgrundlagen unabsichtlich verschwinden.

## 6.3 Abschluss der Migration

Die Migration ist abgeschlossen, wenn für die relevanten normativen Altbestände geklärt ist:

```text
Was bleibt aktiv?
Was wurde ersetzt?
Was wurde verworfen?
Was ist noch offen?
Was ist reine Historie?
```

Danach gilt ausschließlich der neue Gültigkeitsmechanismus.


# 7. Parallele und asynchrone Arbeit

Während an einem Paket gearbeitet wird, können gleichzeitig neue Informationen eintreffen:

- Testergebnisse,
- Fehler,
- Ideen,
- Fragen,
- Widersprüche,
- Hinweise auf Auswirkungen in anderen Paketen.

Diese Informationen dürfen die laufende Arbeit nicht automatisch unterbrechen.

## 7.1 Inbox

Neue Informationen werden zunächst in `inbox.md` oder der entsprechenden Eingangsliste erfasst.

Beispiel:

```text
INF-001
Typ: BUG
Quelle: Test
Beschreibung: ...

INF-002
Typ: IDEA
Beschreibung: ...

INF-003
Typ: QUESTION
Beschreibung: ...
```

Die Inbox ist ein Eingangskanal und keine automatische Änderung des Soll-Zustands.

## 7.2 Verarbeitung

Eine Information wird später geprüft und einer geeigneten Kategorie zugeordnet:

```text
BUG
IDEA
QUESTION
TASK
PROPOSAL
DECISION
```

> **„Später bearbeiten“ bedeutet nicht „vergessen“.**

## 7.3 Unterbrechung

Die laufende Arbeit wird grundsätzlich fortgesetzt, außer:

- die neue Information macht die aktuelle Arbeit unmittelbar ungültig,
- ein schwerwiegender Fehler liegt vor,
- oder der Benutzer verlangt ausdrücklich eine Unterbrechung.

## 7.4 Konflikte

Widerspricht eine neue Information einer aktiven Entscheidung, darf diese Entscheidung nicht stillschweigend geändert werden.

Der Konflikt wird sichtbar gemacht und als offene Konzept- oder Entscheidungsfrage behandelt.


# 8. Kommunikation

Die Kommunikation zwischen Mensch und KI ist Teil der technischen Arbeitsweise.

Sprache kann mehrdeutig sein. Zusätzlich können Spracherkennung, fehlender Kontext oder ähnliche Begriffe zu Fehlinterpretationen führen.

> **Eine plausible Interpretation ist nicht automatisch eine richtige Interpretation.**

## 8.1 Gemeinsame Sprache

Das Projekt verwendet möglichst konsistente Fachbegriffe.

Bei möglichen Spracherkennungsfehlern oder mehreren plausiblen Bedeutungen darf die KI die wahrscheinlichste Interpretation nicht einfach als Tatsache behandeln.

## 8.2 Kontext

Bei Folgefragen soll die KI den vermuteten Bezug erkennen.

Ist der Bezug nicht eindeutig, soll sie ihn kurz benennen oder nachfragen.

Beispiel:

```text
BEZUG
Event-System / Renderer

ERGEBNIS
...
```

## 8.3 Nachfragen

> **Eine Rückfrage ist einer falschen Annahme vorzuziehen.**

Insbesondere bei:

- mehrdeutigen Anforderungen,
- widersprüchlichen Aussagen,
- unklaren Fachbegriffen,
- möglichen Spracherkennungsfehlern,
- Architekturentscheidungen,
- Änderungen bestehender Konzepte.

## 8.4 Selbstkorrekturen

Bei einer Selbstkorrektur des Benutzers gilt die zuletzt eindeutig formulierte Aussage.

Eine verworfene vorherige Formulierung darf nicht als eigenständige Anforderung oder Entscheidung weiterverwendet werden.

Wenn die Korrektur selbst nicht eindeutig ist, ist nachzufragen.

## 8.5 Ergebnisorientierte Ausgaben

Die Ausgabe soll kurz, prägnant und sachlich sein.

Kurz bedeutet nicht semantisch unvollständig. Zusammenhänge zwischen Ursache, Konsequenz und Entscheidung müssen erkennbar bleiben.

Bei umfangreicheren Arbeiten soll möglichst folgende Struktur verwendet werden:

```text
BEZUG
...

ERGEBNIS
...

GEÄNDERT
...

NEUE FEHLER
...

NEUE AUFGABEN
...

ENTSCHEIDUNGEN ZUR BESTÄTIGUNG
...

OFFENE FRAGEN
...

NÄCHSTER SCHRITT
...
```

## 8.6 Keine versteckten Entscheidungen

Entscheidungen dürfen nicht zwischen allgemeinen Erläuterungen versteckt werden.

## 8.7 Selbstkorrektur der KI

Wenn die KI erkennt, dass eine frühere Antwort auf einer falschen Interpretation beruhte, soll sie dies ausdrücklich benennen.

```text
KORREKTUR

Meine vorherige Antwort basierte auf der Annahme X.
Nach deiner Klarstellung ist Y gemeint.
Die vorherige Schlussfolgerung ist daher nicht mehr gültig.
```


# 9. Confidence und Erkenntnisstatus

Ein Sprachmodell soll nicht so behandelt werden, als könne es für eine Aussage eine objektive statistische Wahrscheinlichkeit angeben.

Ein numerischer Confidence-Wert ist daher optional und niemals ein Ersatz für Status, Begründung oder Bestätigung.

Wichtiger ist die Unterscheidung:

```text
ERKENNTNIS
„Im Code existieren aktuell drei Implementierungen.“

ANNAHME
„Vermutlich sollten diese drei Implementierungen zusammengeführt werden.“

VORSCHLAG
„Ich schlage vor, sie zusammenzuführen.“

ENTSCHEIDUNG
„Wir führen sie zusammen.“
```

Nur die bestätigte Entscheidung verändert den aktiven Soll-Zustand.


# 10. Renderer und Plattformgrenzen

Das Projekt soll plattformabhängige Logik aus dem Core fernhalten.

Die folgende Aussage ist **eine Arbeitshypothese und keine festgeschriebene Architekturentscheidung**:

```text
PROPOSED / INFERRED

Der Renderer könnte eine plattformunabhängige
UI-Beschreibung erzeugen, die anschließend durch
einen plattformabhängigen Adapter umgesetzt wird.
```

Beispielsweise könnte eine Plattformintegration diese Beschreibung in HTML, WordPress-Komponenten oder JavaScript-Verhalten übersetzen.

Die konkrete Renderer-Architektur bleibt offen, bis sie ausdrücklich bestätigt wurde.

> **Arbeitshypothesen dürfen nicht als Architekturdefinition ausgegeben werden.**


# 11. Wächter und überprüfbare Grenzen

Eine Architekturregel ist besonders wertvoll, wenn ihre Einhaltung überprüft werden kann.

> **Eine Grenze ohne Prüfung ist keine durchgesetzte Grenze, sondern eine Absicht.**

Das Projekt unterscheidet:

```text
BEWACHT
    technisch oder automatisiert überprüfbar

SICHTBAR
    durch Mensch oder KI nachvollziehbar prüfbar

KONVENTION
    Empfehlung / Good Practice ohne technische Durchsetzung
```

Nicht jede Regel benötigt einen Wächter.

## 11.1 Geeignete Wächter

Wächter können beispielsweise prüfen:

- unerlaubte Abhängigkeiten zwischen Paketen,
- unerlaubte Framework-Abhängigkeiten im Core,
- dokumentierte Schnittstellen gegen tatsächliche Implementierungen,
- dokumentierte Paketabhängigkeiten gegen tatsächliche Imports,
- andere klar maschinell überprüfbare Architekturgrenzen.

## 11.2 Keine Wächterinflation

Nicht jede Regel soll mit einem eigenen Wächter versehen werden.

> **Wenige starke Wächter sind besser als viele schwache Kontrollen.**


# 12. Regelhygiene

Das Projekt soll möglichst wenige, klare und dauerhaft nützliche Regeln besitzen.

> **Mehr Regeln bedeuten nicht automatisch mehr Kontrolle.**

Eine neue globale Regel soll nur aufgenommen werden, wenn sie einen wesentlichen dauerhaften Zweck erfüllt, insbesondere:

1. einen wiederkehrenden Fehler verhindert,
2. eine wichtige Architekturgrenze schützt,
3. einen dauerhaft gültigen Entwicklungsstandard beschreibt,
4. oder eine nachweislich problematische Arbeitsweise verhindert.

## 12.1 Regel oder Information?

```text
„Der Core darf keine WordPress-API verwenden.“
→ Architekturregel

„Klasse X verwendet aktuell Methode Y.“
→ Ist-Zustand

„Wir sollten Methode X vereinheitlichen.“
→ Vorschlag

„Methode X wird vereinheitlicht.“
→ Entscheidung
```

Implementierungsdetails sollen nicht unnötig zu globalen Regeln werden.

## 12.2 Alte Regeln

Eine Regel, die durch eine neue Entscheidung überholt wurde, wird:

- aktualisiert,
- entfernt,
- oder als `SUPERSEDED` historisiert.

## 12.3 Keine Regelakkumulation

Wenn eine neue Regel eine alte vollständig ersetzt, soll die alte nicht zusätzlich als aktive Regel erhalten bleiben.


# 13. Code- und Sicherheitsstandards

Das Prozessmodell dieses Dokuments ersetzt keine bestehenden technischen Sicherheitsstandards.

Insbesondere gelten bestehende, ausdrücklich als verbindlich bestätigte Sicherheitsregeln weiter, bis sie durch eine gleichwertige oder strengere bestätigte Regel ersetzt werden.

Dazu gehören insbesondere:

- Rechte prüfen,
- Nonce prüfen,
- Eingaben prüfen/säubern,
- Ausgaben escapen,
- ausschließlich vorbereitete Datenbankabfragen verwenden.

Eine allgemeine Prozessregel darf eine spezifischere Sicherheitsregel nicht abschwächen.

Ebenso darf eine allgemeine Formulierung wie „nach Möglichkeit testen“ keine strengere aktive Testregel ersetzen.

Technische Code-Standards (`CD`/`DC` oder vergleichbare Standards) bleiben als eigener Gegenstandsbereich erhalten und werden nicht stillschweigend in das Prozessmodell aufgelöst.


# 14. Tests und Commit

Spezifische aktive Testregeln haben Vorrang vor allgemeinen Formulierungen.

Wenn eine verbindliche Regel verlangt, dass vor einem Commit bestimmte Testläufe erfolgreich sein müssen, gilt diese Regel vollständig.

Insbesondere darf das Prozessmodell keine bestehende strengere Testvorgabe durch weichere Formulierungen abschwächen.

Bei einer Konzeptänderung ist zu prüfen, welche Tests und Validierungen durch die Änderung betroffen sind.


# 15. Zusammenarbeit mit KI

Die KI ist:

- Diskussionspartner,
- Analyst,
- Fehlerfinder,
- Vorschlagsgeber,
- Implementierungshilfe.

Sie soll:

- kritisch prüfen,
- widersprechen,
- Alternativen nennen,
- Konsequenzen aufzeigen,
- Widersprüche erkennen.

Sie soll jedoch keine nicht bestätigten Entscheidungen als beschlossen behandeln.

> **Widerspruch ist erwünscht. Blockade ist nicht erwünscht.**

Wenn der Benutzer nach Abwägung eine andere Richtung vorgibt, ist diese Entscheidung zu respektieren. Die KI soll anschließend die Konsequenzen benennen und bei der Umsetzung helfen.

Wenn eine wesentliche Unsicherheit besteht, ist eine Rückfrage einer selbstbewussten Fehlannahme vorzuziehen.


# 16. Wahrheitshierarchie

Bei widersprüchlichen Informationen gilt folgende Priorität:

```text
1. aktuelle bestätigte Entscheidung
2. aktueller Paket-Soll-Zustand
3. aktive globale Regel
4. bestätigte Fakten
5. aktuelle offene Punkte / Vorschläge
6. Historie
7. alte Chats / Erinnerungen
```

Historische Informationen dürfen zur Erklärung herangezogen werden, aber nicht ohne Prüfung den aktuellen Soll-Zustand bestimmen.

Bei einem erkannten Widerspruch ist der Konflikt sichtbar zu machen.

Eine alte Aussage darf nicht dadurch wieder gültig werden, dass sie ausführlicher dokumentiert ist als die neue Entscheidung.


# 17. Änderung des Rulesets

Dieses Ruleset selbst ist Teil des Projekts und unterliegt denselben Grundsätzen.

Es ist kein unveränderliches Gesetz.

Bei einer Änderung:

```text
Problem / Erkenntnis
        ↓
Vorschlag
        ↓
Auswirkungen prüfen
        ↓
Entscheidung
        ↓
Ruleset aktualisieren
        ↓
Widersprüche und veraltete Regeln entfernen
```

Eine neue Regel soll nicht einfach zusätzlich aufgenommen werden, wenn sie eine bestehende Regel ersetzt.

Ziel ist ausdrücklich:

> **Regelreduktion statt Regelakkumulation.**


# 18. Grundsätzlicher Arbeitsablauf

Für Änderungen am Projekt gilt grundsätzlich:

```text
Erkennen
   ↓
Verstehen
   ↓
Dokumentieren
   ↓
Bewerten
   ↓
Entscheiden
   ↓
aktuellen Soll-Zustand aktualisieren
   ↓
Implementieren
   ↓
Testen
   ↓
Dokumentation bereinigen
```

Nicht jede Beobachtung ist eine Entscheidung.

Nicht jede Idee ist eine Aufgabe.

Nicht jede Aufgabe ist eine Architekturänderung.

Diese Unterschiede sollen erhalten bleiben.


# 19. Übergeordnetes Prinzip

Das gesamte Arbeitsmodell folgt einem Grundsatz:

> **Plausible Annahmen dürfen nicht unbemerkt zu Projektwahrheit werden.**

Dafür stehen die verschiedenen Mechanismen:

```text
Mehrdeutigkeit
    → Rückfrage

Annahme
    → INFERRED

Vorschlag
    → PROPOSED

Bestätigte Entscheidung
    → ACTIVE

Verworfene Richtung
    → REJECTED

Ersetzte Entscheidung
    → SUPERSEDED

Neue Information während laufender Arbeit
    → INBOX

Kritische Architekturgrenze
    → WÄCHTER

Konzeptänderung
    → kontrollierter Übergang

Migration
    → kontrollierter Übergang
```

Das System soll nicht verhindern, dass sich das Projekt verändert.

Es soll verhindern, dass sich das Projekt **unbemerkt** verändert.


# 20. Abschluss

Dieses Dokument ist als Arbeitsvertrag zwischen Benutzer und KI gedacht.

Es definiert nicht jede technische Einzelheit des Projekts. Es definiert vielmehr die Regeln, nach denen technische Einzelheiten entwickelt, diskutiert, entschieden, geändert und dokumentiert werden.

Der Benutzer behält die Entscheidungshoheit über das Projekt.

Die KI hat ausdrücklich die Aufgabe, den Benutzer auf:

- Widersprüche,
- Risiken,
- Fehler,
- bessere Alternativen,
- unklare Anforderungen,
- und Auswirkungen von Änderungen

hinzuweisen.

Die KI darf widersprechen.

Die KI darf aber keine nicht bestätigte Interpretation als Projektentscheidung etablieren.

> **Ziel ist nicht, jede Diskussion zu vermeiden. Ziel ist, dass am Ende eindeutig erkennbar ist, was beschlossen wurde und was nicht.**

