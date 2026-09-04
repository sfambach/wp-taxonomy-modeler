<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RecordKind;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\EdgeRecord;
use Taxmod\Core\Model\Storage;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\Clock;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * Entering something against a model, and finding it again.
 *
 * ⚠️ **Where a value goes is not a choice either** (D-232). The attribute's target sits in a
 * branch, the branch says where the value is kept, and the author never picks:
 *
 * ```mermaid
 * flowchart LR
 *   T["the target's branch"] --> D["Data Types → inside the record, by path"]
 *   T --> K["Constants → a reference to a node"]
 *   T --> M["Model → a reference to a record"]
 *   T --> C["Compositions → records of its own"]
 * ```
 *
 * ⚠️ **Multiplicity plays no part in it.** Five integers are five **paths** in one record, not
 * five records (D-232).
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class DataEntry
{
    /** Wie tief der Abstieg in geschachtelte Teile geht -- ein Knoten kann auf sich selbst zeigen. */
    private const TEILE_TIEFSTENS = 4;

    /*
     * ⚠️ *Hier nahm der Dienst zusaetzlich einen `Settings` entgegen, um `persistent` aufzuloesen.
     * **Der Schluessel ist mit D-538 gefallen und die Tabelle mit D-579** — was nicht speichert,
     * sagt seither die Art der Kante.*
     */
    public function __construct(
        private readonly RecordRepository $records,
        private readonly RelationRepository $relations,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Whether values given through this attribute are kept at all.
     *
     * ⚠️ **The teeth behind [D-378](../../../docs/NewConcept/90-decision-log.md).** The owner brought
     * the distinction from object orientation — *there are attributes that get persisted and ones
     * that do not; a multiplicator is not persistent* — and a flag nothing enforces is decoration.
     * **So a write through a non-persistent attribute is refused rather than dropped**: dropping it
     * silently would let a form appear to save and lose the value, which is worse than either
     * storing it or saying no.
     *
     * ⚠️ *Resolved along the ordinary chain, so a **type** may declare itself non-persistent once and
     * every attribute using it inherits that — the owner's arrangement.*
     */
    public function keepsValues(Relation $edge): bool
    {
        // ⚠️ **Die Art der Kante sagt es** ([D-538](../../../docs/NewConcept/90-decision-log.md)).
        // *Seine Herleitung: «für den Benutzer werden ja nur die **Felder** gespeichert, nicht die
        // Settings, weil die Settings Eigenschaften des Modells sind.» **Damit können «nicht
        // speichernd» und «ist eine Einstellung» nie auseinanderfallen** — und zwei Angaben, die nie
        // widersprechen können, sind eine.*
        if ($edge->kind->isSetting()) {
            return false;
        }

        // ⚠️ **Hier stand bis zum 2026-08-30 ein Lauf durch die Auflösungskette, und er ist
        // ersatzlos weg.** *Er las den Schlüssel `persistent`, dessen 148 Zeilen mit
        // [D-538](../../../docs/NewConcept/90-decision-log.md) gefallen sind. **Von diesen 148 sagten
        // 146 nur die Vorgabe, eine erreichte nichts, und die einzige wirksame war
        // `Prefixes.exponent`** — die jetzt eine Einstellungskante ist und oben beantwortet wird.*
        //
        // ⚠️ *Zwei Warnungen, die hier standen, gehen mit: die über `??` vor `?->` und die über das
        // `?? true`, das der Eigentümer zweimal gefangen hat. **Sie waren richtig, solange es eine
        // Kette gab; jetzt gibt es keine.***
        return true;
    }

    /**
     * Start a record against a model node.
     *
     * ⚠️ **Only a branch that has instances can have one** (D-183). A data type has no
     * instances of its own — a `Text` node is not a thing somebody owns three of.
     */
    /**
     * @param RecordKind $kind Wer die Zeile schreibt — ein Mensch, der Autor, oder das Bauen
     *                         ([C65](../../../docs/NewConcept/10-domain-core.md)).
     */
    public function create(int $nodeId, RecordKind $kind = RecordKind::User): NodeRecord
    {
        $model = $this->nodes->byId($nodeId);

        // ⚠️ **Nicht mehr «welcher Zweig», sondern «hat er überhaupt Felder»**
        // ([D-522](../../../docs/NewConcept/90-decision-log.md)). *Der Eigentümer: «so ein Record, den
        // ich hier im Modell eingebe, ist auch einfach nur ein Record zur Kante — gehört er zu Field,
        // ist es ein Default-Wert; gehört er zu Settings, ist es eine Einstellung.»*
        //
        // ⚠️ **[D-183](../../../docs/NewConcept/90-decision-log.md) sagte das Gegenteil — «everything
        // under Definition has none, it is only a means to an end» — und war schon falsch, bevor
        // jemand daran rührte.** *Gemessen: **232 Setting-Zeilen** hängen an Knoten ausserhalb von
        // `Model` und `Compositions`, darunter `kilo`s Exponent 3. **Die Daten waren da; sie lagen nur
        // in einer anderen Tabelle und hiessen anders.***
        //
        // ⚠️ *Die neue Bedingung ist die, die etwas bedeutet: **ein Knoten ohne Felder hat nichts
        // aufzuzeichnen.** Sie schliesst dieselben Knoten aus, die auch vorher nichts konnten — nur
        // aus einem Grund, der am Knoten steht statt an seinem Zweig.*
        // ⚠️ **Additiv, nicht ersetzend, und das hat ein Test gezeigt.** *Meine erste Fassung fragte
        // **nur** nach Feldern — und nahm damit einem Modellknoten **ohne** Felder das Anlegen weg, das
        // er vorher konnte. Ein Modell, an dem noch nichts erklärt ist, ist eine Baustelle und kein
        // Fehler.*
        $branch = $this->framework->branchOf($model);
        $hatFelder = $this->relations->fieldEdgesOf($this->framework->inheritanceOwnersOf($model)) !== [];

        if (($branch === null || ! $branch->holdsData()) && ! $hatFelder) {
            throw NotYetStorable::thatBranchHasNoRecords($model->name);
        }

        $record = new NodeRecord(
            0,
            $model->id,
            $model->version,
            $this->clock->now()->format('Y-m-d H:i:s'),
            $kind
        );

        $id = $this->records->add($record);

        $this->ensureRequiredParts($id, $model, $kind);

        // ⚠️ *Die Marke faehrt mit, sonst gibt die Methode etwas zurueck, das anders aussieht als das,
        // was sie geschrieben hat. **Gemessen war genau das der Fall**: die Spalte trug `default`, das
        // zurueckgegebene Exemplar sagte `user`.*
        return new NodeRecord($id, $record->nodeId, $record->nodeVersion, $record->createdAt, $record->kind);
    }

    /**
     * Was die Multiplizität verlangt, entsteht mit dem Datensatz.
     *
     * ⚠️ **Auf sein Wort, und es ist dieselbe Regel wie bei den Eingaben:** *«genauso bei `1..*` muss ein
     * Record vorhanden sein»* — gesagt unmittelbar nach *«wenn `1..1` steht, muss ein Wert gesetzt
     * sein»*. **Einmal für den Wert, einmal für den Datensatz.**
     *
     * ⚠️ *Bis heute war das ein einmaliger Nachtrag: 61 Knoten bekamen ihre `DisplayOption` per Skript.
     * **Ein Nachtrag hält die Regel für den Bestand und nicht für das nächste, was jemand anlegt.***
     *
     * ⚠️ **Nur Kanten, die in diesem Datensatz überhaupt etwas halten.** *Eine Einstellung hält in einem
     * **Benutzer**satz nichts ([D-538](../../../docs/NewConcept/90-decision-log.md)), also entstünde dort
     * ein Teil, den niemand füllen darf.*
     *
     * ⚠️ *Und nur, wo das Ziel einen eigenen Satz braucht — das entscheidet
     * {@see self::ownsItsRecord()} nach [D-541](../../../docs/NewConcept/90-decision-log.md), und
     * {@see self::createPart()} sagt Nein, wenn es anders ist.*
     */
    private function ensureRequiredParts(int $recordId, Node $model, RecordKind $kind): void
    {
        foreach ($this->relations->fieldEdgesOf($this->framework->inheritanceOwnersOf($model)) as $edge) {
            if (! $edge->multiplicity->requiresOne() || $edge->hide) {
                continue;
            }

            if ($kind === RecordKind::User && ! $this->keepsValues($edge)) {
                continue;
            }

            $ziel = $this->nodes->find($edge->toId);

            if ($ziel === null || ! $this->ownsItsRecord($edge, $ziel)) {
                continue;
            }

            // WICHTIG: Ist das Ziel ein Basisknoten, aus dem gewaehlt wird, entsteht hier nichts.
            // Seit D-584 sagt die node_id des Datensatzes, *welcher* Renderer es ist -- ein im
            // Voraus angelegter Satz truege den Basisknoten und damit eine Wahl, die niemand
            // getroffen hat. Gemessen: jeder Lauf legte so einen Satz von «Renderer» an, 16 Stueck,
            // auf die nichts zeigte. Der Satz entsteht durch die Wahl (D-583), nicht davor.
            if ($this->isChosenFrom($ziel)) {
                continue;
            }

            $this->createPart($recordId, $edge->id);
        }
    }

    /**
     * Wird aus diesem Knoten *gewaehlt*, statt ihn selbst zu benutzen?
     *
     * WICHTIG: Ja, sobald andere Knoten von ihm erben -- dann ist er der Basisknoten einer Auswahl
     * (D-584: die Vererbung sagt, was gewaehlt werden darf). «Adresse» hat keine erbenden Knoten
     * und wird deshalb direkt benutzt.
     */
    private function isChosenFrom(Node $target): bool
    {
        return $this->nodes->childrenOf($target) !== [];
    }

    /**
     * Put a value in at one attribute of the record's model.
     *
     * @param string $locale Only ever non-empty for an attribute declared translatable (D-317).
     */
    public function put(int $recordId, int $edgeId, TypedValue $value, string $locale = ''): void
    {
        $edge      = $this->writableEdge($recordId, $edgeId);
        $vorhanden = $this->valuesOn($recordId, $edge->id, $locale);

        // ⚠️ **Sonst schriebe jedes Speichern eine zweite Zeile** ([D-530](../../../docs/NewConcept/90-decision-log.md)).
        // *Bis dahin tat `$wpdb->replace()` das über den eindeutigen Schlüssel; **der ist weg**, und
        // damit muss dieser Dienst sagen, welche Zeile er meint.*
        if (count($vorhanden) > 1) {
            throw NotYetStorable::thatFieldHasSeveralValues($edge->name, count($vorhanden));
        }

        $this->records->putValue(
            $vorhanden === []
                ? EdgeRecord::direct($recordId, $edge->id, $value, $locale)
                : new EdgeRecord($recordId, $vorhanden[0]->path, $edge->id, $locale, $value, $vorhanden[0]->id, $vorhanden[0]->position)
        );
    }

    /**
     * Einen Wert **an einer Verwendungsstelle** setzen — «das Feld B des Feldes A dieses Datensatzes».
     *
     * ⚠️ **Kein neues Mittel, sondern das vorhandene tiefer benutzt.** *Der Eigentümer: «wir haben
     * alle Mittel, einer Kanten-Knoten-Kombination in jeglicher Schachtelung Daten zuzuweisen — warum
     * brauche ich hier ein zusätzliches?» **Das ist die Antwort:** ein Wert im Datensatz des
     * Besitzers, adressiert über die Kette der Kanten.*
     *
     * ⚠️ *Gemessen benutzen 21 Zeilen der alten Settings-Tabelle diese Adresse längst — 20 davon sind
     * die Exponenten von `Prefixes.exponent`. **Nur gelesen hat sie in `record_values` nie jemand.***
     *
     * @param list<int> $edgeIds Von aussen nach innen.
     */
    public function putAt(int $recordId, array $edgeIds, TypedValue $value, string $locale = ''): void
    {
        $kette  = $this->walkedEdges($recordId, $edgeIds);
        $letzte = $kette[array_key_last($kette)];
        $satz   = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);

        $this->refuseUnwritable($letzte, $satz->kind);

        $pfad      = implode('.', $edgeIds);
        $vorhanden = $this->valuesAtPath($recordId, $pfad, $locale);

        if (count($vorhanden) > 1) {
            throw NotYetStorable::thatFieldHasSeveralValues($letzte->name, count($vorhanden));
        }

        $this->records->putValue(
            $vorhanden === []
                ? EdgeRecord::at($recordId, $edgeIds, $value, $locale)
                : new EdgeRecord($recordId, $pfad, $letzte->id, $locale, $value, $vorhanden[0]->id, $vorhanden[0]->position)
        );
    }

    /**
     * Die Werte an einer Verwendungsstelle, in ihrer Reihenfolge.
     *
     * @param  list<int> $edgeIds
     * @return list<EdgeRecord>
     */
    public function valuesAt(int $recordId, array $edgeIds, string $locale = ''): array
    {
        $this->walkedEdges($recordId, $edgeIds);

        return $this->valuesAtPath($recordId, implode('.', $edgeIds), $locale);
    }

    /**
     * Die Kette abgehen und dabei jede Stufe prüfen.
     *
     * ⚠️ **Das ist der Wert dieser Methode, nicht das Zusammensetzen des Pfades.** *Eine Adresse wie
     * «Feld 4654, darin Feld 7788» ist nur dann etwas wert, wenn 7788 wirklich ein Feld des Zieles von
     * 4654 ist. **Ohne die Prüfung könnte man an jede erfundene Stelle schreiben**, und es fiele erst
     * auf, wenn jemand dort etwas sucht.*
     *
     * @param  list<int>      $edgeIds
     * @return list<Relation>
     */
    private function walkedEdges(int $recordId, array $edgeIds): array
    {
        if ($edgeIds === []) {
            throw new \InvalidArgumentException('Ein Pfad ohne Kante adressiert nichts.');
        }

        $record   = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);
        $besitzer = $this->nodes->byId($record->nodeId);
        $kette    = [];

        foreach ($edgeIds as $edgeId) {
            $gefunden = null;

            foreach ($this->relations->fieldEdgesOf($this->framework->inheritanceOwnersOf($besitzer)) as $kante) {
                if ($kante->id === $edgeId) {
                    $gefunden = $kante;
                }
            }

            if ($gefunden === null) {
                throw NotYetStorable::notAFieldOfThisModel($edgeId, $besitzer->name);
            }

            $kette[]  = $gefunden;
            $besitzer = $this->nodes->byId($gefunden->toId);
        }

        return $kette;
    }

    /** Die Zeilen unter genau diesem Pfad. @return list<EdgeRecord> */
    private function valuesAtPath(int $recordId, string $path, string $locale): array
    {
        $meine = [];

        foreach ($this->records->valuesOf($recordId) as $wert) {
            if ($wert->path === $path && $wert->locale === $locale) {
                $meine[] = $wert;
            }
        }

        return $meine;
    }

    /**
     * Die Zeilen, die ein Feld in diesem Datensatz belegt — in ihrer Reihenfolge.
     *
     * @return list<EdgeRecord>
     */
    private function valuesOn(int $recordId, int $edgeId, string $locale): array
    {
        $meine = [];

        foreach ($this->records->valuesOf($recordId) as $wert) {
            if ($wert->edgeId === $edgeId && $wert->locale === $locale) {
                $meine[] = $wert;
            }
        }

        return $meine;
    }

    /**
     * Einen **weiteren** Wert an ein Feld hängen — die Mehrfachheit von der Werteseite.
     *
     * ⚠️ **Mehrere Werte sind mehrere Pfade, keine mehreren Kanten** — *`DataEntry`s eigener Docblock
     * sagt das seit langem («five integers are five **paths** in one record»), und der eindeutige
     * Schlüssel `(record_id, path, locale)` sah es immer vor. **Gemessen am 2026-08-30 hatte es
     * niemand je benutzt:** alle 43 Wertzeilen trugen einen Pfad, der schlicht die Kanten-Id war.*
     *
     * ⚠️ **Die neue Zeile hängt sich hinten an** — `position` eins über der höchsten. *Die Zeilen-Id
     * trennt sie von ihren Geschwistern, `position` ordnet sie, und beide sind Spalten, die es schon
     * gab ([D-530](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ *Ob ein Feld überhaupt mehrere tragen darf, sagt seine Mehrfachheit
     * ({@see \Taxmod\Core\Model\Multiplicity::allowsMany()}) — **hier wird es nicht geprüft**, weil
     * die Mehrfachheit an der Verwendungsstelle aufgelöst wird und dieser Dienst die Kette nicht
     * kennt. Der Rand fragt, bevor er den Knopf zeichnet.*
     *
     */
    public function appendValue(int $recordId, int $edgeId, TypedValue $value, string $locale = ''): void
    {
        $edge     = $this->writableEdge($recordId, $edgeId);
        $hinterste = -1;

        foreach ($this->valuesOn($recordId, $edge->id, $locale) as $vorhanden) {
            $hinterste = max($hinterste, $vorhanden->position);
        }

        $this->records->putValue(EdgeRecord::direct($recordId, $edge->id, $value, $locale, $hinterste + 1));
    }

    /**
     * Wie viele Werte ein Feld in diesem Datensatz trägt.
     *
     * ⚠️ *Über die **Kanten-Id**: alle Werte eines Feldes teilen sich eine Kante, und seit
     * [D-530](../../../docs/NewConcept/90-decision-log.md) auch einen Pfad.*
     */
    public function countValues(int $recordId, int $edgeId): int
    {
        $n = 0;

        foreach ($this->records->valuesOf($recordId) as $value) {
            if ($value->edgeId === $edgeId) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Die Kante, in die geschrieben werden darf — mit allen Wächtern, an einer Stelle.
     *
     * ⚠️ **Herausgezogen, damit `put()` und {@see appendValue()} nicht zwei Sätze Wächter haben.**
     * *Zwei Kopien einer Prüfung sind zwei Orte, an denen die nächste Regel vergessen wird.*
     */
    private function writableEdge(int $recordId, int $edgeId): Relation
    {
        $record = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);
        $edge   = $this->edgeOf($record, $edgeId);

        $this->refuseUnwritable($edge, $record->kind);

        return $edge;
    }

    /**
     * Die zwei Verweigerungen, die für jede Schreibstelle gelten — **einmal, nicht zweimal**.
     *
     * ⚠️ *Herausgezogen, als {@see putAt()} dieselben Prüfungen brauchte. Zwei Kopien einer Prüfung
     * sind zwei Orte, an denen die nächste Regel vergessen wird.*
     */
    /**
     * Braucht der Wert dieses Feldes einen **eigenen Datensatz**, oder ist er ein Verweis?
     *
     * ⚠️ **Für eine Einstellung sagt es das Ziel, nicht der Zweig.** *Der Eigentümer, dreimal und
     * zuletzt deutlich: «im Grunde sind alle Einstellungen Kompositionen … mit «sie halten keine
     * Daten» meinst du, **sie werden im verwendenden Modell gespeichert** — genau das sagt Komposition
     * aus, und Settings ist davon abgeleitet.»*
     *
     * ⚠️ **Ich hatte daraus einen Konflikt gebaut, den es nicht gibt.** *Eine Wertzeile im
     * verwendenden Datensatz trägt entweder eine Zahl, einen Knotenverweis oder einen Verweis auf
     * einen eigenen Teil — **alles drei liegt im verwendenden Datensatz**. Was der Verweis meint,
     * sagt das Ziel ([D-540](../../../docs/NewConcept/90-decision-log.md)): hat es **eigene Felder**,
     * müssen deren Werte irgendwo stehen, also in einem Teil; hat es nur **Kinder**, wählt man eines
     * davon aus.*
     *
     * ⚠️ *Für alles, was **keine** Einstellung ist, antwortet weiter der Zweig — dort trennt er
     * `Model` von `Compositions`, und das ist eine Frage des **Besitzes**, die dieses Ziel nicht
     * beantworten kann ([D-133](../../../docs/NewConcept/90-decision-log.md)).*
     */
    private function ownsItsRecord(Relation $edge, Node $target): bool
    {
        if ($edge->kind->isSetting()) {
            return $this->hasOwnFields($target);
        }

        $branch = $this->framework->branchOf($target);

        return $branch !== null && $branch->storage() === Storage::OwnRecords;
    }

    /**
     * Hat dieser Knoten Felder, die **er selbst** erklärt hat?
     *
     * ⚠️ **Nicht die geerbten, und das ist der ganze Trick.** *Ein Feld an der Wurzel erscheint an
     * **allen** Knoten ([OQ-133](../../../docs/NewConcept/91-open-questions.md) hat es gemessen: 124),
     * also hätte «hat Felder» für jeden Knoten `true` gesagt und die Unterscheidung wäre keine.*
     */
    private function hasOwnFields(Node $target): bool
    {
        foreach ($this->relations->fieldEdgesOf([$target->id]) as $edge) {
            if ($edge->fromId === $target->id) {
                return true;
            }
        }

        return false;
    }

    private function refuseUnwritable(Relation $edge, RecordKind $kind = RecordKind::User): void
    {
        $target = $this->nodes->byId($edge->toId);
        $branch = $this->framework->branchOf($target);

        if ($branch === null) {
            throw NotYetStorable::thatBranchHasNoRecords($target->name);
        }

        // ⚠️ **A non-persistent attribute has no place to put a value** (D-378) — it exists to be
        // read by a calculation, and its model-level value is its `default` (D-026). Refused rather
        // than dropped: a silent drop lets a form look as though it saved.
        // ⚠️ **«Nicht speichernd» heisst «landet nicht im Benutzerdatensatz» — nicht «hat keinen
        // Wert»** ([D-538](../../../docs/NewConcept/90-decision-log.md)). *Der Eigentümer: «für den
        // Benutzer werden ja nur die Felder gespeichert, nicht die Settings, weil die Settings
        // Eigenschaften des Modells sind.» **Ein Vorgabewert ist genau so eine Eigenschaft**, und
        // [D-026](../../../docs/NewConcept/90-decision-log.md) sagt, wo er lebt: «at model level there
        // are no values, only defaults». Ohne diese Ausnahme liesse sich der Exponent von `kilo` nicht
        // hinschreiben — der einzige echte nicht-speichernde Fall im ganzen Modell.*
        if ($kind === RecordKind::User && ! $this->keepsValues($edge)) {
            throw NotYetStorable::thatFieldKeepsNothing($edge->name);
        }

        // ⚠️ Refused rather than guessed: a composed part is a record of its own, and nothing
        // here creates one yet. Storing it inline would put the value in the wrong place and
        // look right until somebody tried to share it.
        if ($this->ownsItsRecord($edge, $target)) {
            throw NotYetStorable::compositionsNeedTheirOwnRecords($edge->name);
        }
    }

    /**
     * Start a **composed part** — a record of its own, owned by the holder.
     *
     * ⚠️ **This is what [D-232](../../../docs/NewConcept/90-decision-log.md) asks for and nothing had
     * built.** *A target in `Compositions` gets **its own records***, and the reason underneath is the
     * owner's own: **does the member need an identity?** *A row does — you point at it, order it,
     * delete a single one. A number in a list does not.* He drew the same line himself: *simple types
     * and composed types can just be stored there; where it gets harder is whole row types, whole
     * tables — there I would insist it is external.*
     *
     * ```mermaid
     * flowchart LR
     *   H["the holder's record"] -->|value_ref| P["the part's own record"]
     *   P --> V["its own values, by path"]
     * ```
     *
     * ⚠️ **The link is the holder's `value_ref` and there is no back-link.** A part has no owner
     * column: it is reached from above, which is what makes *dies with the holder* a walk rather than
     * a flag to keep in step ([C12](../../../docs/NewConcept/10-domain-core.md)).
     *
     * ⚠️ **One part per occurrence, and asking twice makes a second one.** *That is the point of it
     * having an identity* — two positions on an order are two positions, and a method that quietly
     * reused the first would make them one thing wearing two names.
     *
     * @param string $path Where under the holder it sits. Empty for a direct attribute, so the edge
     *                     id is the whole path; an index like `2` for the third of several.
     */
    /**
     * Einen zusammengesetzten Teil **an einer Verwendungsstelle** anlegen.
     *
     * ⚠️ **Derselbe Akt wie {@see createPart()}, nur mit geprüfter Adresse.** *Jener nimmt einen Pfad
     * als **Zeichenkette** entgegen und schreibt ihn ungeprüft — solange nur eine Stufe darin stand,
     * fiel das nicht auf. **Sobald echte Daten an mehrstufige Adressen wandern, ist eine ungeprüfte
     * Adresse eine, die auf nichts zeigt**, und man merkt es erst beim Suchen.*
     *
     * @param list<int> $edgeIds Von aussen nach innen; die **letzte** ist die zusammengesetzte Kante.
     */
    public function createPartAt(int $recordId, array $edgeIds): NodeRecord
    {
        $kette = $this->walkedEdges($recordId, $edgeIds);

        return $this->createPart(
            $recordId,
            $kette[array_key_last($kette)]->id,
            implode('.', $edgeIds)
        );
    }

    /**
     * Eine Angabe des Modells an einem Knoten festschreiben — dort, wo sie gelesen wird.
     *
     * ⚠️ **Das fehlende Gegenstück zum Leser** ([D-543](../../../docs/NewConcept/90-decision-log.md)).
     * *Der Eigentümer hat es an der Oberfläche gesehen: «den Render kann ich noch nicht setzen.» Und
     * gemessen war es kein fehlender Renderer, sondern ein **Schreiber an der alten Stelle**: der
     * Wähler am Knoten schrieb in die `settings`-Tabelle, und {@see ModelValues} liest aus Datensätzen
     * und **gewinnt**. **Vierter Fall derselben Sache an einem Tag** — Daten umgezogen, Schreiber
     * stehengeblieben; die Reihenfolge heisst deshalb Wächter, Leser, Daten.*
     *
     * ⚠️ **Und sein Einwand war der richtige Zuschnitt:** *«auch Settings sind etwas, das gerendert
     * werden kann, und die Renderer dafür existieren schon — der Unterschied: **Eingabe geschieht im
     * Modell und nicht im Frontend**.» Deshalb steht hier keine neue Speicherform: die Angabe liegt in
     * einem Datensatz wie jeder Wert, und ihr Datensatz ist der **`default`** des Knotens — denn
     * [D-026](../../../docs/NewConcept/90-decision-log.md) sagt es scharf: «at model level there are no
     * values, only defaults».*
     *
     * ⚠️ **Zwei Stufen, weil [D-541](../../../docs/NewConcept/90-decision-log.md) es so verlangt:** *hat
     * das Ziel eigene Felder, gehört ihm ein **eigener Datensatz** — dann wird der Teil angelegt und der
     * Wert **darin** geschrieben. Hat es nur Kinder, ist der Wert ein Verweis und liegt direkt an der
     * Kante. **Die Entscheidung fällt nicht hier**, sondern in {@see self::ownsItsRecord()}, und
     * {@see self::refuseUnwritable()} verweigert den direkten Weg, wenn er falsch ist.*
     *
     * ```mermaid
     * flowchart LR
     *   K["Knoten"] --> D["sein default-Satz"]
     *   D -->|"Traegerkante"| T["Teil: DisplayOption"]
     *   T -->|"Wertkante"| W["Verweis auf den Renderer-Knoten"]
     * ```
     */
    public function putSettingValue(int $nodeId, SettingKey $key, TypedValue $value, string $locale = ''): void
    {
        $aussen = $this->framework->settingEdgeId($key);

        if ($aussen === 0) {
            throw NotYetStorable::thatSettingHasNoEdgeYet($key->value);
        }

        $this->putSettingAt($nodeId, $aussen, $this->framework->settingValueEdgeId($key), $value, $locale);
    }

    /**
     * Dieselbe Sache, an einer Kante, die die Saat nicht aufgeschrieben hat.
     *
     * ⚠️ **Der allgemeine Fall, und {@see self::putSettingValue()} ist jetzt sein Sonderfall.** *Der
     * Eigentümer: «gut, ich sehe die Settings, kann sie aber nicht einstellen.» Und die Angaben, die er
     * einstellen will — `with_label`, `label_role`, `read_only` — sind gewöhnliche Einstellungskanten
     * ohne Eintrag im Verzeichnis. **Ein Schreiber, der nur die drei aufgeschriebenen kennt, hilft ihm
     * nicht.***
     *
     * ⚠️ *`$innen === 0` heisst «der Wert liegt direkt an der Kante». Zeigt die Kante trotzdem auf ein
     * Ziel mit eigenen Feldern, sagt {@see self::refuseUnwritable()} Nein — **die Entscheidung fällt
     * dort und wird hier nicht geraten.***
     */
    public function putSettingAt(int $nodeId, int $aussen, int $innen, TypedValue $value, string $locale = ''): void
    {
        $satzId = $this->defaultRecordOf($nodeId);

        // ⚠️ *Keine eigene Wertkante heisst: die Angabe **ist** der Verweis, und sie steht direkt an der
        // Trägerkante. `refuseUnwritable()` sagt Nein, wenn das Ziel doch einen eigenen Satz braucht —
        // also wird hier nichts geraten.*
        if ($innen === 0) {
            // WICHTIG: Die Wahl eines Renderers legt einen Datensatz an, keinen Knotenverweis
            // (D-583, D-584). Der Eigentuemer: «wenn ich den Renderer auswaehle, muss ein
            // Datensatz geaendert werden, es sollte schon einer da sein.» Solange «Renderer»
            // feldlos war, griff das nie -- seit D-585 traegt er «converter», und damit
            // braucht jeder gewaehlte Renderer seinen eigenen Satz.
            if ($value->isAReference() && $this->targetOwnsItsRecord($satzId, $aussen)) {
                // WICHTIG: Der Halter steht seit TASK-020 in `nodes.settings_record_id` und nicht
                // mehr als Wertzeile an einer Traegerkante (D-584: «bei genau einem Renderer ist ein
                // einzelner Zeiger auf einen einzelnen Datensatz genau richtig»). Der alte Weg hing
                // an einer Kante -- und als der Eigentuemer den Huellknoten loeschte, hing er an
                // einer Kante, die es nicht mehr gab.
                $this->chooseSettingRecordAtNode($nodeId, (int) $value->reference);

                return;
            }

            $this->put($satzId, $aussen, $value, $locale);

            return;
        }

        $teile  = $this->partsOf($satzId);
        $teilId = $teile[(string) $aussen] ?? null;

        if ($teilId === null) {
            $teilId = $this->createPart($satzId, $aussen)->id;
        }

        // WICHTIG: Dieselbe Wahl eine Ebene tiefer. Die aeussere Kante fuehrt in den Behaelter,
        // die innere traegt den Renderer -- und der braucht seit D-585 seinen eigenen Satz.
        if ($value->isAReference() && $this->targetOwnsItsRecord($teilId, $innen)) {
            $this->chooseSettingRecord($teilId, $innen, (int) $value->reference);

            return;
        }

        $this->put($teilId, $innen, $value, $locale);
    }

    /**
     * Dieselbe Adresse, aber die Angabe wird **herausgenommen** statt geschrieben.
     *
     * ⚠️ **Auf sein Wort, gemessen an `converter`:** *«wenn ich `0..1` wähle, müsste ich auch nichts im
     * Value wählen können — kann ich auch auswählen, wird aber nicht speichern, müsste eigentlich den
     * Datensatz dahinter löschen.»* **Genau so war es:** *der Schreiber am Rand kehrte bei einem leeren
     * Wert einfach um — «ein leeres Feld löscht nicht» stand als Begründung darüber — und damit war
     * «nichts» die einzige Wahl, die sich nicht speichern liess.*
     *
     * ⚠️ **Und es ist kein neuer Zustand, sondern der dritte, den es längst gibt** —
     * [D-232](../../../docs/NewConcept/90-decision-log.md)s *unbeantwortet*, wie {@see self::clear()}
     * ihn schon herstellt. *Was fehlte, war nur der Weg dorthin über die zweistufige Adresse.*
     *
     * ⚠️ **Kein Teil wird angelegt, um in ihm zu löschen.** *Gibt es ihn nicht, ist die Angabe schon
     * unbeantwortet, und ein Satz, der nur entsteht, um leer zu sein, wäre ein Datensatz aus einem
     * Nicht-Ereignis.*
     */
    public function clearSettingAt(int $nodeId, int $aussen, int $innen, string $locale = ''): void
    {
        $satzId = $this->defaultRecordOf($nodeId);

        if ($innen === 0) {
            // WICHTIG: Was ueber die Spalte geschrieben wurde, muss auch ueber die Spalte
            // herausgenommen werden -- sonst waere «nichts» wieder die einzige Wahl, die sich nicht
            // speichern laesst, genau der Fall, den der Eigentuemer an `converter` gefunden hat.
            if ($this->targetOwnsItsRecord($satzId, $aussen)) {
                $bisher = $this->nodes->settingsRecordIdsOf([$nodeId])[$nodeId] ?? 0;

                if ($bisher !== 0) {
                    $this->nodes->rememberSettingsRecord($nodeId, 0);
                    $this->records->forgetRecord($bisher);
                }
            }

            $this->clear($satzId, $aussen, $locale);

            return;
        }

        $teilId = $this->partsOf($satzId)[(string) $aussen] ?? null;

        if ($teilId === null) {
            return;
        }

        $this->clear($teilId, $innen, $locale);
    }

    /**
     * Was an den Einstellungskanten dieses Knotens steht — je Kante ihr Wert.
     *
     * ⚠️ **Damit eine Bedienung zeigen kann, was gesetzt ist.** *Ein Schalter ohne gelesenen Zustand
     * steht immer auf «aus», und das nächste Speichern schreibt «aus» — **auch wenn niemand das
     * wollte**. Dieselbe Falle wie bei einer Auswahlliste ohne Vorauswahl.*
     *
     * ⚠️ *Nur aus dem `default`-Satz ([D-026](../../../docs/NewConcept/90-decision-log.md): «at model
     * level there are no values, only defaults») und in **einem** Zug für alle Kanten (`CD-7`).*
     *
     * @param  list<int>              $edgeIds
     * @return array<int, TypedValue> Kanten-Id => Wert; fehlt einer, fehlt der Eintrag.
     */
    public function settingValuesOf(int $nodeId, array $edgeIds): array
    {
        if ($edgeIds === []) {
            return [];
        }

        $gesucht = [];

        foreach ($edgeIds as $id) {
            $gesucht[(string) $id] = $id;
        }

        $werte = [];

        foreach ($this->records->ofNode($nodeId) as $satz) {
            if ($satz->kind !== RecordKind::Default) {
                continue;
            }

            foreach ($this->records->valuesOf($satz->id) as $wert) {
                $kante = $gesucht[$wert->path] ?? null;

                if ($kante !== null && ! $wert->value->isNothing()) {
                    $werte[$kante] = $wert->value;
                }
            }
        }

        return $werte;
    }

    /**
     * Einen weiteren Teil an dieser Kante anlegen — die Zeile, die `1..*` möglich macht.
     *
     * ⚠️ **Auf sein Bestehen:** *«somit muss ich Zeilen hinzufügen können»* — und der Grund steht in
     * [D-548](../../../docs/NewConcept/90-decision-log.md): mehrere `DisplayOption`s sind mehrere
     * Renderer, für das Farbschema.
     *
     * ⚠️ *Der `default`-Satz wird angelegt, falls es noch keinen gibt — sonst hinge der neue Teil an
     * nichts. Ob am Ziel überhaupt ein Teil entstehen darf, sagt {@see self::createPart()}: dort sitzt
     * [D-541](../../../docs/NewConcept/90-decision-log.md)s Regel, und sie wird hier nicht kopiert.*
     */
    public function addSettingPart(int $nodeId, int $carrierEdgeId): NodeRecord
    {
        return $this->createPart($this->defaultRecordOf($nodeId), $carrierEdgeId);
    }

    /**
     * Die **Teile** einer Einstellung samt ihren Werten — je Trägerkante eine Liste.
     *
     * ⚠️ **Das fehlende Stück, und er hat es gefunden, bevor ich es zugab.** *Der Eigentümer, nachdem
     * ich ihm die Zeichnung erklärt hatte: «auch bezweifle ich, dass dies Datensätze sind, die wir hier
     * sehen — bitte überrasch mich, dass es doch so ist.» **Er hatte recht:** die Bedienelemente in der
     * Wertspalte wurden aus den **Kanten** des Teils gezeichnet, und kein einziger seiner
     * Kanten-Datensätze wurde gelesen. Gemessen an `Passiv`: im Teil steht `render = form`, der
     * Auswahlkasten zeigte nichts.*
     *
     * ⚠️ **Eine Liste je Trägerkante, weil eine Einstellung mehrere Teile haben kann**
     * ([D-548](../../../docs/NewConcept/90-decision-log.md)): *`Display Option` trägt `1..*`, und der
     * Grund ist seiner — «mehrere Display Options bedeutet mehrere Renderer möglich … hatten wir
     * definiert für Farbschema». **Ein Teil ist eine Zeile** der Tabelle
     * ([D-546](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ *Drei Abfragen für einen ganzen Block, gleich wie viele Kanten und Teile: die Sätze des
     * Knotens, ihre Kanten-Datensätze, und die Werte aller Teile in einem Zug
     * ({@see \Taxmod\Core\Repository\RecordRepository::valuesOfMany()}). `CD-7` und
     * [D-159](../../../docs/NewConcept/90-decision-log.md).*
     *
     * @param  list<int>                                  $edgeIds Trägerkanten
     * @return array<int, list<array{id: int, werte: array<int, TypedValue>}>>
     *         Kanten-Id => je Teil seine Satz-Id und seine Werte, geschlüsselt über die **innere** Kante.
     */
    public function settingPartsOf(int $nodeId, array $edgeIds): array
    {
        if ($edgeIds === []) {
            return [];
        }

        $gesucht = [];

        foreach ($edgeIds as $id) {
            $gesucht[(string) $id] = $id;
        }

        // ⚠️ *Auf Modellebene gibt es keine Werte, nur Vorgaben ([D-026](../../../docs/NewConcept/90-decision-log.md)).*
        $satzIds = [];

        foreach ($this->records->ofNode($nodeId) as $satz) {
            if ($satz->kind === RecordKind::Default) {
                $satzIds[] = $satz->id;
            }
        }

        if ($satzIds === []) {
            return [];
        }

        /** @var array<int, list<int>> Kanten-Id => Satz-Ids ihrer Teile, in der Reihenfolge der Werte */
        $teileJeKante = [];
        $alleTeile    = [];

        foreach ($this->records->valuesOfMany($satzIds) as $werte) {
            foreach ($werte as $wert) {
                $kante = $gesucht[$wert->path] ?? null;

                if ($kante === null || $wert->value->reference === null) {
                    continue;
                }

                $teileJeKante[$kante][] = $wert->value->reference;
                $alleTeile[]            = $wert->value->reference;
            }
        }

        if ($alleTeile === []) {
            return [];
        }

        $innere = $this->records->valuesOfMany(array_values(array_unique($alleTeile)));
        $aus    = [];

        foreach ($teileJeKante as $kante => $teile) {
            foreach ($teile as $teilId) {
                $werte = [];

                foreach ($innere[$teilId] ?? [] as $wert) {
                    if (! $wert->value->isNothing()) {
                        $werte[$wert->edgeId] = $wert->value;
                    }
                }

                // ⚠️ *Der Knoten des Teils kommt mit, damit der Rand beim Speichern prüfen kann, ob eine
                // geschickte Kante überhaupt zu diesem Teil gehört (`CD-5`) — ohne dafür noch einmal
                // nachzuschlagen.*
                $satz = $this->records->find($teilId);

                $aus[$kante][] = [
                    'id'     => $teilId,
                    'nodeId' => $satz?->nodeId ?? 0,
                    'werte'  => $werte,
                    // WICHTIG: Die Teile *dieses* Teils, sonst endet der Abstieg hier. Seit D-583
                    // ist ein gewaehlter Renderer selbst ein Datensatz -- ohne diese Zeile zeichnet
                    // die Maske «compact» als Auswahl und seine eigenen Felder nie.
                    'teile'  => $this->partsBelow($teilId, 1),
                ];
            }
        }

        return $aus;
    }

    /**
     * Die Teile eines Teils, rekursiv -- Kanten-Id => Liste von Teilen.
     *
     * WICHTIG: Ein Renderer-Datensatz kann selbst einen tragen (ein Konverter, ein Unter-Renderer).
     * Die Tiefe ist begrenzt, weil ein Knoten auf sich selbst zeigen kann und der Abstieg sonst
     * nie endet -- derselbe Grund, aus dem der Zeichner eine Grenze hat.
     *
     * @return array<int, list<array{id:int, nodeId:int, werte:array<int, TypedValue>, teile:array}>>
     */
    private function partsBelow(int $recordId, int $tiefe): array
    {
        if ($tiefe > self::TEILE_TIEFSTENS) {
            return [];
        }

        $aus = [];

        foreach ($this->records->valuesOf($recordId) as $wert) {
            if ($wert->value->reference === null) {
                continue;
            }

            $satz = $this->records->find($wert->value->reference);

            if ($satz === null) {
                continue;
            }

            $werte = [];

            foreach ($this->records->valuesOf($satz->id) as $innen) {
                if (! $innen->value->isNothing()) {
                    $werte[$innen->edgeId] = $innen->value;
                }
            }

            $aus[$wert->edgeId][] = [
                'id'     => $satz->id,
                'nodeId' => $satz->nodeId,
                'werte'  => $werte,
                'teile'  => $this->partsBelow($satz->id, $tiefe + 1),
            ];
        }

        return $aus;
    }

    /**
     * Der `default`-Satz eines Knotens — angelegt, wenn es noch keinen gibt.
     *
     * ⚠️ *Genau **einer**: mehrere `default`-Sätze an einem Knoten wären zwei Antworten auf eine Frage,
     * und der Leser nimmt den ersten. Gibt es schon einen, wird er benutzt und nicht ein zweiter
     * daneben gestellt.*
     */
    private function defaultRecordOf(int $nodeId): int
    {
        foreach ($this->records->ofNode($nodeId) as $satz) {
            if ($satz->kind === RecordKind::Default) {
                return $satz->id;
            }
        }

        return $this->create($nodeId, RecordKind::Default)->id;
    }

    /**
     * Braucht das Ziel dieser Kante einen eigenen Datensatz?
     */
    private function targetOwnsItsRecord(int $recordId, int $edgeId): bool
    {
        $record = $this->records->find($recordId);

        if ($record === null) {
            return false;
        }

        $edge = $this->edgeOf($record, $edgeId);

        return $this->ownsItsRecord($edge, $this->nodes->byId($edge->toId));
    }

    /**
     * Einen Einstellungsdatensatz **waehlen** -- den vorhandenen umhaengen oder einen anlegen.
     *
     * WICHTIG: Die Zeile *ist* der Datensatz (D-583). Die Wahl erzeugt nichts Zweites: gibt es den
     * Teil schon, bekommt er den neuen Knoten; gibt es ihn nicht, entsteht er. Ein zweiter
     * Erzeugungsweg waere genau das, was der Eigentuemer korrigiert hat.
     *
     * WICHTIG: Der alte Teil wird weggeworfen, wenn ein anderer Renderer gewaehlt wird -- seine
     * Felder sind die des alten Knotens und sagen ueber den neuen nichts. Stehen zu lassen hiesse,
     * Werte zu behalten, die niemand mehr lesen kann.
     */
    /**
     * Dieselbe Wahl, aber am **Knoten** statt an einer Kante — der Ort aus TASK-020.
     *
     * ⚠️ **Eine Spalte, keine Kante** ([D-584](../../../docs/NewConcept/90-decision-log.md)). *Der
     * Zeiger steht in `nodes.settings_record_id`, der Satz ist ein `default`-Satz des gewaehlten
     * Knotens, und seine `node_id` sagt, welcher Renderer es ist.*
     *
     * ⚠️ *Der alte Satz wird vergessen, wenn ein anderer Renderer gewaehlt wird — seine Felder sind
     * die des alten Knotens und sagen ueber den neuen nichts. Dieselbe Begruendung wie bei
     * {@see self::chooseSettingRecord()}, nur eine Ebene hoeher.*
     *
     * ```mermaid
     * flowchart LR
     *   K["Knoten"] -->|"settings_record_id"| S["Satz"]
     *   S -->|"node_id"| R["Renderer"]
     * ```
     */
    public function chooseSettingRecordAtNode(int $nodeId, int $chosenNodeId): void
    {
        $bisher = $this->nodes->settingsRecordIdsOf([$nodeId])[$nodeId] ?? 0;

        if ($bisher !== 0) {
            $satz = $this->records->find($bisher);

            if ($satz !== null && $satz->nodeId === $chosenNodeId) {
                return;
            }

            $this->nodes->rememberSettingsRecord($nodeId, 0);

            if ($satz !== null) {
                $this->records->forgetRecord($bisher);
            }
        }

        $this->nodes->rememberSettingsRecord(
            $nodeId,
            $this->create($chosenNodeId, RecordKind::Default)->id
        );
    }

    private function chooseSettingRecord(int $recordId, int $edgeId, int $chosenNodeId): void
    {
        $teilId = $this->partsOf($recordId)[(string) $edgeId] ?? null;

        if ($teilId !== null) {
            $teil = $this->records->find($teilId);

            if ($teil !== null && $teil->nodeId === $chosenNodeId) {
                return;
            }

            $this->records->forgetRecord($teilId);
        }

        $this->createPart($recordId, $edgeId, '', $chosenNodeId);
    }

    public function createPart(int $recordId, int $edgeId, string $path = '', int $chosenNodeId = 0): NodeRecord
    {
        $record = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);
        $edge   = $this->edgeOf($record, $edgeId);
        $target = $this->nodes->byId($edge->toId);
        $branch = $this->framework->branchOf($target);

        // ⚠️ Refused rather than accommodated: a part is only a part where the branch says the value
        // has records of its own. Anywhere else the value belongs *in* the holder's record and a part
        // would be a second home for it.
        if (! $this->ownsItsRecord($edge, $target)) {
            throw NotYetStorable::thatIsNotAComposedPart($edge->name);
        }

        // ⚠️ **Ein Teil erbt die Art seines Besitzers.** *Ein Teil eines `default`-Satzes ist selbst
        // eine Vorgabe, kein Benutzerwert — [D-026](../../../docs/NewConcept/90-decision-log.md): «at
        // model level there are no values, only defaults».*
        //
        // ⚠️ **Ohne das lässt sich eine Einstellung mit eigenen Feldern nicht als Einstellung markieren.**
        // *Gemessen am 2026-08-30: die 51 Teile von `DisplayOption` waren Art `user`, und
        // {@see self::refuseUnwritable()} verweigert eine Einstellungskante in einem Benutzersatz. Der
        // Eigentümer hatte gerade gefragt, warum `render` und `converter` als **Felder** erscheinen —
        // «nur damit du rendern kannst, das ist falsch» —, und die Antwort hing an dieser Zeile.*
        // ⚠️ **Der Datensatz ist einer des *gewählten* Knotens, nicht des Kantenziels**
        // ([D-584](../../../docs/NewConcept/90-decision-log.md)). *Die Kante zeigt auf den
        // Basisknoten — `Renderer` —, gewählt wird ein erbender: `compact`. **Ohne das wäre jeder
        // Renderer-Datensatz einer von `Renderer` und trüge dessen Felder statt seiner eigenen.***
        //
        // ⚠️ *Geprüft statt geglaubt: der gewählte Knoten muss unter dem Kantenziel liegen. Sonst
        // liesse sich als Renderer irgendein Knoten eintragen, und der Fehler fiele erst beim
        // Zeichnen auf.*
        $gewaehlt = $target;

        if ($chosenNodeId !== 0 && $chosenNodeId !== $target->id) {
            $gewaehlt = $this->nodes->byId($chosenNodeId);

            if (! $gewaehlt->isDescendantOf($target)) {
                throw NotYetStorable::thatIsNotAComposedPart($edge->name);
            }
        }

        $part = $this->create($gewaehlt->id, $record->kind);

        // The holder points at it, which is the whole of the relationship.
        $this->records->putValue(new EdgeRecord(
            $recordId,
            $path === '' ? (string) $edgeId : $path,
            $edgeId,
            '',
            // ⚠️ *Ein **Datensatz**verweis und kein Knotenverweis — die einzige Stelle im Kern, die
            // einen schreibt. Der Raum wandert seit TASK-005 mit in die Spalte `value_ref_kind`.*
            TypedValue::ofRecordReference($part->id)
        ));

        return $part;
    }

    /**
     * Every part a record owns, by the path that reaches it.
     *
     * ⚠️ **Read from the holder's own values**, because that is the only place the link lives. *One
     * query per level rather than one per value: a parts list of thirty rows asks once, which is what
     * `CD-7` is about.*
     *
     * @return array<string, int> path ⇒ the part record's id
     */
    public function partsOf(int $recordId): array
    {
        $record = $this->records->find($recordId);

        if ($record === null) {
            return [];
        }

        $model = $this->nodes->byId($record->nodeId);
        $owned = [];

        foreach ($this->relations->fieldEdgesOf($this->framework->inheritanceOwnersOf($model)) as $edge) {
            $target = $this->nodes->byId($edge->toId);
            $branch = $this->framework->branchOf($target);

            if ($this->ownsItsRecord($edge, $target)) {
                $owned[$edge->id] = true;
            }
        }

        $parts = [];

        foreach ($this->records->valuesOf($recordId) as $value) {
            if (isset($owned[$value->edgeId]) && $value->value->reference !== null) {
                $parts[$value->path] = $value->value->reference;
            }
        }

        return $parts;
    }

    /** Take a value out again, so the attribute is simply unanswered (D-232's three states). */
    public function clear(int $recordId, int $edgeId, string $locale = ''): void
    {
        $this->records->forgetValue($recordId, (string) $edgeId, $locale);
    }

    /**
     * Take a value out again, addressed by its **path** rather than by its edge.
     *
     * ⚠️ **The two are not the same and confusing them removed the wrong row.** A direct attribute's
     * path *is* its edge id, so {@see clear()} reads as if it covered everything — but the second
     * occurrence of a multi-valued member is `<edge>.1`, and clearing by edge silently took out the
     * **first** one. *Found while making a check idempotent: it cleared what it meant to keep.*
     */
    public function clearPath(int $recordId, string $path, string $locale = ''): void
    {
        $this->records->forgetValue($recordId, $path, $locale);
    }

    /** @return list<EdgeRecord> */
    public function valuesOf(int $recordId): array
    {
        return $this->records->valuesOf($recordId);
    }

    /** @return list<NodeRecord> */
    public function recordsOf(int $nodeId): array
    {
        return $this->records->ofNode($nodeId);
    }

    /**
     * Wer diese Datensätze **hält** — je Satz die Wertzeile, die auf ihn zeigt.
     *
     * ⚠️ *Ein Durchgriff und keine Logik: die Frage ist eine Abfrage, und sie steht im Repository, weil
     * sie eine Abfrage ist. Hier steht sie, damit der Rand nicht am Repository vorbei fragen muss.*
     *
     * @param  list<int> $recordIds
     * @return array<int, \Taxmod\Core\Model\EdgeRecord>
     */
    public function holdersOf(array $recordIds): array
    {
        return $this->records->holdersOf($recordIds);
    }
    public function find(int $recordId): ?NodeRecord
    {
        return $this->records->find($recordId);
    }

    /**
     * Every record holding this value at this attribute.
     *
     * @return list<NodeRecord>
     */
    public function findByValue(int $edgeId, TypedValue $value): array
    {
        $found = [];

        foreach ($this->records->findByEdgeValue($edgeId, $value) as $id) {
            $record = $this->records->find($id);

            if ($record !== null) {
                $found[] = $record;
            }
        }

        return $found;
    }

    /**
     * The attribute must belong to the record's model — its own or an inherited one.
     *
     * ⚠️ **Checked rather than trusted.** An edge id arriving from a form is input, and a value
     * written against an attribute the model does not have is a value nothing will ever read.
     */
    private function edgeOf(NodeRecord $record, int $edgeId): \Taxmod\Core\Model\Relation
    {
        $model = $this->nodes->byId($record->nodeId);
        $owned = $this->relations->fieldEdgesOf($this->framework->inheritanceOwnersOf($model));

        foreach ($owned as $edge) {
            if ($edge->id === $edgeId) {
                return $edge;
            }
        }

        throw NotYetStorable::notAFieldOfThisModel($edgeId, $model->name);
    }
}
