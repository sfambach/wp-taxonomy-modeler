# Paket · Datenbank — Eingang

**Ein Eingangskanal, keine Änderung des Soll-Zustands**
([`arbeitsmodell.md`](../../arbeitsmodell.md) §7.1).

Was hier steht, ist erfasst und noch nicht eingeordnet. *«Später bearbeiten» bedeutet nicht
«vergessen».*

---

```text
INF-001
Typ: HYPOTHESE — vom Eigentümer
Beschreibung: Der geteilte Id-Raum könnte die Wurzel mehrerer Probleme sein.
```

*«Ich glaube ehrlich gesagt, dass viele Probleme daraus entstanden sind, dass wir einen gemeinsamen
Id-Bereich für Knoten und Kanten haben und daraus der Wunsch eines Pfades entstanden ist.»*

⚠️ **Die erste Hälfte ist mit TASK-004 beantwortet.** *Die zweite nicht:
[D-082](../../NewConcept/90-decision-log.md) nennt als Grund für `nodes.path` **nicht** den Id-Raum,
sondern die vorgerechnete Vorfahrensuche — «materialised ancestor path, derived and rebuildable».
**Beides kann nebeneinander wahr sein.***

```text
INF-002
Typ: FRAGE
Beschreibung: Alle sieben Fremdschlüssel zeigen heute auf identities.id.
```

Genau das trägt die Schattentabellen: **mehrere Zeilen mit derselben Id brechen keinen davon.**
Fällt `identities` (TASK-004), ist zu klären, worauf sie künftig zeigen — oder ob es sie noch gibt.

```text
INF-003
Typ: VORSCHLAG — offen, er denkt darüber nach
Beschreibung: Labels als Kanten-Datensätze statt eigener Tabelle.
```

**Sein Rahmen:** *«das Label ist nichts anderes als Multilang mit zusätzlichen Feldtypen.»*

**Der Vorschlag:** die fünf Rollen werden **Kanten**, einmal an der Wurzel deklariert und von allen
geerbt — `form`, `table`, `select`, `symbol`, `help`, je `0..*`. Ein Label ist dann **ein
Kanten-Datensatz** mit `locale` und Text. Keine `Label`-Knoten, keine eingebetteten Datensätze.

```text
Knoten-Datensatz  #10   node_id = Condensator · record_type = default
   #10 · form   · de_DE · "Kondensator"
   #10 · form   · en_US · "Capacitor"
   #10 · symbol · ""    · "C"
```

**Was wegfiele:** die Tabelle `labels`, **476 Zeilen** eigener Klassen (`Labels`, `LabelRepository`,
`WpdbLabelRepository`), zwei Wächter, ein zweiter Auflösungsweg und die toten Spalten `path` und
`number`.

**Was es kostet:** die Zeilenzahl bleibt gleich — *eine Zeile je geschriebenem Text, heute wie
morgen*. Dazu **bis zu 40 zusätzliche Knoten-Datensätze**, einer je Knoten mit Labels; die meisten
gibt es schon.

⚠️ **Der Punkt, an dem er abgebrochen hat, und er hat einen Fehler in meinem Vorschlag gefunden:**
*heute hängt ein Label am **Knoten**, in meinem Vorschlag an einem **Knoten-Datensatz** — also an
einer Ausprägung. Bei `Einheitenwert` mit 21 Benutzer-Datensätzen wären das 21 Gelegenheiten für
verschiedene Beschriftungen. **Unsinn.***

**Die Auflösung, die es braucht, und sie muss ausdrücklich dastehen:**

> **Ein Label steht immer am `default`-Datensatz des Knotens — nie an einem `user`-Datensatz.**

*Dort liegen heute schon die Einstellungen, und [D-026](../../NewConcept/90-decision-log.md) begründet
es: «at model level there are no values, only defaults».*

⚠️ **Offen:** *ob dieser Satz reicht, oder ob «Label am Knoten» und «Daten am Datensatz» zwei zu
verschiedene Dinge sind, um sich eine Tabelle zu teilen. **Darüber denkt er nach.***

⚠️ *Und eine zweite Folge, falls es kommt: `locale` müsste auf dem Kanten-Datensatz **bleiben** — wir
hatten sie gestrichen, weil sie leer war, und sie war leer, weil die Labels noch nicht dort lagen.
Der Schlüssel würde dann `(node_record_id, relation_id, sort_order, locale)`.*

## INF-004 · Der Einstellungsbaum zeigt nur Kanten — er denkt noch darüber nach

**Am 2026-09-04 entschieden: «erstmal alles zeigen».** *Also **beide** Zeilenarten — die Kante und
der Knoten dahinter —, nicht die auf Kanten verkürzte Fassung. Das ist ausdrücklich vorläufig:
«erstmal». **Der Grund, es nicht gleich zu verkürzen: die kurze Fassung nimmt eine Auskunft weg,
die man erst vermisst, wenn man sie braucht** — welcher Knoten hinter einer Kante steht. Zeigt
sich im Betrieb, dass die Knotenzeilen nichts beitragen, fallen sie später; umgekehrt wäre es
teurer.*

⚠️ *Der Stand, den er sich angesehen hat:*

**2026-09-02, unentschieden.** Sein Satz: *«die Frage die sich mir stellt, wir könnten auch nur
die Kanten nehmen, das würde glaube ich reichen»* — und danach: *«merk dir das mal, da muss ich
nochmal drüber nachdenken».*

**Der Stand, bis er entscheidet:**

- Die Einstellungsmaske eines Knotens zeigt **eine Wurzelzeile** (der Knoten selbst) und darunter
  **nur Kantenzeilen**, geschachtelt.
- Die Knotenzeilen entfallen in der Anzeige, weil jeder Knoten über genau **eine** Kante erreicht
  wird. Damit gibt es je Schritt nur eine Zeile und keine Vorrangfrage.
- **Leer heisst geerbt:** sagt die Kantenzeile nichts, gilt der Zielknoten, dann der Rückfall. Der
  geerbte Wert steht blass an derselben Stelle; Tippen macht blass zu schwarz.
- «Alle `Text` sollen so aussehen» sagt er dann **auf dem Knoten `Text`**, wo dieser die Wurzel ist.
- **In den Daten bleibt die Abwechslung Kante/Knoten bestehen** — sie trägt die Adresse. Nur das
  Bild lässt die Knotenzeilen weg.

⚠️ **Offen ausserdem:** *die Tiefe hat kein Ende, und ein Knoten, der auf sich selbst zeigt, lässt
den Baum ewig laufen. Beides ungeklärt.*

Gehört zu [D-583](../../NewConcept/90-decision-log.md), TASK-021 und TASK-022.

## INF-006 · Was der 2026-09-04 an der Oberfläche geändert hat

**Kein Auftrag, sondern der Stand** — damit der nächste Leser die Maske wiedererkennt.

| | |
|---|---|
| **Auswahldialog** | wählt und schliesst sich; der gewählte Knoten steht danach in einem gesperrten Feld der Zeile, der Anlegen-Knopf daneben ([D-589](../../NewConcept/90-decision-log.md)) |
| **Baumansicht** | Suchfeld oben, in **jedem** Baum. In der Seitenansicht sucht der Server, im Dialog filtert der Browser |
| **Auswahlbaum** | zeichnet dieselbe Zeile wie der Modellbaum, ohne die Funktionen rechts, mit Klapp-Pfeilen |
| **Feld anlegen** | ohne Namen zieht es den Knotennamen; nur der Einstiegsast ist offen |
| **Feld ändern** | «Points at» ist über einen Dialog änderbar, für eigene Felder |

⚠️ **Zwei Stellen brauchen jetzt Skript, die vorher keines brauchten:** *der Auswahldialog — Klappen,
Wählen, Schliessen — und der Schalter «Knotennamen benutzen». **Die Seitenansicht bleibt skriptfrei**,
und ohne Skript bleibt jede Wahl gültig: sie reist über den Radioknopf im Formular.*

⚠️ **Was es kostet, gemessen an `Adresse`:** *176 KB Seite, 0,13 s, vier Auswahldialoge, 183
Baumzeilen. **Es wächst mit der Zahl der Felder**, weil jede eigene Zeile ihren eigenen Dialog trägt.*

## INF-007 · Eine Spalte plus Raum, oder zwei Spalten? — **entschieden am 2026-09-04**

**Eine Spalte plus Raum** ([D-597](../../NewConcept/90-decision-log.md)), auf sein Wort: *«ok ich
vertraue dir, eine Spalte, und zweck kein echter FK»*. Der Grund war seine Frage — *«ist es denn
sicher, dass es immer entweder oder ist?»* — und die Antwort darauf ist **nein**: nicht ob,
sondern wie viele «oder» es einmal gibt, ist offen.

⚠️ *Der ursprüngliche Eintrag steht darunter unverändert.*

### Der Eintrag, wie er gestellt wurde — `package.md` §5a gegen TASK-005

**2026-09-04, beim Bauen von TASK-005 aufgefallen. Nicht entschieden, sondern erfasst (`PR-4`).**

TASK-005 sagt: *«`value_ref` bekommt eine Spalte für den Raum»* — so gebaut, `value_ref_kind` mit
`node` oder `record`, nach dem Muster `changelog.owner_kind`, wie [§6](package.md) es verlangt.

**Der Soll-Zustand in [§5a](package.md) sieht dagegen zwei getrennte Spalten vor** —
`value_node_record_id` **oder** `value_node_id`. Das ist dieselbe Aussage in einer anderen Form: dort
sagt der **Spaltenname** die Zieltabelle, hier eine zweite Spalte.

| | dafür | dagegen |
|---|---|---|
| **eine Spalte + Raum** | ein Index, eine Wertspalte mehr neben `value_int` … `value_date`; der Raum ist ein Datum wie jedes andere | zwei Spalten müssen zusammenpassen — der Wächter muss es prüfen |
| **zwei Spalten** | jeder Fremdschlüssel nennt seine Tabelle im Namen, echte Fremdschlüssel möglich | zwei nullbare Spalten, die einander ausschliessen müssen — auch das braucht einen Wächter |

⚠️ **Gebaut ist die erste Form, weil TASK-005 sie wörtlich verlangt und weil sie vor TASK-004 stehen
muss.** *Sie steht der zweiten nicht im Weg: eine spätere Aufteilung liest `value_ref_kind` und
verteilt — **was heute nicht ginge, weil der Raum nirgends stünde.** Die Frage stellt sich erst beim
Umbenennen auf `relation_records`.*

---

## INF-008 · Wo wohnt die Installationsidentität, seit `identities` weg ist?

**2026-09-04, beim Bauen von TASK-004 aufgefallen. Nicht entschieden, sondern erfasst (`PR-4`).**

Die Installationsidentität ist **weder Knoten noch Kante** — sie ist der Kopf der
Einstellungskette und hat keine Zeile in irgendeiner Tabelle. Bis Fassung 20 zog sie ihre Nummer
aus `identities`; **die Tabelle ist gestrichen, und damit hat sie keine Quelle mehr.**

**So gebaut, bis Du entscheidest:** auf einer **bestehenden** Installation bleibt die vorhandene
Nummer unangetastet (hier: 641). Auf einer **frischen** ist `1` reserviert, und `nodes` wie
`relations` beginnen deshalb bei `2`.

⚠️ *Das ist eine Reservierung und kein Zuhause. Denkbar wären: eine eigene kleine Tabelle, ein
Knoten wie jeder andere (dann wäre die Kette durchgehend aus Knoten), oder `owner_kind =
installation`, wie das Änderungsbuch es schon führt — dort stehen gemessen **3 Zeilen** dieser Art.*

---

## INF-009 · `settings.owner_id` nennt ihren Raum nicht — und muss es

**2026-09-04, beim Bauen von TASK-004 gemessen. Der Wächter hält es sichtbar, entschieden ist es
nicht (`PR-4`).**

[`package.md` §6](package.md) verlangt: *«Kann eine Spalte auf mehr als eine Tabelle zeigen, nennt
eine zweite Spalte den Raum.»* **`settings.owner_id` kann und tut es nicht** — gemessen zeigt sie
auf **3 Knoten und 10 Kanten**. Solange alle aus `identities` zogen, war das schadlos.

⚠️ **Es ist nicht theoretisch, es ist zweimal passiert.** *`Settings::refuseWhereItDoesNotApply()`
beantwortete «ist das eine Kante?» mit «die Knotentabelle kennt die Nummer nicht». Mit eigenen
Räumen kennen sie **beide** — `package4-check` brach daran ab: «multiplicity gehört nicht an einen
Knoten», gesagt über eine Kante.*

**So gebaut, bis Du entscheidest — zwei Behelfe, beide mit Ablaufdatum:**

| | |
|---|---|
| `Schema::RELATION_SPACE_OFFSET` | der Kantenraum beginnt eine Milliarde über dem Knotenraum, damit sich die beiden nicht überholen |
| `Settings` bekommt den Kantenspeicher | die Frage «ist das eine Kante?» wird bei den Kanten gestellt statt bei den Knoten |

⚠️ **Beide fallen an dem Tag weg, an dem `settings.owner_id` ihren Raum nennt** — `owner_kind`, wie
`changelog` es seit jeher führt und wie `record_values.value_ref_kind` es seit TASK-005 tut. *Der
Preis ist nicht die Spalte, sondern die Leser: `SettingRepository::forOwners()` bekommt heute eine
Liste blosser Nummern, und die Kette müsste künftig Nummer **und** Raum tragen.*

⚠️ *`labels.owner_id` hat das Problem **nicht**: gemessen gehören alle 47 Zeilen einem Knoten, keine
einer Kante — [`id-space-check.php`](../../../scripts/dev/id-space-check.php) prüft das jetzt gegen
`nodes` statt gegen `identities` und ist damit strenger als vorher.*

⚠️ **Erledigt am 2026-09-04 mit TASK-017 — nicht beantwortet, sondern weggefallen.** *Die Frage war
«welchen Raum meint `settings.owner_id`». **Die Tabelle ist gestrichen** ([D-579](../../NewConcept/90-decision-log.md)),
also gibt es die Spalte nicht mehr, und beide Behelfe fallen mit ihr:*

| Behelf | was daraus wurde |
|---|---|
| `Schema::RELATION_SPACE_OFFSET` | **weg.** Beide Räume beginnen wieder dort, wo der gemeinsame aufgehört hat — genau das, was Du verlangt hattest. *Umnummeriert wurde nichts: `AUTO_INCREMENT` lässt sich nicht nach unten setzen, eine bestehende Installation behält also ihren Zählerstand.* |
| `Settings` bekommt den Kantenspeicher | **weg** — der Dienst selbst ist gestrichen. |

⚠️ *Der Wächter zieht mit: [`id-space-check.php`](../../../scripts/dev/id-space-check.php) verlangte
«keine Nummer ist zugleich Knoten und Kante» und **verlangt es nicht mehr** — das war die Zusage des
Behelfs, und [`package.md` §6](package.md) sagt das Gegenteil («Knoten 5, Kante 5 und Datensatz 5»).
Gemessen nach dem Wegfall: **1 solche Nummer**, gemeldet statt beanstandet.*

---

## INF-010 · Erbt eine Einstellung — und woher?

**2026-09-04, beim Bauen von TASK-017 gemessen. Nicht entschieden (`PR-4`).**

Bis heute erbte eine Einstellung über die **Auflösungskette** der `settings`-Tabelle:
*Installation → Modellwurzel → Vorfahren → Knoten → Verwendungsstelle*. Die Tabelle ist gestrichen
([D-579](../../NewConcept/90-decision-log.md)), und der Weg über die Datensätze **erbt nicht** —
{@see ModelValues} sagt es in ihrem eigenen Docblock: *«geantwortet wird aus dem `default`-Satz
**dieses** Knotens»*.

⚠️ **Gemessen, was das kostet: zwei Kerntests und ein Abschnitt von `package7-check`.** *«Eine Wahl
am Typ erreicht jede Verwendung» und «das Symbol erbt die Kette hinunter» sind rot geworden und
stehen jetzt als `markTestIncomplete` mit dieser Nummer im Text — **nicht gelöscht und nicht
abgeschwächt**, damit die Frage sichtbar bleibt, statt in einem grünen Lauf zu verschwinden.*

⚠️ *In der Sache ist es womöglich kein Verlust, sondern eine andere Antwort: seit
[D-526](../../NewConcept/90-decision-log.md) ist ein geerbtes Feld **dieselbe** Kante, und die Kante
erbt. Was heute nicht erbt, ist der **Wert** im Datensatz. Ob er das soll — und ob dann der Vorfahr
oder die Kante antwortet — ist Deine Entscheidung, nicht meine.*

---

## INF-011 · Es gibt keinen Schreiber für eine Einstellung an einer Verwendungsstelle

**2026-09-04, beim Bauen von TASK-017 gemessen. Nicht entschieden (`PR-4`).**

`DataEntry::putSettingAt()` schreibt eine Angabe **an einem Knoten**. Für eine Angabe **an einer
Kante** — `label_role` an `Einheitenwert.prefix`, `converter` an einem Feld — gab es genau einen
Schreiber, und der war `Settings::put()` in die gestrichene Tabelle.

⚠️ **Die Ablage kann es**: der Wert liegt im Satz des Besitzers unter der Adresse
`<Verwendungsstelle>.<Einstellungskante>`, und {@see ModelValues::forUseSite()} **liest** ihn dort.
*Zwei Wächter legen die Zeile deshalb heute selbst über die Speicher an
([`converter-check.php`](../../../scripts/dev/converter-check.php),
[`package7-check.php`](../../../scripts/dev/package7-check.php)) — das ist ein Behelf in einer
Prüfung und keine Lösung.*

⚠️ *Was fehlt, ist eine Zeile im Kern und keine Entscheidung über das Modell — aber sie gehört an
eine Stelle, und welche, sagst Du.*

**2026-09-04 gebaut — die Stelle ist `DataEntry`, neben dem Schreiber am Knoten.** *Sie heisst
`putSettingAtUseSite()` / `clearSettingAtUseSite()`, legt keine neue Ablage an und schreibt an genau
die Adresse, an der `ModelValues::forUseSite()` liest. **Die zwei Behelfe sind weg**: `converter-check`
und `package7-check` schreiben jetzt durch den Kern und sind beide grün. Der Rand ruft sie in
`NodesScreen::saveUseSiteSettings()` — die Angaben einer **Feldzeile**, die als
`taxmod_field_setting[<Kanten-Id>][<Schlüssel>]` ankommen und bis dahin bis auf `multiplicity` **alle
fallengelassen wurden**; gezeichnet waren sie längst, als Feldzeilen im Settings-Block
([D-520](../../NewConcept/90-decision-log.md)), und keine zweite Tafel ist dazugekommen.*

⚠️ **Eine Grenze, die dabei gemessen wurde und die Du kennen solltest.** *Eine Einstellungskante wird
an der Kette des **Besitzers** gesucht, weil der Leser sie genau dort sucht. Ein Schlüssel, den nur
der **Zielknoten** erklärt — `read_only` gibt es zweimal, an `Root` und an `Integer` — wird an einer
Verwendungsstelle darum **nicht** geschrieben statt an eine Adresse gelegt, die niemand liest. Das
ist heute eine Entscheidung des Schreibers und keine des Modells; **ob eine Verwendungsstelle eine
Einstellung des Ziels überschreiben können soll, sagst Du.***

---

## INF-012 · Wer prüft künftig, dass eine Grenze nur enger wird?

**2026-09-04, beim Bauen von TASK-017 gemessen. Nicht entschieden (`PR-4`).**

[D-312](../../NewConcept/90-decision-log.md): *eine begrenzende Einstellung darf nur nach unten
enger werden.* Durchgesetzt hat das `Settings::put()` — es warf `CannotWiden`, und
`package7-check.php` hat die Verweigerung gemessen. **Der Dienst ist mit der Tabelle gestrichen, und
damit gibt es heute keine Stelle mehr, die ein Weiten bemerkt.**

⚠️ *Das ist ein Verlust und keine Vereinfachung.* Die Ausnahme `CannotWiden` steht noch, ohne
Werfer; der Abschnitt in `package7-check.php` ist mit dieser Nummer im Text ausgebaut.

## INF-013 · `addField()` leitet die Kantenart nicht aus dem Ast ab

**2026-09-04, vom Eigentümer an seinem Modell gefunden.** *«converter sollte setting von renderer
sein, nicht field.»*

**Die Oberfläche sagt dem Benutzer:** *«‹Kind› is not a choice — it follows from where the target
sits in the tree.»* **Über den Code stimmt das nicht:** `ModelEditor::addField()` legte die Kante
`Renderer --converter--> Converter` als `aggregation` an, obwohl `Converter` im Einstellungsast
liegt. Erst `markAsSetting()` machte sie zu dem, was sie ist.

⚠️ **Es ist mir am selben Tag zweimal passiert** — *bei dieser Kante ([D-585](../../NewConcept/90-decision-log.md))
und beim Gerüst für `node-kind-check`. **Dort fiel es auf, weil ein Wächter rot wurde; hier hat es
nur sein Blick gefunden.*** Die Kante stand einen halben Tag falsch im Modell.

**Gemessen nach der Korrektur:** *keine weitere Kante zeigt in den Einstellungsast, ohne eine
Einstellungskante zu sein — es war die einzige.*

⚠️ **Nicht entschieden (`PR-4`):** *soll `addField()` die Art ableiten, oder soll der Aufrufer sie
nennen müssen? **Für das Ableiten spricht, dass die Oberfläche es ohnehin behauptet**; dagegen, dass
ein Akt, der rät, schwerer zu prüfen ist als einer, dem man es sagt. **Ein Wächter fehlt in beiden
Fällen** — dass jede Kante in den Einstellungsast eine Einstellungskante ist, prüft heute niemand.*

---

## INF-014 · An der Modellwurzel steht ein Renderer, und seit D-602 gilt er für alles

**2026-09-04, beim Bauen von [D-602](../../NewConcept/90-decision-log.md) gemessen. Nicht
entschieden (`PR-4`).**

Die Auflösungskette hat die Vorfahren zurück, und **die Wurzel ist ein Vorfahr** — genau so sagt es
[D-602](../../NewConcept/90-decision-log.md). Damit wirkt zum ersten Mal, was im Datensatz der Wurzel
steht.

⚠️ **Was dort steht, gemessen:** *der `default`-Satz von `Root` trägt eine `Display Option`, und
deren Teil sagt `render = checkbox` und `converter = hexadecimal`. **Damit wird jedes Feld des
Modells als Ankreuzfeld gezeichnet** — ein Datum, eine Adresse, eine Farbe.*

⚠️ **Was es kostet, gemessen:** *`package7-check` verliert 16 Zusagen, `composition-check` sechs.
Mit dieser einen Zeile stillgelegt sind beide Läufe grün und der Rest des Netzes unverändert.*

⚠️ **Woher die Zeile kommt, gemessen:** *kein Journaleintrag, keine Zeile im Schatten — sie ist
**nie über die Oberfläche geschrieben worden**. Sie stammt aus `scripts/migrate-renderer-settings.php`,
das eine `settings`-Zeile mit `owner_id = 1` umgezogen hat. **Und `1` ist die Modellwurzel, nicht die
Installation**: `installationId()` ist gemessen `641` und steht in keinem Knotenpfad.*

⚠️ **Die Frage, und sie ist Deine:** *ist eine Einstellung an der Modellwurzel gewollt — der
modellweite Vorgabewert, so wie `Root` die Einstellungskanten trägt, damit jeder sie erbt
([D-545](../../NewConcept/90-decision-log.md)) — und ist dann nur **dieser Wert** falsch? Oder soll
die Wurzel als Träger von Werten überhaupt ausgenommen sein? **Ich habe nichts gelöscht und nichts
ausgenommen**: die Kette ist gebaut, wie D-602 sie beschreibt, und die zwei Läufe stehen rot, statt
dass die Frage in einem grünen Lauf verschwindet.*

---

## INF-015 · Was die Wanderung aus D-594 stehengelassen hat

**2026-09-04, bei TASK-024 gemessen. Nicht entschieden (`PR-4`).**

[D-594](../../NewConcept/90-decision-log.md) ist vollzogen: 29 Renderer-Wahlen sind umgezogen, 2
Konverter-Werte hängen an der geerbten Kante, 161 leere Behälter sind gefallen. **Drei Dinge blieben
liegen, weil D-594 sie nicht nennt.**

⚠️ **1 · Die Halterzeilen sitzen an einer toten Kante.** *Die 29 umgezogenen Sätze werden von einer
Wertzeile an Kante `44093` gehalten — dem Feld `display` des gelöschten Hüllknotens. **Die Kante gibt
es nicht mehr, den Halter schon.** D-594 zeichnet den Halter ausdrücklich als bleibend
(«Halter → Satz (node_id = «compact»)»), also blieb er. Zusammen mit den Resten aus Punkt 2 sind das
**32 Wertzeilen, deren Kante fehlt** — `id-space-check` zählt sie. Das ist genau die Leiche, die
[D-604](../../NewConcept/90-decision-log.md) sichtbar machen will; **wohin der Halter stattdessen
gehört — `settings_record_id` ist heute in 0 Knoten und 0 Kanten gefüllt — ist nicht entschieden.**

⚠️ **2 · Satz `2233` trägt drei `render`-Zeilen, in zwei Formen.** *Eine zeigt auf einen **Knoten**
(`table`, die alte Form) — sie ist umgezogen. Zwei zeigen auf einen **Datensatz**
(`5893` und `5902`, die neue Form aus [D-583](../../NewConcept/90-decision-log.md)) — **sie stehen
noch da, und `5893` gibt es nicht mehr.** Dazu eine `converter`-Zeile ohne Verweis. **Ich habe
nichts davon gewählt oder geworfen**: D-594 beschreibt eine Ebene weniger, keinen Formwechsel.*

⚠️ **3 · 28 Datensätze ohne Knoten sind nicht vom Hüllknoten.** *Ihre `node_id` liegt im
Testraum (`149000083806` aufwärts), sie tragen keine Werte und niemand hält sie — **Rückstände aus
Wächterläufen, die ihre Knoten aufräumen, ihre Datensätze aber nicht.** Gemessen entstehen sie nicht
bei jedem Lauf; die Zahl blieb über zwei volle Wächterläufe bei 28. **Sie sind nicht in D-594 und
darum stehengeblieben** — und sie sind der einzige Grund, warum `package6-check` und
`unitvalue-check` weiter «kein Datensatz ohne Knoten» rot melden, jetzt mit 28 statt 218.*

---

## INF-016 · Was TASK-020 stehengelassen hat

**2026-09-04 gemessen. Nicht entschieden (`PR-4`).**

TASK-020 ist vollzogen: **28 Halter sind aus der Wertzeile an der toten Kante `44093` in
`nodes.settings_record_id` gezogen**, der Leser fragt die Spalte, der Schreiber füllt sie, und
`settings-record-column-check.php` bewacht beide Richtungen. **Punkt 1 aus `INF-015` ist damit
erledigt.** Vier Wertzeilen an toten Kanten blieben stehen.

⚠️ **1 · Der Halter der *Wurzel* ist nicht mitgezogen — gemessen, nicht vermutet.** *Er zeigt auf
einen Satz von `checkbox`. Solange er an der toten Kante hing, sah ihn niemand; **in der Spalte
wirkt er, und die Vorfahrenkette aus [D-602](../../NewConcept/90-decision-log.md) trägt ihn an jeden
Knoten des Modells.** Beim ersten Lauf, der ihn mitnahm, wurden `composition-check` und
`package7-check` rot — «a bool gets the sliding switch — checkbox», «the date is a date control».
**Das ist `INF-014` und eine eigene Entscheidung:** ist die Display-Option an der Wurzel gewollt
(dann muss die Kette sie anders behandeln) oder ein Versehen (dann fällt sie)?*

⚠️ **2 · Die drei Reste in Satz `2233` stehen weiter** — unverändert Punkt 2 aus `INF-015`. *Zwei
`render`-Zeilen der Neuform an Kante `44091` und eine `converter`-Zeile ohne Verweis an `44092`.
**Nichts davon steht in TASK-020**, und der Wächter deckelt sie bei vier statt sie wegzuschauen: die
Zahl darf fallen und nie steigen.*

⚠️ **3 · ERLEDIGT am 2026-09-05 — der Befund darunter war überholt.** *Gemessen: `renderer-choice-check` 14,
`setting-write-check` 27, `page-blocks-check` 33, `multiplicity-check` 22 — **alle vier grün**. Die
Einstellung wird über `nodes.settings_record_id` geschrieben und gelesen; eine Einstellungskante für
den Renderer braucht niemand dafür. **Sein Satz, der das richtigstellte:** «Wir haben ein Setting im
Knoten, das den Renderer wählt.» Er hat recht — ich hatte einen Befund von vor TASK-020
weitergetragen, ohne ihn nachzumessen (`erst messen, dann behaupten`).*

⚠️ *Der überholte Wortlaut, als Beleg:* **Was die Spalte nicht heilt: es gibt keine Einstellungskante für den Renderer mehr.** *Sie
ging mit dem Hüllknoten. Der **Leser** kommt ohne sie aus — die sechs beobachteten Knoten zeichnen
wieder mit `table`, `form`, `slider`, `node`, `chooser-dialog`, `form` statt sechsmal `plain`. **Der
Schreiber am Rand kommt nicht ohne sie aus:** die Einstellungsmaske sucht ihre Zeilen über die
Feldkanten des Knotens, und ohne Kante gibt es keine Zeile, in der man wählen könnte. Darum melden
`setting-write-check`, `page-blocks-check`, `multiplicity-check` und der Auswahl-Teil von
`renderer-choice-check` weiter rot. **Wie eine Einstellungskante entsteht, ist offen** — dieselbe
Frage, die seit dem Umbau `settings` → Felder offensteht.*

---

## INF-017 · Die 324 leeren Datensätze stehen weiter da

**2026-09-05, beim Bau von TASK-043. Nicht entschieden (`PR-4`).**

**Der Schreiber ist umgestellt:** ein Datensatz entsteht jetzt beim ersten Schreiben, nicht beim
Ansehen oder Löschen ([D-609](../../NewConcept/90-decision-log.md)). **Was schon dasteht, ist damit
nicht weg** — und es wegzuräumen ist eine Handlung an seinen Daten, keine Folge dieses Umbaus.

⚠️ **Die Grenze steht schon in [D-610](../../NewConcept/90-decision-log.md) und ist das Wichtige:**
*«unbenutzt» heisst **nirgends referenziert**, nicht «ohne Wertzeilen» — 26 der leeren Sätze stehen
in `nodes.settings_record_id` und tragen ihre Aussage in ihrer `node_id`. **Das ist TASK-044**, und
sie ist offen.

⚠️ **Nebenbefund, gemessen und behoben:** *`cleartrash-check` baute seinen `ModelEditor` mit einer
Variablen, die es nie gab — der Akt bekam **kein** Record-Repository und nahm in diesem Lauf die
Datensätze gar nicht mit. **Die Zusage «its records went with it» gab es nicht**, sie ist jetzt da
und grün.*

---

## INF-018 · Ein Strukturrenderer am Typ blendet jedes Feld aus, das auf ihn zeigt

**2026-09-05, beim Grünmachen von `unitvalue-check`. Nicht entschieden (`PR-4`) — und ausdrücklich
nicht angefasst, weil [D-610](../../NewConcept/90-decision-log.md) genau diese Sätze schützt.**

**Gemessen:** *der Knoten `Base units` trägt in `nodes.settings_record_id` die Renderer-Wahl `table`.
`TableRenderer::fits()` verlangt einen **Knoten** als Gegenstand; ein **Wert**, der auf einen Knoten
verweist, ist keiner. **Ergebnis: das Feld `einheit` zeichnet sich als leerer Text** — `2.7 kilo `
statt `2.7 kilo Ohm`. Der Nachbarfall belegt es: `Prefixes` hat `chooser-dialog` gewählt, und
`prefix` zeichnet sich einwandfrei als «kilo».*

⚠️ **Zwei Zusagen in `unitvalue-check` bleiben deshalb rot, und das ist der ehrliche Zustand.** *Ihr
Kommentar nennt heute [D-579](../../NewConcept/90-decision-log.md) als Ursache — **das stimmt nur
noch für den Prefix.** Für die Einheit ist die Ursache diese hier, und sie umzuschreiben hiesse, eine
Zusage zu entschärfen, statt einen Befund zu melden (`PR-9`).*

⚠️ **Die offene Frage ist nicht «welcher Renderer», sondern wer gefragt wird:** *zieht ein
**Verweis** den Renderer des Zielknotens, oder den seines eigenen Feldes? Heute zieht er den des
Ziels — und dort steht eine Wahl, die für die **Ansicht des Knotens** getroffen wurde, nicht für
seine Erwähnung in einem fremden Feld. Gehört zu TASK-052 und
[D-617](../../NewConcept/90-decision-log.md) («ein Knoten ohne eigene Aussage soll seinen Renderer
aus seinem Typ nehmen»).*

⚠️ *Der Weg ohne Entscheidung wäre gewesen, die Wahl an `Base units` wegzunehmen. **Das sind die 26
Sätze, vor denen D-610 warnt** — «wer sie als leer wegräumt, löscht 26 Renderer-Wahlen».*

---

## INF-019 · Wo eine Einstellung ohne Trägerkante hingehört, und was «keiner» dort heisst

**2026-09-05, beim Beheben von TASK-052. Nicht entschieden (`PR-4`).**

**Gemessen:** *auf der Seite von `Integer` stand **kein einziges Steuerelement mit `renderer` im
Namen**. Der Wähler hatte in der **Wertspalte des Einstellungsblocks** gestanden, und dieser Block
zeichnet **Kanten**; seit [D-584](../../NewConcept/90-decision-log.md) steht der Renderer in
`nodes.settings_record_id`, und mit dem Hüllknoten `DisplayOption`
([D-594](../../NewConcept/90-decision-log.md)) fiel seine Trägerkante. **Eine Zeile ohne Kante hat
der Kantenblock nicht** — der Wähler verschwand, ohne dass etwas rot wurde.*

⚠️ **Gebaut ist der Weg, nicht der Ort.** *Die Wahl steht jetzt als eigener kleiner Block unter den
zwei Tabellen, hängt über `form="…"` am Seitenformular und wird über den **Schlüssel** angenommen.
**Ob das ihr Platz ist, ist offen:** eigener Block, oder eine Zeile im Einstellungsblock, die keine
Kante hat? Das Zweite wäre näher an [D-518](../../NewConcept/90-decision-log.md) («Felder und
Einstellungen sind zweimal dieselbe Tabelle») und kostet eine Zeile ohne Kanten-Id in einem
Zeilenrenderer, der heute überall eine hat.*

⚠️ **Zweite offene Frage: was «kein Renderer» heisst.** *Die Auswahl hat keine leere Wahl, ein leerer
Wert schreibt deshalb nichts. Ob es «keinen Renderer wählen» überhaupt geben soll — und ob das die
Spalte auf `0` setzt oder den Satz wegräumt — sagt keine Entscheidung.*

⚠️ **Und die Liste ist bewusst auf den Renderer beschränkt.** *Gemessen liest
{@see \Taxmod\Core\Service\ModelValues} genau **einen** Schlüssel aus der Spalte. Jede weitere
Einstellung dort zu zeichnen hiesse, ein Steuerelement anzubieten, dessen Wert niemand wieder
anzeigt — derselbe Mangel, nur andersherum.*

---

## INF-020 · Ein `__Test`-Knoten liegt seit einem abgestürzten Lauf im Einstellungsast

**2026-09-05, beim Abschluss von TASK-041. Nicht angefasst (`PR-4`, D-613).**

**Gemessen:** *der Einstellungsast trägt 37 Knoten unter seiner Wurzel; 36 tragen die Marke, der
siebenunddreissigste ist `__Test` (`149000087094`). **Auf ihn zeigt keine Kante ausser Vererbung**,
er hält nichts, und er stammt nicht vom Eigentümer: den Namen vergibt
[`geruest.php`](../../../scripts/dev/geruest.php) für seinen Testbehälter.*

⚠️ **Er ist nicht markiert, und das ist richtig so** — *er ist keine Einstellung. `setting-kind-check`
meldet ihn als Hinweis, nicht als Fehler; die Wanderung lässt ihn stehen. **Weggeräumt habe ich ihn
nicht**: er gehört einem anderen Wächter, und einen fremden Knoten zu löschen ist genau der Fehler
aus TASK-025, TASK-039 und TASK-047.*

⚠️ *Er ist ein Beleg für **TASK-050** (D-614): ein Testast, in dem solche Reste liegen dürfen, statt
im Arbeitsbaum des Eigentümers. Bis dahin bleibt die Frage offen, **wer** ihn wegräumen darf.*

---

## INF-021 · Gehört zum Mal am toten Verweis ein Grund?

**2026-09-05, beim Bau von TASK-038. Nicht entschieden (`PR-4`).**

**Gebaut ist das Mal:** *ein Verweis auf einen verschwundenen Knoten steht rot als `#4711` da — in
der Anzeige wie im Auswahldialog. **Er ist unterscheidbar von «nichts gewählt», und das war das
Verlangte** ([D-604](../../NewConcept/90-decision-log.md)).*

⚠️ **Offen ist die zweite Hälfte, die es anderswo gibt:** *[D-608](../../NewConcept/90-decision-log.md)
verlangt für die **gesperrte** Zeile ausdrücklich beides — «‹gesperrt› muss sichtbar sein» **und**
«muss ne Tooltip-Begründung da sein». Am toten Verweis steht heute nur das Mal. **Wer `#4711` sieht,
weiss nicht, dass dort ein gelöschter Knoten stand** — er könnte es für eine Id halten, die jemand
hineingeschrieben hat.*

⚠️ **Warum es nicht nebenbei gebaut ist:** *die Nummer ist sprachlos, ein Satz wäre Benutzertext.
Der Kern darf keinen erfinden (`AR-2`, `CD-1`); er müsste als Wort vom Rand kommen, wie
`word:locked-reason` in der Feldzeile. **Das ist eine Zeile Arbeit und eine Entscheidung**, welchen
Satz sie trägt — und die gehört ihm.*

---

## INF-020 · Was die Klassenspalte nicht ablösen kann — 23 von 46 Optionen bleiben

**2026-09-05, bei TASK-009. Nicht entschieden (`PR-4`) — und ausdrücklich nicht erfunden.**

**Gemessen vor dem Umbau: 46 Optionen, nicht 56** (die Zahl in TASK-009 stammt von einem früheren
Stand). **23 sind gefallen**, weil ihr Knoten seit TASK-008 selbst sagt, welche PHP-Klasse ihn
umsetzt. **23 stehen weiter, aus drei verschiedenen Gründen:**

```text
11  taxmod_type_<name>_id       int, text, bool, …  — es gibt keine Klasse je Typ
 3  taxmod_render_<behaelter>_id Renderer, Converter, Validator — ein Ort, keine Klasse
 9  taxmod_render_renderer_…_id  tree, head, settings, choice, …  — Oberflaechenrenderer
```

⚠️ **Die elf Typoptionen sind der eigentliche Befund, und sie stehen wörtlich in der Aufgabe** —
*«welcher Knoten ist der Int-Typ»*, und `geltende-regeln.md` §5 nennt sie als das, was die Spalte
aus §3.2 ablösen soll. **Gemessen geht das nicht:** ein einfacher Typ ist in diesem Code ein
**Aufzählungsfall** ({@see \Taxmod\Core\Model\SimpleType}), keine Klasse. Alle elf Typen antworteten
mit demselben Klassennamen, und die Spalte könnte `int` nicht von `text` unterscheiden. **Eine
Marke statt des Klassennamens ist genau das, was die Entscheidung ausschliesst** — *«die Spalte
trägt den Klassennamen, keine Factory»*.

⚠️ **Drei Wege, keiner davon gewählt:** *(a) je Typ eine Klasse, dann greift die Spalte unverändert;
(b) die Spalte darf auch einen Aufzählungsfall nennen (`…\SimpleType::Int`), dann prüft der Wächter
`defined()` statt `class_exists()`; (c) die elf Optionen bleiben, bis `labels` steht (TASK-019) und
ein Typ über etwas anderes zu finden ist. **Heute gilt (c), weil nichts entschieden wurde** —
`type-binding-check.php` bewacht die alte Form unverändert und ist grün.*

⚠️ **Die neun Oberflächen-Optionen sind Rückstand und werden nicht angefasst.** *Sie zeigen auf
Knoten, die eine ältere Fassung der Saat angelegt hat; `rendering-scaffold-check` sichert
ausdrücklich zu, dass **keiner der neun heute gesät wird**. Die Optionen liest niemand mehr — aber
sie zu löschen hiesse, eine Bindung zu Daten des Eigentümers wegzuwerfen, für die es keinen Ersatz
gibt. **Gemeldet, nicht aufgeräumt.***

---

## INF-021 · `cleartrash-check` lässt bei jedem Lauf einen Datensatz ohne Knoten zurück

**2026-09-05, beim Wächterdurchlauf zu TASK-009. Nicht behoben — es ist nicht diese Aufgabe.**

**Gemessen, und zwar reproduzierbar:** *vor dem Lauf 2 Waisen, danach 3; jeder weitere Lauf legt
eine dazu. Die Waise trägt eine `node_id` aus dem Zahlenraum der Wegwerfknoten
(`149000088283`) und **keine einzige Wertzeile**. Von den anderen zehn Wächtern im Durchlauf legt
keiner eine dazu.*

⚠️ **Es ist derselbe Fehler wie in TASK-025, TASK-039 und TASK-047** — *ein Wächter, der mehr
hinterlässt, als er anfasst.* **Und er ist teurer als er aussieht:** *`package6-check` und
`id-space-check` sind **wegen dieser Waisen rot**, und zwar seit dem 2026-09-05 — die beiden roten
Zusagen «kein Datensatz gehört einem Knoten, den es nicht gibt» stimmen, es sind bloss nicht die
Daten des Eigentümers.*

⚠️ *`clearTrash()` selbst ist seit TASK-039 richtig — es nimmt die Datensätze mit. Zu finden ist,
welcher Schritt des Wächters einen Datensatz **nach** dem Wegräumen anlegt.*

---

## INF-022 · Welcher Ast im Verschiebe-Dialog aufgeht, ist gewählt und nicht entschieden

**2026-09-05, bei TASK-054. Gebaut und ausdrücklich benannt, weil es eine Annahme ist.**

[D-615](../../NewConcept/90-decision-log.md) sagt, **was** der Einstiegsast tut — er klappt einen Ast
auf und den Rest zu —, aber nicht, **welcher** es beim Verschieben sein soll. Ich habe den Ast
genommen, in dem der Knoten heute liegt: wer verschiebt, bleibt meistens in der Nähe.

⚠️ *Die anderen drei Dialoge machen es je anders — der Typ-Dialog öffnet die Datentypen, der
Ziel-Wechsel den Ast des heutigen Ziels. **Das ist dreimal plausibel und nirgends entschieden.**
Wenn er einen anderen Anfangspunkt will, ist es je eine Zeile.*

⚠️ *Und ein Sonderfall bleibt offen: liegt der Knoten in **keinem** Ast, geht gar nichts auf, und der
Dialog zeigt nur die Zweigköpfe. Ob das richtig ist oder ob dann der Ast des heutigen Elternknotens
gelten soll, ist ungeprüft.*

---

## INF-022 · «Die erste Position ist immer null» — gezählt, nicht hergestellt

**2026-09-05, bei TASK-012. Nicht entschieden (`PR-4`).**

**Sein Satz:** *«Position würde ich eher Order nennen. Und die erste Position ist immer null.»*

**Gemessen nach der Umbenennung: 12 Listen beginnen nicht bei `0`, sondern bei `1`** — je Knoten und
je Kantenart gezählt, darunter der Wurzelknoten selbst. *Der Grund ist harmlos: eine gelöschte Kante
lässt eine Lücke, und niemand nummeriert danach um.*

⚠️ **Offen ist, was sein Satz verlangt:** *«gezählt wird ab null» — dann ist der Bestand in Ordnung,
und die Zahl misst nur Lücken. Oder «die Liste ist lückenlos ab null» — dann müssten die zwölf
Listen umnummeriert werden, und das ist eine Handlung an seinen Daten.* **`sort-order-check.php`
zählt sie darum und verlangt nichts.**

---

## INF-023 · Zwei Kanten tauschen konnte der eindeutige Schlüssel nicht — still

**2026-09-05, bei TASK-012. Behoben, hier als Warnung notiert.**

**Gemessen:** *sobald `(from_id, kind, sort_order)` eindeutig war, tat `moveUp()` **nichts mehr**.
Ein Tausch schreibt zwangsläufig einmal auf eine Stelle, die noch besetzt ist; MySQL weist das
zurück, und `$wpdb` sagt darüber nichts. **Genau ein Wächter hat es gemerkt** — `package2-check`,
«moving up swaps them» —, und ohne ihn wäre es an der Oberfläche als «der Knopf tut manchmal nichts»
aufgetaucht.*

**`ModelEditor` geht seither über eine freie Stelle: erst zur Seite, dann der andere, dann hin.**

⚠️ **Die Lehre gehört zu den nächsten Aufgaben:** *jeder eindeutige Schlüssel, der über eine Spalte
geht, die jemand tauschen kann, braucht diesen Umweg. **TASK-018 legt genau so einen an**
(`(parent_node_id, sort_order)`).*

---

## INF-024 · TASK-013 hängt an einer Frage, die keine Entscheidung beantwortet: was wird aus den Werten einer geparkten Kante

**2026-09-05. Aufgabe **nicht angefangen**, weil das Wichtigste daran nicht entschieden ist
(`PR-4`).**

[D-575](../../NewConcept/90-decision-log.md), wörtlich von ihm: *«Parken heisst: in die
Schattentabelle wandern, mit der Änderungsgruppe im Gepäck.»*

**Was gemessen schon steht und die Aufgabe leichter macht, als sie aussieht:** *`relations_history`
**hat** die Spalte `parked_by_group_id` — die Änderungsgruppe kann also mitreisen, ohne dass eine
Spalte erfunden werden muss. Und **von 14 geparkten Kanten trägt keine einzige eine Wertzeile**, es
wäre heute also nichts zu verlieren.*

⚠️ **Genau das ist aber der Punkt, an dem ich nichts erfinden darf.** *Sobald die Zeile aus
`relations` **verschwindet**, zeigen ihre `record_values.edge_id` auf nichts mehr — heute an null
Zeilen, morgen an der ersten geparkten Kante, an der jemand Daten eingegeben hat.* **Drei mögliche
Antworten, und keine steht irgendwo:**

```text
a  die Wertzeilen wandern mit in ihren Schatten     — Daten verschwinden aus der Ansicht
b  die Wertzeilen bleiben stehen                    — Waisen, die id-space-check meldet
c  eine Kante mit Werten laesst sich nicht parken   — eine neue Regel an der Oberflaeche
```

⚠️ **Der Rest der Aufgabe ist Handwerk und gemessen:** *23 Stellen in 12 Dateien sprechen von
«geparkt», `parkedFieldEdgesOf()` müsste aus dem Schatten lesen (jüngste Fassung je Id, sofern die Id
nicht wieder lebt), und Wiederherstellen ginge über den vorhandenen `Restore`-Weg. **Das ist zu
bauen, sobald a, b oder c dasteht** — vorher wäre jede Fassung eine stillschweigende Entscheidung
über seine Daten.*

---

## INF-025 · Nachgemessen zu TASK-009: es sind 40 Optionen, und 31 davon sind eine lebende Bindung

**2026-09-05, beim Zuendebringen von TASK-009. Gemessen, nicht aus dem Bericht übernommen — und
darum stehen hier andere Zahlen als in `INF-020`.**

**`INF-020` sagte «23 stehen weiter». Das war zu wenig gezählt.** *Gemessen an der Datenbank stehen
heute **49 `taxmod_`-Optionen**; **40** davon merken sich eine Knoten-Id, und **31** zeigen dabei auf
einen Knoten, den es wirklich gibt. Die frühere Zählung hatte die gesäten Einzelknoten, die fünf Äste
und die fünf Beschriftungsrollen gar nicht angesehen.*

```text
11  taxmod_type_<name>_id          einfache Typen        Aufzaehlungsfall SimpleType
 5  taxmod_branch_<ast>_id         Aeste                 Aufzaehlungsfall Branch
 5  taxmod_role_<rolle>            Beschriftungsrollen   Aufzaehlungsfall SeededRole
 4  taxmod_{root,trash,primitives,roles}_id              je ein einzelner Ort
 3  taxmod_render_<behaelter>_id   Renderer/Converter/Validator — ein Ort, keine Klasse
 3  taxmod_testast_<ast>_id        Wegwerfaeste der Waechter, kein Modellwissen
 9  taxmod_render_renderer_…_id    Rueckstand — zeigen auf nichts
```

⚠️ **Die Frage aus `INF-020` ist dieselbe, sie ist nur viermal so gross.** *Sie betraf elf
Typoptionen; gemessen betrifft sie **einundzwanzig**, denn `Branch` und `SeededRole` sind genau wie
`SimpleType` Aufzählungen und keine Klassen. `nodes.implemented_by` trägt den Klassennamen
(TASK-008, auf sein Wort: «wenn das ohne Factory geht, weil der Klassenname da drinsteht,
perfekt»), und ein Aufzählungsfall hat keinen. **Nichts davon lässt sich mit der heutigen Spalte
ablösen, ohne die Entscheidung zu brechen** (`PR-4`), also ist nichts davon abgelöst worden.*

⚠️ **Die drei Wege stehen unverändert und keiner ist gewählt** — *(a) je Fall eine Klasse; (b) die
Spalte darf auch einen Aufzählungsfall nennen, dann prüft der Wächter `defined()` statt
`class_exists()`; (c) es bleibt, bis ein Knoten über etwas anderes zu finden ist. **Heute gilt (c).**
Was sich gegenüber `INF-020` ändert, ist nur der Preis von (a): elf Klassen zu erfinden ist eine
Überlegung wert, einundzwanzig eher nicht.*

⚠️ **Ein Befund korrigiert `INF-020` und macht die neun Rückstände billiger, als sie dort klangen.**
*Dort stand, sie zeigten «auf Knoten, die eine ältere Fassung der Saat angelegt hat», und darum
wäre ihr Löschen ein Wegwerfen einer Bindung zu seinen Daten. **Gemessen ist das nicht so:** die
neun Ids (`43643`–`43659`) stehen **weder in `nodes` noch in `nodes_history`** — sie zeigen auf
nichts, das je existiert hat. **Sie halten keine Bindung, und ihr Löschen verlöre nichts.** Ich habe
sie trotzdem stehenlassen, weil Löschen in seinem Bestand seine Entscheidung ist und nicht meine;
die Frage ist jetzt nur eine Ja-Nein-Frage statt einer Abwägung.*

⚠️ *`taxmod_installation_id` sieht wie eine zwölfte aus und ist keine — der Kern sagt selbst «Not a
node». Wo die Installationsidentität wohnt, ist `INF-008`.*

**Was dazugekommen ist:** [`node-binding-check.php`](../../../scripts/dev/node-binding-check.php).
*Er hält vier Zusagen: keine **unbekannte** Option merkt sich eine Knoten-Id; **keine der 23
abgelösten Klassenoptionen ist zurück**; jede registrierte Klasse steht an genau einem Knoten; und
die neun Rückstände zeigen weiterhin auf nichts. **Geprüft, dass er beisst** — mit einer
untergeschobenen `taxmod_render_renderer_slider_id` und einer erfundenen Option wird er rot, und er
lässt beim Lauf nichts liegen (`INF-021`).*

---

## INF-026 · Die Äste und die Beschriftungsrollen sind derselbe Fall wie die Typen — gefragt, nicht entschieden

**2026-09-05, beim Vollzug von [D-484](../../NewConcept/90-decision-log.md).** *Die elf einfachen
Typen haben jetzt je eine Klasse, und damit ist ihre Bindung an einen Knoten aus der WordPress-Option
in den Knoten gezogen (`AR-1`, TASK-009).*

⚠️ **Die fünf `taxmod_branch_<ast>_id` und die fünf `taxmod_role_<rolle>` sind technisch derselbe
Fall** — *`Branch` und `SeededRole` sind Aufzählungen wie `SimpleType` war, und derselbe Weg stünde
ihnen offen: je Fall eine Klasse, der Klassenname in `nodes.implemented_by`, die Option fällt.*

⚠️ **Sie sind nicht angefasst worden, und das ist Absicht.** *D-484 spricht von **spezialisierten
Typen**. Ob dieselbe Begründung — «dann ist auch klar, wie viele wir haben» — für Äste und Rollen
gilt, hat der Eigentümer nicht gesagt, und es wird nicht geraten (`PR-4`). **Ein Ast ist auch nicht
offensichtlich dasselbe wie ein Typ:** er trägt kein Verhalten, das eine Klasse tragen könnte — er
ist ein Ort. Genau das war der Grund, aus dem die drei Behälteroptionen (`Renderer`, `Converter`,
`Validator`) schon beim ersten Mal stehengeblieben sind.*

**Die Frage ist eine Ja-Nein-Frage:** sollen Äste und Rollen denselben Weg gehen? *Zehn Optionen
hängen daran.*

---

## INF-027 · `BaseScaffold::importOnce()` ruft zwei Methoden, die es nicht mehr gibt

**Gefunden am 2026-09-05, nebenbei, und nicht angefasst.** *`importOnce()` ruft `boundTheNumbers()`
und `declareKeyDefaults()`; beide sind mit der `settings`-Tabelle gestrichen worden (D-579), und der
Kommentar in derselben Datei sagt es selbst: «Hier standen `boundTheNumbers()`, `seededNode()` und
`declareKeyDefaults()` … Die Tabelle ist mit D-579 gestrichen.»*

⚠️ **Es ist ein Aufruf ins Leere und damit ein fataler Fehler, sobald der Weg genommen wird** — er
wird nur nicht genommen, weil `importOnce()` bei gesetzter Option früh zurückkehrt. *Auf einer
frischen Installation wäre es der erste Lauf, der stirbt.* **Gemeldet und nicht repariert**, weil es
zu einer anderen Arbeit gehört als der, in der es auffiel.

### Der Vorschlag dazu, auf seine Bitte — untersucht am 2026-09-05

**Sein Auftrag:** *«welchen Vorteil wird das denn bringen? … einen Nachteil seh ich schon: wenn wir
das umschalten, müssten wir eine andere Klasse hinterlegen. Und was passiert mit den Daten, wenn
ich von einer Klasse in die andere umschalte? … Ich seh aktuell noch nicht die Notwendigkeit.
Vielleicht kannst Du's mal untersuchen und einen Vorschlag machen.»*

**Vorschlag: keine Klassen für Äste und Rollen — aber die zehn Optionen fallen trotzdem.**

⚠️ **Gemessen, woran D-484 hing, und der Grund trägt hier nicht.** *D-484 wollte das **Inventar**:
«wie viele spezialisierte Typen haben wir» stand an **drei** Stellen — Aufzählung 11, gesät 11,
Registratur 10 — und sie stimmten nicht überein. **Bei Ästen und Rollen gibt es diese drei Stellen
nicht:** keine Registratur bindet etwas je Ast, keine je Rolle. Die Aufzählung ist die einzige
Stelle, und sie kann nicht auseinanderlaufen.*

⚠️ **Gemessen, wieviel Verhalten es zu tragen gäbe.** *`Branch` hat 102 Zeilen und drei
Verzweigungen (`relationKind`, `holdsData`, `storage`), 17 Aufrufstellen. `SeededRole` hat 44
Zeilen, **keine einzige Verzweigung** und eine Methode, die ein Ja/Nein zurückgibt. Zum Vergleich
trug `SimpleType` 257 Zeilen. **Fünf Klassen für eine Ja/Nein-Frage ist Bauwerk ohne Last.***

⚠️ **Und sein Einwand ist der stärkere Teil, nicht der schwächere.** *Der Ast eines Knotens
**wechselt** — er hat am selben Tag `Street / H#` nach `Combined` verschieben wollen (TASK-055).
Ein Typwechsel ist selten und in [D-595](../../NewConcept/90-decision-log.md) als Datenfrage
geklärt; **ein Astwechsel ist gewöhnliche Modellarbeit.** Mit einer Klasse je Ast würde ein
Verschieben die **Klasse des Objekts** ändern — ein Vorgang, den keine Sprache leicht macht und
den heute eine Zeilenänderung erledigt. **Der Vorteil, den er nennt — der Übersetzer erkennt es —
kostet genau an der Stelle, an der am meisten passiert.***

**Was stattdessen die zehn Optionen ablöst — der Knoten sagt, was er ist.** *Dieselbe Regel, die
schon `field_type` trägt (98 leer, 39 `setting`): **was ein Ding ist, sagt das Ding, nicht der
Ort.** Ein Astkopf und ein Rollenknoten tragen ihre Marke in einer Spalte; die Option verschwindet,
und niemand muss dafür eine Klasse erfinden.*

⚠️ **Offen und ausdrücklich seine Entscheidung:** *ob die Marke in `field_type` mitwohnt oder eine
eigene Spalte bekommt. **Dafür, dass sie es nicht tut:** `field_type` sagt heute, ob ein Knoten eine
Einstellung ist — «ist ein Astkopf» ist eine andere Frage, und zwei Fragen in einer Spalte war schon
einmal der Fehler ([`geltende-regeln.md`](geltende-regeln.md)).*

---

## INF-028 · Nach TASK-013 lesen sich 16 Kanten als geparkt, nicht 14 — und die drei zusätzlichen waren es schon

**2026-09-05, beim Bauen von TASK-013. Gemessen, nicht geschätzt.**

Seit [D-619](../../NewConcept/90-decision-log.md) heisst geparkt: **die Zeile steht im Schatten mit
einer Änderungsgruppe, und lebend gibt es sie nicht.** Vorher hiess es: **die lebende Zeile trägt
eine Gruppe.** Die beiden Sätze decken sich fast, aber nicht ganz.

```text
14  trugen die Gruppe an der lebenden Zeile  — sie sind gewandert
16  lesen sich nach der Wanderung als geparkt
 3  davon hatten lebend gar keine Zeile mehr  (min, max auf Integer; persistent auf Root)
```

**Was von den dreien gemessen bekannt ist:** *ihr Schattenstand trägt eine Änderungsgruppe, ihre
lebende Zeile ist seit dem 2026-08-30 fort, und **ihr letzter Journaleintrag lautet bei allen dreien
«attribute removed»**. Sie waren also geparkt und sind nie wieder aufgetaucht — die alte Lesart hat
sie schlicht nicht gefunden, weil sie eine lebende Zeile verlangte, die es nicht mehr gab.*

⚠️ **Nicht entschieden und deshalb nicht entschieden gebaut** (`PR-4`): *ob diese drei in der Liste
«entfernte Felder» ihres Besitzers erscheinen sollen. **Dafür:** sie sind entfernte Felder, und sie
lassen sich zurückholen — die neue Lesart macht sichtbar, was vorher unauffindbar war. **Dagegen:**
sie erscheinen an Knoten (`Integer`, `Root`), an denen der Eigentümer sie seit einer Woche nicht
gesehen hat, und drei Zeilen mehr in einer Liste sind eine Änderung, die niemand bestellt hat.*

⚠️ *Gebaut ist heute die erste Lesart — **sie erscheinen** —, weil die Regel «geparkt heisst: im
Schatten, lebend fort» genau das sagt und eine Ausnahme davon eine zweite Regel wäre. Sie ist mit
einer Zeile im Leseweg umkehrbar, sobald er das Gegenteil sagt.*

## INF-029 · Der Kern baut HTML, und darum steht das Framework draussen vor der Tür

**2026-09-05, vom Eigentümer aufgeworfen:** *«wir haben aktuell das Problem, dass wir Core-HTML
erzeugen und dadurch nicht alle Fähigkeiten des Frameworks nutzen können.»*

**Gemessen am selben Tag:** *`src/Core/Renderer/` hat 53 Dateien und 6684 Zeilen; **36 davon bauen
HTML**. Der Kern hat sich seine eigene Fluchthilfe geschrieben, mit einem Kommentar, der den Grund
nennt: «Plain PHP, because the core may not reach for `esc_html()`». **Genau eine** WordPress-nahe
Stelle gibt es im ganzen Kern, und das ist dieser Kommentar.*

⚠️ **Das ist kein Fehler, sondern der Preis von `CD-1`** — der Kern ruft kein WordPress auf und ist
darum ohne WordPress prüfbar. **Der Preis ist nur nie beziffert worden**, und er heisst: alles, was
das Framework an Bedienung mitbringt, ist unerreichbar. *Maskierung und `wp_kses`, `wp_nonce_field`,
die Helfer `selected()`/`checked()`, der Editor, die Medienauswahl — und, das ist der grosse,
**Gutenberg**: ein Block zeichnet sich aus Bausteinen im Browser und nicht aus einer Zeichenkette
vom Server.*

**Drei Wege, keiner gewählt (`PR-4`):**

| | | dafür | dagegen |
|---|---|---|---|
| **A** | Der Kern gibt eine **Beschreibung** zurück — was zu zeichnen ist, nicht wie —, der Rand zeichnet | trennt sauber, macht Gutenberg möglich, `CD-1` bleibt unangetastet | **36 Dateien** ziehen um; die Beschreibung muss so genau sein, dass zwei Ränder dasselbe daraus machen |
| **B** | Der Kern behält das HTML, bekommt aber eine **Naht zum Framework** gereicht (Maskierung, Nonce, Editor) | wenig Umbau, der Kern bleibt ohne WordPress prüfbar | die Naht wächst mit jedem Bedürfnis; Gutenberg löst sie nicht |
| **C** | Es bleibt, wie es ist | nichts kostet etwas | die Grenze, die er benennt, bleibt bestehen |

⚠️ **Was zuerst zu klären ist, vor der Wahl:** *für welche Oberfläche das gilt. Für die Admin-Seite
reicht **B** vermutlich; für einen Block reicht **nur A**. Und was ein Block überhaupt ist, liegt
selbst noch quer — [D-253](../../NewConcept/90-decision-log.md) teilt drei Oberflächen,
[D-278](../../NewConcept/90-decision-log.md) schafft die Ebene ab, und
[D-547](../../NewConcept/90-decision-log.md) führt später doch wieder eine ein.*

## INF-030 · Konverter und Validatoren sind Knoten und kommen nicht als ihre Klasse an

**2026-09-05, beim Bau von [D-620](../../NewConcept/90-decision-log.md) gemessen.** *D-620 nennt die
**Typ- und Renderer-Knoten**: sie erben jetzt von `Node` und eine geladene Zeile kommt als ihre Klasse
an. **Die beiden anderen gesäten Familien tun das nicht** — `Settings > Converter` (`binary`,
`hexadecimal`, `octal`, `roman`) und `Settings > Validator` (`range`, `shape`) tragen ihre Klasse
genauso in `nodes.implemented_by`, aber diese Klassen sind keine Knotenklassen, also lädt die
Hydrierung sie als schlichtes `Node`.*

⚠️ **Nicht erfunden und nicht mitgebaut** (`PR-4`): *sein Satz war «die Renderer werden Knoten», und
die Entscheidung sagt «die gesäten Typ- und Renderer-Knoten». **Konverter und Validatoren einfach
mitzuziehen wäre die Entscheidung zu erweitern, während man sie vollzieht.*** Der Umbau ist derselbe
wie bei den Renderern (eine gemeinsame Elternklasse), also klein — offen ist nur, ob er gewollt ist.

## INF-031 · Ein Renderer ohne Knoten erbt trotzdem von Node

**2026-09-05, dieselbe Messung.** *Die Oberflächen-Renderer (`RendererRegistry::addForSurfaces()` —
die Baumzelle, die Auswahlzelle) werden **nicht** gesät: es gibt keinen Knoten zu ihnen. Sie erben
dennoch von `RendererNode`, weil sie in derselben Klassenfamilie liegen wie die gesäten.*

⚠️ **Die Alternative wäre, die Familie zu spalten** — und dann stünde die Antwort auf «wird dieser
gesät» an zwei Stellen: in der Registratur, die sie schon trifft, und in der Vererbung. *Gebaut ist
darum die ungespaltene Familie; ein Steckbrief ohne Id ist ohnehin kein Knoten, sondern das Ding, das
zeichnet.* **Falls das stört, ist es eine Zwischenklasse und keine Umstellung.**

## INF-032 · Der Vorfahrenlauf für Untertypen wurde nicht gebraucht

**2026-09-05.** *[D-484](../../NewConcept/90-decision-log.md) hat als Preis genannt, dass die
Hydrierung für einen **Untertyp** ihren Unterscheider erst aus dem Lauf die Vorfahren hoch bekommt —
«dass `Description` unter `text` ein Text ist, ergibt erst der Lauf die Vorfahren hoch».*

⚠️ **Der Preis ist nicht angefallen, weil D-620 den Umfang enger zieht:** *«das heisst nicht, dass
jeder Knoten eine eigene Klasse bekommt — es sind die gesäten Typ- und Renderer-Knoten.» **Ein
Untertyp bekommt also keine Klasse**, und damit braucht die Hydrierung keinen Vorfahrenlauf: der
Unterscheider steht in `implemented_by` oder es gibt keinen.*

⚠️ *Notiert, weil es eine **Auslegung** ist und keine Messung: D-620 sagt, welche Knoten eine Klasse
bekommen, und nicht ausdrücklich, dass ein Untertyp als sein Obertyp ankommen soll. Falls er das doch
will, ist der Vorfahrenlauf wieder fällig — und dann an einer Stelle, die je Zeile läuft (`CD-7`).*

## INF-033 · TASK-033 liegt zur Hälfte in gesperrtem Gebiet — der Renderer ist Code, die zwei Knoten sind Modell

**2026-09-05, beim Anlauf auf TASK-033 gemessen und nicht gebaut.**

Sein Wort: *«ich würde auch sagen, der Renderer hat ein Setting dialog/inline.»* Der **Code**-Teil ist
klein — die zwei Klassen unterscheiden sich in genau einem Punkt, dem Aufklappen; die eine ist 69
Zeilen und liest denselben Zeilenvorrat wie die andere. **Der Rest ist Modellarbeit:**

| Was | Wo | |
|---|---|---|
| Die zwei Knoten `chooser-dialog` (43499) und `chooser-inline` (43501) werden einer | Saat und Wegräumen | Persistenz |
| `dialog`/`inline` wird eine Angabe | ein Einstellungsknoten plus Kante | Modell |
| Wer heute auf den entfallenden Knoten zeigt | Umzug der Werte | Modell |

⚠️ **Gemessen ist der gute Teil: null Wertzeilen zeigen auf einen der beiden Knoten.** *Es hängt
nichts daran, der Umzug wäre also leer — aber die Saat und ihr Fassungszähler liegen in der
Persistenz, und wer die Klasse zusammenlegt, ohne den Knoten mitzunehmen, hinterlässt einen Namen,
den nichts mehr einlöst.*

⚠️ **Offen und ausdrücklich nicht geraten** (`PR-4`): *ob die Angabe `inline` eine eigene
Einstellungsart wird (dann gehört sie in die Aufzählung der Schlüssel) oder eine gewöhnliche
Einstellungskante auf einen Ja/Nein-Knoten. **Beides ist vertretbar, und die Wahl bestimmt, wie die
Saat aussieht** — deshalb wird sie nicht nebenbei getroffen.*

## INF-034 · `SeededFrameworkNodes` bekommt einen Kantenspeicher, den sie nicht mehr liest

**2026-09-05, bei TASK-018 entstanden und stehengelassen.**

Die Saat schrieb je Rahmenknoten **zwei** Zeilen: den Knoten und seine Vererbungskante. Seit
[D-581](../../NewConcept/90-decision-log.md) ist es eine, und der `RelationRepository` im
Konstruktor wird von keiner Zeile der Klasse mehr gelesen.

⚠️ **Warum er trotzdem steht:** *ihn zu streichen sind **58 Aufrufstellen**, die ihn der Reihe nach
übergeben — quer durch `src`, `tests` und die Prüfläufe. **Das mitten in einer Datenwanderung zu
tun, hiesse zwei Umbauten in einem Commit**, und einer davon wäre reine Formsache. Er fällt mit
TASK-032, wo die Kantenart ohnehin angefasst wird.*

## INF-035 · `labels.owner_id` nennt ihren Raum nicht — und eine Kante kann die Nummer eines Knotens tragen

**2026-09-05, an einem rot gewordenen Prüflauf gemessen, nicht hergeleitet.**

`package5-check` fragte `forOwners([$edge->id])` und bekam **sechs** Zeilen statt einer: die frische
Kante trug **dieselbe Nummer** wie ein Knoten, der zwei Zeilen zuvor entstanden war und schon fünf
Beschriftungen hatte.

⚠️ **Die Lücke ist alt, TASK-018 hat sie nur ausgelöst.** *Seit TASK-004 hat jede Tabelle ihren
eigenen Id-Raum; beide begannen bei derselben Zahl. Solange ein neuer Knoten **immer** auch eine
Vererbungskante anlegte, liefen die zwei Zähler im Gleichschritt und trafen sich selten. **Seit
D-581 ein Knoten keine Kante mehr anlegt, laufen sie verschieden schnell** — und dann treffen sie
sich.

⚠️ **Es ist derselbe Fehler, den `INF-009` an `settings.owner_id` beschrieb**, und die Abhilfe hat
[D-164](../../NewConcept/90-decision-log.md) längst benannt: *eine zweite Spalte, die den Raum
nennt, wie `changelog.owner_kind` und wie `record_values.value_ref_kind` seit TASK-005.*

⚠️ *Offen und nicht geraten (`PR-4`): **ob das eine eigene Aufgabe ist oder zu TASK-019 gehört**, wo
`labels` ohnehin zu `labels` und `label_texts` wird. Gemessen im heutigen Bestand: **null** Ids, die
Knoten und Kante zugleich sind — der Schaden ist bisher nur in Prüfläufen aufgetreten, die selbst
anlegen.*

## INF-036 · `always-on-check` ist rot, und nicht wegen dieses Umbaus

**2026-09-05 gemessen:** das Immer-Gelesene (`CLAUDE.md`, `docs/arbeitsmodell.md`, `AGENTS.md`) liegt
mit 44 761 Bytes **1 591 Bytes über der Decke** von 43 170.

⚠️ *Hier nur festgehalten, nicht behoben: **alle drei Dateien sind für diesen Auftrag gesperrt.** Der
Lauf war schon rot, bevor TASK-018 anfing, und keine seiner Zeilen berührt eine der drei.*

## INF-037 · TASK-032 hat eine unentschiedene Stelle: **woran erkennt man eine Einstellungskante, wenn `relation_type` gefallen ist?**

**2026-09-05, unmittelbar nach TASK-018 gemessen und deshalb nicht gebaut** (`PR-4`).

[D-587](../../NewConcept/90-decision-log.md) streicht `relation_type` und ersetzt es durch **eine**
Angabe — «wird mit dem Knoten gelöscht». Für die Frage, welche Kante eine **Einstellung** ist, sagt
D-587: *«ob eine Kante eine Einstellung trägt, sagt der Ast des Zielknotens und keine Spalte»*.
**Genau diese Hälfte ist überholt:** [D-622](../../NewConcept/90-decision-log.md) hält fest, *«und
eine Hälfte von D-587 ist überholt … **das sagt seit heute die Kante** ([D-621](#))»*, und
[D-621](../../NewConcept/90-decision-log.md) streicht den Ast-Automatismus.

⚠️ **Damit sagt keine Entscheidung, *woran* man es der Kante ansieht.** *Der Ast darf es nicht mehr
sein, `relation_type` gibt es nicht mehr, und `record_type` trägt nach
[D-583](../../NewConcept/90-decision-log.md) etwas anderes — wer den Wert geschrieben hat, nicht was
die Kante erklärt.*

**Warum das ein Halt ist und keine Kleinigkeit — gemessen am 2026-09-05:**

| | |
|---|---|
| `kind->isSetting()` im Kern und am Rand | **16 Stellen** |
| Verzweigungen auf `$edge->kind` überhaupt | **26 Stellen** |
| Lebende Zeilen: `setting` / `composition` / `aggregation` | **11 / 42 / 4** |
| `nodes.field_type` gesetzt (fällt mit D-621) | **39** |

⚠️ *`isSetting()` entscheidet unter anderem, **ob ein Feld im Formular gezeichnet wird**
(`Rendering`, `FormRenderer`), **ob ein Wert überhaupt geschrieben werden darf** (`DataEntry`) und
**welche Liste die Knotenseite zeigt** (`NodesScreen`). Die Frage stillschweigend durch «der Ast des
Ziels» zu ersetzen hiesse, D-621 zurückzunehmen; sie durch «Zielknoten trägt `field_type`» zu
ersetzen hiesse, eine Spalte zu benutzen, die dieselbe Entscheidung gerade streicht.*

⚠️ **Was zu entscheiden ist, in seinen Worten formuliert:** *soll die Kante eine zweite Angabe
bekommen — «diese Kante ist eine Einstellung» — neben «wird mit dem Knoten gelöscht»? Dann sind es
**zwei** Schalter an der Kante und nicht einer, und `relation_type` ist nicht wirklich gefallen,
sondern in zwei Ja/Nein-Angaben zerlegt. **Das ist eine vertretbare Antwort und braucht sein Wort**,
weil D-587 ausdrücklich von *einer* neuen Angabe spricht.*

**TASK-018 ist davon nicht berührt und steht** — die Vererbung ist eine Spalte, und die Kantentabelle
trägt nur noch Komposition, Aggregation und Einstellung.

---

## INF-039 · `node_records.created_at` steht noch, weil das Änderungsbuch erst 9 von 421 Datensätzen kennt

**TASK-015 nennt sie ausdrücklich** — *der Eigentümer: «das `created_at` ist eigentlich was fürs
Log»* — **und knüpft sie ebenso ausdrücklich an eine Bedingung:** *«`created_at` fällt erst, wenn das
Änderungsbuch Datensätze führt. Erst der neue Leser, dann die Daten.»*

**Gemessen am 2026-09-05, und die Zahl in der Aufgabe war eine ältere:**

| | |
|---|---|
| Einträge im Änderungsbuch mit `owner_kind = 'record'` | **9** |
| dazu `owner_kind = 'record_value'` | **8** |
| lebende Datensätze in `node_records` | **421** |
| Zeilen mit leerem `created_at` | **0** |

⚠️ **Die Bedingung ist damit halb erfüllt und das genügt nicht.** *Es gibt inzwischen einen
Schreiber — `DataEntry` meldet «record created» —, aber er läuft erst, seit es ihn gibt. **Für 412
Datensätze hält allein die Spalte fest, wann sie entstanden sind.** Sie fallen zu lassen hiesse,
diese Zeiten wegzuwerfen; sie aus der Spalte ins Log zu schreiben hiesse, Geschichte zu erfinden,
die niemand aufgeschrieben hat ([D-065](../../NewConcept/90-decision-log.md): Geschichte ist
eingefroren).*

⚠️ **Zu entscheiden ist eine von zwei Fragen, und keine davon ist geraten worden (`PR-4`):** *(a) die
412 Entstehungszeiten sind entbehrlich, dann fällt die Spalte sofort; oder (b) sie sind es nicht,
dann fällt sie erst, wenn jeder heutige Datensatz einen Eintrag hat — und woher der käme, sagt
niemand.* **Gemessen liest heute niemand die Spalte zur Anzeige**: `NodeRecord::$createdAt` wird
geschrieben und weitergereicht, die Entstehungszeile am Schirm kommt aus dem Änderungsbuch.
