<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\TypeNodes;
use Taxmod\Core\Service\ModelEditor;

/**
 * The base scaffold — the simple data types, imported **once**.
 *
 * ⚠️ **Once, and then hands off** (D-119). After the import these are ordinary authored content:
 * a model that never needs `color` may throw it away, and reactivating the plugin must not bring
 * it back. That is what the stored version guards — not *do the nodes exist*, but *has the
 * scaffold been delivered*. Asking the first question instead would quietly undo the owner's
 * deletions on every activation, which is exactly the kind of helpfulness nobody asked for.
 *
 * ⚠️ **Not framework-protected** ([D-194](../../../docs/NewConcept/90-decision-log.md)):
 * protection covers the handful of nodes the machinery stands on, and a data type is not one of
 * them.
 *
 * ⚠️ **Composed types are not here yet.** `quantity`, `money`, `range`, `period`, `tolerance`,
 * `ratio`, `address`, `markup` and `Link` are composed *of* attributes, so seeding them means
 * seeding relations — a bigger step, and one that wants the renderers to be worth looking at.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class BaseScaffold
{
    public const OPTION = 'taxmod_base_scaffold';

    /** Raise it only to deliver something genuinely new; every raise re-enters every install. */
    public const VERSION = 6;

    public function __construct(
        private readonly ModelEditor $editor,
        private readonly FrameworkNodes $framework,
        /**
         * ⚠️ **Required, because remembering the id is part of seeding** ([D-510](../../../docs/NewConcept/90-decision-log.md)).
         * *A scaffold that creates the node and writes nothing down leaves the next reader with only
         * the name to go on — which is the fault this replaces.*
         */
        private readonly TypeNodes $typeNodes,
    ) {
    }

    /** @return list<string> The names actually created, so a caller can report what it did. */
    public function importOnce(): array
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return [];
        }

        $created = $this->import();

        $this->boundTheNumbers();
        $this->declareKeyDefaults();

        update_option(self::OPTION, self::VERSION, true);

        return $created;
    }

    /**
     * Create what is not there, whatever the stored version says.
     *
     * Separate from {@see importOnce()} so a check script can call it directly — and so the
     * *once* is a decision of the caller rather than something buried in the writing.
     *
     * @return list<string>
     */
    public function import(): array
    {
        $dataTypes = $this->framework->rootOf(Branch::DataTypes);

        $taken = [];
        $byId  = [];

        foreach ($this->editor->childrenOf($dataTypes->id) as $child) {
            // ⚠️ **`??=` und nicht `=`: der erste Treffer gewinnt, nicht der letzte.** *Gemessen am
            // 2026-08-29: `childrenOf()` liefert nach Position, und ein zweiter Knoten namens
            // `Integer` stand hinter dem gesäten — also band die Saat sich an den **Doppelgänger** und
            // legte beim nächsten Lauf einen dritten `Integer` an. [D-022](../../../docs/NewConcept/90-decision-log.md)
            // sagt, dass Knotennamen absichtlich nicht eindeutig sind; «der letzte gewinnt» ist dazu
            // keine Regel, sondern ein Zufall.*
            $taken[$child->name] ??= $child;
            $byId[$child->id]      = $child;
        }

        $created = [];

        // ⚠️ **The short machine name becomes the spelled-out one** ([D-428](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *the data type `int` is shown as `int`, `decimal` as `decimal` — unify that, for
        // `int` = `Integer`.* **A rename and not a label**, because
        // [D-369](../../../docs/NewConcept/90-decision-log.md) says the modelling tree shows a node's
        // own name — *«there I would take the node name»* — so a label would not appear there at all.
        //
        // ⚠️ *Renaming rather than re-seeding, because these nodes are pointed at: every attribute in
        // the model targets one of them, and creating `Integer` beside `int` would leave every
        // existing field attached to the old one.*
        foreach (SimpleType::cases() as $type) {
            $old = $taken[$type->value] ?? null;

            if ($old === null || isset($taken[$type->nodeName()])) {
                continue;
            }

            $this->editor->rename($old->id, $type->nodeName());

            $taken[$type->nodeName()] = $old;

            unset($taken[$type->value]);
        }

        // ⚠️ **Die Saat schlägt selbst Id zuerst nach und schreibt die Id danach fest**
        // ([D-510](../../../docs/NewConcept/90-decision-log.md)) — dieselbe Reihenfolge wie überall
        // sonst. *Ohne das Nachschlagen könnte ein Doppelgänger die Bindung übernehmen, obwohl längst
        // notiert ist, welcher Knoten der Typ ist. Der notierte Knoten zählt nur, solange er noch unter
        // `Data Types` hängt: ein Typ, den der Eigentümer weggeworfen hat, ist weg
        // ([D-119](../../../docs/NewConcept/90-decision-log.md)).*
        //
        // ⚠️ *Und festgeschrieben wird auch, was schon dastand — nicht nur, was dieser Lauf angelegt
        // hat. Sonst bliebe jede vor der Entscheidung gesäte Installation für immer über den Notnagel
        // in {@see SeededTypeNodes} gebunden, also über einen Rückfall, der die Arbeit der Saat tut.*
        foreach (SimpleType::cases() as $type) {
            $known = $this->typeNodes->nodeId($type);
            $node  = ($known === null ? null : ($byId[$known] ?? null))
                ?? $taken[$type->nodeName()]
                ?? null;

            if ($node === null) {
                $node      = $this->editor->createNode($type->nodeName(), $dataTypes->id);
                $created[] = $type->nodeName();
            }

            $this->typeNodes->remember($type, $node->id);
        }

        return $created;
    }
    /*
     * Hier standen `boundTheNumbers()`, `seededNode()` und `declareKeyDefaults()` — Grenzen fuer
     * die Zahlentypen und die erklaerten Vorgaben der Schalter, beide in die `settings`-Tabelle
     * geschrieben. **Die Tabelle ist mit D-579 gestrichen**, und mit ihr der einzige Ort, an den
     * sie schrieben. *Was ein Schalter bedeutet, wenn niemand etwas gesagt hat, sagt seither
     * `SettingKey::defaultSwitch()` im Kern — eine Zeile weniger, die widersprechen kann.*
     */
}
