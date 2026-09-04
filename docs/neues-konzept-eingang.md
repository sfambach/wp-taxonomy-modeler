# Eingang für das neue Konzept

**Angelegt 2026-09-01, unmittelbar nach [D-568](NewConcept/90-decision-log.md).**

Mit D-568 gilt `docs/NewConcept/` als veraltet und wird **nicht mehr erweitert** — auch nicht um
offene Fragen. Der Aufbau des neuen Konzepts ist noch nicht festgelegt. Bis dahin sammelt dieses
Blatt, was sonst verloren ginge.

**Das ist ein Eingangskanal, keine Entscheidung** (Arbeitsmodell v1.2 §7.1). Nichts hier ist
`ACTIVE`. Ein Eintrag wird später geprüft und eingeordnet.

---

## INF-001 · Der geteilte Id-Raum als mögliche Wurzel

**Typ:** `HYPOTHESE` · **Status:** `INFERRED` — **vom Eigentümer**, ausdrücklich als Vermutung
formuliert

**Seine Worte, 2026-09-01:**

> *«Ich glaube ehrlich gesagt, dass viele Probleme daraus entstanden sind, dass wir einen gemeinsamen
> Id-Bereich für Knoten und Kanten haben und daraus der Wunsch eines Pfades entstanden ist. Aber das
> ändern wir im neuen Konzept.»*

**Was daran gemessen ist:**

| | |
|---|---|
| Der geteilte Id-Raum **existiert** | [D-339](NewConcept/90-decision-log.md): *«identities(id), append-only … nodes and relations take the number and use it as their own primary key»* |
| Er wird **massiv** verbraucht | 64 703 vergebene Identitäten für **680** lebende Zeilen — **1,1 %**; 53 561 gehören zu gar keiner Tabelle |
| Alle Fremdschlüssel zeigen auf `identities.id` | genau das trägt den Schattentabellen-Entwurf: mehrere Zeilen mit derselben Id brechen keinen davon |

**Was der zweiten Hälfte der Hypothese widerspricht — und es ist eine echte Einschränkung:**

[D-082](NewConcept/90-decision-log.md) nennt als Grund für `nodes.path` **nicht** den Id-Raum,
sondern:

> *«`path` is the **materialised ancestor path — derived and rebuildable**»*

**Der Pfad entstand also aus dem Wunsch nach einer vorgerechneten Vorfahrensuche, nicht aus dem
geteilten Id-Raum.** Und D-082 steht bis heute auf `agreed (proposal)` — **die Abkürzung wurde nie
bestätigt**, und `50-wordpress-persistence.md` zitiert für sie ausserdem die falsche Nummer (`D-014`).

⚠️ *Beides kann nebeneinander wahr sein: der Id-Raum ist ein eigenes Thema, der Pfad ein anderes. Ich
löse das nicht auf — es gehört in das neue Konzept, und er hat es dort ausdrücklich hingelegt.*

**Für das neue Konzept zu entscheiden:**

1. Teilen sich Knoten und Kanten weiterhin einen Id-Raum?
2. Wenn ja: wann wird eine Identität vergeben — beim Anfordern oder beim Schreiben der Zeile?
3. Braucht es eine vorgerechnete Vorfahrensuche überhaupt, und wenn ja, in welcher Form?

---

## INF-002 · Das Tabellen-Review liegt vor

**Typ:** `MATERIAL` · **Status:** `FACT` — an der laufenden Datenbank gemessen

[`docs/review-tabellen.md`](review-tabellen.md), 2026-09-01. **Eingangsmaterial für das neue Konzept,
kein Flickwerk am alten.** Die Kernpunkte:

- **`path` steht in vier Tabellen.** `labels.path` und `settings.path` sind **leer** (0 von 47, 0 von 3). `record_values.path` ist der **Spiegel** von `edge_id` (je 12 verschiedene Werte bei 183 Zeilen). `nodes.path` trägt punktseparierte Id-Ketten und ist in **127 von 127** Fällen aus `relations` herleitbar, ohne eine einzige Abweichung.
- **18 Knoten haben mehr als eine eingehende Kante** — ein einzelner Pfad je Knoten kann davon nur einen Weg nennen und ist dort **verlustbehaftet**.
- **Zwei beschlossene Mechanismen kommen in den Daten nicht vor:** [D-530](NewConcept/90-decision-log.md)s `position` ist in **0 von 183** Zeilen gefüllt; [D-536](NewConcept/90-decision-log.md)s Journalversion in **25 von 23 229**.
- **Neun Spalten** tragen keine oder nur eine einzige Ausprägung; drei davon sind echte Kandidaten (`nodes.kind`, `labels.number`, die `records_history`-Spalten).

---

## INF-003 · Was das neue Konzept vor seinem Inhalt braucht

**Typ:** `QUESTION` · **Status:** `OPEN`

D-568 sagt: **wie das neue Konzept aufgebaut wird, ist vor seinem Inhalt festzulegen.** Offen ist
damit:

1. **Welche Pakete**, und wo verlaufen ihre Grenzen? (Arbeitsmodell v1.2 §2)
2. **Was verhindert, dass es wieder 2688 KB werden?** [D-566](NewConcept/90-decision-log.md) gibt den Maßstab — *prüfbar durch einen Menschen* —, aber keine Grenze. Ein messbarer Deckel wäre ein Wächter; ohne einen ist es eine Absicht.
3. **Was wird aus den 307 Regeln** des alten Bestandes — je Paket verteilt, oder als Historie geschlossen?
4. **Was wird aus den 562 Entscheidungen?** Nach v1.2 §6.1 sind sie `LEGACY`, bis geprüft. Bisher sind acht geklärt.

---

## INF-004 · Ein Feld, das den Knotennamen benutzt, folgt ihm — statt ihn zu kopieren

**Typ:** `DECISION` · **Status:** `CONFIRMED`, aber **zurückgestellt** — vom Eigentümer, 2026-09-04:
*«vergiss bitte den Vorschlag mit der Id, das werde ich später angehen, vielleicht könnte man das
auf den Parkplatz schieben für spätere Releases»*. Die Entscheidung steht; der Zeitpunkt ist offen.
Ein erster Bauversuch (Schema-Spalte, `Relation`-Modell, Wanderung) wurde noch am selben Tag
zurückgenommen, bevor er fertig war — nichts davon ist im Code.

**Sein Wort:** *«wenn use the node name gewählt ist sollte eigentlich die node_labels_id an das
Feld gehängt werden, nur wenn später ein Name eingegeben wird soll ein eigenes Label gewählt
werden»* — und auf Rückfrage: Basisname des Knotens (nicht eine Rolle/Locale-Label); bestehende,
bereits kopierte Feldnamen werden umgestellt statt nur künftige; der Eintrag hier steht auf sein
Geheiss.

**Der gemessene Ist-Zustand, vor der Änderung:** [`ModelEditor::addField()`](../src/Core/Service/ModelEditor.php)
nimmt einen reinen `string $name`. Beim Anlegen mit angehaktem "Use the node name" kopiert
[`NodesScreen`](../src/WordPress/Admin/NodesScreen.php) einmalig den **damaligen** Basisnamen des
Zielknotens in `relations.name`. Wird der Knoten später umbenannt, läuft die Kopie auseinander —
niemand aktualisiert sie nach.

**Die Entscheidung:** Ein Feld, das "use node name" trägt, zeigt den **aktuellen** Basisnamen
seines Zielknotens, nicht eine eingefrorene Kopie. Erst ein eigener, ausdrücklich eingegebener
Name löst die Kopplung — dann bekommt die Kante ihren eigenen Text, wie heute.

**Was das berührt (Datenmodell, deshalb hier und nicht stillschweigend gebaut, Arbeitsmodell v1.2
§4.1):** eine neue Spalte auf `relations` (Schema-Version, `dbDelta`), das `Relation`-Modell, die
Zeile in [`FieldRowRenderer`](../src/Core/Renderer/FieldRowRenderer.php), und eine rückwirkende
Wanderung für bestehende Kanten, deren Name heute zufällig mit dem Namen ihres Zielknotens
übereinstimmt (das einzige Erkennungsmerkmal, das es rückwirkend gibt — echte Verwechslungen mit
einem absichtlich gleichlautenden eigenen Namen sind eine bekannte, hingenommene Unschärfe dieser
Wanderung).

**Nicht umgesetzt — Parkplatz für ein späteres Release**, auf sein Wort.

---

## INF-005 · Beschriftungsrollen als Zeilen statt als Spalten — auf dem Parkplatz

**Typ:** `IDEA` · **Status:** `PARKED` — vom Eigentümer, 2026-09-04

**Sein Wort:** *«zu 2: ich glaube, Rollen sind was Programmiertechnisches. Aber wenn ich überlege,
dass ich die im Renderer auswählen kann, brauche ich evtl. dann und wann neue, wenn die alten nicht
reichen. Würde das mal auf den Parkplatz für mögliche spätere Entwicklungen schieben und bei vier
Spalten bleiben.»*

**Es bleibt bei vier Spalten** in `label_texts`: `form`, `table`, `select`, `help`
([D-580](NewConcept/90-decision-log.md), [D-598](NewConcept/90-decision-log.md)).

**Was den Parkplatz begründet, ist sein eigener Einwand gegen sich selbst.** *Eine Rolle sieht nach
Programmierung aus — bis man merkt, dass **er** sie im Renderer auswählt. Damit ist sie eine
Angabe des Modellierers, und Angaben des Modellierers wachsen. **Vier Spalten heisst: eine fünfte
Rolle ist eine Schemaänderung**; Zeilen mit `role_id` hiesse: eine fünfte Rolle ist eine neue Zeile.*

⚠️ *Woran man merken wird, dass der Parkplatz zu verlassen ist: **wenn zum ersten Mal eine Rolle
fehlt.** Solange die vier reichen, wäre die Zeilenform eine Tabelle mehr für einen Fall, den es
nicht gibt.*

---

## Erledigte Eingänge

*(noch keine)*
