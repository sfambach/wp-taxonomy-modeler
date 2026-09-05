<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The label roles that ship — **the seeded set, not the possible set** (D-151, D-196).
 *
 * ⚠️ **Roles are nodes, and this enum is not the role.** It names the handful the engine seeds
 * and must be able to find; an author adds more as ordinary nodes, and nothing here has to know.
 * That is possible only because D-044 made the role a **setting a renderer reads** rather than a
 * constant it hard-codes — so the name merely flows through and may be data.
 *
 * ⚠️ **`help` is the end of the fallback chain** (D-209). It was called `long` until the owner
 * struck the word: *`long` is gone, it is defined as `help`.*
 *
 * @see docs/NewConcept/40-i18n.md
 */
enum SeededRole: string
{
    /**
     * Wie das Ding heisst — **und seit [D-646](../../../docs/NewConcept/90-decision-log.md) ist auch
     * das eine Beschriftung je Sprache.**
     *
     * ⚠️ **Sein Fund, der [D-580](../../../docs/NewConcept/90-decision-log.md) an einer tragenden
     * Stelle berichtigt:** *«der Name muss auch sprachabhängig werden, sonst schaltet man die Sprache
     * um und alle Knoten haben noch den gleichen Namen».*
     *
     * ⚠️ **Und es ist das Ende der Kette** ([D-386](../../../docs/NewConcept/90-decision-log.md)):
     * *jede andere Rolle fällt hierauf zurück, nie umgekehrt.*
     */
    case Name = 'name';

    /** What a field is called in a form. */
    case Form = 'form';

    /** What a column is called in a table — often shorter. */
    case Table = 'table';

    /** What an entry is called in a chooser. */
    case Select = 'select';

    /** A very short text — `Ω`, `St`, `Pos.` ⚠️ A **label**, not an icon (D-252). */
    case Symbol = 'symbol';

    /** The long description, which doubles as the tooltip, and ends the chain (D-209). */
    case Help = 'help';

    /**
     * ⚠️ **Hier stand `translatableByDefault()`, und `symbol` war der eine Fall, der `false` sagte**
     * (D-261, D-262). *[D-645](../../../docs/NewConcept/90-decision-log.md) hat das aufgehoben, auf
     * sein Wort: «Symbol wird sprachabhängig. Und wenn's nicht gepflegt ist, fällt's jetzt sowieso auf
     * die Defaultsprache zurück.» **Damit ist jede Rolle sprachabhängig**, und eine Methode, deren
     * Antwort für alle Fälle dieselbe ist, ist keine Unterscheidung mehr, sondern eine Zeile, die
     * nachläuft.*
     *
     * ⚠️ *D-262s Satz «ein Standard, keine Tatsache» ist damit eingelöst und nicht umgangen: das
     * Symbol **darf** je Sprache anders sein, und jetzt kann es das auch.*
     */
}
