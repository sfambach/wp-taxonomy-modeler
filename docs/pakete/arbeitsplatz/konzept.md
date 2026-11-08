# Paket · Arbeitsplatz — Konzeptentwurf

**Stand 2026-11-08. Entwurf, nichts entschieden, nichts gebaut** ([INF-114](../../neues-konzept-eingang.md#inf-114--standort-beim-einloggen-und-drucker-je-standort-2026-11-08)).
Alles ist mein Vorschlag (`INFERRED`), bis es eine `D-<nnn>` hat (`PR-3`).

**Seine Worte (2026-11-08):**

> *«vermerke, ob ich mich oben oder unten befinde beim Einloggen. Sodass ich in meinem persönlichen Einstellungen zuweisen kann, welche Drucker oben gültig sind und welche»* [unten]

> *«Ich hatte noch die Idee, dass man beim Login angeben kann, an welchen Arbeitsplatz man sich befindet. Und den Arbeitsplatz wiederum könnte man dann verschiedene Drucker Konfigurationen zuweisen … dass wenn ich mich hier oben im OG am Arbeitsplatz oder im Erdgeschoss am Arbeitsplatz anmelde, dass ich dann auch nur bestimmte Drucker zur Verfügung habe, nicht die im Keller.»*

> *«ein Wechsel des Arbeitsplatzes muss natürlich möglich sein. Vielleicht ist es dann eine Einstellung … unter Benutzer. Dass man da hinterlegt, … an welchen Arbeitsplatz ich mich gerade» — «finden.»*

## Bild

```mermaid
flowchart LR
  B[Benutzer] -->|«Aktueller Arbeitsplatz» · Benutzereinstellung D-1232| A[Arbeitsplatz · Satz im Modell]
  A -->|Drucker 0..*| D[Drucker · bestehender Knoten D-1190]
  D -->|aktiv D-1231 · passende Rolle D-1192| W[Druckdialog: nur diese Drucker]
```

Der Druckdialog zeigt nur die Drucker des Arbeitsplatzes, an dem man gerade ist. Welche Drucker zu welchem Arbeitsplatz gehören, steht einmal am Arbeitsplatz, nicht bei jedem Benutzer.

## Wo was liegt

| Was | Wo | Warum |
|---|---|---|
| **Arbeitsplatz** | Satz unter einem neuen Knoten «Arbeitsplätze» (neben «Drucker» unter Druck): Name, Drucker (Satzverweis → Drucker, 0..*), Standarddrucker (0..1, einer der Drucker), Lagerort (0..1, siehe A3) | Daten, nicht Code: anlegen, umbenennen, rückgängig wie jeder Satz. Gilt für alle Benutzer, die dort sitzen |
| **Drucker** | bleibt, wie er ist (Drucker-Thread, D-1190/D-1191/D-1231) | kein Druckerfeld wird doppelt angelegt; der Arbeitsplatz verweist nur |
| **Aktueller Arbeitsplatz** | Benutzereinstellung (D-1232), gespeichert beim Benutzer | jeder sitzt woanders; wechselt man, ändert sich nur der eigene Wert |

## Wählen und wechseln

1. **Benutzereinstellungen:** Auswahl «Aktueller Arbeitsplatz» (Logistik › Einstellungen › Benutzereinstellungen).
2. **Kopfzeile:** der aktuelle Arbeitsplatz steht oben in Logistik und Modeller; ein Klick öffnet die Auswahl. Wechsel ohne Umweg über die Einstellungen.
3. **Beim Anmelden:** gibt es mehr als einen Arbeitsplatz, fragt eine Seite direkt nach der Anmeldung «Wo bist du?» mit dem letzten Wert vorgewählt. Abschaltbar in den Benutzereinstellungen («Beim Anmelden fragen»).

## Wirkung im Druckdialog

| Fall | Angebotene Drucker |
|---|---|
| Arbeitsplatz gewählt | aktiv ∧ dem Arbeitsplatz zugeordnet ∧ passende Rolle; vorgewählt der Standarddrucker des Arbeitsplatzes |
| kein Arbeitsplatz gewählt oder keiner angelegt | alle aktiven mit passender Rolle (wie heute) |
| Arbeitsplatz ohne passenden Drucker | Hinweis «An diesem Arbeitsplatz druckt kein Drucker dieses Etikett» + Link zum Wechseln |

Eingehängt an der einen Stelle, die heute die Drucker sammelt (Lager `LabelTemplates::printers`, Modeller `LabelButton`), als Filter, nicht als zweite Liste.

## Schritte

1. Knoten «Arbeitsplätze» per Modelländerung; Datensätze OG, EG, Keller mit ihren Druckern.
2. Benutzereinstellung «Aktueller Arbeitsplatz» + Filter im Druckdialog.
3. Kopfzeile mit Wechsel; danach Abfrage beim Anmelden.

## Angenommen / offen (an Stefan)

- **A1** Arbeitsplätze sind gemeinsame Sätze, nicht persönliche Listen je Benutzer. Dein erster Wunsch nannte «in meinen persönlichen Einstellungen zuweisen»; ich schlage die Zuordnung am Arbeitsplatz vor, damit sie nicht je Benutzer doppelt gepflegt wird.
- **A2** Der Standarddrucker hängt am Arbeitsplatz statt am Benutzer (ersetzt den Vorschlag G2 aus dem Benutzer-Entwurf INF-112).
- **A3** Ein Arbeitsplatz darf einen Lagerort nennen. Dann könnte z. B. der Wareneingang diesen Ort vorschlagen. Nur vorgesehen, nicht Teil der ersten Schritte.
- **A4** Der Arbeitsplatz gilt für den Benutzer, nicht für das Gerät. Wer gleichzeitig oben am PC und unten am PDA angemeldet ist, hat überall denselben Arbeitsplatz. Alternative: je Gerät (Cookie), dann kennt jedes Gerät seinen Platz von selbst.
