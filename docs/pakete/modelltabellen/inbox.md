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
