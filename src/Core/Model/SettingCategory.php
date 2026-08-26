<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Whose setting this is — the group a panel puts it in.
 *
 * The owner got here in three steps over an afternoon, and the third one is the answer:
 *
 * 1. *What still bothers me is that presentation settings and «real» settings are together.*
 * 2. *A category per setting, and then grouped by category: display, internal … I do not know what to
 *    call the others, probably just setting.*
 * 3. ***Settings for `int` category integer, settings for `double` category double — I think the
 *    principle is clear.***
 *
 * ```mermaid
 * flowchart LR
 *   K["a key"] --> Q{whose is it}
 *   Q -->|"how it is drawn"| D[display]
 *   Q -->|"belongs to the type"| T["integer · double · text …"]
 *   Q -->|"true of anything"| R[rules]
 * ```
 *
 * ⚠️ **The third step is not a nicer grouping — it answers
 * [OQ-093](../../../docs/NewConcept/91-open-questions.md).** That question was *how does a key say
 * which subjects it applies to*, raised because a **text node was being offered `factor`**. A key
 * that belongs to a type is offered where that type is, and nowhere else; the grouping and the
 * applying turn out to be one mechanism seen twice.
 *
 * ⚠️ **Which group a key falls in is asked of the key *and the subject*, never of the key alone.**
 * `range_min` on an integer is an **integer** setting and on a decimal a **double** setting — the
 * same key, two groups, because the key borrows its type from what is being configured
 * ({@see SettingKey::typeFor()}) and the borrowing is the thing that makes it belong.
 *
 * ⚠️ **`Rules` holds what is true of anything at all** — `mandatory`, `hide`, `read_only`,
 * `multiplicity`, `persistent`, `order`. *A thing can be required whatever it holds.*
 *
 * ⚠️ **There were three fixed groups for an hour and one of them is gone.** `Internal` was made for
 * `developer`, and the owner retired that key outright: *develop is not a setting on the node but a
 * setting in the WordPress admin settings menu*
 * ([D-389](../../../docs/NewConcept/90-decision-log.md)). *Worth recording because the group looked
 * necessary right up to the moment it was empty.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
enum SettingCategory: string
{
    /**
     * How the thing is drawn. Change one and no value becomes valid or invalid.
     *
     * ⚠️ *Free keys belong here too — `cols` and `rows` are drawing instructions
     * ([D-364](../../../docs/NewConcept/90-decision-log.md)) — which is why an unknown key lands here
     * rather than in `Rules`: guessing that somebody's own key **constrains** the model would be the
     * more damaging of the two ways to be wrong.*
     */
    case Display = 'display';

    /**
     * It belongs to the **type** being configured, and the group is named after that type.
     *
     * ⚠️ **The heading is the type's own word** — *integer*, *double*, *text* — which is why this
     * case carries no label of its own: {@see label()} answers with the type, and a group per type is
     * therefore free rather than something to enumerate here.
     */
    case OfTheType = 'of-the-type';

    /** True of anything, whatever it holds. The rules. */
    case Rules = 'rules';

    /**
     * Which group this key falls in, for this subject.
     *
     * ⚠️ **Derived from one question and not from a table to maintain**: *does it draw, does it
     * belong to the type, or is it true of anything?* A key added tomorrow is placed by answering
     * that rather than by somebody remembering to edit a list here.
     *
     * @param SimpleType|null $subject The simple type being configured, where there is one.
     */
    public static function of(SettingKey $key, ?SimpleType $subject = null): self
    {
        if (in_array($key, [SettingKey::Renderer, SettingKey::Converter, SettingKey::Validator, SettingKey::Icon], true)) {
            return self::Display;
        }

        // ⚠️ **A key that borrows its type belongs to that type** — and `range_step` borrows too,
        // which is why it sits with the ranges rather than with the renderer choice. *That moves it
        // out of `Display`, where it was for an hour: the earlier test asked «does it change what the
        // model permits», and by that test the step is presentation. This test asks «whose is it»,
        // and it is the integer's. The owner's grouping is the better question of the two.*
        if ($key->shape() === SettingShape::LikeTheSubject) {
            return $subject === null ? self::Rules : self::OfTheType;
        }

        // ⚠️ `factor` and `offset` belong to a **unit**, which is a node under `Constants` and not a
        // simple type at all — so nothing here can place them by type, and they fall to the rules.
        // *That is the honest remainder of OQ-093 rather than a hiding place: the question of a key
        // that belongs to a **branch** is still open.*
        return self::Rules;
    }

    /**
     * The key a heading is looked up under.
     *
     * ⚠️ **A type's own name for `OfTheType`, so `integer` and `double` are separate groups without
     * this enum knowing that either exists.** The words themselves are the boundary's (`AR-2`).
     */
    public function label(?SimpleType $subject = null): string
    {
        if ($this === self::OfTheType && $subject !== null) {
            return $subject->value;
        }

        return $this->value;
    }

    /**
     * Where a key of somebody's own belongs.
     *
     * ⚠️ **Display, and deliberately the safer guess** — see the note on {@see Display}.
     */
    public static function ofFreeKey(): self
    {
        return self::Display;
    }
}
