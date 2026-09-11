<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Die Namen, die der Motor selbst liest — reserviert, damit ein Autor sie nicht überschreibt (D-084).
 *
 * ⚠️ **Eine Liste reservierter Wörter, keine Liste erlaubter** (TASK-075, [D-506](../../../docs/NewConcept/90-decision-log.md),
 * [D-529](../../../docs/NewConcept/90-decision-log.md)): *eine Einstellung ist eine Kante, und die Tafel
 * zeichnet jeden im Modell erklärten Namen über seine Kante — auch die, die hier nicht stehen.
 * `Narrowing` und die Richtung je Schlüssel sind mit D-529 gefallen: «die Kette gibt es nicht mehr».*
 *
 * ⚠️ **These names are reserved** (D-084). There is no `scope` column and no prefix: some keys
 * are the engine's, so an author cannot define a setting called `hide` and silently break
 * rendering. Everything else is a free key belonging to whoever made it.
 *
 * ⚠️ **~~Bounding settings may only be tightened downwards; choosing settings are free~~ — retired by
 * [D-411](../../../docs/NewConcept/90-decision-log.md).** *An attribute may reopen anything a node
 * said: «if something is hidden I can make it visible elsewhere; if it is read-only here I can make it
 * editable there.»* A range on a node is a **default for its fields**, not a promise about a group —
 * so being wider below is not a violation but what overriding a default looks like. **The code that
 * still enforces the old rule is list row 34**, and it is marked rather than deleted here because
 * removing a refusal deserves its own diff.
 *
 * ⚠️ *Marked at all because a docblock stating a retired rule is how a retired rule keeps getting
 * reasoned from — which happened three times on 2026-08-26 alone (`PR-10`).*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
enum SettingKey: string
{
    // Bounding — they limit what is possible.

    /**
     * How often the attribute may occur — one of exactly four values (D-351).
     *
     * ⚠️ **Relation-only**, and the only key that is: a node describes a thing and a thing has no
     * multiplicity. See {@see Multiplicity} for what *narrower* means among the four.
     */
    // ⚠️ **`Multiplicity` stand hier bis Schritt 2 des Bauplans (2026-09-11) und ist gefallen**
    // ([D-713](../../../docs/NewConcept/90-decision-log.md)): *sie ist eine Spalte der Kante und keine
    // Einstellung — sein Wort: «bei multiplizität war ich mir eigentlich immer eine spalte der kante
    // vorgestellt». Die Maske zeichnet sie unter {@see EdgeColumn::MULTIPLICITY} weiter, als Spalte.*

    // ⚠️ **`mandatory` used to be here and is gone** ([D-405](../../../docs/NewConcept/90-decision-log.md)).
    // The owner: *whether a field is mandatory is already determined by the multiplicity — whether it
    // runs from zero to something or from one to something. **From one it means it is mandatory.***
    // **There is no fifth combination**, so the two keys could never disagree, and a fact that cannot
    // disagree with another fact is the same fact. {@see Multiplicity::requiresOne()} is the answer.

    /**
     * ⚠️ **`Hide` was here and is gone since 2026-08-28 — [D-457](../../../docs/NewConcept/90-decision-log.md).**
     *
     * *It is a **column** on {@see \Taxmod\Core\Model\Identity} now, on a node and on an relation. The
     * reason is measured twice: as a **setting** it sat in the resolution chain, and an attribute's chain
     * contains its **target node** — so hiding a **type** blanked **every field of that type**
     * ([OQ-101](../../../docs/NewConcept/91-open-questions.md), and again on 2026-08-27).*
     *
     * ⚠️ *[D-426](../../../docs/NewConcept/90-decision-log.md) put it plainly: «a column is not in the
     * chain, so the two can no longer reach each other **by construction** rather than by a rule
     * somebody has to remember.» **This tombstone exists so nobody adds the key back**, which is what
     * a reader who finds `hide` in the settings screen's history would otherwise reasonably do.*
     *
     * ⚠️ *`read_only` and `persistent` did **not** follow it out ([D-460](../../../docs/NewConcept/90-decision-log.md),
     * [D-461](../../../docs/NewConcept/90-decision-log.md)): they want the chain, and inheriting down a
     * type is the point of them rather than an accident.*
     */

    // ⚠️ **`ReadOnly` stand hier bis Schritt 2 des Bauplans (2026-09-11) und ist gefallen**
    // ([D-714](../../../docs/NewConcept/90-decision-log.md)): *«read_only braucht es nur an der kante»,
    // und dort ist es eine Spalte ({@see EdgeColumn::READ_ONLY}). Es gibt kein `read_only` am Knoten
    // mehr; die Einstellungskante an der Wurzel ist mit Fassung 48 gewandert.*

    /**
     * Wo eine Feldzeile an diesem Knoten steht — ein Kind ordnet geerbte Felder an derselben Adresse wie
     * seine anderen Einstellungen ([D-698](../../../docs/NewConcept/90-decision-log.md): *«kind darf felder
     * neu anordnen»*). Nichts gesetzt heisst Reihenfolge des Besitzers.
     */
    case Position = 'position';

    /** Smallest permitted value. */
    case Min = 'min';

    /** Largest permitted value. */
    case Max = 'max';

    // Choosing — they pick within the bounds.

    /**
     * How coarsely a numeric control moves — the third of R17's triple.
     *
     * ⚠️ **Named by the concept and therefore reserved.** [R17](../../../docs/NewConcept/30-renderer.md#r12r17)
     * says *integer and double **nodes** need min, max and step as settings*, in one breath. The
     * first two are `range_min` and `range_max`; leaving the third as a free key would have made
     * one of three siblings an outsider, and an author could then define `step` to mean something
     * else on the very nodes that use it.
     *
     * ⚠️ **Choosing, not bounding, and the reason is precise: nothing validates it.** A step of
     * five does not make seven unstorable — an import, a data pack or a computed value will write
     * seven and no rule is broken. It says how the **control** moves, not what the model permits,
     * and [D-312](../../../docs/NewConcept/90-decision-log.md)'s narrowing rule exists for what is
     * *allowed*: a restriction that may be reopened anywhere says nothing when it is read. **A
     * granularity says nothing about what is allowed in the first place**, so there is nothing to
     * protect from being reopened.
     */
    case Step = 'step';

    /** ⚠️ A default is not a bound but a choice inside the permitted set, so it stays free. */
    case DefaultValue = 'default';

    case Renderer = 'renderer';
    case Converter = 'converter';

    /**
     * Which validator checks what is entered — the third of the triple, and it was missing.
     *
     * ⚠️ **The owner named the three apart himself, and the reason is worth keeping:** *an `int`
     * converter is something different from an `int` validator or renderer. The **renderer** says how
     * it is shown, the **converter** says convert the output to binary, and the **validator** checks
     * whether the input is correct. So each has its own job.* Two of the three had keys and the third
     * did not — noticed on the settings panel, where he simply said *validator (display) setting is
     * missing*.
     *
     * ⚠️ **Several, eventually, and that is already decided.**
     * [D-158](../../../docs/NewConcept/90-decision-log.md) says a message is *per validator, not per
     * attribute* — *an attribute may carry range, format and uniqueness checks, three messages rather
     * than one* — so one key holding one name is the first step and not the final shape. *It is
     * honest as a first step because nothing can choose a second validator until any validator
     * exists.*
     *
     * ⚠️ *Like `converter`, it draws today as a **dead** control: none is built
     * ([D-219](../../../docs/NewConcept/90-decision-log.md) decided them), and R28–R32 wants a
     * disabled control rather than an empty box that looks fillable.*
     */
    case Validator = 'validator';

    case Icon = 'icon';

    /**
     * Wie breit ein Feld **in Zeichen** gezeichnet werden soll
     * ([D-659](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Sein Wort, am Typ `text` erklärt:** *«an Text-Typ ein Setting `display size` in Zeichen
     * einführen, damit Strasse die gross ist und Hausnummer die klein ist — eher wie ihr Inhalt
     * dargestellt werden — und der Text-Renderer müsste das berücksichtigen.»*
     *
     * ⚠️ **Zwei Dinge, die sie nicht ist, und beide stehen ausdrücklich in der Entscheidung:**
     * *(1) **keine Längenbegrenzung** — sie beschneidet nichts und weist nichts zurück; wer eine
     * Grenze will, braucht einen Validator. (2) **ein Wunsch, kein Befehl** — ein Rand, der sie
     * nicht umsetzen kann, ignoriert sie, statt zu scheitern. Genau darum steht sie hier und nicht
     * bei den begrenzenden Schlüsseln: sie sagt nichts darüber, was erlaubt ist.*
     *
     * ⚠️ **Eine ganze Zahl von sich aus, nicht die des Gegenstands.** *Eine Anzahl Zeichen ist eine
     * Anzahl, gleichgültig ob ein Text oder eine Zahl darunter steht — dieselbe Begründung, mit der
     * `factor` ein Dezimalwert bleibt, was die Einheit auch misst. `LikeTheSubject` hiesse «die
     * Breite eines Textes ist ein Text».*
     *
     * ⚠️ *Sie erbt die Auflösungskette wie `min` und `max` ([D-602](../../../docs/NewConcept/90-decision-log.md)):
     * am Typ erklärt, an der Verwendungsstelle überschreibbar ([D-611](../../../docs/NewConcept/90-decision-log.md)).*
     */
    case DisplaySize = 'display_size';

    /**
     * How much of the parent's reference unit this one is
     * ([D-274](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Named by the decision, not invented here:** *each unit carries its **factor** to the
     * parent's reference unit*, so inch → millimetre is a multiplication through the shared parent.
     * The parent **is** the dimension, because the tree is inheritance
     * ([D-041](../../../docs/NewConcept/90-decision-log.md)) — no new construct.
     */
    case Factor = 'factor';

    /**
     * What has to be added after the factor — the other half of D-274's rule.
     *
     * ⚠️ **Without it the commonest conversion of all falls outside the rule:** °C → °F is
     * `×1.8 + 32`. *And whatever is neither factor nor offset is a **converter**
     * ([D-219](../../../docs/NewConcept/90-decision-log.md)) — wire gauge to cross-section is a
     * table, not a calculation. The rule covers the linear case and hands the rest over honestly.*
     */
    case Offset = 'offset';

    /**
     * Whether a value given through this attribute is **kept**. Default **true**.
     *
     * ⚠️ **The owner brought it from object orientation and it is the piece that was missing:** *there
     * are attributes of an object, and there are ones that get persisted and ones that do not. A
     * multiplicator is not persistent — it counts only as an attribute.* **That justifies an
     * attribute where a record can never answer**, which is the thing four attempts had failed to
     * justify: a `node_label` type, a read-only-default trick, a reserved key on every node, and a
     * type marked derived. *All four were trying to say **this is not stored** in a place that could
     * not say it.*
     *
     * ```mermaid
     * flowchart LR
     *   T["a simple type · persistent = false"] --> A["every attribute using it"]
     *   A -->|"may override"| U["one use site"]
     * ```
     *
     * ⚠️ **Set on the type, inherited by the attribute, overridable there** — the owner's own
     * arrangement: *what we have on the attribute we have on the node too … first as a setting on
     * simple data types, the attribute takes it over and can override it.* That is the ordinary chain
     * ([D-015](../../../docs/NewConcept/90-decision-log.md), [D-032](../../../docs/NewConcept/90-decision-log.md))
     * and needs nothing new. **A type can therefore declare itself a calculation basis once** and
     * every use of it inherits that, instead of every author remembering it per relation.
     *
     * ⚠️ **Why *here* and not on {@see SimpleType}, which is where I first put the same idea and it
     * failed.** As a property of the **type** it made one of twelve types answer *no column at all*,
     * which is a hole in the type system. As a setting on the **subject** it is a decision per use,
     * resolved by the chain like every other — the same word, one level down, and that level is what
     * makes it work.
     *
     * ⚠️ **Choosing rather than bounding, and it is not a permission.**
     * [D-312](../../../docs/NewConcept/90-decision-log.md)'s narrowing rule governs what is
     * *allowed*; persistence governs what *happens*. *Flipping it is a storage change and not a
     * relaxation — the same shape as [D-134](../../../docs/NewConcept/90-decision-log.md)'s note that
     * changing a multiplicity from `1` to `1..*` becomes a migration.*
     *
     * ⚠️ **A model-level value still has a home**: [D-026](../../../docs/NewConcept/90-decision-log.md)
     * — *at model level there are no values, only defaults* — so a non-persistent attribute's value
     * is its `default`, which is what a default has always been.
     */
    // ⚠️ **`Persistent` stand hier bis 2026-09-01 und ist mit [D-538](../../../docs/NewConcept/90-decision-log.md)
    // ersatzlos gefallen.** *Der Eigentümer hat es selbst hergeleitet: «ich glaube nämlich eigentlich,
    // dass diese nicht persistenten Datensätze alles eigentlich Settings sind … wenn der Benutzer was
    // eingibt in einen Knoten, der Settings und Felder hat, dann werden für den Benutzer ja nur die
    // Felder gespeichert und nicht die Settings, weil die Settings ja Eigenschaften des Modells sind.»*
    // **Dass ein Wert nicht im Benutzerdatensatz landet, sagt seit [D-526] die Relationsart.**
    // *Gemessen am 2026-09-01, bevor der Schlüssel fiel: **null Zeilen** in der Tabelle, **keine einzige
    // Verzweigung** im Code, die ihn las — geschrieben wurde er an genau einer Stelle und von vier
    // Prüfungen behauptet. Die Kante, die ihn trug (`exponent`, 4654), ist bereits `kind = setting`.*

    // ⚠️ **`order` used to be here and is gone** ([D-407](../../../docs/NewConcept/90-decision-log.md)).
    // The owner: *if `order` is not used then remove it.* **It was not used**: nothing in
    // `Taxmod\Core` read it, and the ordering it claimed to hold is the `position` **column** on
    // `relations` — 84 relations use that, `FormRenderer` sorts by it, and `moveUp`/`moveDown` write it.
    // *Two homes for one fact, and only one of them was ever the truth.*


    /**
     * Whether a key says something only a **use** can have.
     *
     * ⚠️ **The asymmetry runs one way** ([50 Persistence](../../../docs/NewConcept/50-wordpress-persistence.md)):
     * everything sayable about a node is also sayable about one use of it, and the reverse is
     * not true. A node describes a *thing*, an relation describes a *use of a thing* — and a thing
     * has no multiplicity, while a use of it does.
     *
     * ⚠️ **This is about where a key applies, not a second mechanism.** Multiplicity still
     * inherits down the chain and is still narrowable: a subtype may tighten `0..1` to `1`.
     */
    // ⚠️ *`isRelationOnly()` stand hier — ihr einziges Mitglied war `Multiplicity`, und die ist eine
    // Spalte ([D-713](../../../docs/NewConcept/90-decision-log.md)). Eine Frage ohne Mitglied ist keine.*

    /**
     * Whether a key says something only a **node** can have.
     *
     * ⚠️ **Der Gegenpfeil zu {@see self::isRelationOnly()}, und heute trägt ihn genau der Renderer**
     * ([D-643](../../../docs/NewConcept/90-decision-log.md)). *Seine Worte: «ich bin mir noch nicht
     * sicher, ob wir an der Kante einen Renderer brauchen, deshalb würde er da wegfallen» — und «es
     * gibt keine Einstellungsmöglichkeit in der GUI, der Benutzer könnte nicht, selbst wenn er
     * wollte.»*
     *
     * ⚠️ **Sachlich, nicht bequem:** *eine Kante ist eine **Verwendungsstelle**, kein Ding. Gezeichnet
     * wird der Knoten dahinter, und dessen Renderer hängt an ihm. Zwei Renderer an einer Verwendung
     * wären zwei Antworten auf dieselbe Frage.*
     *
     * ⚠️ *Gemessen am 2026-09-05, bevor der Schlüssel fiel: **je 0 Zeilen** in
     * `relations.settings_record_id` und `relations.target_settings_record_id` — nie belegt, seit es
     * sie gab. Die Maske bot ihn trotzdem an.*
     */
    public function isNodeOnly(): bool
    {
        return $this === self::Renderer;
    }
    /** Whether a name belongs to the engine and may therefore not be used freely. */
    public static function isReserved(string $key): bool
    {
        return self::tryFrom($key) !== null;
    }

    /**
     * What this key's value looks like, so a control can be drawn for it.
     *
     * ⚠️ **`icon` is `Words` provisionally, and that is a gap rather than an answer.**
     * [D-251](../../../docs/NewConcept/90-decision-log.md) says the tree row draws a node's icon
     * *where one is set* and nothing says **what** an icon is — a symbol name, a media reference, a
     * character. Treated as characters until it is decided, which is the least it can be.
     *
     * @see SettingShape
     */
    public function shape(): SettingShape
    {
        return match ($this) {
            self::Factor, self::Offset                 => SettingShape::Exact,
            // ⚠️ *Eine Anzahl Zeichen — siehe {@see self::DisplaySize}; eine Stelle in der Liste — D-698.*
            self::DisplaySize, self::Position          => SettingShape::Whole,
            self::Renderer, self::Converter,
            self::Validator                            => SettingShape::ARegisteredName,
            // ⚠️ These four borrow their type from whatever is being configured — a default for a
            // text is a text, a minimum for a decimal is a decimal.
            self::DefaultValue, self::Min,
            self::Max, self::Step            => SettingShape::LikeTheSubject,
            // ⚠️ **An icon is chosen from a set, not typed** (D-390): the installation offers a
            // list and a person picks one, so a text box here would ask somebody to know a Dashicon
            // key by heart. *Which icons exist is a boundary fact and arrives with the options.*
            self::Icon                                 => SettingShape::ARegisteredName,
        };
    }

    /**
     * What this key means when **nobody has said anything** — or `null` where it means nothing.
     *
     * ⚠️ **This is [D-401](../../../docs/NewConcept/90-decision-log.md)'s one home.** The owner,
     * refusing both ways out offered him: *neither a nor b. We said a bool can have only two states,
     * «not set» does not exist. **If a value is there then the value, otherwise the default.*** *And
     * the default was being invented in twelve places as `?? false` or `?? true` — across three
     * renderers, `RenderContext`, `DataEntry`, three spots in `Rendering` and three on the nodes
     * screen. The duplicated-fact prohibition, in the most literal form it takes in this codebase.*
     *
     * ⚠️ **It already cost the owner two reports of one bug.** `persistent` resolved to `true` inside
     * a reader while the switch drew **off**, so *the control stated the opposite of what was in
     * force* — he caught it twice ([D-377](../../../docs/NewConcept/90-decision-log.md),
     * [D-404](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Two consumers, one answer, and that is the point.**
     * {@see \Taxmod\WordPress\Persistence\BaseScaffold} writes these onto the **installation
     * identity** so the existing walk answers for every node ([D-404](../../../docs/NewConcept/90-decision-log.md)),
     * and every reader asks **the same method** where the row is missing. *A row a person can see and
     * change, with a compiled-in answer underneath it rather than instead of it.*
     *
     * ⚠️ **`multiplicity` delegates rather than repeating itself.** `Multiplicity::standard()` already
     * owns *what an attribute means when nobody has said* — `0..1`, on the owner's word — and copying
     * `0..1` into this match would be a second home for the very fact this method exists to have one
     * of.
     */
    public function declaredDefault(): ?TypedValue
    {
        // ⚠️ *Bis Schritt 2 des Bauplans antworteten hier `read_only` und `multiplicity` — beide
        // sind Spalten der Kante geworden ([D-713](../../../docs/NewConcept/90-decision-log.md),
        // [D-714](../../../docs/NewConcept/90-decision-log.md)); ihre Vorgaben stehen an der Spalte.*
        return match ($this) {
            // ⚠️ **Nothing, and that is an answer.** A range, a factor or a renderer has no meaning
            // nobody chose — [D-352](../../../docs/NewConcept/90-decision-log.md) resolves a renderer
            // from the **type** instead, which is a different mechanism and must not be shadowed here.
            default            => null,
        };
    }

    /**
     * The same answer for a switch, unwrapped — what a two-state key is when nothing is stored.
     *
     * ⚠️ **Exists so a reader never writes `?? false` again.** *Every caller had the fallback inline,
     * which is how `persistent` came to be read as `true` in one file and drawn as `off` in another.*
     *
     * @throws \LogicException where the key is not a switch — asking a range for its boolean default
     *                         is a mistake in the caller, not a value to invent.
     */
    public function defaultSwitch(): bool
    {
        if ($this->shape() !== SettingShape::Switch) {
            throw new \LogicException("The setting «{$this->value}» is not a switch.");
        }

        // ⚠️ Read off `declaredDefault()` rather than repeated — one home means one `match`.
        return $this->declaredDefault()?->asBool() ?? false;
    }

    /**
     * Which engine keys have anything to say about this subject, whether or not one is set.
     *
     * ⚠️ **This is what lets a panel show what *applies* rather than only what is *stored*.** The
     * owner, looking at an `int` node whose chain was empty: *the settings that belong firmly to
     * the data type — min, max, step — should be shown as such.* A panel listing only what somebody
     * has written cannot say what could be written, and
     * [R33c](../../../docs/NewConcept/30-renderer.md#r33c--automatic-is-a-default-never-a-fact)
     * wants the opposite: *an automatic choice must be visible.*
     *
     * ⚠️ **Derived, never listed per type.** A key applies where a control can be drawn for it —
     * {@see typeFor()} answers that — or where it is a choice from a set. Writing out *which keys
     * an integer has* would be a table to maintain beside the truth, and the two would drift.
     *
     * @param  SimpleType|null $subject The simple type being configured, or null for anything else.
     * @param  bool            $isRelation  Whether the subject is a use site rather than a node.
     * @return list<self>
     */
    public static function applyingTo(?SimpleType $subject, bool $isRelation = false): array
    {
        $applying = [];

        foreach (self::cases() as $key) {
            // ⚠️ *Hier stand «a thing has no multiplicity» — die Multiplizität ist keine Einstellung mehr
            // ([D-713](../../../docs/NewConcept/90-decision-log.md)), die Frage stellt sich nicht.*
            // ⚠️ *Und die Gegenrichtung: der Renderer gehört dem Knoten, nicht der Verwendungsstelle
            // ([D-643](../../../docs/NewConcept/90-decision-log.md)).*
            if ($key->isNodeOnly() && $isRelation) {
                continue;
            }

            if ($key->typeFor($subject) !== null || $key->shape()->isAChoice()) {
                $applying[] = $key;
            }
        }

        return $applying;
    }

    /**
     * The type a control for this key should be drawn as, given what is being configured.
     *
     * ⚠️ **Null means *not a typed field*** — a choice from a set, which wants a chooser rather
     * than an input, and no chooser renderer is built yet. It also covers the honest case where the
     * subject has no type of its own to borrow: a `default` on a node that is not a simple data
     * type has no shape to be drawn in, and guessing `text` there would invite somebody to type a
     * reference as characters.
     *
     * @param SimpleType|null $subject What the setting is being written on, where that is a simple
     *                                 data type. Null for anything else.
     */
    public function typeFor(?SimpleType $subject): ?SimpleType
    {
        return match ($this->shape()) {
            SettingShape::Switch         => SimpleType::Bool,
            SettingShape::Whole          => SimpleType::Int,
            SettingShape::Exact          => SimpleType::Decimal,
            SettingShape::Words          => SimpleType::Text,
            SettingShape::LikeTheSubject => $subject,
            // A set to choose from, not a value to type.
            SettingShape::OneOfFour, SettingShape::ARegisteredName => null,
        };
    }
}
