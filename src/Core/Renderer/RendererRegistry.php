<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;

/**
 * The registry has two jobs, and they are asked at different moments (D-217).
 *
 * | When | Question |
 * |---|---|
 * | render time | *give me the renderer of this name* |
 * | configuration time | *which renderers are eligible for this node at all* |
 *
 * ⚠️ **The key is the type** (R14a). That is what lets *where several are eligible and nobody has
 * chosen, one is marked **default per type*** be a fact the registry holds, rather than a
 * convention every caller has to remember — and it is why registration order decides nothing:
 * a default is named when the renderer is added, or there is none.
 *
 * ⚠️ **It is internal** (D-276). No public API for other plugins hangs off it, so it may change
 * freely — the boundary exists for portability, not for third parties.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RendererRegistry
{
    /** @var array<string, Renderer> */
    private array $byName = [];

    /** @var array<string, Renderer> Keyed by the simple type's own value. */
    private array $defaultByType = [];

    /** @var array<string, array<string, Renderer>> Type ⇒ purpose ⇒ renderer. See {@see addForPurpose()}. */
    private array $defaultByTypeAndPurpose = [];

    /** @var array<string, true> Names a surface asks for and nobody is offered. */
    private array $surfaceOnly = [];

    public function __construct(private readonly Renderer $fallback = new PlainRenderer())
    {
        $this->add($this->fallback);
    }

    /**
     * @param SimpleType ...$asDefaultFor The types this renderer answers for until somebody
     *                                    chooses otherwise. ⚠️ **Named here rather than derived
     *                                    from `handles()`**: three renderers handle an integer
     *                                    and exactly one of them is the default, which is a
     *                                    decision and not a property of the class.
     */
    public function add(Renderer $renderer, SimpleType ...$asDefaultFor): void
    {
        $this->byName[$renderer->name()] = $renderer;

        foreach ($asDefaultFor as $type) {
            $this->defaultByType[$type->value] = $renderer;
        }
    }

    /**
     * Registered, and **not offered as a choice** — a renderer a *surface* asks for by name.
     *
     * ⚠️ **[D-367](90-decision-log.md) makes this a real category rather than a special case.** The
     * tree walks and the **node renderer draws**, and *which* cell is the surface's decision: the
     * modelling tree, the chooser and the trash want the node drawn differently. **So the cell is
     * not something a model author picks for a node** — offering it would let somebody set a tree
     * cell as a node's renderer and turn the detail view into a row.
     *
     * ⚠️ **Registered all the same, because [R12](30-renderer.md#r12r17) says the registry is *the
     * one place where all renderers are registered*.** Bypassing it and instantiating the cell at
     * the call site would break that for the sake of one flag.
     *
     * *Until now only the fallback had this treatment, for the analogous reason: naming it would
     * make «no renderer» a decision somebody made (R14b).*
     */
    public function addForSurfaces(Renderer $renderer): void
    {
        $this->byName[$renderer->name()] = $renderer;
        $this->surfaceOnly[$renderer->name()] = true;
    }

    /**
     * The names that draw a node's value — everything a surface asked for is left out.
     *
     * ⚠️ **Es gibt sie, damit die Saat keine zweite Liste der Renderer wird.**
     * *[D-511](docs/NewConcept/90-decision-log.md) legt die Renderer als Knoten unter `Constants` ab.
     * Würde die Saat ihre Namen selbst aufzählen, wäre das eine Doppelung — und die Art, die
     * **auseinanderläuft, ohne dass etwas rot wird**: ein neuer Renderer im Code, kein Knoten im
     * Modell, und die Auswahl zeigt ihn nie.*
     *
     * ⚠️ *Der Rückfall `plain` **ist dabei**, und das ist kein Versehen: seine Bedeutung ist «hier
     * zeichnet noch nichts» ([R14b](docs/NewConcept/30-renderer.md)), und das muss wählbar sein.
     * **Meine erste Zählung liess ihn weg und ergab 15 statt 16** — der Konstruktor registriert ihn,
     * nicht `ShippedRenderers`, also fiel er beim Zählen der Aufrufe durch.*
     *
     * ⚠️ *Die Oberflächen-Renderer bleiben draussen, auf das Wort des Eigentümers: «es geht hier nur um
     * die Knotenrenderer». `addForSurfaces()` markiert sie schon — **die Trennung wird hier gelesen und
     * nicht ein zweites Mal getroffen**.*
     *
     * @return list<string> Sortiert, damit zwei Läufe dieselbe Reihenfolge säen.
     */
    public function namesForNodes(): array
    {
        $names = array_keys(array_diff_key($this->byName, $this->surfaceOnly));

        sort($names);

        return $names;
    }

    /**
     * Die Klassen hinter {@see namesForNodes()} — **die Brücke zu den Knoten**.
     *
     * ⚠️ *`nodes.implemented_by` trägt die Klasse ([D-620](../../../docs/NewConcept/90-decision-log.md)),
     * also ist das die Menge, mit der man alle Renderer-Knoten in **einer** Abfrage holt (`CD-7`) —
     * die zweite Aufgabe derselben Brücke, die das Inventar gebaut hat
     * ([D-647](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * @return list<class-string>
     */
    public function classesForNodes(): array
    {
        $classes = [];

        foreach ($this->namesForNodes() as $name) {
            $class = $this->classFor($name);

            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Die Kennungen, die eine **Oberfläche** anfordert und niemand wählt.
     *
     * ⚠️ *Das Gegenstück zu {@see namesForNodes()}, und es hat seit
     * [D-648](../../../docs/NewConcept/90-decision-log.md) eine Zusage: **ein interner Renderer hat
     * keinen Knoten.** Ohne diese Liste müsste ein Wächter die Trennung ein zweites Mal treffen —
     * `addForSurfaces()` trifft sie schon.*
     *
     * @return list<string> Sortiert, damit zwei Läufe dieselbe Reihenfolge melden.
     */
    public function namesForSurfaces(): array
    {
        $names = array_keys($this->surfaceOnly);

        sort($names);

        return $names;
    }

    /**
     * Die PHP-Klasse hinter diesem Namen, oder `null`, wenn ihn niemand registriert hat.
     *
     * ⚠️ **Ohne Rückfall, und das ist der Unterschied zu {@see byName()}** (TASK-008). *Der Rückfall
     * ist beim Zeichnen richtig — «hier zeichnet noch nichts» ist besser als ein Absturz. **Als
     * Antwort auf «welche Klasse setzt diesen Knoten um» wäre er eine Lüge**: der Knoten bekäme
     * `PlainRenderer` eingetragen und niemand sähe, dass der eigentliche Renderer fehlt.*
     */
    public function classFor(string $name): ?string
    {
        $renderer = $this->byName[$name] ?? null;

        return $renderer === null ? null : $renderer::class;
    }

    /** Render time: by name, or the fallback when the name is unknown. */
    public function byName(string $name): Renderer
    {
        return $this->byName[$name] ?? $this->fallback;
    }

    /**
     * Whether any renderer answers to this name at all.
     *
     * ⚠️ **A different question from *is it eligible*, and the difference is the owner's point.**
     * *You cannot turn a text into a binary number — well, you can, it just makes no sense, unless
     * you have a special use case.* So {@see eligibleFor()} says what **makes sense** and builds
     * the list a person is offered ([R14](30-renderer.md#r12r17): *so the settings UI can offer a
     * choice*), while this says what **exists**. A name nobody registered is a real error — it
     * resolves to the fallback and shows as *no renderer* on a node that has one. A name that is
     * registered but unusual is somebody's special case (D-360).
     */
    public function knows(string $name): bool
    {
        return isset($this->byName[$name]) && $this->byName[$name] !== $this->fallback;
    }

    /**
     * What draws this type when nobody has chosen — and the fallback where nothing was marked.
     *
     * ⚠️ **Reaching the fallback here is the fault [R14b](30-renderer.md#r14b--the-last-resort-renderer-is-a-fault-indicator-not-a-floor)
     * describes**, not a quiet floor: a type with no default is a type somebody forgot, and the
     * fallback marks its output so the omission is visible instead of merely tidy.
     */
    public function defaultFor(?SimpleType $type, ?Purpose $purpose = null): Renderer
    {
        if ($type === null) {
            return $this->fallback;
        }

        // ⚠️ **A purpose-specific default wins where one was marked** ([D-108](90-decision-log.md),
        // [D-244](90-decision-log.md)): a reference is *shown* by the reference renderer and *picked*
        // by a chooser. *Bis [D-727](90-decision-log.md) waren das zwei Wähler; seither ist es einer mit Schalter.*
        if ($purpose !== null) {
            $forPurpose = $this->defaultByTypeAndPurpose[$type->value][$purpose->value] ?? null;

            if ($forPurpose !== null) {
                return $forPurpose;
            }
        }

        return $this->defaultByType[$type->value] ?? $this->fallback;
    }

    /**
     * A default for one type **and one purpose**, where showing and choosing are different renderers.
     *
     * ⚠️ **[D-108](90-decision-log.md) needs this and [R14a](30-renderer.md#r14a--the-key-is-the-type-purpose-travels-in-the-context)
     * alone could not express it.** R14a marks one default *per type*; a reference is shown by one
     * renderer and picked by another. *D-108 and D-244 made the chooser two renderers with the dialog
     * as default; [D-727](90-decision-log.md) replaced that with one chooser and a switch, off by default.*
     * So a reference is drawn one way and picked another — and until now
     * `node_ref` had a single default that **declined** `edit`, so the descent fell back and marked
     * every reference field as a fault.
     *
     * ⚠️ *The type default stays the general answer; this overrides it only where a purpose genuinely
     * wants a different renderer. Registered as an offered renderer too, because a person may pick the
     * **inline** chooser instead — D-244 flips the default and leaves D-108's construction alone.*
     *
     * @var array<string, array<string, Renderer>>
     */
    public function addForPurpose(Renderer $renderer, Purpose $purpose, SimpleType ...$types): void
    {
        $this->byName[$renderer->name()] = $renderer;

        foreach ($types as $type) {
            $this->defaultByTypeAndPurpose[$type->value][$purpose->value] = $renderer;
        }
    }

    public function fallback(): Renderer
    {
        return $this->fallback;
    }

    /**
     * Ob ein Renderer für diesen Typ zulässig ist — dieselbe Regel, nach der {@see eligibleFor()} anbietet.
     *
     * ⚠️ *`null` heisst «dieser Gegenstand hat keinen einfachen Typ», und zulässig ist dann, was
     * strukturell zeichnet (`handles() === []`) — nicht «alles». Die Regel steht an **einer** Stelle,
     * damit die Tafel und der Abstieg nie verschieden antworten.*
     */
    public function permits(Renderer $renderer, ?SimpleType $type): bool
    {
        $drawn = $renderer->handles();

        return $type === null ? $drawn === [] : in_array($type, $drawn, true);
    }

    /**
     * Configuration time: what this subject may be given.
     *
     * ⚠️ **`null` means *this subject has no simple type*, not *do not filter*.** The two look
     * alike and conflating them is how a spinner ends up offered for a supplier: a node under
     * `Model` has no simple type, so what fits it is a **structural** renderer — one that declares
     * `handles() === []`, chosen for what a subject *is* rather than for what it holds. Today
     * there are none, and an empty list is the honest answer.
     *
     * @param  SimpleType|null $type       The subject's type, or null when it has none.
     * @param  Purpose|null    $forPurpose Narrow to renderers that can answer for it — that is
     *                                     how *not searchable* stops being a special case.
     * @return list<Renderer>
     */
    public function eligibleFor(
        Renderable $subject,
        ?SimpleType $type = null,
        ?Purpose $forPurpose = null,
        /**
         * Ob es an dieser Stelle überhaupt etwas zu wählen gibt.
         *
         * ⚠️ **Sein Befund am 2026-09-06 an `Ampere`:** *«aktuell werden die beiden chooser
         * angeboten das kann aber nicht richig sein weil der knoten keine kinder hat».*
         *
         * ⚠️ *Die Frage kann {@see Renderer::fits()} nicht beantworten — es bekommt den Gegenstand,
         * nicht seine Kinder. Also beantwortet sie der Aufrufer, der beides sieht
         * ({@see \Taxmod\Core\Service\Rendering::choicesForNode()}), und die Registratur fragt nur
         * noch, wer eine Menge braucht. **Vorgabe `true`, damit jede vorhandene Aufrufstelle
         * weiterläuft wie bisher.***
         */
        bool $thereIsAChoice = true,
    ): array {
        $fitting = [];

        foreach ($this->byName as $name => $renderer) {
            if ($renderer === $this->fallback || isset($this->surfaceOnly[$name])) {
                // ⚠️ Never offered as a choice. The fallback because naming it would make *no
                // renderer* a decision somebody made (R14b); a surface renderer because *which*
                // cell a tree draws is the surface's call and not the author's (D-367).
                continue;
            }

            if (! $renderer->fits($subject)) {
                continue;
            }

            // ⚠️ *Eine Auswahlliste ohne etwas zur Auswahl ist ein leerer Kasten — angeboten wird
            // sie nur, wo es eine Menge gibt.*
            if (! $thereIsAChoice && $renderer->needsSomethingToChooseFrom()) {
                continue;
            }

            $drawn = $renderer->handles();

            if ($type === null ? $drawn !== [] : ! in_array($type, $drawn, true)) {
                continue;
            }

            if ($forPurpose !== null && ! in_array($forPurpose, $renderer->supports(), true)) {
                continue;
            }

            $fitting[] = $renderer;
        }

        return $fitting;
    }

    /**
     * The renderer the chain chose — the relation's own setting, then the target, then its ancestors,
     * then the type's default (R41). Nothing separate is walked here: the chain has already been
     * resolved and its answer simply read.
     *
     * ⚠️ **Null means *nothing can answer for this purpose*, and it is a real answer** (D-217).
     * That is the mechanism behind *not searchable*: a renderer that declines `Search` makes its
     * attribute absent from the filter. **Substituting the fallback here would defeat it** — every
     * attribute would become searchable again, through a control that cannot search. What the
     * caller does with a null is the caller's policy, and it differs by purpose: a **value** must
     * never silently disappear, an unanswerable **filter** must never silently appear.
     *
     * @param array<string, \Taxmod\Core\Model\ResolvedSetting> $settings
     */
    public function chosenFor(
        Renderable $subject,
        array $settings,
        Purpose $purpose,
        ?SimpleType $type = null,
    ): ?Renderer {
        $chosen  = $settings['renderer']->value->text ?? null;
        $vorgabe = $settings['renderer'] ?? null;

        // ⚠️ *Die Vorgabe der Auflösung (kein Besitzer, nicht hier gesetzt) ist keine Wahl — hier entscheidet der
        // Typ und der Zweck, wie bisher, damit ein Ding nicht als Formular in ein Feld gezeichnet wird.*
        if ($vorgabe instanceof \Taxmod\Core\Model\ResolvedSetting && $vorgabe->fromOwnerId === 0 && ! $vorgabe->setHere) {
            $chosen = null;
        }

        if ($chosen === null || $chosen === '') {
            // ⚠️ **Ohne den Zweck, und das ist Absicht.** *Für einen Augenblick stand hier
            // `defaultFor($type, $purpose)` — dann kam für `node_ref` beim Bearbeiten der
            // Auswahldialog heraus, **auch wenn es nichts zu wählen gab**, und er zeichnete eine
            // leere Hülle. Ein Verweis ohne Möglichkeiten soll sich als «kein Renderer» zeigen und
            // nicht als Bedienung, die keine ist — ein Test hielt genau das fest und wurde rot.*
            //
            // ⚠️ *Woraus eine Auswahl besteht, weiss die Registratur nicht: das steht im Modell, unter
            // dem Ziel der Kante. Deshalb entscheidet der Abstieg das
            // ({@see \Taxmod\Core\Service\Rendering::optionsFor()}) und nicht diese Tabelle.*
            $renderer = $this->defaultFor($type);

            return in_array($purpose, $renderer->supports(), true) ? $renderer : null;
        }

        $named = $this->byName($chosen);

        // ⚠️ **Ein geerbter Renderer, der hier nicht zulässig ist, gilt nicht — der erste zulässige
        // gilt** ([D-687](../../../docs/NewConcept/90-decision-log.md), [D-688](../../../docs/NewConcept/90-decision-log.md)).
        // *Sein Wort: «gerade wenn ein vererbter renderer nicht zulässig ist müsste auch ein
        // zulässiger gewählt werden». **Nur der geerbte:** was jemand **hier** gewählt hat, bleibt,
        // auch wenn es ungewöhnlich ist — die zulässige Menge ist Rat und kein Zaun
        // ([D-360](../../../docs/NewConcept/90-decision-log.md)). Der erste zulässige ist der
        // Typ-Standard, derselbe, den die Tafel als automatisch anbietet.*
        $ausDerKette = $settings['renderer'] ?? null;

        if ($ausDerKette instanceof \Taxmod\Core\Model\ResolvedSetting
            && $ausDerKette->isInherited()
            && $ausDerKette->fromOwnerId !== 0
            && ! $this->permits($named, $type)
        ) {
            $renderer = $this->defaultFor($type);

            return in_array($purpose, $renderer->supports(), true) ? $renderer : null;
        }

        // ⚠️ **A named renderer that cannot serve here is not in force, and the *type's default*
        // draws — not the fallback.** The owner found this by asking *how do we render a constant
        // node?* after setting `renderer = chooser-inline` on `Base units`: **a chooser is for
        // picking**, so it is registered for surfaces only and supports `Edit`, and the field descent
        // asked it for `Display`. It said no, `fieldsFor()` reached for the fallback, and the fallback
        // printed the reference as `→ 4044`. *The unit `Ω` became a bare id, which is the same fault
        // he had already reported once as `→ 285`.*
        //
        // ⚠️ **Why the type's default and not nothing.** The type still says what a `node_ref` looks
        // like — `reference` (R14a) — and a setting that cannot apply here has no business removing an
        // answer the registry already holds. *The fallback means «nothing draws this», and something
        // does.*
        //
        // ⚠️ *What this does **not** do is tell anybody the stored name is unusable. It is substituted
        // quietly, and quiet substitution is how the bug hid in the first place — so the renderer
        // control has to say it, and that is its own row on the working list.*
        if (in_array($purpose, $named->supports(), true) && ! isset($this->surfaceOnly[$chosen])) {
            return $named;
        }

        // ⚠️ **Three cases, and the tests were right that they are three.** My first attempt
        // substituted the type's default for all of them and broke two checks that had been honest
        // when written — *`PR-9` earning its place*:
        //
        // | The stored name | What must happen |
        // |---|---|
        // | **unknown to the registry** | the **fallback**, visibly — somebody typed a name that does not exist, and substituting would hide it |
        // | **known but cannot serve here** | the **type's default** — the type still says what a `node_ref` looks like |
        // | **known, cannot serve, and the type has no default either** | **nothing** — a renderer that declines a purpose yields nothing for it |
        //
        // ⚠️ *The middle row is the fix; the outer two are what the tests were defending.*
        if (! isset($this->byName[$chosen])) {
            return in_array($purpose, $this->fallback->supports(), true) ? $this->fallback : null;
        }

        $default = $this->defaultFor($type);

        if ($default === $this->fallback) {
            return null;
        }

        return in_array($purpose, $default->supports(), true) ? $default : null;
    }
}
