# Event-System – Konzeptbeschreibung

## 1. Ziel

Das Event-System soll einen einheitlichen, frameworkunabhängigen Mechanismus bereitstellen, mit dem Ereignisse innerhalb des Systems beschrieben, verteilt und verarbeitet werden können.

Die Grundlage bildet **PSR-14 – Event Dispatcher**. Das bestehende PSR-14-Modell soll nicht durch eine eigene Event-Architektur ersetzt, sondern um die projektspezifischen Anforderungen ergänzt werden.

Dieses Konzept dient zunächst als **Soll-Vorgabe für eine Analyse des bestehenden Projekts**.

Claude soll das bestehende Projekt anhand dieses Konzepts untersuchen und anschließend feststellen:

- welche Event-Mechanismen bereits vorhanden sind,
- welche Ereignisse bereits explizit oder implizit existieren,
- welche Listener vorhanden sind,
- welche konkreten Eventtypen benötigt werden,
- wo die bestehende Implementierung vom Konzept abweicht,
- welche Änderungen für eine Umsetzung erforderlich sind.

**Die konkreten Eventtypen sollen nicht vorab vollständig festgelegt werden.** Sie sollen aus dem bestehenden Projekt und seinen tatsächlichen Anforderungen abgeleitet werden.

## 2. Grundlage: PSR-14

Das Event-System orientiert sich am grundlegenden Modell von PSR-14:

```text
Event
  ↓
Dispatcher
  ↓
Listener Provider
  ↓
Listener
```

Der Event-Erzeuger übergibt ein Event an den Dispatcher. Der Dispatcher ermittelt über den Listener Provider die für dieses Event zuständigen Listener und führt diese aus.

Damit werden die Verantwortlichkeiten getrennt:

- **Event:** beschreibt, was passiert ist.
- **Dispatcher:** verteilt das Event.
- **Listener Provider:** bestimmt, welche Listener für das Event zuständig sind.
- **Listener:** verarbeitet das Event.

Diese Grundstruktur soll möglichst unverändert übernommen werden.

PSR-14 definiert damit die grundlegende Event-Infrastruktur. Die nachfolgenden Punkte sind projektspezifische Ergänzungen.

## 3. Was ist ein Event?

Ein Event beschreibt ein **eingetretenes Ereignis innerhalb des Systems**.

Ein Event beschreibt dabei den **Auslöser und die dafür relevanten Informationen**, nicht die daraus folgende fachliche oder technische Verarbeitung.

Ein Event kann beispielsweise durch eine Benutzerinteraktion entstehen:

```text
Benutzer
   ↓
GUI-Interaktion
   ↓
Event
```

Häufige Auslöser sind:

- Änderung eines Feldes,
- Änderung einer Einstellung,
- Betätigung eines Buttons,
- Änderung eines Nodes,
- Änderung eines Node Records,
- Änderung einer Edge,
- Änderung eines Edge Records.

Events sind jedoch **nicht auf die GUI beschränkt**. Auch interne Systemvorgänge können Events erzeugen, wenn andere Komponenten darüber informiert werden oder darauf reagieren sollen.

Die GUI ist somit ein häufiger Erzeuger von Events, aber keine Voraussetzung für ein Event.

## 4. GUI-Interaktionen als Event-Erzeuger

Ein wichtiger Anwendungsfall sind GUI-Interaktionen.

Beispielsweise:

```text
[ Add Node ]
      ↓
ButtonPressEvent
```

Das Event enthält die Information darüber, **welcher Button bzw. welche UI-Aktion ausgelöst wurde**.

Es sagt jedoch nicht:

> „Lege einen Node an.“

Stattdessen entscheidet ein Listener, was daraus folgt:

```text
ButtonPressEvent
       │
       ▼
   Listener
       │
       └── erkennt "add-node"
               ↓
           Node anlegen
```

Entsprechend:

```text
[ Delete Node ]
       ↓
ButtonPressEvent
       ↓
Listener
       ↓
Node löschen
```

oder:

```text
[ Save ]
   ↓
ButtonPressEvent
   ↓
SaveListener
   ↓
Änderungen speichern
```

Das bedeutet:

> **Das Event beschreibt den Auslöser. Der Listener entscheidet über die Reaktion.**

Dadurch wird vermieden, dass das Event selbst fachliche oder technische Aktionen vorgibt.

Ein `Add Node`-Button erzeugt deshalb nicht automatisch einen speziellen `NodeCreateEvent`. Ob ein solcher Eventtyp im konkreten System sinnvoll ist, soll anhand des bestehenden Projekts entschieden werden.

## 5. Modelländerungen als Events

Auch Modelländerungen können Events erzeugen.

Beispielsweise:

```text
Benutzer ändert Feld
       ↓
Modelländerung
       ↓
NodeRecordChangeEvent
       ↓
Dispatcher
       ↓
passende Listener
```

Als Event-Familie sind beispielsweise denkbar:

```text
Change
├── NodeChange
├── NodeRecordChange
├── EdgeChange
└── EdgeRecordChange
```

Diese Aufteilung ist zunächst ein **konzeptionelles Beispiel**.

Die tatsächlich erforderlichen Eventtypen sind anhand des bestehenden Projekts zu ermitteln.

## 6. Inhalt eines Events

Ein Event soll alle Informationen enthalten, die für seine Verarbeitung erforderlich sind.

Ein Listener soll nicht gezwungen sein, die Daten zunächst aus der Datenbank zu laden, nur um herauszufinden, was passiert ist.

Beispiel eines `NodeRecordChange`:

```text
Event-ID: E4711
Event-Typ: NodeRecordChange

Node-ID: 23
Node-Version: 7

Node-Record-ID: 184
Node-Record-Version: 12

Payload:
    Vorname = "Peter"
```

Der Payload enthält grundsätzlich die **relevanten geänderten Daten** und nicht zwingend den vollständigen Datensatz.

Wenn nur ein Feld geändert wurde, genügt entsprechend dieses Feld.

## 7. Versionen

Wenn ein Event sich auf eine konkrete Modell- oder Record-Version bezieht, soll diese Information Bestandteil des Event-Kontexts sein.

Beispielsweise:

```text
Node-ID: 23
Node-Version: 7

Record-ID: 184
Record-Version: 12
```

Die Prüfung von Versionen, Concurrency und Locking gehört dagegen **nicht zum Event-System**.

Diese Aufgaben liegen in der Datenhaltungs-/Persistenzschicht.

Das Event liefert lediglich den erforderlichen Kontext.

## 8. Event-ID und Identität

Jedes konkrete Event besitzt eine eigene Event-ID.

Die Event-ID beschreibt die **Identität eines konkreten Events**.

Beispiel:

```text
Event A
ID = E4711

Event B
ID = E4712
```

Damit sind A und B unterschiedliche konkrete Events.

Die Event-ID dient der eindeutigen Identifikation des konkreten Events.

## 9. Dispatcher und Listener

Die Verteilung der Events orientiert sich am PSR-14-Modell.

```text
Event
  ↓
Dispatcher
  ↓
Listener Provider
  ↓
passende Listener
```

Ein Listener wird für die Eventtypen registriert, die er verarbeiten kann.

Dadurch wird vermieden, dass jeder Listener zunächst ein generisches Event erhält und anschließend selbst feststellen muss:

> „Ist dieses Event überhaupt für mich relevant?“

Die Auswahl der zuständigen Listener erfolgt bereits über die Event-/Listener-Struktur.

## 10. Verarbeitung

Ein Event kann von mehreren Listenern verarbeitet werden.

Beispiel:

```text
NodeRecordChangeEvent
       │
       ├── Listener A
       ├── Listener B
       └── Listener C
```

Jeder Listener verarbeitet das Event unabhängig entsprechend seiner Aufgabe.

Ein Listener kann direkt interne Verarbeitung durchführen.

Beispielsweise:

```text
ButtonPressEvent
       ↓
SaveListener
       ↓
Modell speichern
```

Eine zusätzliche `SaveAction` ist dafür nicht erforderlich.

Die Verarbeitung eines Events kann optional im Event selbst dokumentiert werden. Ein Listener kann dazu sein Verarbeitungsergebnis am Event hinterlegen, beispielsweise:

```text
Event E4711
├── Listener A → SUCCESS
├── Listener B → SUCCESS
└── Listener C → FAILED
```

Diese Processing-Information ist **optional und konfigurierbar**. Sie kann insbesondere für Diagnose und Fehleranalyse genutzt werden. Im normalen Betrieb kann auf die detaillierte Aufzeichnung verzichtet werden.

## 11. Action ist kein Bestandteil des Event-Modells

Das Event-System benötigt keine eigene Action-Abstraktion.

Ein Event kann durch einen Listener beliebige weitere Verarbeitung auslösen. Dazu kann in einem anderen Teil des Systems gegebenenfalls auch ein Action-Mechanismus existieren.

Für das Event-Konzept gilt jedoch:

```text
Event ≠ Action
```

Eine Action ist kein notwendiger Bestandteil eines Events.

Das Event-System soll deshalb keine künstliche Action-Schicht zwischen Event und Listener einführen.

## 12. Fehlerbehandlung

Fehler bei der Verarbeitung eines Events sollen nicht grundsätzlich vom Event-System verschluckt werden.

Das grundlegende Verhalten orientiert sich dabei an PSR-14: Wirft ein Listener eine Exception, wird die weitere Verarbeitung gemäß PSR-14 unterbrochen und die Exception an den Event-Erzeuger weitergegeben.

Zusätzlich muss zwischen **technischer Fehlerbehandlung** und **Benutzerinformation** unterschieden werden.

Ein Fehler kann beispielsweise ausschließlich intern relevant sein:

```text
Event
 ↓
Listener
 ↓
Fehler
 ↓
Logging
```

Ist ein Fehler dagegen für den Benutzer relevant und kann dieser darauf reagieren, muss die Information an die übergeordnete Anwendung bzw. GUI weitergegeben werden können.

Beispiel:

```text
Feldänderung
    ↓
Event
    ↓
Verarbeitung
    ↓
Fehler
    ├── Logging
    │
    └── Fehlerinformation
             ↓
            GUI
```

Die GUI entscheidet anschließend selbst, wie der Fehler dargestellt wird:

```text
GUI
├── Feld markieren
├── Ausrufezeichen anzeigen
├── allgemeine Fehlermeldung
└── sonstige Darstellung
```

**Die konkrete Fehlerdarstellung gehört nicht zum Event-System.**

Sie ist Bestandteil eines übergeordneten Fehler-/GUI-Konzepts.

## 13. Kein automatisches Retry

Ein fehlgeschlagenes Event wird nicht automatisch erneut verarbeitet.

Es gibt keinen automatischen Retry-Mechanismus als Bestandteil des Event-Systems.

Ein späteres erneutes Ausführen eines fehlgeschlagenen Vorgangs kann bei Bedarf als separate Funktion eingeführt werden.

Dabei muss berücksichtigt werden, dass sich der Zustand des Systems inzwischen verändert haben kann.

## 14. Logging

Logging ist ein Bestandteil des Event-Systems, insbesondere zur Diagnose und Fehleranalyse.

Es soll möglich sein, die Menge der protokollierten Informationen über konfigurierbare Log-Level zu reduzieren.

Beispielsweise:

```text
DEBUG
INFO
WARNING
ERROR
CRITICAL
```

Im Entwicklungs- bzw. Debuggingbetrieb können detaillierte Informationen über die Eventverarbeitung protokolliert werden:

```text
Event E4711 erzeugt
Listener A gestartet
Listener A erfolgreich
Listener B gestartet
Listener B erfolgreich
Listener C gestartet
Listener C fehlgeschlagen
```

Im normalen Betrieb kann beispielsweise nur noch Folgendes protokolliert werden:

```text
ERROR
CRITICAL
```

Die detaillierte Processing-Information am Event und die ausführliche Log-Ausgabe sind damit zwei getrennte Diagnosemöglichkeiten. Beide sind optional und können über die Konfiguration reduziert bzw. deaktiviert werden.

## 15. Stoppable Events

PSR-14 bietet die Möglichkeit, die weitere Event-Propagation vorzeitig zu beenden.

Diese Möglichkeit soll im System **nicht als regulärer Mechanismus verwendet werden**.

Das vorzeitige Beenden kann dazu führen, dass andere registrierte Listener nicht mehr ausgeführt werden. Bei voneinander abhängigen Verarbeitungen können dadurch unerwünschte Zustände oder Inkonsistenzen entstehen.

Daher gilt:

> **Events sollen grundsätzlich an alle dafür registrierten Listener weitergegeben werden, sofern die Verarbeitung nicht durch einen Fehler oder einen ausdrücklich begründeten Stopp beendet wird. Stoppable Events sind nur in eindeutig begründeten Ausnahmefällen zu verwenden und müssen hinsichtlich ihrer möglichen Auswirkungen geprüft werden.**

Die PSR-14-Möglichkeit bleibt damit grundsätzlich kompatibel, wird aber nicht zu einem normalen Bestandteil der Event-Logik.

## 16. Konfiguration

Alle konfigurierbaren Einstellungen des Event-Systems werden auf der Konfigurationsseite unter der Überschrift **„Event“** zusammengefasst.

Welche konkreten Einstellungen tatsächlich erforderlich sind, soll anhand der bestehenden Implementierung und der späteren technischen Anforderungen ermittelt werden.

Dazu können insbesondere Logging und die optionale Aufzeichnung der Processing-Ergebnisse gehören.

## 17. Abgrenzung

Das Event-System ist bewusst von anderen Bereichen getrennt.

```text
GUI / Framework
    │
    │ Benutzerinteraktion
    ▼
Event-Erzeugung
    │
    ▼
Event-System
    │
    ├── Event
    ├── Dispatcher
    ├── Listener Provider
    ├── Listener
    └── Logging
    │
    ▼
Modell / Anwendung
    │
    ▼
Datenhaltung
```

Dabei gilt insbesondere:

**GUI**

entscheidet, wann eine Benutzerinteraktion als relevante Änderung an das Modell bzw. Event-System übergeben wird.

**Event-System**

beschreibt und verteilt Ereignisse und ermöglicht deren Verarbeitung.

**Fehlerkonzept**

bestimmt Bedeutung und Weitergabe von Fehlern.

**GUI**

entscheidet über die Darstellung von Benutzerfehlern.

**Datenhaltung**

verantwortet Persistenz, Versionierung, Concurrency und Locking.

Diese Verantwortlichkeiten sollen nicht vermischt werden.

## 18. Auftrag zur Analyse des bestehenden Projekts

Vor einer Implementierung soll das bestehende Projekt gegen dieses Konzept geprüft werden.

Die Analyse soll insbesondere folgende Fragen beantworten:

1. Welche Event-Mechanismen existieren bereits?
2. Welche Stellen erzeugen bereits implizit Ereignisse?
3. Welche bestehenden Events oder Event-ähnlichen Strukturen gibt es?
4. Welche Listener bzw. Callback-/Handler-Mechanismen existieren?
5. Welche konkreten Eventtypen werden tatsächlich benötigt?
6. Welche Informationen müssen die jeweiligen Events enthalten?
7. Wo existieren derzeit direkte Kopplungen, die durch Events ersetzt werden könnten?
8. Welche vorhandenen Mechanismen können weiterverwendet werden?
9. Wo weicht die bestehende Implementierung vom Konzept ab?
10. Welche Änderungen sind für eine Umsetzung erforderlich?

**Wichtig:** Die Analyse soll nicht davon ausgehen, dass die in diesem Dokument verwendeten Beispiele automatisch die endgültigen Eventtypen darstellen.

Beispiele wie

```text
ButtonPressEvent
NodeRecordChange
```

dienen der Erläuterung des Konzepts.

Die tatsächliche Eventstruktur soll aus dem bestehenden Projekt abgeleitet werden.

## 19. Vorgehen nach der Analyse

Erst nachdem die Ist/Soll-Analyse abgeschlossen ist, soll ein Implementierungs- bzw. Refactoringplan erstellt werden.

Dabei sollen insbesondere bestehende Funktionalitäten erhalten bleiben, sofern sie nicht ausdrücklich durch das neue Konzept ersetzt werden.

Die Reihenfolge ist daher:

```text
Konzept
   ↓
Ist-Analyse
   ↓
Soll-/Ist-Abgleich
   ↓
konkrete Eventtypen bestimmen
   ↓
Refactoring-Konzept
   ↓
Implementierung
   ↓
Tests
```

Das Event-Konzept selbst soll **keine unnötigen Implementierungsdetails vorwegnehmen**, die sich sinnvoller aus dem vorhandenen Projekt ableiten lassen.

## 20. Kurzfassung der Architektur

```text
                         EVENT
                           │
                           ▼
                      Dispatcher
                           │
                    Listener Provider
                           │
              ┌────────────┼────────────┐
              ▼            ▼            ▼
          Listener A   Listener B   Listener C
              │            │            │
              └────────────┴────────────┘
                           │
                       Verarbeitung
                           │
                           ▼
                        Logging
```

Zentrale Prinzipien:

> **Event beschreibt das Ereignis.**

> **Listener entscheidet über die Reaktion.**

> **Dispatcher verteilt das Event.**

> **Listener werden über den Listener Provider gezielt für Eventtypen ausgewählt.**

> **Processing-Ergebnisse können optional im Event dokumentiert werden.**

> **Logging dient der Diagnose und Fehleranalyse.**

> **GUI-Darstellung, Fehlerdarstellung und Datenhaltung bleiben außerhalb des Event-Kerns.**

> **PSR-14 bildet die Grundlage; projektspezifische Anforderungen werden darauf aufgesetzt.**
