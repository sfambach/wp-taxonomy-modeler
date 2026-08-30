<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Branch;
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
    /**
     * @param Settings|null $settings Optional so the existing wiring keeps working; without it the
     *                                `persistent` flag cannot be resolved and every attribute is
     *                                treated as persistent, which is the default anyway.
     */
    public function __construct(
        private readonly RecordRepository $records,
        private readonly RelationRepository $relations,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
        private readonly Clock $clock,
        private readonly ?Settings $settings = null,
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
        if ($this->settings === null) {
            return true;
        }

        $resolved = $this->settings->resolve($this->settings->chainForUseSite($edge));

        // ⚠️ **`??` before `?->`, because a missing array key is not a null object.** The two look
        // alike and are not: `$a['x']?->y` on an absent key raises a warning and then yields null, so
        // it *appears* to work. **`RenderContext::setting()` carries this exact warning in its own
        // docblock and I wrote the bug two files away** — found by storing a real value, because
        // `persistent` is unset on almost every attribute.
        return ($resolved[SettingKey::Persistent->value] ?? null)?->value->asBool()
            // ⚠️ **The key answers, not this line** ([D-401](../../../docs/NewConcept/90-decision-log.md)).
            // *This `?? true` is the one the owner caught twice: it made the data layer read
            // `persistent` as on while the switch drew it off, so the control stated the opposite of
            // what was in force.*
            ?? SettingKey::Persistent->defaultSwitch();
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
        $hatFelder = $this->relations->fieldEdgesOf([...$model->ancestorIds(), $model->id]) !== [];

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

        // ⚠️ *Die Marke faehrt mit, sonst gibt die Methode etwas zurueck, das anders aussieht als das,
        // was sie geschrieben hat. **Gemessen war genau das der Fall**: die Spalte trug `default`, das
        // zurueckgegebene Exemplar sagte `user`.*
        return new NodeRecord($id, $record->nodeId, $record->nodeVersion, $record->createdAt, $record->kind);
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

            foreach ($this->relations->fieldEdgesOf([...$besitzer->ancestorIds(), $besitzer->id]) as $kante) {
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
        if ($branch->storage() === Storage::OwnRecords) {
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

    public function createPart(int $recordId, int $edgeId, string $path = ''): NodeRecord
    {
        $record = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);
        $edge   = $this->edgeOf($record, $edgeId);
        $target = $this->nodes->byId($edge->toId);
        $branch = $this->framework->branchOf($target);

        // ⚠️ Refused rather than accommodated: a part is only a part where the branch says the value
        // has records of its own. Anywhere else the value belongs *in* the holder's record and a part
        // would be a second home for it.
        if ($branch === null || $branch->storage() !== Storage::OwnRecords) {
            throw NotYetStorable::thatIsNotAComposedPart($edge->name);
        }

        $part = $this->create($target->id);

        // The holder points at it, which is the whole of the relationship.
        $this->records->putValue(new EdgeRecord(
            $recordId,
            $path === '' ? (string) $edgeId : $path,
            $edgeId,
            '',
            TypedValue::ofReference($part->id)
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

        foreach ($this->relations->fieldEdgesOf([...$model->ancestorIds(), $model->id]) as $edge) {
            $target = $this->nodes->byId($edge->toId);
            $branch = $this->framework->branchOf($target);

            if ($branch !== null && $branch->storage() === Storage::OwnRecords) {
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
        $owned = $this->relations->fieldEdgesOf([...$model->ancestorIds(), $model->id]);

        foreach ($owned as $edge) {
            if ($edge->id === $edgeId) {
                return $edge;
            }
        }

        throw NotYetStorable::notAFieldOfThisModel($edgeId, $model->name);
    }
}
