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

### ✅ Erledigt am 2026-09-05, Schemafassung 31 — als eigener Schritt, vor TASK-019

**`labels.owner_kind` steht**, gefüllt, im eindeutigen Schlüssel und bewacht. *Das Muster ist das
vorgegebene und kein neues:* [D-164](../../NewConcept/90-decision-log.md),
[D-597](../../NewConcept/90-decision-log.md), [`package.md` §6](package.md) — «kann eine Spalte auf
mehr als eine Tabelle zeigen, nennt eine zweite Spalte den Raum».

| | vorher | nachher |
|---|---|---|
| Beschriftungen | 47 | 47 |
| Prüfsumme über Eigentümer · Pfad · Rolle · Numerus · Locale · **Text** | `4e98b857…` | `4e98b857…` |
| davon `owner_kind = node` | — | **47** |
| davon `owner_kind = relation` | — | 0 |
| davon **ohne Raum** | — | **0** |
| Eigentümer, die Knoten sind / Kanten sind / keines von beidem | 40 / 0 / 0 | unverändert |
| Nummern, die zugleich Knoten und Kante sind | 0 | 0 |

⚠️ **Der Schritt löscht nichts und schreibt keinen Text um** — er füllt eine neue Spalte, deren
voriger Wert bekanntlich leer war. *Deshalb **keine Schattenzeilen**: umkehrbar ist er durch das
Fallenlassen der Spalte, und ein Schatten hielte nichts fest, was nicht schon feststünde.*

⚠️ **Zugleich hat `labels` eine `version` bekommen** ([D-634](../../NewConcept/90-decision-log.md)):
*sie war die **einzige** Tabelle ohne Zeilennummer, und `Labels::note()` musste dem Journal `null`
hinschreiben. Die Ablage zählt sie beim Überschreiben, nicht der Aufrufer. Alle 47 Zeilen stehen auf
Version 1 — es ist nichts nachträglich erfunden worden.*

⚠️ **Drei Stellen haben dabei **geraten**, und das ist der eigentliche Fund:** *(1) `Labels::note()`
fragte «gibt es einen Knoten mit dieser Nummer? dann `node`, sonst `relation`» und schrieb die
Antwort ins Änderungsbuch; (2) `ModelEditor::clearTrash()` reichte eine **gemischte** Liste aus
Knoten- und Kantennummern an `forgetOwners()`; (3) `Residue::orphanedLabels()` fragte «weder Knoten
noch Kante», was zu **nachsichtig** war — die Beschriftung einer gelöschten Kante blieb liegen,
solange irgendein Knoten dieselbe Nummer trug. **Alle drei nennen den Raum jetzt.**

⚠️ **Und der Fehler ist im laufenden Prüflauf noch einmal aufgetreten, als Beweis:** *`package5-check`
§6 zählte **zwei** Zeilen statt einer, weil seine Abfrage `owner_kind` nicht nannte — die frisch
angelegte Kante trug wieder dieselbe Nummer wie der Knoten zwei Abschnitte darüber. **Der Filter, den
TASK-018 als Notbehelf in §5 eingesetzt hatte, ist wieder eine gezählte Zusage** (`count === 1`).*

**Neu am Netz:** [`label-space-check.php`](../../../scripts/dev/label-space-check.php) — die Spalten,
der Schlüssel, die Zahlen der Wanderung gegen die von heute, keine Zeile ohne Raum, keine Waise je
Raum, und die Gegenprobe: dieselbe Nummer als Knoten und als Kante trägt zwei verschiedene Texte.
**Mitgezogen:** `id-space-check` (Abschnitt 6 neu; `labels.owner_id` steht nicht mehr als «zeigt auf
einen Knoten» in Abschnitt 3 — *das war eine Beobachtung an Daten ohne beschriftete Kante, keine
Zusage*), `package5-check`, `package7-check`, `labels-page-save-check`, `journal-address-check`,
`cleartrash-check`, `seed-twice-check`. **Keiner ist entschärft**, einer ist wieder schärfer.

⚠️ **Zwei Einträge im Entscheidungsprotokoll fehlen und sind geschuldet** (`PR-3`): *das Protokoll ist
für diesen Auftrag gesperrt. Zu schreiben sind — «`labels.owner_kind` nach dem Muster von D-164/D-597»
und «`labels` bekommt eine Version, D-634 gilt damit auch für Beschriftungen».*

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

## INF-040 · TASK-019 ist **nicht** gebaut, und zwar aus drei gemessenen Gründen

**2026-09-05, beim Anlauf gemessen, nicht geschätzt** (`PR-4`, `PR-7`). *Gebaut wurde an diesem Tag
nur, was TASK-019 als Messung vorausschickte: `labels.owner_kind` und `labels.version` (`INF-035`).
**Die Teilung nach [D-580](../../NewConcept/90-decision-log.md) steht aus.***

**Erstens: die Teilung und der Umzug des Namens sind ein Stück und lassen sich nicht halbieren.**
*D-580 dreht die Richtung des Verweises um — `nodes.label_id` und `relations.label_id` statt
`labels.owner_id`. **Baut man nur die Umkehrung, entstehen 194 Beschriftungszeilen, die nichts
enthalten**, denn ihr einziger sprachunabhängiger Inhalt ist heute das Symbol (38 Zeilen). Baut man
nur den Namensumzug, gibt es die Tabelle nicht, in die er soll.*

**Zweitens: der Namensumzug ist der grosse Posten, und er ist jetzt gezählt.**

| | |
|---|---|
| Knoten mit Namen · Kanten mit Namen | **137 · 57** — also 194 künftige `labels`-Zeilen |
| Beschriftungen heute | **47** (38 `symbol`, 3 `form`, 3 `table`, 2 `select`, 1 `help`) |
| SQL-Stellen in `scripts/dev`, die eine Spalte `name` lesen oder schreiben | **166** |
| Prüfläufe in `scripts/dev` insgesamt | 88, davon **60** mit einer solchen Stelle |
| Zeilen in den Schattentabellen, die einen Namen tragen | `nodes_history` **20 685** · `relations_history` **25 124** |
| Index auf `nodes.name` | vorhanden |

⚠️ *Jede dieser Stellen wird zu einem Verbund über `label_id`. **Das ist mechanisch, aber es ist
nicht wenig**, und jeder der 60 Läufe muss danach einzeln grün sein (`PR-9`). Ob die Schattentabellen
den Namen **behalten** — als eingefrorene Geschichte, wie `changelog` es tut
([D-065](../../NewConcept/90-decision-log.md)) — oder mitwandern, sagt D-580 nicht.*

**Drittens: drei Fragen stehen offen, und keine davon wird beim Bauen nebenbei beantwortet.**

1. **Die von D-580 selbst benannte:** *«sind die vier Rollen Spalten in `label_texts` oder Zeilen mit
   `role_id`?»* — Spalten heissen: eine neue Rolle ist ein Schemawechsel. Zeilen heissen: eine neue
   Rolle ist eine Zeile. **Heute sind die Rollen Knoten im Modell**
   ([D-151](../../NewConcept/90-decision-log.md)).
2. **`symbol` als Spalte gegen [D-262](../../NewConcept/90-decision-log.md).** *D-580 legt `symbol`
   sprachunabhängig in `labels` — gemessen tragen **38 von 38** keine Sprache. **D-262 sagt aber
   ausdrücklich, das sei «ein Standard, keine Tatsache»**: ein Symbol, das sich je Sprache wirklich
   unterscheidet, dürfe als übersetzbar gekennzeichnet werden. *Als Spalte ist es das nicht mehr.*
3. **Die neutrale Zeile fällt.** *D-580: «Die heutigen 43 sprachlosen Labels werden zu Zeilen der
   Standardsprache.» Damit ändert sich die Rückfallkette — heute fällt sie von `de_DE` auf die
   **leere** Locale zurück, danach auf die **Standardsprache**. Gemessen ist die `en_US`, `WPLANG`
   ist leer. **Der Kern kennt die Standardsprache nicht** (`CD-1`); sie müsste vom Rand hereingereicht
   werden.*

⚠️ **Was nicht der Grund ist:** *`INF-003` — sein Vorschlag, Beschriftungen als Kanten-Datensätze zu
führen — ist hier nicht angerührt und nicht vorweggenommen. **Er denkt darüber nach**, und der Umbau
von heute steht ihm nicht im Weg: er hat der Tabelle eine Spalte gegeben, keine Struktur festgezurrt.*

---

## INF-042 · Wo die Renderer-Wahl auf Dauer hingehört

**2026-09-05, aufgefallen beim Bauen von TASK-057** ([D-642](../../NewConcept/90-decision-log.md)).
*Nicht entschieden, nicht erfunden (`PR-4`).*

**Der Renderer hat jetzt eine Kante wie jede andere Einstellung, und seine Zeile steht im
Einstellungsblock.** *Sie zeigt dort die Felder **des gewählten** Renderers — `converter` —, aber
**keinen Wähler dafür, welcher es ist**. Der Wähler steht weiterhin in einem eigenen Block darunter.*

⚠️ **Warum die Zeile ihren eigenen Wähler nicht zeichnen kann, und das ist gemessen:** *ein Wähler
entsteht in der Wertspalte aus den **unmarkierten** Kindern des Kantenziels
([D-540](../../NewConcept/90-decision-log.md)). **Alle neunzehn Knoten unter `Renderer` tragen
`field_type = setting`**, sind also markiert — die Auswahl sieht durch jeden hindurch und bietet null
Möglichkeiten an. **Die Möglichkeiten des Renderers stehen nicht im Modell, sondern in der
Registratur** (`R14a`), und dorthin greift nur der Weg über den Schlüssel.*

⚠️ **Es sind heute keine zwei Steuerelemente für dieselbe Angabe** (`R1`): *der Block bedient die
Wahl, die Zeile bedient die Felder des Gewählten. **Aber es sind zwei Orte für eine Sache**, und das
ist keine Ruhelage.*

**Die Frage an den Eigentümer, und es sind drei mögliche Antworten:**

1. **Die Renderer-Knoten hören auf, markiert zu sein** — dann zeichnet die Wertspalte den Wähler von
   selbst, und der eigene Block fällt weg. *Berührt `field_type` an neunzehn Knoten.*
2. **Die Wertspalte lernt, ihre Möglichkeiten aus der Registratur zu holen**, wenn das Kantenziel
   `Renderer` ist. *Eine Sonderregel für einen Knoten — genau das, was `CD` verbietet.*
3. **Es bleibt, wie es ist** — ein eigener Block für die eine Angabe, deren Möglichkeiten nicht im
   Modell stehen. *Ehrlich, aber der Block braucht dann eine Erklärung, die nicht «Rest» heisst.*

⚠️ *Zusammen mit `INF-019` zu lesen: was «kein Renderer» heissen soll, ist ebenfalls offen.*

⚠️ **Nachtrag 2026-09-05: Antwort 1 ist eingetreten, ohne dass jemand sie gewählt hat.** *Mit dem
Rückbau von `nodes.field_type` ([D-621](../../NewConcept/90-decision-log.md), TASK-059) tragen die
neunzehn Renderer keine Marke mehr — **die Zeile zeichnet ihren Wähler jetzt selbst, 12 statt 0
Möglichkeiten.** Offen bleibt der Rest: sechs Renderer hängen unter einem Zwischenknoten und fehlen
darum, siehe `INF-043`.*

---

## INF-043 · Der Zwischenknoten `render with label` verliert seine Durchlässigkeit

**2026-09-05, beim Rückbau von `nodes.field_type`** ([D-621](../../NewConcept/90-decision-log.md)).
*Nicht entschieden, nicht erfunden (`PR-4`).*

**`INF-042`, Antwort 1 ist gebaut, und sie wirkt: der Wähler ist zurück.** *Gemessen vorher und
nachher — `Renderer` bot **0** Möglichkeiten an und bietet jetzt **12**, `Label roles` 0 → 5,
`Converter` 0 → 4, `Validator` 0 → 2, `Orientation` 0 → 2. Und was der Eigentümer sieht, hat sich
sonst nirgends verschoben: **der Renderername je Knoten ist über alle 137 Knoten Zeile für Zeile
derselbe.***

⚠️ **Was aus den 12 fehlt, sind 6, und sie sind die wichtigen:** *`form`, `table`, `compact`,
`reference`, `chooser-dialog`, `chooser-inline` hängen unter dem Zwischenknoten `render with label`.
**[D-544](../../NewConcept/90-decision-log.md) hat sie sichtbar gemacht, und zwar über die Marke** —
auf sein Wort «table, form, compact muss wählbar bleiben, warum auch nicht?». Die Auswahl stieg durch
den markierten Zwischenknoten hindurch. **Ohne Spalte trägt er keine Marke mehr, weil keine Kante auf
ihn zeigt** — also gilt er als Möglichkeit, und seine Kinder werden nicht mehr angeboten.*

⚠️ **Was ihn heute rettet und warum das keine Ruhelage ist:** *der eigene Renderer-Block auf der
Knotenseite holt die Möglichkeiten aus der **Registratur** (`R14a`) und kennt alle neunzehn.
`render with label` fällt dort heraus, weil es keinen Renderer dieses Namens gibt. **Die Wahl
funktioniert also — aber über den Block, nicht über die Zeile**, und damit ist `INF-042` nur halb
beantwortet.*

**Drei mögliche Antworten, keine davon hier gewählt:**

1. **«Durchlässig» heisst künftig «hat sichtbare Kinder».** *Gemessen ist das falsch: `Base units`
   böte dann 14 statt 2 Möglichkeiten und `Electronic Parts` 4 statt 2 — man könnte `With prefix`
   und `Passiv` nicht mehr wählen.*
2. **Der Zwischenknoten fällt**, die sechs hängen direkt unter `Renderer`. *Dann stimmt alles von
   selbst — aber «mit Beschriftung» ist eine Aussage, die dann nirgends mehr steht.*
3. **Es bleibt beim eigenen Block**, und die Zeile im Einstellungsblock zeigt weiter nur die Felder
   des Gewählten. *Ehrlich, und `INF-042` bleibt offen.*

---

## INF-044 · `Boolean` ist heute nur noch Ziel einer Einstellungskante

**2026-09-05, beim Rückbau von `nodes.field_type`.** *Ein Befund, keine Entscheidung (`PR-4`).*

**Gemessen: von 137 Knoten geben Spalte und Kante an 136 dieselbe Antwort — und an einem nicht.**
*`Boolean` hat genau **eine** eingehende Kante, `render with label --with_label--> Boolean`, und die
ist eine Einstellungskante. Nach der Regel «jede eingehende Kante ist eine Einstellungskante» ist
`Boolean` damit selbst eine Einstellung; die Spalte sagte «nichts».*

⚠️ **[D-621](../../NewConcept/90-decision-log.md) hat für `Boolean` etwas anderes gemessen:** *«`Integer`,
`Decimal` und `Boolean` sind Ziel einer Kompositions- **und** einer Einstellungskante.» **Für `Integer`
und `Decimal` stimmt das heute noch, für `Boolean` nicht mehr** — die Kompositionskante ist seither
weg. Die Entscheidung ist davon nicht berührt; die Zahl darin ist es.*

⚠️ **Was daran unruhig ist, und es ist der eigentliche Punkt:** *der Charakter eines Knotens kippt,
wenn irgendwo im Modell eine **fremde** Kante entsteht oder verschwindet. Hängt jemand morgen ein
Boolean-Feld an einen Modellknoten, ist `Boolean` wieder «Modell». **Das ist die Kehrseite von «die
Kante sagt, was etwas hier ist»**: eine Frage nach dem Knoten allein hat streng genommen keine
Antwort, und die Stellen, die sie trotzdem stellen — heute genau eine, der Behälter aus
[D-546](../../NewConcept/90-decision-log.md) — bekommen eine, die sich bewegen kann.*

⚠️ *Ausgewirkt hat es sich nicht: der Renderername je Knoten ist über alle 137 unverändert.*

---

## INF-045 · Eine Wanderung kann die Schemafassung nicht überholen

**2026-09-05, beim Rückbau von `nodes.field_type`. Ein Werkzeugbefund, teuer bezahlt.**

**Der erste Entwurf sicherte die 39 Marken in einem Skript daneben — Schattenzeile, Änderungsgruppe,
Version — und kam nie zum Zug.** *Ein Wanderungsskript beginnt mit `require wp-load.php`, und **das
Laden von WordPress hebt die Schemafassung**. Als die erste eigene Zeile lief, war die Spalte schon
gelöscht. Das Skript meldete «die Spalte ist schon weg — nichts zu tun», und das war die Wahrheit.*

⚠️ **Der Schaden ist begrenzt und wird nicht beschönigt** (`PR-7`): *die lebenden Marken liessen sich
aus den Kanten wiederherstellen — 39 von 39, weil die Ableitung genau sie reproduziert. **Was
endgültig weg ist, sind die 190 `field_type`-Werte in `nodes_history`**, weil die Spalte dort im
selben Zug fiel. Sie sind gegenstandslos, seit ein Knoten die Angabe nicht mehr trägt; aufgeschrieben
steht es trotzdem, weil ein Verlust, den niemand nennt, ein Verlust ist, den niemand findet.*

⚠️ **Die Lehre ist allgemein und gilt für jede weitere Spalte, die fällt:** *was gesichert werden
muss, gehört **in den Fassungsschritt selbst** und nicht in ein Skript daneben. Ein Skript läuft auf
dieser einen Installation, die Fassung läuft auf jeder — und ein Sichern, das die Wanderung überholen
kann, ist keines. Fassung 33 macht es jetzt so.*

⚠️ **Nachtrag: [D-644](../../NewConcept/90-decision-log.md) sagt mehr, als hier gebaut ist, und der
Rest ist ausdruecklich offen.** *Sein Wort war «genau 1, bitte so umsetzen», und Antwort 1 lautet
vollstaendig: «dann zeichnet die Wertspalte den Waehler von selbst, **und der eigene Block faellt
weg**». **Die erste Haelfte ist gebaut, die zweite nicht** — und der Grund ist der Befund oben:
**faellt der Block heute, verliert der Eigentuemer die sechs Renderer unter `render with label`**,
weil der Weg ueber das Modell sie nicht mehr erreicht und nur der Block sie noch aus der Registratur
holt. **Den Block zu loeschen waere eine Entscheidung ueber diese sechs, und die ist nicht getroffen**
(`PR-4`). Der Block faellt, sobald `INF-043` beantwortet ist — nicht vorher.*

---

## INF-046 · Eine Beschriftung hat keinen `path` mehr — und was das kostet

**2026-09-05, beim Bauen von TASK-019 gemessen** (`PR-4`, `PR-7`).

**`labels.path` adressierte eine Stelle *innerhalb* eines Eigentümers** ([D-158](../../NewConcept/90-decision-log.md),
[D-413](../../NewConcept/90-decision-log.md)) — «eine von mehreren Prüfungen an demselben Knoten».
**Mit dem umgedrehten Verweis aus [D-580](../../NewConcept/90-decision-log.md) gibt es dafür keine
Stelle mehr:** ein Knoten zeigt mit **einer** `label_id` auf **eine** Beschriftung, und die hat
keinen Platz für ein «welches darin».

⚠️ **Gemessen, bevor etwas fiel: keine einzige der 52 Beschriftungen trug einen Pfad.** *Es ist
also nichts verlorengegangen — verloren ist die **Möglichkeit**, und die stand in einer Entscheidung.*

⚠️ **Die Wanderung rät hier nicht** (`PR-4`): *fände sie eine Zeile mit Pfad, bricht sie ab und lässt
alles stehen. Auf einer anderen Installation kann es sie geben.*

**Die offene Frage:** *soll eine Stelle innerhalb eines Knotens eigene Beschriftungen tragen können —
und wenn ja, wie, wenn der Verweis von aussen kommt?* Zwei Formen liegen nahe und **keine ist
gewählt**: eine eigene `label_id` an der Stelle selbst (dann ist die Stelle ein Ding mit Identität),
oder eine dritte Spalte in `label_texts`. **Nicht entschieden, nicht gebaut.**

---

## INF-047 · Zwei Wächter, die sich gegenseitig rot machen — und einer, der es schon vorher war

**2026-09-05, beim Grünziehen nach TASK-019 gemessen. Keiner der drei Befunde hängt am Namensumzug.**

**Erstens: `scaffold-check` verschiebt `Color`, und `inheritance-column-check` misst genau das.**
*Gemessen: läuft `scaffold-check`, steht der Typknoten `Color` danach auf Stelle 14 statt 12 unter
`Data Types`. `inheritance-column-check` vergleicht `sort_order` gegen die Aufzeichnung von TASK-018
(`taxmod_task018_shape`) und wird davon rot — **jedes Mal, und dauerhaft**, denn die Aufzeichnung ist
ein Standbild. **Nacheinander laufen die beiden nicht zusammen.** Zurückgestellt und nicht
entschärft: der Knoten steht wieder auf 12.*

**Zweitens: `unitvalue-check` zeichnet `2.7 kilo` statt `2.7 kilo Ohm`, und der Grund ist gemessen.**
*Das Feld `einheit` zeigt auf `Base units`, und **dieser Knoten trägt die Renderer-Wahl `table`** —
Datensatz 2233, angelegt am 2026-08-30. Der `table`-Renderer zeichnet für einen einzelnen Verweis
nichts. **Es ist kein Beschriftungsfehler:** dieselbe Prüfung bestätigt zwei Zeilen darüber, dass
`Ohm` das Symbol `Ω` und die Rolle `form` den Namen `Ohm` liefert. Was zu entscheiden wäre: soll ein
Knoten wie `Base units` überhaupt eine Renderer-Wahl tragen, wenn ein Feld auf ihn zeigt?*

**Drittens: `always-on-check` und `rules-index-check` waren vorher rot und sind es geblieben.**
*Das Immer-Gelesene liegt 2266 Bytes über der Decke, und das Regelverzeichnis nennt eine andere Zahl
als die 303 Regeln im Baum. **Beide betreffen gesperrte Dateien** (`CLAUDE.md`, `AGENTS.md`,
`arbeitsmodell.md`) und wurden hier nicht angefasst.*

---

## INF-048 · Die Renderer-Wahl kommt aus der Registratur — was dabei auffiel

**2026-09-05, beim Fall des eigenen Renderer-Blocks** ([D-644](../../NewConcept/90-decision-log.md),
sein Wort: *«Renderer-Box ist übrigens immer noch da, die muss weg!»*). *Befunde, keine
Entscheidungen (`PR-4`, `PR-7`).*

**Gebaut ist, was er gesagt hat, und aus zwei Quellen, die einander nicht ersetzen:** *die **Menge**
kommt aus der Registratur ([D-603](../../NewConcept/90-decision-log.md), `eligibleFor()` am Knoten),
die **Gestalt** aus der Tiefe (`R63`, [D-109](../../NewConcept/90-decision-log.md)). `INF-042` und
`INF-043` sind damit beantwortet: der eigene Block ist weg, und `render with label` fällt aus der
Wahl, weil ihn keine Renderer-Klasse umsetzt — **ohne dass er gelöscht oder verschoben wurde**.*

⚠️ **Erstens: der zweite Fall von `R63` ist nirgends gebaut.** *«Several levels → tree view» hat
heute keinen Fall — jede Menge, die dieser Bildschirm anbietet, ist flach, weil sie entweder aus der
Registratur kommt (eine Ebene) oder aus dem Abstieg unter das Kantenziel, der die Ebenen einebnet.
**Die Ableitung wird also nirgends falsch, sie wird nur nirgends gebraucht** — und ob sie fehlt, zeigt
sich erst an einem Ziel, das sie braucht. Nicht erfunden, aufgeschrieben.*

⚠️ **Zweitens: `reference` ist beim Bearbeiten nirgends wählbar, und das war schon vorher so.**
*Von den sechs Renderern unter `render with label` sind fünf wieder zu erreichen — `form`, `table`,
`compact` an einem Knoten ohne eigenen Typ, `chooser-dialog` und `chooser-inline` an einem
Knotenverweis. **`reference` unterstützt nur `Purpose::Display`**, wird also von `eligibleFor(…, Edit)`
nie zurückgegeben. Der eigene Block hat ihn aus demselben Grund nie angeboten; hier geht nichts
verloren. Was fehlt, ist die Entscheidung, ob ein reiner Anzeige-Renderer überhaupt wählbar sein soll.*

⚠️ **Drittens: ein Knoten verliert seine Wahl, und es ist genau einer.** *`User reference` hat
**keinen einzigen** tauglichen Renderer — vorher fiel die Verengung an ihm aus und die Zeile bot
ersatzweise alles an, was im Baum stand. **Jetzt ist die Zeile leer und gesperrt**, und das ist
[R28](../../NewConcept/30-renderer.md#r28r32--the-rule-complete)s Antwort auf «nichts zu wählen» —
dieselbe, die `validator` schon bekommt. **Gemessen an allen 137 Knoten: einer hat keinen tauglichen
Renderer, 136 haben mindestens einen.***

⚠️ **Viertens, und das ist der teuerste Fund: die Zeile zeigte nicht, was gesetzt ist.** *Der
gespeicherte Wert ist seit [D-583](../../NewConcept/90-decision-log.md) ein **Datensatz** des
gewählten Renderers, die Wahlliste steht aber auf **Knoten-Ids** — der Verweis fand sich dort nie
wieder, und die Liste stand auf ihrem ersten Eintrag. **Der nächste Klick hätte den geschrieben.**
Aufgefallen ist es erst, als der eigene Block wegfiel, denn der ging über den **Namen**. Behoben,
indem die Zeile den geltenden Renderer über seinen Namen auf seinen Knoten abbildet und ihn so
vorwählt — **gezeigt, nicht geschrieben** ([R33c](../../NewConcept/30-renderer.md)). ⚠️ *Was hier
nicht entschieden ist: **ob eine Wertzeile allgemein einen Datensatzverweis auf seinen Knoten
abbilden soll**. Heute tut es nur die Renderer-Zeile, weil nur sie einen eigenen Satz anlegt.*

---

## INF-049 · Die Renderer-Wahl hat eine eigene Klasse — was dabei auffiel

**2026-09-05, beim Bau von [D-647](../../NewConcept/90-decision-log.md) und
[D-648](../../NewConcept/90-decision-log.md).** *Befunde, keine Entscheidungen (`PR-4`, `PR-7`).*

**Gebaut ist, was er gesagt hat:** *die Wahl geht **von den Knoten aus und siebt mit der
Registratur**, sie steht **flach**, sie zeigt die **`select`-Beschriftung** mit Rückfall auf den
Namen, und gespeichert bleibt der **Verweis**. Der Wähler selbst bekommt keinen Knoten und steht als
elfter interner Renderer in der Registratur. Der Wächter `renderer-choice-mask-check` geht denselben
Weg wie vorher und ist unverändert grün, jetzt mit vier Zusagen mehr.*

⚠️ **Erstens: die `select`-Rolle ist verkabelt, aber im Bestand trägt sie niemand.** *Gemessen am
2026-09-05: **3 `select`-Beschriftungen im ganzen Modell gegen 195 Namen — und keine der drei sitzt
auf einem Renderer-Knoten** (es sind `Kondensator` in zwei Sprachen und `Read Only`). **Alle 17
Einträge der Liste kommen also heute über den Rückfall.** Das ist kein Fehler — der Rückfall ist
gewollt —, aber es heisst, dass eine Zusage am Rand die Rolle nicht prüfen kann, ohne sein Modell zu
verändern. **Deshalb prüft sie der Kern** (`RendererChoiceTest`), und der Rand vergleicht nur noch,
dass die Liste dieselbe Auflösung zeigt wie die Beschriftungskette. Wer eine `select`-Beschriftung
auf einen Renderer-Knoten setzt, sieht sie sofort.*

⚠️ **Zweitens: die Liste sortiert nach Zeichen und nicht nach Sprache.** *Sie stand vorher nach
Knotennamen und steht jetzt nach der angezeigten Beschriftung — beide Male mit `asort`. Solange alle
Einträge Kennungen in Kleinbuchstaben sind, fällt nichts auf; **eine Beschriftung mit grossem
Anfangsbuchstaben stünde vor allen kleingeschriebenen**, und Umlaute stünden hinten. Nicht geändert,
weil das eine Frage an die ganze Oberfläche ist und nicht an diese eine Liste.*

⚠️ **Drittens: die Vorauswahl ging über den angezeigten Text und hätte still aufgehört zu
funktionieren.** *Sie verglich den Namen des geltenden Renderers mit den **Werten** der Liste —
solange dort Knotennamen standen, traf das zu. **Mit der `select`-Beschriftung hätte sie nichts mehr
gefunden, und die Liste wäre auf ihren ersten Eintrag zurückgefallen** — genau der Fehler, den
`INF-048` viertens beschreibt, einmal mehr. Sie geht jetzt denselben Bogen wie die Liste selbst:
Kennung → Klasse → Knoten. **Aufgefallen ist es nur, weil beides in eine Klasse wanderte**; verteilt
hätte es niemand nebeneinander gesehen.*

⚠️ **Viertens: die Zahlen aus [D-648](../../NewConcept/90-decision-log.md) sind jetzt Zusagen, und
sie stehen bei 17 zu 11.** *Vorher 27 Kennungen gegen 17 Knoten; der Wähler macht daraus 28 gegen 17.
**Beide Richtungen sind bewacht** — jeder wählbare Renderer hat einen Knoten mit seiner Klasse, und
kein interner hat einen. Vorher war beides nur gemessen.*

---

## INF-050 · Der zweite Löschknopf fragt nicht

**Aufgefallen beim Bauen von TASK-037** ([D-604](../../NewConcept/90-decision-log.md)).

⚠️ **Es gibt zwei Wege in den Papierkorb, und nur einer fragt jetzt.** *`trash` — «der Knoten und
alles darunter» — bekommt den Dialog. **`trash_node` — «die Kinder rücken zum Grosselternknoten
auf» — parkt den Knoten genauso und fragt nichts.* Das ist heute nicht falsch: sein Ergebnis ist die
Hälfte, die D-604 mit «nein» ausdrücklich erlaubt — die Verwendungen bleiben stehen und zeigen ins
Leere, und seit TASK-038 ist das am Feld sichtbar. **Aber der Benutzer hat es nicht gewählt**, und
genau das ist der Satz seiner Entscheidung.

⚠️ **Nicht geraten, weil zwei Wege denkbar sind und beide etwas kosten:** *derselbe Dialog mit einem
dritten Knopf («aufrücken und Verwendungen mitnehmen») — dann hat ein Dialog drei Antworten, und
zwei davon unterscheiden sich in etwas, das mit den Verwendungen nichts zu tun hat. Oder ein zweiter
Dialog am zweiten Knopf — dann steht dieselbe Frage zweimal auf der Seite.*

⚠️ **Gemessen am 2026-09-05, damit die Frage eine Grösse hat:** *von 137 lebenden Knoten sind **22**
Ziel einer benannten Kante, **5** davon mehr als einmal; die schwerste ist `Text` mit **26**
Verwendungen. **Für 115 Knoten ändert sich durch TASK-037 nichts** — der Dialog erscheint nur dort,
wo etwas zerbrechen kann.*

---

## INF-051 · `relation_records.path` ist **kein** Spiegel von `relation_id` — TASK-002 angehalten

**Aufgefallen beim Anfangen von TASK-002**, am 2026-09-05. *Die Aufgabe verlangt, die Spalte zu
streichen; sie heisst dort «reiner Spiegel von `relation_id`». **Nachgemessen ist sie das nicht**,
und der Unterschied ist nicht kosmetisch: er trägt die Adresse einer Einstellung **an einer
Verwendungsstelle**.*

⚠️ **Woran die alte Messung vorbeigesehen hat: sie hat nur die lebende Tabelle gezählt.**

| Gemessen am 2026-09-05 | |
|---|---|
| lebende Zeilen, in denen `path` von `relation_id` abweicht | **0 von 103** — daher die Lesart «Spiegel» |
| lebende Zeilen mit mehrteiligem Pfad | **0** |
| **Schattenzeilen, in denen `path` abweicht** | **1119 von 5569** |
| **Schattenzeilen mit mehrteiligem Pfad** | **1117** — die jüngste von heute |

*Der Spiegel ist also nur der **augenblickliche** Zustand einer Tabelle, in der gerade keine
Einstellung an einer Verwendungsstelle gesetzt ist.*

⚠️ **Und der Kern schreibt den zweiteiligen Pfad heute, an drei Stellen.** *Das ist kein Rest,
sondern der gebaute Mechanismus aus [D-611](../../NewConcept/90-decision-log.md):*

| Stelle in `DataEntry` | Pfad |
|---|---|
| `putSettingAtUseSite()` | `Verwendungsstelle . Einstellungskante` |
| `clearSettingAtUseSite()` | derselbe, zum Löschen |
| `createPartAt()` | die ganze Kette von aussen nach innen, geprüft |

*Und beide Leser entscheiden **am Pfad**, nicht an `relation_id`: `$gesucht[$wert->path]`.*

⚠️ **Was ein Streichen kostet, ist deshalb kein Aufräumen, sondern ein stiller Verlust.** *Ohne die
Spalte fallen «diese Einstellung, überall» und «diese Einstellung, nur an dieser Verwendungsstelle»
auf **dieselbe Zeile** — dieselbe `node_record_id`, dieselbe `relation_id`, derselbe `locale`. Die
zweite überschriebe die erste, ohne dass irgendetwas rot würde.*

⚠️ **Das Konzept sagt dasselbe, und es ist die geltende Fassung** (`PR-10` — zitiert, nicht
erinnert). [D-232](../../NewConcept/90-decision-log.md) hat [D-133](../../NewConcept/90-decision-log.md)
abgelöst: der **Ast** entscheidet, wo ein Wert liegt, nicht die Multiplizität. Und
[`Storage.php`](../../../src/Core/Model/Storage.php) hält den dritten Fall fest:

> *`InsideTheRecord` — «No instances: the value sits in the holder's record, **addressed by path**».*

Dazu [D-374](../../NewConcept/90-decision-log.md), über den zusammengesetzten Typ, der noch nicht
speichern kann ([D-375](../../NewConcept/90-decision-log.md)):

> *«[D-220] … answered with **those are not two fields, they are members of one value**, stored
> **inside** the record by path ([D-133], [D-134]). So `2.7 kΩ` is three rows.»*

**ENTSCHEIDUNG ERFORDERLICH: JA.** *Die Frage, die TASK-002 selbst schon gestellt und die nie jemand
beantwortet hat — «bekommt eine Komposition mit Multiplizität 1 immer ihren eigenen Datensatz?» — ist
genau diese. **Sagt er ja**, dann liegt kein Wert mehr im Satz des Halters, `Storage::InsideTheRecord`
verliert seinen Sinn, und die Spalte kann fallen — dann aber zusammen mit der Adresse der
Verwendungsstelle, die einen **anderen** Träger braucht. **Sagt er nein**, bleibt die Spalte und
TASK-002 wird gestrichen statt verschoben.*

⚠️ *Nicht geraten und nichts angefasst (`PR-4`). Die Spalte steht unverändert; gebaut ist nur der
Wächter [`path-check.php`](../../../scripts/dev/path-check.php), und er hält diesen Zustand fest,
statt ihn vorwegzunehmen.*

---

## INF-052 · Wer sonst noch die Kantenart nennen müsste

**Aufgefallen beim Bauen von TASK-053** ([D-618](../../NewConcept/90-decision-log.md)).

⚠️ **`addField()` nimmt die Art jetzt als Angabe entgegen, und die Maske gibt sie an. Der Rest tut
es nicht.** *Gemessen: **rund 140 Aufrufe** von `addField()` im Baum — Saatgut, Gerüste, Wächter,
Tests. Sie alle lassen die Angabe weg, und für sie leitet der Akt die Art weiter aus dem Zielast ab.*

⚠️ **Der Rückfall ist bewusst und benannt, aber er ist nicht die Entscheidung.** *D-618 sagt «der
Benutzer legt fest»; ein Wächter ist kein Benutzer, ein Saatgutlauf auch nicht. **Offen ist, ob
diese ~140 Stellen ihre Art nennen sollen** — dann fällt die Ableitung ganz — **oder ob der Rückfall
für Nicht-Benutzer bleibt.** Das ist eine Aufgabe und keine Nebenbemerkung; geraten wird sie nicht
(`PR-4`).*

---

## INF-053 · Der neue Renderer-Knoten kommt auf keiner zweiten Anlage an

**Aufgefallen beim Bauen von [D-649](../../NewConcept/90-decision-log.md).**

⚠️ **Der Renderer `user` ist registriert, und die Saat legt seinen Knoten deshalb von selbst an —
aber nur, wenn sie überhaupt noch einmal läuft.** *`RenderingScaffold` merkt sich eine Fassung und
kehrt nicht zurück, solange die Zahl gleich bleibt. **Auf diesem Rechner ist der Knoten da**, weil
`rendering-scaffold-check` `import()` direkt ruft; **eine frische Anlage bekäme ihn nicht**, und der
Wähler zeigte einen Renderer, den niemand speichern kann.*

⚠️ **Die Zahl steht in einer Datei, die während dieser Arbeit einem anderen Agenten gehört**
(`src/WordPress/Persistence/`). *Sie ist nicht geraten und nicht angefasst; **es ist eine Zahl, kein
Entwurf** — sie muss um eins steigen, sobald die Datei frei ist. Ein Wächter sähe es nicht: er läuft
gegen eine Datenbank, in der der Knoten schon liegt.*

---

## INF-054 · `read_only` hat an einer Verwendungsstelle noch keine Kante

**Gemessen am 2026-09-05, beim Bauen von [D-650](../../NewConcept/90-decision-log.md).**

⚠️ **Die Regel «gesperrt heisst: der angemeldete Benutzer» ist gebaut und wird von der Kette
beantwortet — aber der Weg, `read_only` an einer Verwendungsstelle **zu setzen**, ist noch nicht
derselbe wie beim Renderer.** *Gemessen: `settingRelationId(read_only)` antwortet **0**, während
dieselbe Frage für `renderer` eine Kante nennt. Der Auflösungsweg liest die Angabe also, wo sie
steht; die Naht, über die die Maske sie schreibt, ist für diesen Schlüssel nicht aufgeschrieben.*

⚠️ *Damit ist die eine Hälfte von D-650 heute nur über eine von Hand gesetzte Angabe zu erreichen.
**Nicht erfunden und nicht nachgezogen** (`PR-4`) — es ist dieselbe Frage, die TASK-052 für den
Renderer beantwortet hat, einen Schlüssel weiter.*

---

## INF-055 · Das Wort für die gesperrte Benutzerwahl fehlt am Rand

**Beim Bauen von [D-650](../../NewConcept/90-decision-log.md).**

⚠️ **Der Kern sagt, *dass* gesperrt ist und *warum* — mit einem Schlüssel, nicht mit einem Satz**
(`AR-2`): `user-ref-picker-missing`. *Bis der Rand ihn übersetzt, steht der Schlüssel selbst im
Hinweistext — sichtbar falsch statt geraten, dieselbe Haltung wie bei «own» und «inherited»
([OQ-087](../../NewConcept/91-open-questions.md)).*

⚠️ *Es ist dieselbe offene Naht wie dort und keine neue; hier steht sie, damit sie beim Sammeln nicht
untergeht.*

---

## INF-052 · `moveSubtree()` ist leer geworden und sollte fallen

**Aufgefallen beim Bauen von TASK-001**, am 2026-09-05.

⚠️ **Die Methode schrieb den Weg jedes Nachfahren um. Es gibt keinen umzuschreiben.** *Seit
Fassung 35 wird der Weg beim Lesen aus `parent_node_id` gerechnet, und das hat der Aufrufer schon
gesetzt, bevor er hierherkommt. **Ein Umzug ändert genau eine Zeile** — die des umgezogenen
Knotens.*

⚠️ **Sie steht trotzdem noch da, und der Grund ist banal:** *ihr Aufrufer ist
[`ModelEditor`](../../../src/Core/Service/ModelEditor.php), und der war für diesen Durchgang
gesperrt, weil ein zweiter Agent darin gearbeitet hat. Sie zu streichen heisst, zwei Aufrufe dort
zu entfernen und `NodeRepository` zu kürzen — ein Handgriff, aber einer in einer fremden Datei.*

⚠️ *Was **mit** ihr gefallen ist und keine eigene Aufgabe braucht: der Versionszähler auf allen
Nachfahren. Sein Grund stand in ihrem eigenen Kommentar — «`save()` writes name and path together,
so a stale form could rename a node and write its old path back» — und ein veraltetes Formular kann
keinen Weg mehr zurückschreiben, weil keiner geschrieben wird. **Fünfhundert unveränderte Zeilen in
den Schatten zu schreiben, hiesse die Geschichte mit Nichts zu füllen.***

---

## INF-053 · Zwei Wächter vergleichen mit einer eingefrorenen Zahl auf seinen Bestand

**Aufgefallen beim Bauen von TASK-001**, am 2026-09-05 — *und es ist genau der Mangel, den
[`waechter-bestand.md`](waechter-bestand.md) als «feste Gleichheit auf eine Menge, die er jederzeit
vergrössern darf» beschreibt.*

⚠️ **`inheritance-column-check` Abschnitt 6 vergleicht mit den Zahlen der Wanderung von TASK-018** —
137 Knoten, 136 Kanten, eine feste Tiefenverteilung. *Am 2026-09-05 ist er rot geworden, weil **ein**
Knoten dazukam (`user`, aus [D-649](../../NewConcept/90-decision-log.md)). **Nichts ist kaputt**; die
Zahl ist nur nicht mehr die von damals.*

⚠️ **Der erste Entwurf von `path-check` hatte denselben Fehler**, und deshalb steht das hier: *er
verglich die Prüfsumme über alle Wege mit der, die die Fassung hinterlassen hatte — und war beim
ersten Lauf rot, weil in derselben Stunde Knoten entstanden und vergingen. **Er fragt jetzt eine
Invariante statt eines Standes:** der gerechnete Weg und der Aufstieg über `parent_node_id` sagen
dasselbe, und die Wanderung hat ihren Rückweg hinterlassen. Das erneuert sich mit seinem Bestand.*

**ENTSCHEIDUNG ERFORDERLICH: NEIN, aber eine Wahl:** *entweder Abschnitt 6 auf dieselbe Art
umschreiben, oder die Zahlen bei jeder bewussten Änderung nachziehen. **Das zweite ist die
Einladung, einen roten Wächter für normal zu halten** — und dann übersieht man den nächsten.*

---

## INF-054 · Zwei abgelaufene Wanderungsskripte lesen eine gefallene Spalte

**Aufgefallen beim Bauen von TASK-001.** *Sie stehen **nicht** im Randlauf und sind deshalb still —
sie würden erst beim Aufruf auffallen:*

| Skript | Was es liest | Stand |
|---|---|---|
| [`field-type-drop-migrate.php`](../../../scripts/dev/field-type-drop-migrate.php) | `nodes.path` **und** `nodes.field_type` | *beide Spalten sind gefallen (Fassung 33 und 35) — das Skript hat nichts mehr zu tun* |
| [`minmax-specialize.php`](../../../scripts/dev/minmax-specialize.php) | `nodes.path`, dazu ein festes Pfadmuster `1.40768.%` | *einmaliger Lauf, gelaufen* |

⚠️ *Nicht angefasst, weil ein abgelaufenes Wanderungsskript wegzuwerfen eine Entscheidung ist und
kein Aufräumen (`PR-4`): **es ist der Beleg dafür, wie die Daten dorthin kamen, wo sie sind.***

---

## INF-057 · Gehoert eine Abbildung dem Renderer oder dem Feld?

⚠️ *Hiess beim Schreiben INF-055 und traegt seit dem Zusammenfuehren die 057: waehrend dieser Arbeit
sind INF-055 und INF-056 parallel vergeben worden. Die Commit-Nachricht nennt noch die alte Nummer.*

**Aufgefallen beim Bauen der Renderer-Einstellungen.** *Seit der Satz des gewaehlten Renderers seine
Einstellungen liefert, stellt sich die Frage, fuer **welche** Schluessel das gelten soll.*

**Gemessen am 2026-09-06** — was heute an Renderer-Saetzen haengt:

| Renderer | Schluessel | Wert |
|---|---|---|
| `compact` | `orientation`, `with_label`, `label_role` | `horizontal`, `1`, `form` |
| `reference`, `table`, `chooser-inline`, `chooser-dialog` | `with_label` | `1` |
| `slider`, `checkbox` | `converter` | `hexadecimal` |

⚠️ **Die ersten beiden Zeilen sind eindeutig: das ist, *wie* ein Renderer zeichnet.** *Die dritte
nicht.* **Gaelte der Satz des Renderers auch am Feld, stuende jede Ganzzahl mit Schieber als Hexzahl
da** — `converter-check` sagt es beim Versuch sofort: *«die 12 steht als 12 da»* wurde rot.

⚠️ *Gebaut ist darum nur die enge Fassung: **der Satz des gewaehlten Renderers gilt fuer den
Behaelter eines Knotens**, nicht fuer jedes Feld, das denselben Renderer benutzt. Das behebt seinen
Befund («compact mit horizontal gewaehlt, gerendert wird vertikal») und entscheidet die Frage nicht.*

**ENTSCHEIDUNG ERFORDERLICH: JA.** *Entweder ist `converter` eine Eigenschaft des **Feldes** — dann
sind die zwei gemessenen Zeilen an `slider` und `checkbox` an der falschen Stelle und gehoeren
weggeraeumt —, oder er ist eine des **Renderers**, dann gilt er fuer alles, was ihn waehlt, und die
zwei Zeilen sind eine Aussage ueber jede Ganzzahl mit Schieber.*

## INF-056 · ERLEDIGT — es war meine Tabelle, nicht der Kode

**2026-09-06, gemeldet und am selben Tag aufgeklaert.** *Er sah in **meiner** Uebersicht, dass `email` die Renderer `datetime` und `color` bekomme, und markierte es als falsch — zu Recht. **Der Fehler war die Darstellung:** ich hatte drei Zeilen in eine gequetscht («`mailto` · `datetime` → `datetime` · `color` → `color`»), und das liest sich wie eine Aufzaehlung fuer `email`. Richtig ist: `email` → `mailto`, `datetime` → `datetime`, `color` → `color`.*

⚠️ **Der Kode war nie betroffen** — die vier Messungen unten haben von Anfang an dagegen gesprochen, und ich habe sie trotzdem als «nicht nachvollzogen» abgelegt, statt zuerst meine eigene Tabelle nachzulesen.

⚠️ **Vier Messungen, und keine reproduziert es:**

| gemessen | Ergebnis |
|---|---|
| `ColorRenderer::handles()` | `color` — sonst nichts |
| `DateTimeRenderer::handles()` | `datetime` — sonst nichts |
| alle elf Typen × beide Zwecke | **kein Fall**, in dem `color` oder `datetime` bei einem fremden Typ steht |
| Typknoten `Email` | angeboten wird `mailto` |

⚠️ **Die Stelle, an der es trotzdem schiefgehen kann, und sie ist gefunden:** *`RendererRegistry::eligibleFor()`
antwortet **ohne** Typangabe mit den Rahmen (`form`, `table`, `compact`, `node`) statt mit nichts.
Und der Waehler wird in `Rendering` mit `typeOfNode($knoten)` gerufen — **dem Typ des Knotens, auf
dessen Seite man steht**, nicht dem des Feldes, um das es geht. **Wo eine Zeile fuer ein Feld
gezeichnet wird, waere das der falsche Typ.***

⚠️ **Was davon bleibt, obwohl der Anlass wegfiel** — *die Stelle oben ist unabhaengig von seinem
Befund eine echte Schwachstelle: **eine Auswahl ohne Typangabe antwortet mit den Rahmen statt mit
nichts**, und der Waehler bekommt den Typ des Knotens, auf dessen Seite man steht. Solange nur
Knotenseiten gezeichnet werden, faellt es nicht auf. **Es ist als Aufgabe wert, nicht als Fehler
gemeldet** — kein Nutzer hat es je gesehen.*

⚠️ **Und die Lehre gehoert mir, nicht ihm:** *ich habe seine Meldung eine Stunde lang gegen den Kode
gemessen, statt zuerst nachzusehen, **was ich ihm gezeigt hatte**. Vier Messungen sprachen dagegen,
und die naheliegendste Erklaerung — meine eigene Zeile ist falsch gesetzt — kam zuletzt.*

⚠️ *Gehoert zu [D-658](../../NewConcept/90-decision-log.md) (jeder einfache Typ hat seine eigenen
Renderer) und [D-603](../../NewConcept/90-decision-log.md) (die Registratur sagt, was taugt).*

---

## INF-058 · `field-hide-check` nagelt einen Wert fest, den er selbst aendern darf

⚠️ *Hiess beim Schreiben INF-056, aus demselben Grund wie INF-057.*

**Gemessen am 2026-09-06, und nicht von dieser Arbeit verursacht** — *nichts an den Datensaetzen
ruehrt an `relations.hide`.*

Der Waechter liest die **letzte** eigene Kante von `Prefixes`, merkt sich ihr `hide` und behauptet
dann: *«das Feld ist zunaechst sichtbar»*. **Heute steht dort `1`**, also faellt die Zusage — und mit
ihr die zweite, die den Symbolwechsel `hidden → visibility` prueft, weil sie von `0` ausgeht.

⚠️ **Es ist genau die Fehlerform, die `renderer-choice-check` schon einmal an sich selbst gefunden
hat:** *«eine Pruefung, die einen benutzerveraenderlichen Wert festnagelt, wird rot, sobald jemand
die Funktion benutzt»* — dort waren es die Renderer-Namen, hier ist es ein Schalter, den der
Eigentuemer umgelegt haben kann. **Der Waechter kann «jemand hat versteckt» nicht von «das Verstecken
ist kaputt» unterscheiden.**

**ENTSCHEIDUNG ERFORDERLICH: NEIN, aber eine Wahl:** *entweder setzt der Waechter den Ausgangszustand
selbst (`hide = 0`) und stellt ihn danach wieder her — er tut das Zurueckstellen ohnehin schon —,
oder er prueft den **Wechsel** statt des Anfangs. **Das Zweite ist die staerkere Zusage**, weil sie
ohne jede Annahme ueber seinen Bestand auskommt.*

⚠️ *Nicht angefasst, weil ein fremder roter Waechter mitten in einer anderen Arbeit stillgestellt zu
werden das ist, was `PR-9` verhindert.*

---

## INF-059 · `display_size` wird an **jedem** Knoten angeboten, nicht nur an `text`

[D-659](../../NewConcept/90-decision-log.md) erklaert die Anzeigebreite **am Typ `text`**. Gebaut ist
sie als gewoehnlicher Schluessel des Rahmenwerks mit fester Form (eine ganze Zahl), und
`SettingKey::applyingTo()` bietet damit **jedem** Gegenstand eine Zeile an — auch einem `int`, einem
`bool`, einem Knoten ohne eigenen Typ.

**Das ist kein Versehen, sondern der bestehende Zustand:** *`factor` und `offset` verhalten sich
genauso, und `SettingCategory` benennt das ausdruecklich als «den ehrlichen Rest von
[OQ-093](../../NewConcept/91-open-questions.md)» — die Frage, wie ein Schluessel sagt, fuer welche
Gegenstaende er gilt, ist offen.* **Eine Verengung waere hier erfunden worden** (`PR-4`), darum steht
sie nicht im Kode.

⚠️ *Im Modell ist der Ort dagegen genau einer: die Einstellungskante haengt an `Text`
(`Text --display_size--> display size`), also sieht die Zeile im Einstellungsblock nur, wer auf `Text`
zeigt. **Der breite Fall betrifft allein die abgeleitete Liste `applyingTo()`**, die der
Datensatzweg nicht benutzt.*

**ENTSCHEIDUNG ERFORDERLICH: NEIN** — aber wenn OQ-093 einmal beantwortet wird, gehoert
`display_size` in dieselbe Antwort wie `factor` und `offset`.

---

## INF-060 · Zwei Luecken am aufklappbaren Einstellungsbereich der Feldzeile

[D-666](../../NewConcept/90-decision-log.md) sagt, **dass** der Bereich aufklappbar ist und dass eine
zugeklappte Zeile **nicht gelesen** wird. Beim Bauen blieben zwei Fragen offen, und beide sind
stillschweigend beantwortet worden — hier steht, wie, damit es kein erfundener Beschluss bleibt
(`PR-4`).

**1. Duerfen mehrere Zeilen gleichzeitig offen sein?** *Der Beschluss sagt nichts dazu.* **Gebaut:
ja** — der Umstand `taxmod_open_rows` traegt eine Menge von Kanten-Ids, und der Knopf schaltet je
Zeile um. *Die Gegenmoeglichkeit waere «immer nur eine», was das Vergleichen zweier Felder
unmoeglich macht — und genau dafuer ist der Bereich da (`Street Name` gegen `House Number`).*
**Aendert man es auf «nur eine», aendert sich nur der Umstand, nicht der Weg.**

**2. Was passiert mit einer nicht gespeicherten Eingabe, wenn man eine andere Zeile aufklappt?**
⚠️ **Auf dem skriptfreien Weg ist sie weg** — *das Aufklappen ist dort ein Seitenaufruf, und der
Knopf steht im Formular **seiner** Zeile, nicht im Seitenformular. Mit Skript bleibt sie stehen, weil
die Seite nicht neu geladen wird.* **Zwei Wege, zwei Verhalten**, und das ist genau die Art
Unterschied, die [D-665](../../NewConcept/90-decision-log.md) an anderer Stelle beanstandet.

**ENTSCHEIDUNG ERFORDERLICH: JA fuer (2).** *Die einfachste Behebung waere, den Aufklapp-Knopf ins
**Seitenformular** zu haengen (`form="taxmod-page-<id>"`), damit der Seitenaufruf die Eingaben
mitnimmt und speichert. Dann klappt Aufklappen aber jedes Mal auch **Speichern** aus — und ob ein
Blick in die Einstellungen ein Schreibvorgang sein darf, ist nicht meine Entscheidung.*

---

## INF-061 · Das Überschreiben an der Verwendungsstelle — Strategie ohne Pfad

**Was das Konzept dazu schon sagt** (nachgelesen, nicht erinnert — `PR-10`):

| Beschluss | Satz |
|---|---|
| [D-611](../../NewConcept/90-decision-log.md) | Eine Verwendungsstelle **darf** eine Einstellung des Zielknotens überschreiben — «aktuell nur für Settings» |
| [D-643](../../NewConcept/90-decision-log.md) | Ihr bisheriger Träger `relations.target_settings_record_id` fällt **ersatzlos** — 0 Zeilen, nie belegt |
| [D-529](../../NewConcept/90-decision-log.md) | Eine Einstellung ist ein Feld, also eine Kante |
| [D-578](../../NewConcept/90-decision-log.md) | Ein Wert an einer Kante ist eine Zeile in `relation_records` — keine zweite Ablage für eine Kantenart |
| [D-577](../../NewConcept/90-decision-log.md) | «Eine Adresse — sie bekommt ihren **eigenen `node_record`**, der Kunde verweist darauf.» **Kein Pfad.** |
| [D-522](../../NewConcept/90-decision-log.md) | Datensätze trägt der Knoten, **der Felder hat** — ein einfacher Typ hat welche, denn seine Einstellungen sind Kanten (D-529) |
| [D-602](../../NewConcept/90-decision-log.md) | Aufgelöst wird: Kante → Zielknoten → Vorfahren → Rückfall im Kode |

**Was heute wirklich passiert** (gemessen an `DataEntry::putSettingAtUseSite()`): der Wert landet im
**Default-Satz des Besitzers**, adressiert mit der Zeichenkette `<Verwendungsstelle>.<Einstellungskante>`.
⚠️ **Es sind schon genau zwei Ids** — sie stecken nur in einer Textspalte statt in der Ablage.

**Vorschlag, und er ist wörtlich die Form aus D-577:**

1. Die Wertzeile der Verwendungsstelle im Satz des Besitzers bekommt einen `value_ref` auf einen
   **eigenen Satz des Zielknotens**.
2. Die Überschreibungen sind gewöhnliche Wertzeilen **in diesem Satz** — `relation_id` = die
   Einstellungskante. Keine neue Spalte, keine neue Tabelle.
3. Der Leser folgt in Schritt 1 der Kette (D-602) diesem Verweis statt einem Pfad.
4. Der Schreiber legt den Satz beim ersten Überschreiben an und räumt ihn weg, wenn die letzte
   Überschreibung fällt.
5. Umzug: jede Zeile mit Pfad `A.B` wandert in den Satz hinter `A`. Zwei Ids, kein Zerlegen von Text.
6. Danach fällt die Spalte `relation_records.path`.

**ENTSCHEIDUNG ERFORDERLICH: JA, an genau einer Stelle.** *Dieser Satz ist weder `default` noch
`example` noch `user` — er ist die Einstellung **dieser** Verwendungsstelle. Sein Wort war: «an den
einfachen Datentypen kann es nur `default`- oder `example`-Datensätze geben». Entweder bekommt
`record_type` einen vierten Wert, oder der Satz zählt als `default` und wird allein dadurch
unterschieden, dass jemand auf ihn zeigt.*

**⚠️ Berichtigung am 2026-09-06, und sie ist seine:** *«D-577 scheint nicht dazu zu passen?»* —
**er hat recht, und die Zeile oben war zu stark.** D-577 handelt von **Daten**: ein Kunde, seine
Adresse, zwei Adressen. Es sagt, wo ein **eingebetteter Wert** liegt, und es sagt «kein Pfad». Über
die **Einstellungen einer Verwendungsstelle** sagt es *nichts*. Was ich oben gemacht habe, ist eine
**Übertragung der Form**, kein Zitat — und der Unterschied ist genau der, den `PR-10` meint.

**Wo die Übertragung klemmt:** die Wertzeile einer Verwendungsstelle im Satz des Besitzers trägt
den **Datenwert** («Bahnhofstrasse»). Ihr `value_ref` für einen Einstellungssatz zu benutzen legte
Daten und Einstellungen in **eine** Zeile. *Das ist keine Kleinigkeit: ein Feld, das schon einen
Wert trägt, hätte dann keinen Platz mehr für seine Einstellungen, und ein Feld ohne Wert bekäme
eine Zeile, nur weil jemand `min` gesetzt hat.*

**Also bleibt offen, und ausdrücklich unbeantwortet (`PR-4`):** wo die Einstellungen einer
Verwendungsstelle liegen, wenn kein Pfad und keine Spalte an `relations` sie trägt. Die zwei Ids
stehen fest — **Verwendungsstelle** und **Einstellungskante** —, die Ablage nicht.

---

## INF-062 · ERLEDIGT — Zurueckholen einer geparkten Kante belebt auch geleerte Werte wieder

**Gefunden am 2026-09-06 beim Bau von `scripts/dev/pakete-check.php`, gemessen und nicht vermutet.**
**Entschieden am 2026-09-06 mit [D-676](../../NewConcept/90-decision-log.md), gebaut und gemessen am
2026-09-07 als Schemafassung 40.**

[D-619](../../NewConcept/90-decision-log.md) sagt: *die Wertzeilen einer geparkten Kante wandern mit,
beim Zurueckholen wieder heraus. Eine Gruppe, ein Akt, umkehrbar.* **Gebaut ist mehr als das.**

**Der gemessene Ablauf**, an einem eigens angelegten Knoten:

| Schritt | lebende Wertzeilen |
|---|---|
| `put("erst")` | 1 |
| `clear()` — der Mensch loescht den Wert | 0 |
| `put("zweit")` | 1 |
| `removeField()` — die Kante wird geparkt | 0, **zwei** Zeilen im Schatten (`erst`, `zweit`) |
| `restoreField()` | **2** — `erst` ist wieder da |

**Die Ursache steht in einer Zeile:** `WpdbRelationRepository::unparkValues()` holt *jede*
Schattenzeile der Kante zurueck, die `deleted = 1` traegt. **Eine Zeile, die das Leeren geloescht
hat, und eine, die das Parken mitgenommen hat, sehen dort gleich aus** — obwohl beide Akte ihre
eigene Aenderungsgruppe haben, und obwohl [D-575](../../NewConcept/90-decision-log.md) genau diese
Gruppe als das Gepaeck des Parkens benennt.

**ENTSCHEIDUNG ERFORDERLICH: JA.** *Der naheliegende Griff waere, nur die Zeilen der
**Parkgruppe** zurueckzuholen — das ist die Form, die [D-601](../../NewConcept/90-decision-log.md)
dem Rueckgaengig ohnehin gibt. **Ihn hier im Vorbeigehen zu waehlen, waere ein erfundener Beschluss
(`PR-4`)**, denn es ist nicht gesagt, ob ein geleerter Wert beim Zurueckholen der Kante
wiederkommen soll oder nicht; der Papierkorb sagt «geparkt, nicht geloescht», und ein geleertes
Feld sagt das gerade nicht.*

⚠️ **Was `pakete-check.php` deshalb tat:** *er leerte an einem **zweiten** Feld und parkte am ersten,
damit Abschnitt 8 die Zusage aus D-619 mass und nicht diese offene Frage. **Der Fall war damit
umgangen, nicht bewacht** — wer ihn entscheidet, bekommt dort seine Zusage.*

### ✅ Erledigt am 2026-09-07, Schemafassung 40

**Der Eigentuemer hat entschieden: der geleerte Wert kommt nicht wieder** ([D-676](../../NewConcept/90-decision-log.md)).
*`relation_records_history` bekommt `parked_by_group_id` — dieselbe Spalte, die `relations_history`
seit Fassung 27 traegt. Das Parken schreibt die Aenderungsgruppe an die Wertzeilen, die es
mitnimmt; das Zurueckholen holt **nur** die Zeilen dieser Gruppe. Eine reine Ergaenzung, `dbDelta`
legt die Spalte an, es wandert nichts.*

**Gemessen auf eigener Wiese, Praefix `__up`, vorher und nachher — dieselben fuenf Schritte:**

| Schritt | lebende Wertzeilen **vorher** | lebende Wertzeilen **nachher** | Schattenzeilen |
|---|---|---|---|
| `put("erst")` | 1 | 1 | 0 |
| `clear()` | 0 | 0 | 1 (ohne Gruppe) |
| `put("zweit")` | 1 | 1 | 1 (ohne Gruppe) |
| parken (`removeField`) | 0 | 0 | 2 — **nachher: eine mit Gruppe, eine ohne** |
| zurueckholen (`restoreField`) | **2** — `erst` und `zweit` | **1** — nur `zweit` | 2 |

⚠️ **Und die zwei Schattenzeilen waren vorher an nichts zu unterscheiden** — *nicht an `deleted`,
nicht an `version`, nicht an `archived_at`. Genau deshalb ist die Aenderungsgruppe die Klammer und
kein Zeitpunkt.*

⚠️ **Der Bestand bleibt `NULL`, und das kostet nichts:** *gemessen am 2026-09-07 tragen die 487
geparkten Kanten zusammen **24** Schattenwertzeilen, und **keine einzige** davon ist zur Parkzeit
ihrer Kante oder spaeter archiviert worden — sie
waren alle vorher schon geloescht. Eine vor Fassung 40 geparkte Kante kommt darum ohne Wertzeilen
zurueck, so wie sie es nach der alten Lesart auch getan haette.*

⚠️ **Der Umweg in `pakete-check.php` ist zurueckgebaut.** *Abschnitt 8 leert und beschreibt jetzt
**dasselbe** Feld neu, das er danach parkt, und traegt drei neue Zusagen: nur die geparkte
Schattenzeile traegt eine Gruppe, es ist dieselbe wie an der Kante, und zurueck kommt genau eine
Wertzeile.*

---

## INF-063 · Gehoeren die Handreparaturen am Modell in die Saat?

**Gefunden am 2026-09-06 beim Bau der Schemafassung 38** ([D-672](../../NewConcept/90-decision-log.md),
sein dritter Punkt: *«die heutigen Handreparaturen gehoeren in genau so eine Wanderung»*).

**Die Wanderung deckt nur die eine Haelfte ab, und das ist Absicht.** *Sie entfernt jede gespeicherte
Renderer-Wahl, die ihr Knoten nicht mehr zulaesst — das ist regelgetrieben und faellt aus der
Aussiebung selbst. **Sie schreibt keine neue Wahl hin** (`PR-4`): welcher Renderer stattdessen gelten
soll, ist eine Entscheidung und keine Ableitung.*

**Was am 2026-09-06 von Hand an seinem Modell gesetzt wurde — Inhalt, nicht Schema:**

| gesetzt | woran |
|---|---|
| `chooser-dialog` | `Base units` |
| `reference` | `With prefix`, `Without prefix` |
| `compact` | `Dimension` |
| die `step`-Kanten | (angelegt) |
| `label_role` | umgezogen |

**ENTSCHEIDUNG ERFORDERLICH: JA.** *Gehoert das in die **Saat** — also in den Zustand, den eine
frische Installation mitbringt —, oder ist es seine Einstellung an seinem Bestand, die eine
Neuinstallation nichts angeht? **Beides ist vertretbar und keines faellt aus einer Regel**: eine
Wahl, die er heute getroffen hat, ist genau die Sorte Angabe, die [D-026](../../NewConcept/90-decision-log.md)
als «default» am Modell kennt — und zugleich genau die Sorte, die ein anderer Betreiber vielleicht
anders will.*

⚠️ **Hier wird sie ausdruecklich nicht beantwortet**, und die Wanderung tut es auch nicht: sie raeumt
weg, was unzulaessig ist, und laesst den Rueckfall greifen. *Wer sie entscheidet, aendert die Saat —
und dann ist es wieder eine Fassung mit einer Wanderung, nach genau demselben Muster.*

⚠️ **BEANTWORTET am 2026-09-06, und die Antwort geht weiter als die Frage.** *Sein Satz:* **«wir
sollten den aktuellen bestand einfrieren lass aber alles mit `__` weg das ist dir»**.

**Damit ist die Frage «gehoeren die Handreparaturen in die Saat?» nicht mit ja beantwortet, sondern
aufgeloest:** *nicht die fuenf Handgriffe wandern in eine Saat, sondern **sein ganzer heutiger
Bestand wird das Abbild** — der Vollzug von [D-600](../../NewConcept/90-decision-log.md), «eine
Neuinstallation entsteht kuenftig aus einem Abbild des gewachsenen Baums». Die Handreparaturen sind
dann keine Sonderfaelle mehr; sie stehen einfach mit drin, wie jede andere Zeile auch.*

| | |
|---|---|
| **wo** | `data/saat.json`, erzeugt von `scripts/dev/saat-export.php` |
| **wer spielt ein** | `SeedImage::importOnce()`, vor der Saat und den vier Geruesten |
| **draussen** | alles mit Praefix `__` samt allem darunter, und was unter dem Papierkorb haengt |
| **bewacht** | `seed-twice-check.php`, Abschnitt 5 — Zaehlung und Pruefsumme, **keine Namen** |

**Gemessen am 2026-09-06:** 136 Knoten, 48 Kanten, 184 Beschriftungen mit 186 Textzeilen, 194 Saetze,
90 Wertzeilen, dazu 23 Optionen. *Draussen blieben drei Knoten `__Test` mit ihren drei Beschriftungen
und die drei Optionen, die auf sie zeigten; im Papierkorb lag nichts.*

⚠️ **Der Abzug ist ein Abbild und kein zweites Modell** (`PR-1`): *er wird **erzeugt** und
eingecheckt, nicht gepflegt. Wer den Baum aendern will, aendert den Baum und zieht danach neu ab.*
