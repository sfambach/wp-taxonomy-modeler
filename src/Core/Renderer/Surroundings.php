<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * What a renderer was told about **everything other than its own value**.
 *
 * ⚠️ **This exists because [D-159](../../../docs/NewConcept/90-decision-log.md) forbids a renderer
 * to reach out, and four different things then had to be handed in.** Each arrived on its own day
 * and for its own reason, and together they are one idea: *resolved before the descent, placed by
 * the renderer.*
 *
 * ```mermaid
 * flowchart LR
 *   D["the descent · resolves and draws"] --> S[surroundings]
 *   B["the boundary · builds controls"] --> S
 *   S --> R["the renderer · places them"]
 * ```
 *
 * | Field | Why a renderer cannot get it itself |
 * |---|---|
 * | `refersTo` | a **reference** draws its target's label ([D-105](../../../docs/NewConcept/90-decision-log.md)); resolving it is a query, and one per row is `CD-7`'s loop |
 * | `parts` | a **container** lays out members the descent drew ([R46](../../../docs/NewConcept/30-renderer.md), [D-366](../../../docs/NewConcept/90-decision-log.md)); it must not be the one asking |
 * | `actions` | a control carries a URL and a nonce — boundary facts (`CD-1`) — and *what may be done* depends on things a renderer must not fetch ([D-367](../../../docs/NewConcept/90-decision-log.md)) |
 *
 * ⚠️ **There was briefly a fourth — `subjectLabel`, what the node being drawn is called — and it is
 * gone.** The tree shows the node's **own name** ([D-369](../../../docs/NewConcept/90-decision-log.md)),
 * so the cell needs nothing handed in and `cellsFor()` saves a query. *A field nothing uses is a
 * field somebody will use wrongly; the chooser can ask for one back when it needs one.*
 *
 * ⚠️ **Grouped rather than left on {@see RenderContext}, which had grown to twelve parameters.**
 * The owner asked whether the core boundary was worth its friction; the honest answer was that most
 * of the friction is one unanswered question ([OQ-087](../../../docs/NewConcept/91-open-questions.md))
 * and the rest was **this shape**. *The chooser will want a fifth field — the set that may be
 * picked — and it belongs here rather than on the context.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class Surroundings
{
    /**
     * @param list<RenderedField> $parts   The members, already drawn, in the order the descent
     *                                     found them. A container regroups; it does not draw.
     * @param list<string>        $actions Finished controls to place with the subject, in order.
     */
    /**
     * @param string|null $href Where the subject is reached, when the surface has somewhere to go.
     *
     * ⚠️ **A URL is handed in, never built.** The core has no idea what an admin screen or a
     * permalink looks like (`CD-1`) — but wrapping a link around what it drew is ordinary markup,
     * so the renderer keeps deciding the **shape** of a row instead of handing that back too.
     */
    /**
     * @param list<Control>   $actions What can be done to the subject — **described**, so that the
     *                                 renderer builds the buttons rather than concatenating
     *                                 somebody else's markup.
     * @param Submission|null $submits Where those controls go, and the nonce that rides with them.
     * @param list<DrawnRow>          $rows     Drawn cells with their depth, for a **walker** — the
     *                                          same arrangement as `parts`, one level up: the tree
     *                                          nests what the cell drew ([D-367](../../../docs/NewConcept/90-decision-log.md)).
     * @param array<string, Section>  $sections Blocks of a node's page, keyed by {@see PageSlot} —
     *                                          the frame's **order** is the enum's, not this array's.
     * @param array<string, RenderedSetting> $configured Drawn settings **of the subject**, by key.
     *
     * ⚠️ **Its own field rather than squeezed into `parts`.** A part is a **member** of the subject —
     * an attribute of a node — while a setting *configures* the subject; the two are drawn alike and
     * mean different things, and one list holding both would make a container guess which it had.
     * *The docblock above predicted this field would be wanted and named the reason: the shape
     * belongs here, not on the context.*
     */
    /**
     * @param array<string, string> $options      What may be chosen, value ⇒ **already translated**
     *                                            label. This is the field the docblock above
     *                                            predicted: *the chooser will want a fifth field —
     *                                            the set that may be picked.*
     * @param bool                  $mayBeNothing Whether leaving it unanswered is itself a real
     *                                            answer.
     *
     * ⚠️ **`mayBeNothing` is a separate fact and not derivable from `options`**, which is exactly
     * what [R31b](../../../docs/NewConcept/30-renderer.md#r31b--the-rule-counts-possibilities-not-entries)
     * turns on: *the test is never how many rows are in the list but how many **outcomes** this
     * control can produce.* One entry plus *nothing* is two outcomes and a live control; one entry
     * without it is one and already decided. **A renderer counting only rows would grey out the very
     * case where a person still has a take-it-or-leave-it decision** — R28–R32's fourth row, which
     * the concept marks as where the rule must not be over-applied. [R29](../../../docs/NewConcept/30-renderer.md)
     * says where the answer comes from: the **multiplicity**, which is not a renderer's to resolve.
     */
    public function __construct(
        public readonly ?string $refersTo = null,
        public readonly array $parts = [],
        public readonly array $actions = [],
        public readonly ?string $href = null,
        public readonly ?Submission $submits = null,
        public readonly array $rows = [],
        /**
         * Je Datensatz die gezeichneten Felder — **für eine Tabelle**.
         *
         * ⚠️ **`parts` ist einer, das hier sind mehrere** ([D-542](../../../docs/NewConcept/90-decision-log.md)).
         * *Der Eigentümer: «der Table-Renderer bekommt auch einen Knoten und kann **mehrere Datensätze
         * untereinander** darstellen». Ein Formular zeichnet **einen** Datensatz, eine Tabelle mehrere
         * — und die Spalten sind dieselben Felder.*
         *
         * ⚠️ *`rows` war es nicht: ein {@see DrawnRow} trägt **eine** Zelle mit einer Tiefe, das ist
         * der Baum. Eine Tabellenzeile ist ein Satz Felder, also dieselbe Form wie `parts`, eine
         * Ebene höher.*
         *
         * @var list<list<RenderedField>>
         */
        public readonly array $records = [],
        public readonly array $sections = [],
        public readonly array $configured = [],
        public readonly array $options = [],
        public readonly bool $mayBeNothing = true,
        /**
         * Whether this reference points at a **record** rather than at a node.
         *
         * ⚠️ **A prepared fact, so no renderer has to ask** ([D-445](../../../docs/NewConcept/90-decision-log.md)):
         * the descent already resolved the edge's type, and `null` there means the target is not a
         * data type and not a constant — *`typeOf()`'s own words: «a `Model` target is a reference to
         * a **record**, which has no simple type of its own and no renderer either — it wants the
         * summary renderer ([D-106](../../../docs/NewConcept/90-decision-log.md))».* **Costs nothing:
         * the type was resolved for the whole form in one query before the descent began.**
         *
         * ⚠️ **Why it exists at all — the message was blaming the wrong thing.** *Measured
         * 2026-08-27 on a `resistance` attribute pointing at `Einheitenwert`: the fallback said «the
         * one set for this cannot draw a reference», which sends a person to the renderer control
         * where **nothing is wrong**. The renderer it needs does not exist yet. A fault that names
         * the wrong cause costs more than one that says «not built».*
         */
        public readonly bool $refersToARecord = false,
        /**
         * Whether this placement is hidden — prepared by the descent, never asked for.
         *
         * ⚠️ **`hide` lives on the **inheritance edge** ([D-467](../../../docs/NewConcept/90-decision-log.md)),
         * and a tree cell draws the **node**.** *So the cell cannot read it off its subject; the walk
         * loads those edges anyway and hands the answer in ([D-445](../../../docs/NewConcept/90-decision-log.md)).*
         *
         * ⚠️ *Only ever true in developer mode's «show hidden» view: with it off the row does not
         * exist, because the walk did not follow its edge.*
         */
        public readonly bool $hidden = false,
        /**
         * The `id` of the form a control belongs to, when it cannot sit inside it.
         *
         * ⚠️ **This exists because a real bug needed it and the owner found it**: *multiplicity is not
         * saved, or something else goes wrong changing 0..1 to 0..\** on `Bauteilliste`'s `Position`.
         * The attribute row is a `<tr>`, its multiplicity sits in one `<td>` and its acts build a
         * `<form>` in **another** — so the control was **outside** the form and submitted nothing.
         * *HTML forbids a form wrapping table rows, so the control has to name the form instead:
         * `form="…"`, which is plain HTML and needs no scripting.*
         *
         * ⚠️ *The same seam the page-head save button uses ([D-392](../../../docs/NewConcept/90-decision-log.md)),
         * pointing the other way: there a **button** stands outside its form, here a **field** does.*
         */
        public readonly string $formId = '',

        /**
         * Vorsatz der Zeilen-Id, damit dieselbe Zeile zweimal auf einer Seite stehen kann.
         *
         * WICHTIG: Seit der Auswahldialog dieselbe Baumzeile zeichnet wie die Seitenansicht, steht
         * jeder Knoten zweimal im Dokument -- und eine HTML-Id darf es nur einmal geben.
         * package7-check hat es gemeldet, elf Stueck. Der Vorsatz trennt die beiden Vorkommen,
         * ohne dass die Zeile zwei Renderer braucht.
         */
        public readonly string $rowIdPrefix = 'taxmod-node-',

        /**
         * Feldname des Suchfeldes im Baum, leer fuer «filtert nur im Browser».
         *
         * WICHTIG: Der Unterschied ist nicht Geschmack, sondern was ueberhaupt da ist. Der
         * Auswahldialog zeigt alle Zeilen, dort kann ein Skript filtern. Die Seitenansicht ist
         * zugeklappt -- gemessen 11 von 145 Zeilen -- und was nicht im Dokument steht, findet kein
         * Skript. Traegt das Feld einen Namen, sucht der Server.
         */
        public readonly string $filterName = '',

        /** Wonach gerade gesucht wird, damit es nach dem Laden im Feld stehen bleibt. */
        public readonly string $filterValue = '',
        /**
         * Spalten **vor** den Feldern, je Zeile — Überschrift => gezeichnete Zelle.
         *
         * ⚠️ **Auf sein Wort zum Datensatz-Block:** *«Action sollte rechts sein, Record, Version davor,
         * sodass wir eine schmale Zeile bekommen … und zu welchem Knoten/Kante es gehört, würde ich auch
         * noch vorne dran schreiben.»*
         *
         * ⚠️ **Bewusst allgemein und nicht «Datensatz-Spalten».** *Die Tabelle soll nicht wissen, dass es
         * Datensätze sind — sie legt Zellen aus, die ihr gegeben werden ([D-366](../../../docs/NewConcept/90-decision-log.md):
         * ein Behälter fasst keinen Wert an). Der Aufrufer sagt, was vorne steht; die Überschriften sind
         * seine Worte, weil der Kern keine machen kann (`AR-2`, [OQ-087](../../../docs/NewConcept/91-open-questions.md)).*
         *
         * ⚠️ *Gleich lang wie {@see self::$records} und in derselben Reihenfolge. Fehlt ein Eintrag,
         * bleibt die Zelle leer — **eine Zeile darf nicht verrutschen**, das sieht wie Daten aus.*
         *
         * @var list<array<string,string>>
         */
        public readonly array $rowLead = [],
        /**
         * Die Bedienelemente **hinter** den Feldern, je Zeile, schon gezeichnet.
         *
         * ⚠️ *Je Zeile und nicht je Tabelle: {@see self::$actions} gilt für das Ganze, hier hat jede
         * Zeile ihre eigenen — ein Speichern gehört zu **einem** Datensatz und darf nicht zwei schreiben.*
         *
         * ⚠️ *Sie tragen ihr Formular selbst mit, denn ein `<tr>` darf kein `<form>` umschliessen — die
         * Wertfelder nennen es über `form="…"`, genau wie in der Feldzeile.*
         *
         * @var list<string>
         */
        public readonly array $rowActs = [],
        /**
         * Ob diese Zeile **gesperrt** ist — [D-607](../../../docs/NewConcept/90-decision-log.md),
         * angezeigt nach [D-608](../../../docs/NewConcept/90-decision-log.md).
         *
         * ⚠️ **Vorbereitet und nicht erfragt** ([D-445](../../../docs/NewConcept/90-decision-log.md)):
         * ob eine Kante auf den Knoten zeigt, der sie erben würde, weiss der Abstieg — er kennt den
         * Knoten der Seite, die Zeile kennt nur ihre Kante.
         *
         * ⚠️ *Ein Wahrheitswert und kein Text: **der Grund ist Benutzertext** und gehört durch die
         * Textdomäne am Rand (`AR-2`), den der Kern nicht rufen darf (`CD-1`). Er kommt als Wort
         * herein wie «own» und «inherited» auch ([OQ-087](../../../docs/NewConcept/91-open-questions.md)).*
         */
        public readonly bool $locked = false,
    ) {
    }

    /**
     * The same surroundings around a different target — what a multi-valued reference does per row.
     *
     * ⚠️ **The label travels with the value.** Two occurrences of one reference point at two
     * different nodes, so carrying the first one's label into the second row would name it wrongly.
     */
    public function referringTo(?string $refersTo): self
    {
        return new self($refersTo, $this->parts, $this->actions, $this->href, $this->submits);
    }
}
