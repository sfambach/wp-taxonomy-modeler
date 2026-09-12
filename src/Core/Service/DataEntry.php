<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\FrozenState;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\ReferenceSpace;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\Storage;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Port\Presets;
use Taxmod\Core\Repository\Changelog;
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
        /**
         * Das Änderungsbuch dieses Dienstes — **es gab keines, und das war die gröbere Hälfte der
         * Lücke** ([D-634](../../../docs/NewConcept/90-decision-log.md)).
         *
         * ⚠️ *Gemessen: **4 354 Schattenzeilen bei Datensatzwerten und null Chronikzeilen** — wer
         * einen Wert änderte, erzeugte keine Chronik. Der Schatten wusste alles, das Buch nichts.*
         *
         * ⚠️ *Nachgestellt und nullbar wie bei {@see Labels}, damit die vorhandenen Aufrufer
         * weiterlaufen; der Rand ({@see \Taxmod\WordPress\Plugin}) reicht es durch.*
         */
        private readonly ?Changelog $changelog = null,
        /**
         * Was ein Feld schon trägt, bevor jemand etwas eingetragen hat — {@see Presets}.
         *
         * ⚠️ **Es gibt sie, weil der Datensatz beim **ersten Schreiben** entsteht**
         * ([D-609](../../../docs/NewConcept/90-decision-log.md), sein Wort: *«ein Datensatz entsteht
         * beim ersten Schreiben, nicht beim Ansehen? ja bitte»*). *Genau dort muss die Id des
         * angemeldeten Benutzers hinein — sein Wort zu `user_ref`: «bei Anlegen gibt es noch keinen
         * Datensatz, dann muss hier automatisch die Benutzer-Id hinterlegt werden, damit sie beim
         * Speichern in den Datensatz kommt» ([D-649](../../../docs/NewConcept/90-decision-log.md)).*
         *
         * ⚠️ *Nachgestellt und nullbar wie das Änderungsbuch: ohne die Naht entsteht der Satz wie
         * bisher, nur ohne Vorbelegung.*
         */
        private readonly ?Presets $presets = null,
    ) {
    }

    /**
     * Eine Zeile ins Änderungsbuch — der einzige Weg dieses Dienstes dorthin.
     *
     * ⚠️ **Die Version ist Pflicht und wird an jeder Stelle vom Speicher erfragt**
     * ([D-634](../../../docs/NewConcept/90-decision-log.md)): *{@see RecordRepository::putValue()} und
     * die drei Vergess-Wege geben sie zurück, weil `RelationRecord` sie nicht trägt. **Geraten wird sie
     * nirgends.***
     *
     * ⚠️ *Der Betreff ist der **Datensatz**, nicht die Wertzeile: so steht die Geschichte eines Satzes
     * an einer Stelle beieinander, und die Adresse innerhalb des Satzes trägt der Zustand als `path`.
     * Die Version bleibt die der geänderten Zeile.*
     */
    private function melden(
        int $ownerId,
        string $ownerKind,
        string $what,
        ?string $before,
        ?string $after,
        ?int $version,
    ): void {
        $this->changelog?->record($ownerId, $ownerKind, $what, $before, $after, $version);
    }

    /**
     * Was eine Wertzeile für die Chronik einfriert — Adresse und Wert, im Format aller anderen
     * Melder ({@see FrozenState}).
     *
     * ⚠️ *`value` steht zuletzt, weil es das einzige Feld ist, das Leerzeichen enthalten darf; jede
     * andere Reihenfolge wird von {@see FrozenState::of()} abgewiesen.*
     *
     * ⚠️ **Das Feld heisst `relation`, seit die Adresse eine Kante ist** (Fassung 39, TASK-002,
     * [D-667](../../../docs/NewConcept/90-decision-log.md)). *Die alten Einträge behalten `path` —
     * Geschichte wird nicht umgeschrieben ([D-065](../../../docs/NewConcept/90-decision-log.md)),
     * dieselbe Regel wie beim Umbenennen von `kind` zu `record_type` (TASK-015). **Sie tragen
     * dieselbe Zahl**: jeder gemessene Pfad war die Kanten-Id.*
     */
    private function wertZustand(int $recordId, int $relationId, string $locale, ?TypedValue $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return FrozenState::of([
            'record'   => $recordId,
            'relation' => $relationId,
            'locale'   => $locale,
            'type'     => $value->typeName(),
            'value'    => $value->rawValue(),
        ])->write();
    }

    /** Was ein Datensatz für die Chronik einfriert. */
    private function satzZustand(NodeRecord $record): string
    {
        return FrozenState::of([
            'node'          => $record->nodeId,
            'node_version'  => $record->nodeVersion,
            // ⚠️ *Der Schlüssel heisst wie die Spalte, seit sie `record_type` heisst (TASK-015).
            // **Die alten Einträge behalten `kind`** — Geschichte ist eingefroren
            // ([D-065](../../../docs/NewConcept/90-decision-log.md)), dieselbe Regel wie beim
            // Eintrag «field type set».*
            'record_type'   => $record->recordType->value,
        ])->write();
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
    public function keepsValues(Relation $relation): bool
    {
        // ⚠️ **Die Art der Kante sagt es** ([D-538](../../../docs/NewConcept/90-decision-log.md)).
        // *Seine Herleitung: «für den Benutzer werden ja nur die **Felder** gespeichert, nicht die
        // Settings, weil die Settings Eigenschaften des Modells sind.» **Damit können «nicht
        // speichernd» und «ist eine Einstellung» nie auseinanderfallen** — und zwei Angaben, die nie
        // widersprechen können, sind eine.*
        if ($relation->isSetting()) {
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
     * @param RecordType $kind Wer die Zeile schreibt — ein Mensch, der Autor, oder das Bauen
     *                         ([C65](../../../docs/NewConcept/10-domain-core.md)).
     */
    public function create(int $nodeId, RecordType $kind = RecordType::User): NodeRecord
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
        $hatFelder = $this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($model)) !== [];

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

        // ⚠️ *Eine Änderungsgruppe um den ganzen Akt: der Satz und die Teile, die seine Multiplizität
        // verlangt, sind **eine** Änderung ([D-348](../../../docs/NewConcept/90-decision-log.md)).*
        $this->changelog?->beginAct();

        try {
            $id = $this->records->add($record);

            // ⚠️ *Version 1: die Spalte `records.version` hat genau diese Vorgabe — abgelesen, nicht
            // angenommen.*
            $this->melden($id, 'record', 'record created', null, $this->satzZustand($record), 1);

            $this->ensureRequiredParts($id, $model, $kind);
            $this->ensurePresets($id, $model, $kind);
        } finally {
            $this->changelog?->endAct();
        }

        // ⚠️ *Die Marke faehrt mit, sonst gibt die Methode etwas zurueck, das anders aussieht als das,
        // was sie geschrieben hat. **Gemessen war genau das der Fall**: die Spalte trug `default`, das
        // zurueckgegebene Exemplar sagte `user`.*
        return new NodeRecord($id, $record->nodeId, $record->nodeVersion, $record->createdAt, $record->recordType);
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
    private function ensureRequiredParts(int $recordId, Node $model, RecordType $kind): void
    {
        foreach ($this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($model)) as $relation) {
            if (! $relation->multiplicity->requiresOne() || $relation->hide) {
                continue;
            }

            if ($kind === RecordType::User && ! $this->keepsValues($relation)) {
                continue;
            }

            $ziel = $this->nodes->find($relation->toNodeId);

            if ($ziel === null || ! $this->ownsItsRecord($relation, $ziel)) {
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

            $this->createPart($recordId, $relation->id);
        }
    }

    /**
     * Was ein Feld schon trägt, sobald der Datensatz entsteht — heute genau `user_ref`.
     *
     * ⚠️ **Hier und nicht beim Speichern des Formulars, und der Unterschied ist Datenverlust.**
     * *Beim Anlegen gibt es noch keinen Wert; ein gesperrtes Feld schickt nichts, also käme über den
     * Formularweg nie etwas an. **Und würde die Id bei jedem Speichern nachgetragen, überschriebe der
     * nächste Bearbeiter den, der angelegt hat** — genau die Auskunft, für die das Feld da ist
     * ([D-649](../../../docs/NewConcept/90-decision-log.md): «schreibgeschützt deckt den Fall ‹wer hat
     * das angelegt›»).*
     *
     * ⚠️ **Dieselben Ausschlüsse wie bei {@see self::ensureRequiredParts()}**, aus denselben Gründen:
     * *eine versteckte Kante wird nicht gezeichnet, und was in einem Benutzersatz nichts hält, bekommt
     * dort auch keine Vorbelegung ([D-538](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ *Welche Felder eine Vorbelegung haben, entscheidet **die Typklasse** und nicht dieser Dienst
     * ([D-650](../../../docs/NewConcept/90-decision-log.md)). Er fragt und schreibt.*
     */
    private function ensurePresets(int $recordId, Node $model, RecordType $kind): void
    {
        if ($this->presets === null) {
            return;
        }

        $kanten = [];

        foreach ($this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($model)) as $relation) {
            if ($relation->hide) {
                continue;
            }

            if ($kind === RecordType::User && ! $this->keepsValues($relation)) {
                continue;
            }

            $kanten[$relation->id] = $relation;
        }

        foreach ($this->presets->presetsFor(array_values($kanten)) as $relationId => $value) {
            $this->put($recordId, $relationId, $value);
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
    public function put(int $recordId, int $relationId, TypedValue $value, string $locale = ''): void
    {
        $relation      = $this->writableRelation($recordId, $relationId);
        $vorhanden = $this->valuesOn($recordId, $relation->id, $locale);

        // ⚠️ **Eindeutig heisst geprüft** ([D-735](../../../docs/NewConcept/90-decision-log.md)): *über den Index der Kante,
        // eine Frage je Schreiben — nicht das Auspacken aller Sätze.*
        if ($relation->unique && ! $value->isNothing()) {
            foreach ($this->records->findByRelationValue($relation->id, $value) as $anderer) {
                if ($anderer !== $recordId) {
                    throw NotYetStorable::thatValueIsTaken($relation->name, (string) $value->rawValue(), $anderer);
                }
            }
        }

        // ⚠️ **Sonst schriebe jedes Speichern eine zweite Zeile** ([D-530](../../../docs/NewConcept/90-decision-log.md)).
        // *Bis dahin tat `$wpdb->replace()` das über den eindeutigen Schlüssel; **der ist weg**, und
        // damit muss dieser Dienst sagen, welche Zeile er meint.*
        if (count($vorhanden) > 1) {
            throw NotYetStorable::thatFieldHasSeveralValues($relation->name, count($vorhanden));
        }

        $neu = $vorhanden === []
            ? RelationRecord::direct($recordId, $relation->id, $value, $locale)
            : new RelationRecord($recordId, $relation->id, $locale, $value, $vorhanden[0]->id, $vorhanden[0]->position);

        $version = $this->records->putValue($neu);

        $this->melden(
            $recordId,
            'record_value',
            'value set',
            $this->wertZustand($recordId, $neu->relationId, $locale, $vorhanden[0]->value ?? null),
            $this->wertZustand($recordId, $neu->relationId, $locale, $value),
            $version
        );
    }

    /**
     * Den Wert **des Knotens selbst** setzen — die Wertzeile mit `relation_id = 0`.
     *
     * ⚠️ **[D-673](../../../docs/NewConcept/90-decision-log.md), und es ist der Fall ohne Feld.**
     * *Sein Wort: «ja, macht das mit relation_id gleich null». Ein einfacher Datentyp hat keine
     * eigenen Kanten — `datetime` hat null, `With Label` hat null —, also gab es **kein Fach** für
     * seinen eigenen Wert. Die Wertzeile sagt sonst, **welches Feld** gemeint ist; hier ist keines
     * gemeint, und genau das heisst die Null.*
     *
     * ⚠️ **Nicht {@see self::put()} mit einer 0**, *weil das über {@see self::writableRelation()}
     * geht und dort eine Kante geladen wird, die es nicht gibt. Ein eigener Weg sagt, was er tut;
     * eine 0, die sich durch eine Kantenprüfung mogelt, wäre eine Falle für den nächsten Leser.*
     *
     * ⚠️ **Nur `default` und `example`, nie eine Eingabe**
     * ([D-664](../../../docs/NewConcept/90-decision-log.md),
     * [D-677](../../../docs/NewConcept/90-decision-log.md)): *sein Wort, «combined keine user daten
     * enthält nur example oder default wie bei typ». **Die Prüfung steht hier und nicht nur an der
     * Maske**, sonst hinge sie an einem Bildschirm.*
     */
    public function putOwnValue(int $recordId, TypedValue $value, string $locale = ''): void
    {
        $record = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);

        $vorhanden = $this->valuesOn($recordId, 0, $locale);

        if (count($vorhanden) > 1) {
            throw NotYetStorable::thatFieldHasSeveralValues('', count($vorhanden));
        }

        $neu = $vorhanden === []
            ? RelationRecord::direct($recordId, 0, $value, $locale)
            : new RelationRecord($recordId, 0, $locale, $value, $vorhanden[0]->id, $vorhanden[0]->position);

        $version = $this->records->putValue($neu);

        $this->melden(
            $recordId,
            'record_value',
            'own value set',
            $this->wertZustand($recordId, 0, $locale, $vorhanden[0]->value ?? null),
            $this->wertZustand($recordId, 0, $locale, $value),
            $version
        );
    }

    /**
     * Den eigenen Wert wieder herausnehmen — leer heisst **nicht beantwortet**.
     *
     * ⚠️ *Derselbe dritte Zustand wie bei einem Feld ({@see self::clear()}): ein Wert, eine
     * ausdrückliche Leere und «nichts gesagt» sind drei Dinge, und ohne diesen Weg gäbe es keinen,
     * einen eigenen Wert wieder loszuwerden.*
     */
    public function clearOwnValue(int $recordId, string $locale = ''): void
    {
        $this->wertLeeren($recordId, 0, $locale);
    }

    /** Der eigene Wert eines Satzes, oder `null`, wenn keiner darin steht. */
    public function ownValueOf(int $recordId, string $locale = ''): ?TypedValue
    {
        $meine = $this->valuesOn($recordId, 0, $locale);

        return $meine === [] ? null : $meine[0]->value;
    }

    /**
     * Einen Wert **an einer Verwendungsstelle** setzen — «das Feld B des Feldes A dieses Datensatzes».
     *
     * ⚠️ **Kein neues Mittel, sondern das vorhandene tiefer benutzt.** *Der Eigentümer: «wir haben
     * alle Mittel, einer Kanten-Knoten-Kombination in jeglicher Schachtelung Daten zuzuweisen — warum
     * brauche ich hier ein zusätzliches?» **Das ist die Antwort:** ein Wert im Datensatz des
     * Besitzers, adressiert über die Kette der Kanten.*
     *
     * ⚠️ **Die Kette wird abgegangen und geprüft, aber nicht mehr aufgeschrieben** (Fassung 39,
     * TASK-002). *Adressiert wird über die **letzte** Kante — sie allein sagt, welches Feld gemeint
     * ist, und der Satz sagt, wem er gehört ([D-667](../../../docs/NewConcept/90-decision-log.md)).
     * **Der Wert der Kette bleibt die Prüfung** ({@see self::walkedRelations()}), nicht die Adresse.*
     *
     * @param list<int> $relationIds Von aussen nach innen.
     */
    public function putAt(int $recordId, array $relationIds, TypedValue $value, string $locale = ''): void
    {
        $kette  = $this->walkedRelations($recordId, $relationIds);
        $letzte = $kette[array_key_last($kette)];
        $satz   = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);

        $this->refuseUnwritable($letzte, $satz->recordType);

        $vorhanden = $this->valuesOn($recordId, $letzte->id, $locale);

        if (count($vorhanden) > 1) {
            throw NotYetStorable::thatFieldHasSeveralValues($letzte->name, count($vorhanden));
        }

        $version = $this->records->putValue(
            $vorhanden === []
                ? RelationRecord::direct($recordId, $letzte->id, $value, $locale)
                : new RelationRecord($recordId, $letzte->id, $locale, $value, $vorhanden[0]->id, $vorhanden[0]->position)
        );

        $this->melden(
            $recordId,
            'record_value',
            'value set',
            $this->wertZustand($recordId, $letzte->id, $locale, $vorhanden[0]->value ?? null),
            $this->wertZustand($recordId, $letzte->id, $locale, $value),
            $version
        );
    }

    /**
     * Die Werte an einer Verwendungsstelle, in ihrer Reihenfolge.
     *
     * @param  list<int> $relationIds
     * @return list<RelationRecord>
     */
    public function valuesAt(int $recordId, array $relationIds, string $locale = ''): array
    {
        $kette = $this->walkedRelations($recordId, $relationIds);

        return $this->valuesOn($recordId, $kette[array_key_last($kette)]->id, $locale);
    }

    /**
     * Die Kette abgehen und dabei jede Stufe prüfen.
     *
     * ⚠️ **Das ist inzwischen der ganze Wert dieser Methode.** *Sie setzte einmal auch den Pfad
     * zusammen; **die Spalte ist mit Fassung 39 gefallen** (TASK-002), die Prüfung nicht. Eine Kette
     * wie «Feld 4654, darin Feld 7788» ist nur dann etwas wert, wenn 7788 wirklich ein Feld des
     * Zieles von 4654 ist. **Ohne sie könnte man an jede erfundene Stelle schreiben**, und es fiele
     * erst auf, wenn jemand dort etwas sucht.*
     *
     * @param  list<int>      $relationIds
     * @return list<Relation>
     */
    private function walkedRelations(int $recordId, array $relationIds): array
    {
        if ($relationIds === []) {
            throw new \InvalidArgumentException('Eine Kette ohne Kante adressiert nichts.');
        }

        $record   = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);
        $besitzer = $this->nodes->byId($record->nodeId);
        $kette    = [];

        foreach ($relationIds as $relationId) {
            $gefunden = null;

            foreach ($this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($besitzer)) as $kante) {
                if ($kante->id === $relationId) {
                    $gefunden = $kante;
                }
            }

            if ($gefunden === null) {
                throw NotYetStorable::notAFieldOfThisModel($relationId, $besitzer->name);
            }

            $kette[]  = $gefunden;
            $besitzer = $this->nodes->byId($gefunden->toNodeId);
        }

        return $kette;
    }

    /**
     * Die Zeilen, die ein Feld in diesem Datensatz belegt — in ihrer Reihenfolge.
     *
     * @return list<RelationRecord>
     */
    private function valuesOn(int $recordId, int $relationId, string $locale): array
    {
        $meine = [];

        foreach ($this->records->valuesOf($recordId) as $wert) {
            if ($wert->relationId === $relationId && $wert->locale === $locale) {
                $meine[] = $wert;
            }
        }

        return $meine;
    }

    /**
     * Einen **weiteren** Wert an ein Feld hängen — die Mehrfachheit von der Werteseite.
     *
     * ⚠️ **Mehrere Werte sind mehrere Zeilen auf derselben Kante** ([D-530](../../../docs/NewConcept/90-decision-log.md)).
     * *Hier stand «mehrere Pfade» — der eindeutige Schlüssel `(node_record_id, path, locale)` sah das
     * einmal vor, und **gemessen am 2026-08-30 hatte es niemand je benutzt:** alle 43 Wertzeilen
     * trugen einen Pfad, der schlicht die Kanten-Id war. **Die Spalte ist mit Fassung 39 gefallen**
     * (TASK-002); getrennt werden die Zeilen durch ihre Id, geordnet durch `position`.*
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
    public function appendValue(int $recordId, int $relationId, TypedValue $value, string $locale = ''): void
    {
        $relation     = $this->writableRelation($recordId, $relationId);
        $hinterste = -1;

        foreach ($this->valuesOn($recordId, $relation->id, $locale) as $vorhanden) {
            $hinterste = max($hinterste, $vorhanden->position);
        }

        $neu     = RelationRecord::direct($recordId, $relation->id, $value, $locale, $hinterste + 1);
        $version = $this->records->putValue($neu);

        // ⚠️ *Ein eigenes Verb: ein angehängter Wert **ersetzt** keinen, er stellt sich daneben — ein
        // «value set» ohne Vorher liesse beides gleich aussehen.*
        $this->melden(
            $recordId,
            'record_value',
            'value appended',
            null,
            $this->wertZustand($recordId, $neu->relationId, $locale, $value),
            $version
        );
    }

    /**
     * Wie viele Werte ein Feld in diesem Datensatz trägt.
     *
     * ⚠️ *Über die **Kanten-Id**: alle Werte eines Feldes teilen sich eine Kante
     * ([D-530](../../../docs/NewConcept/90-decision-log.md)).*
     */
    public function countValues(int $recordId, int $relationId): int
    {
        $n = 0;

        foreach ($this->records->valuesOf($recordId) as $value) {
            if ($value->relationId === $relationId) {
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
    private function writableRelation(int $recordId, int $relationId): Relation
    {
        $record = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);
        $relation   = $this->relationOf($record, $relationId);

        $this->refuseUnwritable($relation, $record->recordType);

        return $relation;
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
    private function ownsItsRecord(Relation $relation, Node $target): bool
    {
        if ($relation->isSetting()) {
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
        foreach ($this->relations->fieldRelationsOf([$target->id]) as $relation) {
            if ($relation->fromNodeId === $target->id) {
                return true;
            }
        }

        return false;
    }

    private function refuseUnwritable(Relation $relation, RecordType $kind = RecordType::User): void
    {
        $target = $this->nodes->byId($relation->toNodeId);
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
        if ($kind === RecordType::User && ! $this->keepsValues($relation)) {
            throw NotYetStorable::thatFieldKeepsNothing($relation->name);
        }

        // ⚠️ Refused rather than guessed: a composed part is a record of its own, and nothing
        // here creates one yet. Storing it inline would put the value in the wrong place and
        // look right until somebody tried to share it.
        if ($this->ownsItsRecord($relation, $target)) {
            throw NotYetStorable::compositionsNeedTheirOwnRecords($relation->name);
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
     * reused the first would make them one thing wearing two names. **Zwei Teile an derselben Kante
     * sind zwei Zeilen** ([D-530](../../../docs/NewConcept/90-decision-log.md)), unterschieden durch
     * ihre Id; der Pfad, der sie einmal unterschied, ist mit Fassung 39 gefallen (TASK-002).
     */
    /**
     * Einen zusammengesetzten Teil **an einer Verwendungsstelle** anlegen.
     *
     * ⚠️ **Derselbe Akt wie {@see createPart()}, nur mit geprüfter Kette.** *Jener glaubt die Kante,
     * die man ihm nennt; dieser geht die Kette ab und weist eine Stufe ab, die am Ziel der vorigen
     * kein Feld ist ({@see self::walkedRelations()}). **Ohne die Prüfung könnte man an jede erfundene
     * Stelle schreiben**, und es fiele erst auf, wenn jemand dort etwas sucht.*
     *
     * ⚠️ *Die Kette wird nicht mehr zu einem Pfad zusammengesetzt (Fassung 39, TASK-002): angelegt
     * wird der Teil an der **letzten** Kante, und wem er gehört, sagt sein Satz
     * ([D-667](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * @param list<int> $relationIds Von aussen nach innen; die **letzte** ist die zusammengesetzte Kante.
     */
    public function createPartAt(int $recordId, array $relationIds): NodeRecord
    {
        $kette = $this->walkedRelations($recordId, $relationIds);

        return $this->createPart($recordId, $kette[array_key_last($kette)]->id);
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
    /**
     * Wie viele Benutzersätze dieses Knotens auf dieser Kante Werte tragen — und wie viele Zeilen.
     *
     * ⚠️ *[D-699](../../../docs/NewConcept/90-decision-log.md): wird ein Feld mit Benutzersätzen eine
     * Einstellung, sagt die Seite, was der Haken kostet. Die Zahl kommt von hier.*
     *
     * @return array{records: int, values: int}
     */
    public function userRecordsHoldingValuesOn(int $nodeId, int $relationId): array
    {
        $saetze = 0;
        $zeilen = 0;

        foreach ($this->records->ofNode($nodeId) as $satz) {
            if ($satz->recordType !== RecordType::User) {
                continue;
            }

            $meine = count($this->valuesOn($satz->id, $relationId, ''));

            if ($meine > 0) {
                ++$saetze;
                $zeilen += $meine;
            }
        }

        return ['records' => $saetze, 'values' => $zeilen];
    }

    /**
     * Die Benutzersätze, die auf dieser Kante Werte tragen, in den Schatten — nach seiner Bestätigung.
     *
     * ⚠️ **Sein Wort ([D-699](../../../docs/NewConcept/90-decision-log.md)):** *«der benutzer muss
     * bestätigen die daten werden gelöscht und das setting bekommt neue».* *Gelöscht heisst hier
     * gewandert ([D-536](../../../docs/NewConcept/90-decision-log.md)): jeder Satz geht mit allen seinen
     * Zeilen über {@see self::removeRecord()} in den Schatten und ist von dort zurückholbar.*
     *
     * @return int Wie viele Sätze gingen.
     */
    public function shadowUserRecordsHoldingValuesOn(int $nodeId, int $relationId): int
    {
        $gegangen = 0;

        foreach ($this->records->ofNode($nodeId) as $satz) {
            if ($satz->recordType !== RecordType::User || $this->valuesOn($satz->id, $relationId, '') === []) {
                continue;
            }

            $this->removeRecord($satz->id);
            ++$gegangen;
        }

        return $gegangen;
    }

    /**
     * Die Benutzersätze unter einem Knoten, auf die kein Verwendersatz zeigt — und was sie tragen.
     *
     * ⚠️ **[D-701](../../../docs/NewConcept/90-decision-log.md), sein Beschluss:** *«Fall 1 bleibt ganz,
     * Fall 2 wird gezeigt der benutzer muss bestätigen».* *Fall 1 ist ein Satz, auf den ein anderer Satz
     * zeigt — ein Teil, seine Adresse ist der Verweis (D-541); der bleibt. Fall 2 hat keinen Verwender und
     * damit unter `Primitives` keinen Ort: der wird gezählt, gezeigt, und geht nach Bestätigung.*
     *
     * @return array{records: int, values: int, ids: list<int>}
     */
    public function unheldUserRecordsUnder(Node $root): array
    {
        $knoten = [$root->id => true];

        foreach ($this->nodes->subtreeOf($root) as $unten) {
            $knoten[$unten->id] = true;
        }

        $kandidaten = [];

        foreach ($this->records->ofNodes(array_keys($knoten)) as $saetze) {
            foreach ($saetze as $satz) {
                if ($satz->recordType === RecordType::User && $satz->relationId === 0) {
                    $kandidaten[$satz->id] = $satz;
                }
            }
        }

        if ($kandidaten === []) {
            return ['records' => 0, 'values' => 0, 'ids' => []];
        }

        $gehalten = $this->records->holdersOf(array_keys($kandidaten));
        $ids      = [];
        $zeilen   = 0;

        foreach ($kandidaten as $id => $satz) {
            if (isset($gehalten[$id])) {
                continue;
            }

            $ids[]   = $id;
            $zeilen += count($this->records->valuesOf($id));
        }

        return ['records' => count($ids), 'values' => $zeilen, 'ids' => $ids];
    }

    /**
     * Die Benutzersätze ohne Verwender unter einem Knoten in den Schatten — nach seiner Bestätigung (D-701).
     *
     * @return int Wie viele Sätze gingen.
     */
    public function shadowUnheldUserRecordsUnder(Node $root): int
    {
        $gegangen = 0;

        foreach ($this->unheldUserRecordsUnder($root)['ids'] as $id) {
            $this->removeRecord($id);
            ++$gegangen;
        }

        return $gegangen;
    }

    /**
     * Eine Angabe **an einer Verwendungsstelle** festschreiben — «hier, an dieser einen Kante».
     *
     * ⚠️ **Das Stück, das gefehlt hat** ([`INF-011`](../../../docs/pakete/modelltabellen/inbox.md)).
     * *{@see self::putSettingAt()} schreibt am **Knoten**; für `label_role` an `Einheitenwert.prefix`
     * gab es genau einen Schreiber, und der war `Settings::put()` in die mit
     * [D-579](../../../docs/NewConcept/90-decision-log.md) gestrichene Tabelle. **Zwei Wächter legten
     * die Zeile deshalb selbst über die Speicher an** — ein Behelf in einer Prüfung.*
     *
     * ⚠️ **Keine neue Ablage, und das ist der ganze Punkt.** *Die Adresse steht schon fest, weil der
     * Leser sie schon liest: {@see ModelValues::forUseSite()} sucht im Satz des **Besitzers** unter
     * `<Verwendungsstelle>.<Einstellungskante>`. Hier wird nur an dieselbe Stelle geschrieben —
     * {@see self::putAt()} tut es, samt geprüfter Adresse.*
     *
     * ⚠️ *Der Satz ist der **`default`** des Besitzers, wie bei jeder Angabe des Modells
     * ([D-026](../../../docs/NewConcept/90-decision-log.md): «at model level there are no values, only
     * defaults»). Der Besitzer ist `fromNodeId` und nicht das Ziel — sonst stünde die Angabe am Typ und
     * gälte für alle, die ihn verwenden, was genau die Unterscheidung ist, um die es hier geht.*
     *
     * ```mermaid
     * flowchart LR
     *   B["Besitzer"] --> D["sein default-Satz"]
     *   D -->|"Kanten-Id . Einstellungskante"| W["der Wert dieser einen Stelle"]
     * ```
     */

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
                    $werte[$innen->relationId] = $innen->value;
                }
            }

            $aus[$wert->relationId][] = [
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

    /**
     * Derselbe Satz, aber **nur gesucht** — `0`, wenn es ihn nicht gibt.
     *
     * ⚠️ **[D-609](../../../docs/NewConcept/90-decision-log.md): ein Datensatz entsteht beim ersten
     * Schreiben, nicht beim Ansehen.** *Wer liest oder löscht, fragt hier; nur wer schreibt, ruft
     * {@see self::settingsRecordOf()} und nimmt das Anlegen in Kauf. **Die beiden Wege getrennt zu
     * haben ist der ganze Fix von BUG-004** — vorher gab es nur den anlegenden.*
     */
    /**
     * Der Einstellungssatz eines Knotens — angelegt beim ersten Schreiben ([D-609](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Die vierte Satzart** ([D-704](../../../docs/NewConcept/90-decision-log.md)): *hier wohnen die
     * Einstellungen; der `default`-Satz daneben trägt Vorgabewerte ([D-524](../../../docs/NewConcept/90-decision-log.md))
     * und den eigenen Wert ([D-673](../../../docs/NewConcept/90-decision-log.md)). Bis zum 2026-09-09 war
     * das ein Satz mit einem Wort für zwei Dinge.*
     */
    private function settingsRecordOf(int $nodeId): int
    {
        $vorhanden = $this->findSettingsRecord($nodeId);

        return $vorhanden !== 0 ? $vorhanden : $this->create($nodeId, RecordType::Settings)->id;
    }

    private function findSettingsRecord(int $nodeId): int
    {
        foreach ($this->records->ofNode($nodeId) as $satz) {
            if ($satz->recordType === RecordType::Settings && $satz->relationId === 0) {
                return $satz->id;
            }
        }

        return 0;
    }

    /**
     * Dieselbe Frage, **ohne** dass es einen Datensatz geben muss.
     *
     * ⚠️ *Die Antwort hängt an der Kante und am Zielknoten — der Datensatz war nur der Umweg, über den
     * der Knoten gefunden wurde. Ihn dafür anzulegen wäre genau das, was
     * [D-609](../../../docs/NewConcept/90-decision-log.md) verbietet.*
     */
    private function targetOwnsItsRecordAtNode(int $nodeId, int $relationId): bool
    {
        $relation = $this->fieldRelationOf($nodeId, $relationId);

        return $this->ownsItsRecord($relation, $this->nodes->byId($relation->toNodeId));
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
    private function chooseSettingRecord(int $recordId, int $relationId, int $chosenNodeId): void
    {
        // ⚠️ **Die Wahl ist ein Verweis auf den *Knoten*, nicht auf einen Teildatensatz**
        // ([D-684](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «knoten record ->
        // relation_record parallel zum konstrukt des knotens».*
        //
        // ⚠️ **Was der Teil gekostet hat, gemessen:** *dreimal umgestellt hiess dreimal ein neuer
        // Satz — 13520, 13521, 13522 —, und die alten blieben herrenlos liegen. **Das war sein
        // «default wert, der nach dem Speichern wiederkommt»** (`INF-071`, `INF-073`). Jetzt ändert
        // ein Wechsel **eine Wertzeile**, und es gibt nichts, was übrig bleiben könnte.*
        //
        // ⚠️ *Ein Teil aus der alten Form wird beim ersten Schreiben mit aufgelöst — seine Werte
        // sind schon gewandert ({@see scripts/dev/setting-part-migrate.php}), er selbst ist dann nur
        // noch die leere Hülle.*
        $teilId = $this->partsOf($recordId)[$relationId] ?? null;

        if ($teilId !== null) {
            $teil = $this->records->find($teilId);

            // ⚠️ **Erst der Verweis, dann der Teil — und ohne die erste Zeile blieb ein Zeiger auf
            // einen Satz stehen, den es nicht mehr gibt.** *{@see RecordRepository::forgetRecord()}
            // raeumt die Werte **des Teils** weg und den Teil selbst; die Zeile im Besitzer, die auf
            // ihn zeigt, gehoert ihm nicht und blieb liegen. {@see self::createPart()} legt danach
            // eine **zweite** an, weil eine Wertzeile ohne Id immer eingefuegt wird.*
            //
            // ⚠️ *Gemessen am 2026-09-05, als der Renderer mit TASK-057 auf seine Kante zurueckzog:
            // **drei Zeilen an einer Kante mit `1..1`** nach drei Wahlen, und die Aufloesung nahm die
            // erste — also die aelteste. **Der Fehler war schon da; die Spalte hatte ihn nur
            // zugedeckt**, weil sie eine Zahl haelt und keine Zeilen.*
            $this->records->forgetValue($recordId, $relationId, '');

            $this->satzEntfernen($teilId);
        }

        // ⚠️ *Über {@see RecordRepository::putValue()} und nicht über {@see self::put()}: die Prüfung
        // dort verlangt für ein Ziel mit eigenen Feldern einen Teil — **und den gibt es nicht mehr**.
        // Die Regel ist nicht umgangen, sie ist mit [D-684](../../../docs/NewConcept/90-decision-log.md)
        // gefallen.*
        $vorhanden = $this->valuesOn($recordId, $relationId, '');
        $wert      = TypedValue::ofReference($chosenNodeId);
        $vorher    = $this->wertZustand($recordId, $relationId, '', $vorhanden[0]->value ?? null);

        $version = $this->records->putValue($vorhanden === []
            ? RelationRecord::direct($recordId, $relationId, $wert, '')
            : new RelationRecord($recordId, $relationId, '', $wert, $vorhanden[0]->id, $vorhanden[0]->position));

        $this->melden(
            $recordId,
            'record_value',
            'setting chosen',
            $vorher,
            $this->wertZustand($recordId, $relationId, '', $wert),
            $version
        );
    }

    public function createPart(int $recordId, int $relationId, int $chosenNodeId = 0): NodeRecord
    {
        $record = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);
        $relation   = $this->relationOf($record, $relationId);
        $target = $this->nodes->byId($relation->toNodeId);
        $branch = $this->framework->branchOf($target);

        // ⚠️ Refused rather than accommodated: a part is only a part where the branch says the value
        // has records of its own. Anywhere else the value belongs *in* the holder's record and a part
        // would be a second home for it.
        if (! $this->ownsItsRecord($relation, $target)) {
            throw NotYetStorable::thatIsNotAComposedPart($relation->name);
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
                throw NotYetStorable::thatIsNotAComposedPart($relation->name);
            }
        }

        // ⚠️ *Der Teil und der Verweis auf ihn sind **ein** Akt: ohne die Klammer stünde der Satz in
        // einer Änderungsgruppe und der Verweis, der ihn hält, in einer anderen.*
        $this->changelog?->beginAct();

        try {
            return $this->teilAnlegen($recordId, $relationId, $gewaehlt, $record->recordType);
        } finally {
            $this->changelog?->endAct();
        }
    }

    /** Der Teil selbst und der Verweis, der ihn hält — innerhalb der Klammer von {@see createPart()}. */
    private function teilAnlegen(int $recordId, int $relationId, Node $gewaehlt, RecordType $kind): NodeRecord
    {
        $part = $this->create($gewaehlt->id, $kind);

        // The holder points at it, which is the whole of the relationship.
        // ⚠️ *Ein **Datensatz**verweis und kein Knotenverweis — die einzige Stelle im Kern, die
        // einen schreibt. Der Raum wandert seit TASK-005 mit in die Spalte `value_ref_kind`.*
        $verweis = TypedValue::ofRecordReference($part->id);

        $version = $this->records->putValue(RelationRecord::direct($recordId, $relationId, $verweis));

        $this->melden(
            $recordId,
            'record_value',
            'part linked',
            null,
            $this->wertZustand($recordId, $relationId, '', $verweis),
            $version
        );

        return $part;
    }

    /**
     * Every part a record owns, by the relation that reaches it.
     *
     * ⚠️ **Read from the holder's own values**, because that is the only place the link lives. *One
     * query per level rather than one per value: a parts list of thirty rows asks once, which is what
     * `CD-7` is about.*
     *
     * ⚠️ *Geschlüsselt über die **Kante** und nicht mehr über einen Pfad (Fassung 39, TASK-002).
     * **Trägt eine Kante mehrere Teile** ([D-548](../../../docs/NewConcept/90-decision-log.md)),
     * nennt diese Liste den ersten — sie beantwortet «welcher Teil hängt an dieser Kante», und wer
     * alle braucht, fragt {@see self::settingPartsOf()}.*
     *
     * @return array<int, int> Kanten-Id ⇒ the part record's id
     */
    public function partsOf(int $recordId): array
    {
        $record = $this->records->find($recordId);

        if ($record === null) {
            return [];
        }

        $model = $this->nodes->byId($record->nodeId);
        $owned = [];

        foreach ($this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($model)) as $relation) {
            $target = $this->nodes->byId($relation->toNodeId);
            $branch = $this->framework->branchOf($target);

            if ($this->ownsItsRecord($relation, $target)) {
                $owned[$relation->id] = true;
            }
        }

        $parts = [];

        // ⚠️ **Ein Verweis auf einen *Knoten* ist kein Teil** ([D-684](../../../docs/NewConcept/90-decision-log.md)).
        // *Seit die Renderer-Wahl den gewählten Knoten nennt statt einen Teildatensatz, stünde hier
        // sonst eine Knotennummer als Satznummer — **gemessen: «There is no record 43511», und 43511
        // ist der Knoten `form`.** Der Raum steht an der Zeile ([D-597](../../../docs/NewConcept/90-decision-log.md)),
        // also wird er gefragt und nicht geraten.*
        foreach ($this->records->valuesOf($recordId) as $value) {
            if (isset($owned[$value->relationId])
                && $value->value->reference !== null
                && $value->value->referenceSpace !== ReferenceSpace::Node
            ) {
                $parts[$value->relationId] ??= $value->value->reference;
            }
        }

        return $parts;
    }

    /**
     * Take a value out again, so the attribute is simply unanswered (D-232's three states).
     *
     * ⚠️ **Hier stand ein zweiter Weg `clearPath()` daneben, und er ist mit der Spalte gefallen**
     * (Fassung 39, TASK-002). *Er nahm eine Adresse als Text entgegen, weil der zweite Wert eines
     * Feldes einmal `<Kante>.1` hiess — **gemessen hat so keine lebende Zeile je geheissen**, und
     * mehrere Werte sind seit [D-530](../../../docs/NewConcept/90-decision-log.md) mehrere Zeilen auf
     * derselben Kante. **Wer genau eine davon meint, nimmt ihre Id** ({@see self::removeRecord()},
     * {@see \Taxmod\Core\Repository\RecordRepository::forgetValueById()}), nicht einen längeren Text.*
     */
    public function clear(int $recordId, int $relationId, string $locale = ''): void
    {
        $this->wertLeeren($recordId, $relationId, $locale);
    }

    /**
     * Eine Wertzeile herausnehmen **und es melden**.
     *
     * ⚠️ *Der Vorher-Zustand wird vor dem Entfernen gelesen — danach gäbe es ihn nicht mehr, und ein
     * «gelöscht, aber was?» ist keine Chronik. Die Version, die diese Änderung erzeugt hat, gibt der
     * Speicher zurück: es ist die, mit der die Zeile in den Schatten geht.*
     */
    private function wertLeeren(int $recordId, int $relationId, string $locale): void
    {
        $vorher = null;

        foreach ($this->records->valuesOf($recordId) as $stand) {
            if ($stand->relationId === $relationId && $stand->locale === $locale) {
                $vorher = $this->wertZustand($recordId, $relationId, $locale, $stand->value);
                break;
            }
        }

        $version = $this->records->forgetValue($recordId, $relationId, $locale);

        // ⚠️ *Nichts zu löschen ist kein Ereignis — ein Buch, das Nicht-Ereignisse aufschreibt, liest
        // niemand (dieselbe Regel wie bei {@see Labels}).*
        if ($vorher === null && $version === null) {
            return;
        }

        $this->melden($recordId, 'record_value', 'value cleared', $vorher, null, $version);
    }

    /**
     * **Genau die** Zeile, die einen Teil hält — über ihre Id, nicht über ihre Kante.
     *
     * ⚠️ **Der Unterschied zählt, seit die Adresse eine Kante ist** (Fassung 39, TASK-002). *Eine
     * Kante mit `1..*` trägt mehrere Teile ([D-548](../../../docs/NewConcept/90-decision-log.md)),
     * und die sind mehrere **Zeilen** auf derselben Kante
     * ([D-530](../../../docs/NewConcept/90-decision-log.md)). Über die Kante geleert fielen **alle** —
     * also über die Id. **Vorher unterschied der Pfad sie**, und genau diese Unterscheidung darf mit
     * ihm nicht verlorengehen.*
     */
    private function halterZeileLeeren(RelationRecord $halter): void
    {
        if ($halter->id === null) {
            return;
        }

        $vorher  = $this->wertZustand($halter->recordId, $halter->relationId, $halter->locale, $halter->value);
        $version = $this->records->forgetValueById($halter->id);

        if ($version === null) {
            return;
        }

        $this->melden($halter->recordId, 'record_value', 'value cleared', $vorher, null, $version);
    }

    /**
     * Einen Datensatz entfernen — **umkehrbar**, mit Schattenzeilen, Änderungsgruppe und Version.
     *
     * ⚠️ **Sein Beschluss** ([D-653](../../../docs/NewConcept/90-decision-log.md)): *«Baue mal die
     * Auswahl und das Löschen».* **Der Weg dorthin war schon da und nur nicht erreichbar:**
     * *{@see self::satzEntfernen()} meldet seit jeher, und {@see \Taxmod\Core\Repository\RecordRepository::forgetRecord()}
     * legt Satz und Werte in den Schatten ([D-536](../../../docs/NewConcept/90-decision-log.md),
     * [D-537](../../../docs/NewConcept/90-decision-log.md)) — es gab nur keinen öffentlichen Weg
     * dahin, sondern nur den Nebenweg der Renderer-Wahl.*
     *
     * ⚠️ **Ist der Satz ein *Teil*, geht die Zeile mit, die auf ihn zeigt** — *sonst bliebe ein
     * `value_ref` auf einen Satz stehen, den es nicht mehr gibt. **Genau dieser Fehler ist am
     * 2026-09-05 schon einmal gemacht worden**, als die Renderer-Wahl den Teil wegnahm und den
     * Zeiger stehenliess ({@see self::chooseSettingRecord()}); `dangling-reference-check` misst ihn.*
     *
     * ⚠️ *Beides in **einer** Änderungsgruppe ([D-348](../../../docs/NewConcept/90-decision-log.md)):
     * der Zeiger und der Satz sind eine Handlung, und ein Rückgängig, das nur die Hälfte zurückholt,
     * wäre keines.*
     */
    public function removeRecord(int $recordId): void
    {
        $satz = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);

        $halter = $this->records->holdersOf([$satz->id])[$satz->id] ?? null;

        $this->changelog?->beginAct();

        try {
            if ($halter !== null) {
                $this->halterZeileLeeren($halter);
            }

            $this->satzEntfernen($recordId);
        } finally {
            $this->changelog?->endAct();
        }
    }

    /**
     * Die **Art** eines bestehenden Datensatzes umstellen.
     *
     * ⚠️ **Sein Wort:** *«default / user / example muss einstellbar sein.»* *Gewaehlt wurde sie beim
     * Anlegen ([D-651](../../../docs/NewConcept/90-decision-log.md),
     * [D-653](../../../docs/NewConcept/90-decision-log.md)) und danach nie wieder — sie stand als
     * Spalte da, und was man einmal falsch waehlt, blieb falsch.*
     *
     * ⚠️ **Was umgestellt wird, ist eine Wirkung und keine Beschriftung**
     * ([D-654](../../../docs/NewConcept/90-decision-log.md)): *«der Unterschied ist, dass der
     * `default` eine Vorgabe macht, die auch bei der Eingabe verwendet werden soll — eine
     * Vorbelegung.»* **Ein Satz, der zu `default` wird, greift ab sofort in jeden neuen Datensatz
     * dieses Knotens ein; einer, der es aufhoert zu sein, tut es nicht mehr.**
     *
     * ⚠️ *Gleiche Art heisst: nichts geschieht. **Kein Akt, keine Version, keine Schattenzeile** — ein
     * Formular, das seinen eigenen Zustand zurueckschickt, ist kein Ereignis, und eine Chronik voll
     * solcher Zeilen waere die Chronik unbrauchbar.*
     */
    public function retypeRecord(int $recordId, RecordType $kind): void
    {
        $satz = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);

        if ($satz->recordType === $kind) {
            return;
        }

        $vorher = $this->satzZustand($satz);

        $this->changelog?->beginAct();

        try {
            $version = $this->records->retypeRecord($recordId, $kind);

            $this->melden(
                $recordId,
                'record',
                'record retyped',
                $vorher,
                // ⚠️ *Der Zustand danach wird aus dem Satz gebaut, den wir schon haben — ein zweites
                // Lesen aus der Datenbank waere eine Abfrage fuer eine Angabe, die hier feststeht.*
                $this->satzZustand(new NodeRecord(
                    $satz->id,
                    $satz->nodeId,
                    $satz->nodeVersion,
                    $satz->createdAt,
                    $kind
                )),
                $version
            );
        } finally {
            $this->changelog?->endAct();
        }
    }

    /** Einen Datensatz entfernen **und es melden** — mit dem Zustand, den er zuletzt hatte. */
    private function satzEntfernen(int $recordId): void
    {
        $satz    = $this->records->find($recordId);
        $version = $this->records->forgetRecord($recordId);

        if ($satz === null) {
            return;
        }

        $this->melden($recordId, 'record', 'record removed', $this->satzZustand($satz), null, $version);
    }

    /** @return list<RelationRecord> */
    public function valuesOf(int $recordId): array
    {
        return $this->records->valuesOf($recordId);
    }

    /**
     * Welche dieser Datensätze überhaupt eine Wertzeile tragen — **in einer Abfrage** (`CD-7`).
     *
     * ⚠️ **Gebraucht, seit ein leerer `default` nicht mehr als vorhanden zählt**
     * ([D-653](../../../docs/NewConcept/90-decision-log.md)): *«ist ein `default`-Satz da **und
     * gefuellt**, zeichnet er; sonst der `example`-Satz».* **Ohne diese Frage kann der Zeichenweg
     * «gefüllt» nicht von «da» unterscheiden** — und gemessen sind 390 von 454 `default`-Sätzen leer.
     *
     * @param  list<int> $recordIds
     * @return list<int> Die Ids derer, die mindestens eine Wertzeile haben.
     */
    public function filledAmong(array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        $aus = [];

        foreach ($this->records->valuesOfMany($recordIds) as $recordId => $werte) {
            if ($werte !== []) {
                $aus[] = $recordId;
            }
        }

        return $aus;
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
     * @return array<int, \Taxmod\Core\Model\RelationRecord>
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
    public function findByValue(int $relationId, TypedValue $value): array
    {
        $found = [];

        foreach ($this->records->findByRelationValue($relationId, $value) as $id) {
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
     * ⚠️ **Checked rather than trusted.** An relation id arriving from a form is input, and a value
     * written against an attribute the model does not have is a value nothing will ever read.
     */
    private function relationOf(NodeRecord $record, int $relationId): \Taxmod\Core\Model\Relation
    {
        return $this->fieldRelationOf($record->nodeId, $relationId);
    }

    /**
     * Dieselbe Suche am Knoten statt am Datensatz — die Kette ist ohnehin die des Knotens.
     */
    private function fieldRelationOf(int $nodeId, int $relationId): \Taxmod\Core\Model\Relation
    {
        $model = $this->nodes->byId($nodeId);
        $owned = $this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($model));

        foreach ($owned as $relation) {
            if ($relation->id === $relationId) {
                return $relation;
            }
        }

        throw NotYetStorable::notAFieldOfThisModel($relationId, $model->name);
    }

    /**
     * Eine Feldzeile an diesem Knoten um einen Schritt verschieben — auch eine geerbte.
     *
     * ⚠️ **[D-698](../../../docs/NewConcept/90-decision-log.md), sein Wort:** *«würde sagen kind darf
     * felder neu anordnen».* *Die Anordnung wohnt an derselben Adresse wie jede andere Einstellung der
     * Stelle — der Satz `Knoten × Kante` ([D-667](../../../docs/NewConcept/90-decision-log.md)), darin
     * `position`. Die Kante des Besitzers bleibt, wie sie ist.*
     *
     * ⚠️ **Geschrieben wird die ganze Liste, nicht nur die zwei Getauschten.** *Eine Position ist nur
     * gegen die anderen eine Aussage; stünde sie allein, hinge die Reihenfolge davon ab, welche Zeile
     * ein Vorfahre später noch anordnet. Mit der ganzen Liste sagt der Knoten, was er sieht.*
     *
     * @return bool Ob sich etwas bewegt hat — `false` am Rand der Liste.
     */
    public function moveFieldAt(int $nodeId, array $relations, int $relationId, int $direction): bool
    {
        $ordnung = new FieldOrder($this->records, $this->relations, $this->nodes, $this->framework);
        $kante   = $ordnung->positionRelation();
        $liste   = $kante === null ? null : $ordnung->movedAt($nodeId, $relations, $relationId, $direction);

        if ($kante === null || $liste === null) {
            return false;
        }

        $traeger = $this->nodes->byId($nodeId);

        $this->changelog?->beginAct();

        try {
            foreach ($liste as $stelle => $zeile) {
                $satz = $this->records->ofRelationAt($nodeId, $zeile->id);

                if ($satz === null) {
                    $neu    = new NodeRecord(0, $nodeId, $traeger->version, $this->clock->now()->format('Y-m-d H:i:s'), RecordType::Settings, $zeile->id);
                    $satzId = $this->records->add($neu);
                    $this->melden($satzId, 'record', 'record created', null, $this->satzZustand($neu), 1);
                } else {
                    $satzId = $satz->id;
                }

                // ⚠️ *Wie {@see self::putSettingAtUseSite()}, nicht über {@see self::put()}: der prüft
                // das Ziel der Kante, und `Integer` wohnt unter `Compositions` — eine Einstellung ist
                // aber kein Teil, sie ist eine Zahl im Satz der Stelle.*
                $wert      = TypedValue::ofInt($stelle);
                $vorhanden = $this->valuesOn($satzId, $kante->id, '');
                $version   = $this->records->putValue(
                    $vorhanden === []
                        ? RelationRecord::direct($satzId, $kante->id, $wert, '')
                        : new RelationRecord($satzId, $kante->id, '', $wert, $vorhanden[0]->id, $vorhanden[0]->position)
                );
                $this->melden(
                    $satzId,
                    'record_value',
                    'value set',
                    $this->wertZustand($satzId, $kante->id, '', $vorhanden[0]->value ?? null),
                    $this->wertZustand($satzId, $kante->id, '', $wert),
                    $version
                );
            }
        } finally {
            $this->changelog?->endAct();
        }

        return true;
    }
}
