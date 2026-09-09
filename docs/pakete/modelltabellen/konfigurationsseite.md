# Die Konfigurationsseite — was beschlossen ist, was dasteht, was fehlt

**Sein Auftrag, 2026-09-09:** *«wir hatten öffters gesagt wenn etwas ins admin menü muss
(settings/installation) allerdings fehlen hier die meisten einträge durchsuche das projekt und
erstelle eine implementierungsliste nur für das installatoins menü, ich würd es lieber in
configutation umbennen.»*

⚠️ **Diese Datei ändert nichts.** *Sie ist die Messung vor dem Bauen — jede Zeile nennt den
Beschluss, der sie verlangt, und den heutigen Zustand als eigene Angabe (`PR-10`).*

---

## 1 · Was der Bildschirm heute zeigt

**Vier Zeilen, gemessen an `SettingsScreen::render()`:**

| Zeile | Ablage | Beschluss |
|---|---|---|
| Icon size | Option `taxmod_icon_size` | [D-397](../../NewConcept/90-decision-log.md) |
| Text size | Option `taxmod_font_size` | [D-397](../../NewConcept/90-decision-log.md) |
| Developer mode | Option `taxmod_developer` | [D-389](../../NewConcept/90-decision-log.md) |
| Default language | Option `taxmod_neutral_locale` | [D-387](../../NewConcept/90-decision-log.md) |

**Im Menü stehen drei Seiten:** `Taxonomy Modeller`, `Installation`, `Cleanup`.

---

## 2 · Der Name

**Er will `Configuration` statt `Installation`.** *Der heutige Name ist an drei Stellen sichtbar: der
Menüeintrag, die Überschrift «Taxonomy Modeller — installation», und der Seitenschlüssel
`taxmod-settings`.*

⚠️ **Der Seitenschlüssel ist eine Adresse, kein Name.** *Ein Lesezeichen auf `page=taxmod-settings`
bricht, wenn er sich ändert — und die Seite heisst ohnehin nicht so. **Umbenennen heisst hier: den
sichtbaren Text ändern und den Schlüssel lassen**, oder den Schlüssel mitziehen und eine
Weiterleitung stehenlassen. Das ist eine eigene Entscheidung.*

⚠️ *Und «Installation» ist im Modell ein **zweites** Ding: die `installationId()`, eine reservierte
Identität ([D-079](../../NewConcept/90-decision-log.md)). **Der Name der Seite mit ihr gleichzusetzen
war von Anfang an schief** — «Konfiguration» trennt die beiden, was für sich schon ein Grund ist.*

---

## 3 · Der Blocker, und er steht über allem anderen

**[D-079](../../NewConcept/90-decision-log.md) und [D-404](../../NewConcept/90-decision-log.md)
legen die installationsweiten Vorgaben auf die *Installationsidentität* — das erste Glied der
Auflösungskette.** [D-404](../../NewConcept/90-decision-log.md) wörtlich: *«A setting key's own
default is a setting written at the installation identity.»*

⚠️ **Dieses Glied gibt es nicht mehr.** [D-602](../../NewConcept/90-decision-log.md): *«Die
Installationsstufe kommt **nicht** zurück»* — die Zeile, die sie trug, ist mit
[D-579](../../NewConcept/90-decision-log.md) weggefallen.

**Gemessen am 2026-09-09:**

```text
taxmod_installation_id = 641
als Knoten vorhanden   = nein
Datensätze daran       = 0
```

**Damit hat alles, was «eine Vorgabe der Installation» sein soll, heute keinen Ort.** Die vier Zeilen
oben liegen als WordPress-Optionen da, was [D-079](../../NewConcept/90-decision-log.md) nur für
*«genuinely WordPress-shaped facts»* erlaubt — und für die neutrale Sprache ausdrücklich **nicht**
([Zeile 14 der Arbeitsliste](../../NewConcept/97-implementation-plan.md): *«the neutral locale does
not [pass that test] — it decides which label row means valid everywhere, which the **core** needs,
and `CD-1` forbids the core to read an option»*).

⚠️ **Zu entscheiden, bevor gebaut wird:** *bekommt die Installationsidentität einen echten Knoten und
damit ihre Stufe in der Kette zurück, oder wird [D-079](../../NewConcept/90-decision-log.md)
zurückgenommen und alles bleibt eine Option? **Beides ist vertretbar; nur der jetzige Zustand nicht**
— ein Beschluss, dessen Mechanismus abgeschafft ist.*

---

## 4 · Was auf die Seite gehört und fehlt

**Jede Zeile: der Beschluss, was er verlangt, und was heute dasteht.**

| # | Was | Beschluss | Heute |
|---|---|---|---|
| 1 | **Vorgabewert je Einstellungsschlüssel** — «was gilt, wenn niemand etwas gesagt hat» | [D-404](../../NewConcept/90-decision-log.md) | **fehlt.** Die acht verstreuten `?? true` / `?? false` stehen weiter im Kode; der Ort dafür ist mit der Installationsstufe gefallen |
| 2 | **Backup-Pflicht vor einem Release-Update**, mit erzwungenem Herunterladen | [D-475](../../NewConcept/90-decision-log.md) | **fehlt ganz.** Kein Knopf, kein Zwang, kein Hinweis |
| 3 | **Update-Log** — eine Zeile je Installation und je Folge-Update, mit Version und Verweis | [D-476](../../NewConcept/90-decision-log.md) | **fehlt ganz** |
| 4 | **Schema-Fassung sichtbar** — welche Fassung liegt, welche erwartet der Kode | `CD-6` (die Fassung ist der Wächter des Aufstiegs) | **fehlt.** Sie steht nur in einer Option |
| 5 | **Die neutrale Sprache zieht von der Option ins Modell** | [Arbeitsliste 14](../../NewConcept/97-implementation-plan.md), begründet in [D-397](../../NewConcept/90-decision-log.md) | **offen.** Sie ist heute `taxmod_neutral_locale`, und der Kern bekommt sie durch eine Naht gereicht |
| 6 | **Die doppelte Entwickleroption fällt** | seine Bestätigung 2026-09-09 | **gemessener Rest, und er ist bestätigt.** `taxmod_developer` **und** `taxmod_developer_mode` stehen beide in der Datenbank; gelesen wird nur die erste, die zweite steht auf `0` und täuscht jeden, der nachsieht. **Sein Wort auf die Frage: «zu 6 ist beides das gleiche»** — also ist es kein Zweifelsfall, sondern eine Zeile zum Wegräumen. *Keine Entscheidung nötig, kein Kode betroffen: `SeedImage` kennt beide Namen schon als «Bildschirmeinstellung eines Betreibers».* |
| 7 | **Der Einstellungssatz im Datensatzblock, am Entwicklerschalter** | `TASK-069`, sein Wort 2026-09-07 | **offen.** Der Schalter ist da, die Zeile nicht |
| 8 | **Die gesäten Gerüste** — welche liefen, in welcher Fassung | [D-119](../../NewConcept/90-decision-log.md) (nach dem Import ist es gewöhnlicher Inhalt) | **fehlt.** Vier Optionen (`taxmod_base_scaffold`, `taxmod_composition_scaffold`, `taxmod_rendering_scaffold`, `taxmod_unit_scaffold`) sagen es, keine Seite zeigt es |
| 9 | **Die Rahmen-Ids** — Wurzel, Papierkorb, die sechs Äste, die Rollen | [D-510](../../NewConcept/90-decision-log.md) (Bindung über die Id) | **fehlt.** 21 Optionen, nur über die Datenbank lesbar; `node-binding-check` prüft sie, ein Mensch sieht sie nicht |
| 10 | **Die Icon-Liste kuratieren** — welche Zeichen darf ein Knoten bekommen | [D-251](../../NewConcept/90-decision-log.md), sein Auftrag 2026-09-09 | **fehlt, und der Kode sagt es selbst.** Die 39 Dashicon-Schlüssel stehen als `NodesScreen::ICONS` fest, mit dem Satz daneben: *«**Curating it in Settings** (unchecking an icon hides it from pickers) **is the next step**, not this one.»* |
| 11 | **Die Zeichen der Bedienelemente** — welcher Akt trägt welches Zeichen | [D-368](../../NewConcept/90-decision-log.md) | **fehlt.** Vier Schlüssel stehen fest im Kode: `trash`, `move`, `networking`, `editor-help`. *Ob sie überhaupt einstellbar sein sollen, ist eine eigene Frage — siehe unten.* |
| 12 | **Der Entwicklermodus, einzeln schaltbar** — jede Diagnose ein eigener Haken | sein Auftrag 2026-09-09, gegen [D-248](../../NewConcept/90-decision-log.md) | **fehlt, und es ist eine Kehrtwende.** Heute ist es **ein** Haken; was er schaltet, steht unten. |

---

---

## 4a · Der Entwicklermodus — was er heute schaltet

**Sein Auftrag, 2026-09-09:** *«Welche einstellungen gibt es aktuell für den developer mode? wir
sollten diese einzeln unter schaltbar machen. wenn developer mode aktiv ist»*

**Gemessen: ein Haken, und er schaltet genau zwei Anzeigen.** *`taxmod_developer` wird an einer
Stelle gelesen und als Umstand — nicht als Einstellung — in den Render-Kontext gereicht
([D-389](../../NewConcept/90-decision-log.md)).*

| # | Was der Haken heute zeigt | Wo |
|---|---|---|
| a | **Der Schreibzähler** neben dem Knotennamen im Baum | `TreeNodeRenderer` |
| b | **Die Renderer-Diagnose** unter einem Datensatz — *wer hat was gezeichnet* | `RecordRenderer` |
| c | **Der Einstellungssatz im Datensatzblock** — `TASK-069`, sein Wort 2026-09-07 | **noch nicht gebaut**, aber schon diesem Haken zugesagt |
| d | **`show the root`** — der Ansichtsschalter im Baum, sein Nachtrag 2026-09-09 | `NodesScreen`, heute **ohne Bedingung** sichtbar |

⚠️ **Sein Wunsch kehrt einen Beschluss um, und das gehört gesagt.**
[D-248](../../NewConcept/90-decision-log.md) hat den Testmodus im Entwicklermodus aufgehen lassen,
mit dem Grund: *«Two modes that overlap are two things to explain and two ways to be in a surprising
state.»* Der Kode zitiert das an Ort und Stelle: *«[D-248] says there is **one** mode for that rather
than a switch per diagnostic.»*

⚠️ *Sein Auftrag hebt das aber **nicht** auf, sondern schneidet es anders zu: **der Modus bleibt
einer** — die Einzelhaken sind nur **innerhalb** des Modus sichtbar und wirksam («wenn developer mode
aktiv ist»). Damit gibt es weiterhin **einen** Zustand, in dem man überrascht werden kann, und
darunter eine Auswahl, was man sehen mag. **Das ist die Verfeinerung von [D-248](../../NewConcept/90-decision-log.md),
nicht sein Widerruf** — aber es ist ein Beschluss und braucht seine eigene `D-<nnn>`, bevor gebaut
wird (`PR-3`).*

⚠️ **Und ein Ort ist zu wählen.** *Drei Haken sind drei Tatsachen über die Installation, also
dieselbe Frage wie in Abschnitt 3: drei weitere WordPress-Optionen, oder eine Zeile am Ort, den es
noch nicht gibt. **Solange Abschnitt 3 offen ist, wären es Optionen** — und das ist genau die
Sorte Entscheidung, die man einmal trifft und nicht dreimal.*

## 5 · Was ausdrücklich **nicht** dorthin gehört

⚠️ *Damit die Liste nicht wächst, bis sie alles enthält.*

**Alles, was je Knoten verschieden sein darf.** [D-397](../../NewConcept/90-decision-log.md) nennt
den Grund und das Beispiel: *«A setting **resolves along the chain**, so anything put there *can
differ per node* — and *developer mode, but only under `Compositions`* is not a thing. **A fact about
the installation has to live where it cannot vary.**»*

**Und keine Tatsache über einen Typ.** [D-404](../../NewConcept/90-decision-log.md): *«`int`'s
`step = 1` belongs on `int`, because it is true of `int` and not of the installation. **The test is
whose fact it is.**»* — *am 2026-09-09 gebaut und genau so abgelegt: `integer_step` hängt an
`Integer`, nicht an der Installation.*

**Und die Zeichen der Bedienelemente vermutlich auch nicht.**
[D-368](../../NewConcept/90-decision-log.md) trennt zwei Dinge, die beide *Icon* hiessen: *«[D-251]s
**node icon** ist **Daten**: eine Einstellung am Knoten, aufgelöst über die Kette, ein Zeichen aus
der Liste der Installation, von einem Modellautor gewählt. **A button glyph is interface**: code, at
the boundary, chosen by nobody using the product.»* — *Zeile 10 der Liste oben (die Icon-Liste) ist
also Konfiguration; **Zeile 11 ist es nach diesem Beschluss nicht**, und steht dort nur, damit die
Frage einmal gestellt wird statt zweimal.*
**Die Aufräumseite bleibt, wo sie ist.** [D-479](../../NewConcept/90-decision-log.md) hat sie als
**dritte Untermenüseite** gebaut, mit vier Quellen ([D-494](../../NewConcept/90-decision-log.md)).
*Sie ist Bedienung, keine Konfiguration.*

---

## 6 · Reihenfolge, wenn gebaut wird

**Zuerst die Entscheidung aus Abschnitt 3** — ohne sie haben die Zeilen 1 und 5 keinen Ort, und alles
andere wäre auf Sand gebaut.

**Dann die vier, die nichts voraussetzen:** die doppelte Option (6), die Schema-Fassung (4), die
Gerüste (8) und die Rahmen-Ids (9). *Drei Anzeigen und ein Wegräumen — kein neuer Mechanismus.*

⚠️ **(6) kann jederzeit fallen und wartet auf nichts.** *Er hat bestätigt, dass beide Optionen
dasselbe meinen; die ungelesene verschwindet, und `node-binding-check` merkt es nicht einmal, weil
sie keine Knoten-Id trägt.*

**Dann Backup und Update-Log (2, 3)** als ein Stück, weil
[D-476](../../NewConcept/90-decision-log.md) den Rückbau ausdrücklich aus dem Backup holt und nicht
aus einem Rückwärtslauf. *Beide gelten für ein Release, nicht für die jetzige Arbeit — sein eigener
Zuschnitt in [D-475](../../NewConcept/90-decision-log.md).*

**Zuletzt (7)**, weil es an der Knotenseite hängt und nicht an dieser.

**Die Icon-Liste (10) hängt an nichts** und kann jederzeit fallen: die 39 Schlüssel stehen schon,
es kommt nur ein Haken je Zeile dazu, und der Kode nennt genau das als nächsten Schritt.

**Die Einzelhaken des Entwicklermodus (12) kommen nach [TASK-069](../../NewConcept/97-implementation-plan.md)**,
nicht davor — sonst wird zweimal an derselben Stelle gebaut. *Und nicht, bevor die Verfeinerung von
[D-248](../../NewConcept/90-decision-log.md) als eigener Beschluss steht.*

⚠️ **Die Umbenennung geht zu jedem Zeitpunkt** und hängt an nichts davon — nur die Frage aus
Abschnitt 2 ist zu beantworten: Schlüssel mitziehen oder nicht.
