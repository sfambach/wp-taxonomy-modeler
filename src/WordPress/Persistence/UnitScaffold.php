<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Settings;

/**
 * Prefixes and base units, under `Constants`.
 *
 * ⚠️ **Constants, and the branch decides why:** a unit is *a fixed value a person may extend*, and
 * an attribute pointing at one stores **a reference to a node**
 * ([D-232](../../../docs/NewConcept/90-decision-log.md)) — which is exactly what a unit is on a
 * value. Nothing here is a data type.
 *
 * ⚠️ **The shape is decided; the list came from the owner.** [D-274](../../../docs/NewConcept/90-decision-log.md)
 * settles that *each unit carries its factor to the parent's reference unit*, and where a factor is
 * not enough, an **offset** — °C → °F is `×1.8 + 32`. The **members** of the list are not in
 * `NewConcept/`; they were read out of the legacy tree and confirmed one by one, because legacy is a
 * quarry and never a source (`PR-1`).
 *
 * ⚠️ **`Gramm`, not `Kilogramm`, and the owner said so for the right reason.** The prefix axis needs
 * an **unprefixed** base: `Kilogramm` already contains one, so prefixing it would produce
 * *kilo-kilogramm*. *The SI base unit is the kilogram and the modelling base cannot be — a case
 * where the physics and the model disagree, and the model has to win.*
 *
 * ```mermaid
 * flowchart TD
 *   C["Constants"] --> P["Prefixes · a power of ten each"]
 *   C --> B["Base units"]
 *   B --> W["With prefix · Gramm · Meter …"]
 *   B --> N["Without prefix · Kelvin · Celsius · Stück"]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class UnitScaffold
{
    public const OPTION = 'taxmod_unit_scaffold';

    /** Raise it only to deliver something genuinely new; every raise re-enters every install. */
    public const VERSION = 1;

    /**
     * The SI prefixes, as **powers of ten**.
     *
     * ⚠️ **An exponent and not a factor**, because `decimal(30,10)` cannot hold 10⁻²⁴ or 10²⁴ — ten
     * decimal places and twenty integer ones. A prefix **is** a power of ten by definition, so the
     * exponent loses nothing (D-372).
     *
     * @var array<string, int>
     */
    private const PREFIXES = [
        'yotta' => 24, 'zetta' => 21, 'exa' => 18, 'peta' => 15, 'tera' => 12,
        'giga'  => 9,  'mega'  => 6,  'kilo' => 3,  'hecto' => 2, 'deca' => 1,
        'deci'  => -1, 'centi' => -2, 'milli' => -3, 'micro' => -6, 'nano' => -9,
        'pico'  => -12, 'femto' => -15, 'atto' => -18, 'zepto' => -21, 'yocto' => -24,
    ];

    /**
     * Units a prefix may be put in front of.
     *
     * ⚠️ *No factors here: each **is** its parent's reference unit, so its factor is one and a
     * stored one would be a fact nobody needs (settings are sparse, [D-015](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * @var list<string>
     */
    private const WITH_PREFIX = [
        'Gramm', 'Meter', 'Liter', 'Sekunde', 'Ampere', 'Ohm',
        'Farad', 'Watt', 'Volt', 'Henry', 'Hertz',
    ];

    /**
     * Units that take no prefix.
     *
     * ⚠️ **`Celsius` is the one that needs D-274's second half.** It is `Kelvin` shifted, not
     * scaled: `°C = K − 273.15`. *Without an offset the commonest conversion of all would fall
     * outside the rule, which is why D-274 named both.*
     *
     * @var array<string, array{factor?: string, offset?: string}>
     */
    private const WITHOUT_PREFIX = [
        'Kelvin'  => [],
        'Celsius' => ['factor' => '1', 'offset' => '-273.15'],
        'Stück'   => [],
    ];

    public function __construct(
        private readonly ModelEditor $editor,
        private readonly FrameworkNodes $framework,
        private readonly Settings $settings,
    ) {
    }

    /** @return list<string> The names actually created, so a caller can report what it did. */
    public function importOnce(): array
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return [];
        }

        $created = $this->import();

        update_option(self::OPTION, self::VERSION, true);

        return $created;
    }

    /**
     * Create what is not there, whatever the stored version says.
     *
     * ⚠️ **Once, and then hands off** ([D-119](../../../docs/NewConcept/90-decision-log.md)). After
     * the import these are ordinary authored content: a model with no use for `Henry` may throw it
     * away, and reactivating the plugin must not bring it back.
     *
     * @return list<string>
     */
    public function import(): array
    {
        $constants = $this->framework->rootOf(Branch::Constants);
        $created   = [];

        $prefixes = $this->ensure($constants, 'Prefixes', $created);

        foreach (self::PREFIXES as $name => $exponent) {
            $node = $this->ensure($prefixes, $name, $created);

            $this->settings->put(
                $this->settings->chainFor($node),
                SettingKey::PrefixExponent->value,
                TypedValue::ofInt($exponent)
            );
        }

        $base = $this->ensure($constants, 'Base units', $created);

        $withPrefix = $this->ensure($base, 'With prefix', $created);

        foreach (self::WITH_PREFIX as $name) {
            $this->ensure($withPrefix, $name, $created);
        }

        $withoutPrefix = $this->ensure($base, 'Without prefix', $created);

        foreach (self::WITHOUT_PREFIX as $name => $conversion) {
            $node = $this->ensure($withoutPrefix, $name, $created);

            if (isset($conversion['factor'])) {
                $this->settings->put(
                    $this->settings->chainFor($node),
                    SettingKey::Factor->value,
                    TypedValue::ofDecimal($conversion['factor'])
                );
            }

            if (isset($conversion['offset'])) {
                $this->settings->put(
                    $this->settings->chainFor($node),
                    SettingKey::Offset->value,
                    TypedValue::ofDecimal($conversion['offset'])
                );
            }
        }

        return $created;
    }

    /**
     * The child of this name, made if it is not there.
     *
     * ⚠️ **Found by name among the children, never by a remembered id.** An id is meaningless
     * (sentence 2 of the core on one page) and storing one per seeded node would be a second place
     * where the tree lives.
     *
     * @param list<string> $created
     */
    private function ensure(Node $parent, string $name, array &$created): Node
    {
        foreach ($this->editor->childrenOf($parent->id) as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }

        $made      = $this->editor->createNode($name, $parent->id);
        $created[] = $name;

        return $made;
    }
}
