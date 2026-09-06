<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\FrozenState;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\LabelRepository;

/**
 * What a thing is called — and what to say when nobody has said.
 *
 * ```mermaid
 * flowchart LR
 *   A["Rolle · angefragte Sprache"] --> B["name · angefragte Sprache"]
 *   B --> C["Rolle · Standardsprache"] --> D["name · Standardsprache"]
 * ```
 *
 * ⚠️ **Number before role** (D-153): a missing plural form falls back to the base form of the
 * **same** role before giving up on the role. *Resistances* falling back to *Resistance* is a
 * near miss; falling back to a different role would answer a different question.
 *
 * ⚠️ **`help` is not in the chain** (D-386) — it was, and it made a `form` label nobody wrote
 * inherit the whole help **sentence**. The owner found it on a real node. *The intent of D-020
 * survives — a role only needs storing where it should genuinely differ — but the thing the others
 * are a variation of is the **name**, never the long text.*
 *
 * ⚠️ **Die Kette endet auf der Rolle `name` und nie auf nichts** (D-020, D-646). *Sie war bis
 * TASK-019 eine Spalte am Knoten; seit [D-646](../../../docs/NewConcept/90-decision-log.md) ist sie
 * **sprachabhängig** und damit eine Beschriftung wie die anderen — sein Fund: «sonst schaltet man die
 * Sprache um und alle Knoten haben noch den gleichen Namen».*
 *
 * ⚠️ **Die sprachneutrale Zeile ist fort** ([D-387](../../../docs/NewConcept/90-decision-log.md),
 * [D-645](../../../docs/NewConcept/90-decision-log.md)). *An ihrer Stelle steht die
 * **Standardsprache**, und die steht auf der Installationsseite — sie wird vom Rand hereingereicht,
 * weil der Kern keine WordPress-Option lesen darf (`CD-1`).*
 *
 * @see docs/NewConcept/40-i18n.md
 */
final class Labels
{
    public function __construct(
        private readonly LabelRepository $labels,
        /**
         * Die Standardsprache, auf die jeder Rückfall zuletzt läuft
         * ([D-387](../../../docs/NewConcept/90-decision-log.md),
         * [D-645](../../../docs/NewConcept/90-decision-log.md)).
         *
         * ⚠️ *Sie steht auf der Installationsseite als `taxmod_neutral_locale` und wird hier
         * **hereingereicht**: der Kern kennt WordPress nicht (`CD-1`). Hier stand vorher ein
         * `FrameworkNodes`, weil die Rolle eine **Knotennummer** war — seit
         * [D-598](../../../docs/NewConcept/90-decision-log.md) ist sie eine Spalte, und die
         * Abhängigkeit ist fort und nicht nur unbenutzt.*
         */
        private readonly string $defaultLocale = 'en_US',
        // ⚠️ **Damit eine Labeländerung in der Geschichte steht** ([D-489](../../../docs/NewConcept/90-decision-log.md)).
        // Der Eigentümer: *«Labeländerungen sollten auch dokumentiert werden.»*
        //
        // ⚠️ *Gemessen war es vorher **keine einzige** Zeile: von 10496 Changelog-Einträgen nannte
        // keiner ein Label ([OQ-126](../../../docs/NewConcept/91-open-questions.md)). Für Settings war
        // das Journalisieren ausdrücklich entschieden ([D-403](../../../docs/NewConcept/90-decision-log.md),
        // **weil 591 Zeilen keine Geschichte hatten**) — für Labels hatte es nie jemand gefragt.*
        //
        // ⚠️ *Optional wie bei {@see Settings}: die Aufrufer, die nur **lesen**, sollen keine
        // Abhängigkeit erklären müssen, die sie nie benutzen.*
        private readonly ?Changelog $changelog = null,
        // ⚠️ **Hier stand ein `NodeRepository`, und es stand hier, um `node` von `relation` zu
        // **raten*** (Fassung 31, `INF-035`). *«Gibt es einen Knoten mit dieser Nummer?» ist die
        // richtige Frage nur, solange keine Kante dieselbe Nummer tragen kann — und seit
        // [D-581](../../../docs/NewConcept/90-decision-log.md) kann sie das. **Die Zeile nennt ihren
        // Raum jetzt selbst** ([D-597](../../../docs/NewConcept/90-decision-log.md)), also ist die
        // Abhängigkeit fort und nicht nur unbenutzt.*
    ) {
    }

    /**
     * What to show for a node, in a role and a locale.
     *
     * @param string $number A plural category; the base form when it does not matter.
     */
    public function of(
        Node $node,
        SeededRole $role = SeededRole::Form,
        string $locale = '',
        string $number = Label::BASE_NUMBER,
    ): string {
        $stored = $this->indexed($this->labels->forOwners([$node->id], IdentitySpace::Node));

        foreach ($this->attempts($role, $number, $locale) as [$tryRole, $tryNumber, $tryLocale]) {
            $found = $stored[$tryRole->value . "\0" . $tryNumber . "\0" . $tryLocale] ?? null;

            if ($found !== null && $found->text !== '') {
                return $found->text;
            }
        }

        return $node->name;
    }

    /**
     * What to show for a whole set of nodes, in one query.
     *
     * ⚠️ **This exists because a renderer may not fetch.** A reference is drawn as *the target's
     * label* ([D-105](../../../docs/NewConcept/90-decision-log.md)), and a renderer is handed
     * everything it needs and reaches out to nothing
     * ([D-159](../../../docs/NewConcept/90-decision-log.md)) — so the labels have to be resolved
     * **before** the descent, for every reference at once. Asking per reference would be a query
     * per row of a parts list, which is the loop `CD-7` forbids.
     *
     * @param  list<Node>          $nodes
     * @return array<int, string>  Keyed by node id.
     */
    public function forNodes(
        array $nodes,
        SeededRole $role = SeededRole::Form,
        string $locale = '',
        string $number = Label::BASE_NUMBER,
    ): array {
        if ($nodes === []) {
            return [];
        }

        // ⚠️ Grouped by owner, because `indexed()` flattens for a single one — a batch that used
        // it would give every node the last node's labels, which is the kind of fault that shows
        // up as *the wrong name on one row* and gets blamed on the data.
        $stored = [];

        foreach ($this->labels->forOwners(array_map(static fn (Node $n): int => $n->id, $nodes), IdentitySpace::Node) as $label) {
            $stored[$label->ownerId][$label->role->value . "\0" . $label->number . "\0" . $label->locale] = $label;
        }

        $order = $this->attempts($role, $number, $locale);

        $found = [];

        foreach ($nodes as $node) {
            $found[$node->id] = $node->name;

            foreach ($order as [$tryRole, $tryNumber, $tryLocale]) {
                $label = $stored[$node->id][$tryRole->value . "\0" . $tryNumber . "\0" . $tryLocale] ?? null;

                if ($label !== null && $label->text !== '') {
                    $found[$node->id] = $label->text;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Die **Hilfe** dieser Knoten — und nur sie, ohne jeden Rückfall auf einen Namen.
     *
     * ⚠️ **[D-662](../../../docs/NewConcept/90-decision-log.md):** *«überall dort, wo help label ist,
     * sollte auch ein kleines Fragezeichen hinter dem Feld stehen.»* **Die Bedingung ist «wo eine
     * Hilfe steht»**, also darf hier nichts erfunden werden: ein Knoten ohne Hilfe fehlt in der
     * Antwort und bekommt kein Zeichen.
     *
     * ⚠️ **Darum geht das *nicht* über {@see self::forNodes()}, und das ist kein Umweg, sondern der
     * Unterschied:** *dessen Kette fällt auf die Rolle `name` und zuletzt auf `$node->name` zurück
     * ([D-020](../../../docs/NewConcept/90-decision-log.md), [D-646](../../../docs/NewConcept/90-decision-log.md)).
     * **Mit Rückfall trüge jedes Feld ein Fragezeichen, hinter dem sein eigener Name stünde** — ein
     * Zeichen ohne Erklärung, genau das, was {@see \Taxmod\Core\Renderer\HintMarkup::icon()} für den
     * leeren Satz ablehnt.*
     *
     * ⚠️ *Die **Sprache** fällt weiterhin zurück — angefragte Sprache, dann Standardsprache
     * ([D-645](../../../docs/NewConcept/90-decision-log.md)). Eine Hilfe, die nur auf Englisch
     * geschrieben ist, ist besser als keine; eine Hilfe, die es gar nicht gibt, ist keine.*
     *
     * @param  list<Node>         $nodes
     * @return array<int, string> Nach Knoten-Id — **nur** die, die wirklich eine Hilfe tragen.
     */
    public function helpFor(array $nodes, string $locale = ''): array
    {
        if ($nodes === []) {
            return [];
        }

        $stored = [];

        foreach ($this->labels->forOwners(array_map(static fn (Node $n): int => $n->id, $nodes), IdentitySpace::Node) as $label) {
            if ($label->role !== SeededRole::Help) {
                continue;
            }

            $stored[$label->ownerId][$label->locale] = $label->text;
        }

        $locales = array_values(array_unique(array_filter(
            [$locale === '' ? $this->defaultLocale : $locale, $this->defaultLocale],
            static fn (string $one): bool => $one !== ''
        )));

        $found = [];

        foreach ($nodes as $node) {
            foreach ($locales as $tryLocale) {
                $text = $stored[$node->id][$tryLocale] ?? '';

                if ($text !== '') {
                    $found[$node->id] = $text;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The order the chain is tried in.
     *
     * ⚠️ **Der Rückfall geht auf die Standardsprache, nicht mehr auf eine sprachneutrale Zeile**
     * ([D-387](../../../docs/NewConcept/90-decision-log.md),
     * [D-645](../../../docs/NewConcept/90-decision-log.md)): *«das mit der sprachneutralen Zeile
     * hatten wir behoben»* — und *«wenn's nicht gepflegt ist, fällt's jetzt sowieso auf die
     * Defaultsprache zurück».*
     *
     * ⚠️ **Innerhalb einer Sprache fällt die Rolle auf `name` zurück, bevor die Sprache weicht**
     * ([D-646](../../../docs/NewConcept/90-decision-log.md)): *ein deutscher Name schlägt eine
     * englische Rollenbeschriftung, denn genau darum wurde der Name sprachabhängig — «sonst schaltet
     * man die Sprache um und alle Knoten haben noch den gleichen Namen».*
     *
     * @return list<array{0: SeededRole, 1: string, 2: string}>
     */
    private function attempts(SeededRole $role, string $number, string $locale): array
    {
        $locales = array_values(array_unique(array_filter(
            [$locale === '' ? $this->defaultLocale : $locale, $this->defaultLocale],
            static fn (string $one): bool => $one !== ''
        )));

        $numbers = $number === Label::BASE_NUMBER ? [Label::BASE_NUMBER] : [$number, Label::BASE_NUMBER];
        $roles   = $role === SeededRole::Name ? [SeededRole::Name] : [$role, SeededRole::Name];

        $order = [];

        foreach ($numbers as $tryNumber) {
            foreach ($locales as $tryLocale) {
                foreach ($roles as $tryRole) {
                    $order[] = [$tryRole, $tryNumber, $tryLocale];
                }
            }
        }

        // ⚠️ **`help` is deliberately *not* in this chain** ([D-386](../../../docs/NewConcept/90-decision-log.md)),
        // and that supersedes the chain of [D-020](../../../docs/NewConcept/90-decision-log.md) and
        // [D-209](../../../docs/NewConcept/90-decision-log.md). Those said `<role>` → `help` →
        // `node.name`, and the owner caught what it does on a real node: a `form` label that nobody
        // wrote inherited **the whole help sentence** — *condensator is an electronic part that has a
        // capacity and can store current* — as the node's name.
        //
        // ⚠️ **The intent survives, only the intermediary was wrong.** D-020 wanted *a role only
        // needs storing where it should genuinely differ*; `help` was never the thing the others are
        // a variation of. **The node's own base name is** — always present, never translated, a label
        // of last resort by D-020's own description — so it is the whole fallback now.
        //
        // ⚠️ *`help` keeps its other job untouched: it is the long text and the tooltip. What it stops
        // being is everybody else's default.*
        return $order;
    }

    /** Write one label. */
    public function put(Label $label): void
    {
        $vorhanden = $this->stored($label);
        $was       = $vorhanden?->text;

        // ⚠️ *Derselbe Wächter wie in {@see Settings::put()}, und aus demselben Grund: **ein Textfeld
        // sendet immer**, und seit [D-488](../../../docs/NewConcept/90-decision-log.md) bei jedem
        // Seitenspeichern. Ohne das schriebe ein Speichern fünf Zeilen neu, von denen niemand eine
        // angefasst hat.*
        if ($was === $label->text) {
            return;
        }

        $this->labels->put($label);

        // ⚠️ **Die Version, die im Journal steht, ist die **neue*** ([D-634](../../../docs/NewConcept/90-decision-log.md)):
        // *die Ablage hebt sie beim Überschreiben um eins, eine frisch angelegte Zeile ist Version 1.
        // Sie hier zu rechnen statt zurückzulesen spart eine zweite Abfrage je Speichern; die Rechnung
        // ist dieselbe, die {@see \Taxmod\WordPress\Persistence\WpdbLabelRepository::put()} ausführt,
        // und `label-space-check` misst sie gegeneinander.*
        $this->note($label, $was, $label->text, $vorhanden === null ? 1 : $vorhanden->version + 1);
    }

    /**
     * Forget one label, so the chain answers again.
     *
     * ⚠️ **Not the same as storing an empty text** ([D-384](../../../docs/NewConcept/90-decision-log.md)):
     * *an empty field means **forget the row**, not store an empty text.* A row that is there and says
     * nothing is litter — and it is the reader of `text !== ''` above who has to remember to skip it,
     * which is one place too many to have to remember anything.
     *
     * *The text of the label handed in is ignored; only where it sits is read.*
     */
    public function forget(Label $label): void
    {
        $vorhanden = $this->stored($label);

        $this->labels->forget($label->ownerId, $label->ownerKind, $label->role, $label->number, $label->locale);

        // ⚠️ *Die Version der Zeile, die es gerade noch gab — beim Löschen gibt es keine neue.*
        $this->note($label, $vorhanden?->text, null, $vorhanden?->version);
    }

    /**
     * Was hier gerade gespeichert ist — oder nichts.
     *
     * ⚠️ **Das hier Gespeicherte und nicht die Kette** ([D-488](../../../docs/NewConcept/90-decision-log.md)).
     * *Was im Feld steht, wenn nichts gesetzt ist, ist die **Antwort der Kette** — gegen sie zu
     * vergleichen würde bei jedem Seitenspeichern den Platzhalter als Änderung protokollieren und ihn
     * damit festschreiben.*
     */
    private function stored(Label $label): ?Label
    {
        foreach ($this->labels->forOwners([$label->ownerId], $label->ownerKind) as $one) {
            if ($one->role === $label->role
                && $one->number === $label->number
                && $one->locale === $label->locale
            ) {
                return $one;
            }
        }

        return null;
    }

    /**
     * Eine Zeile je geschriebenem oder gelöschtem Label.
     *
     * ```mermaid
     * flowchart LR
     *   L["ein Label geschrieben"] --> O["verzeichnet gegen seinen EIGENTÜMER"]
     *   O --> Q["«was ist mit diesem Knoten passiert» kennt jetzt auch seine Namen"]
     * ```
     *
     * ⚠️ **Gegen den Eigentümer und nicht gegen das Label, genau wie bei einem Setting**
     * ([D-403](../../../docs/NewConcept/90-decision-log.md)): *ein Label hat keine Identität, zu der
     * jemand hinnavigiert — man sieht einen **Knoten** an und fragt, was sich geändert hat.*
     *
     * ⚠️ **Die Adresse steht in den Zustandsspalten und nicht mehr im `what` — das war hier zuerst
     * falsch herum.** *Diese Methode schrieb `label role=733 locale=(neutral) path=… set` in das
     * `what`-Feld. Gemessen spricht dreierlei dagegen: `what` wird **auf Gleichheit** abgefragt
     * ({@see Changelog::actAround()} fragt `WHERE what = %s`), es wird auf dem Knotenbildschirm **roh
     * angezeigt**, und es hat schon **19 von 31** unterschiedlichen Werten an Schlüsselnamen verloren.
     * Eine Adresse im Verb macht jedes Vorkommen zu einem eigenen Verb.* **Und der Umbau kostet
     * nichts:** *diese Schreibweise ist von heute, in der Tabelle stand **keine einzige** Zeile davon.*
     *
     * ⚠️ **Ein Format, eine Stelle** ({@see FrozenState}): Rolle, Pfad, Numerus, Locale und Text sind
     * dieselben Felder, die auch ein Setting schreibt, gebaut vom selben Erbauer. *Zwei Dienste mit je
     * eigener Zeichenkette waren zwei Formate, und das zweite hat niemand gelesen.*
     *
     * ⚠️ *Eine **leere** Locale bleibt leer und wird nicht «(neutral)» — das war Prosa für einen
     * Bildschirm in einer Spalte, aus der ein Abspieler liest. Leer heisst locale-neutral, genau wie
     * ein leerer Pfad den Eigentümer selbst meint ([D-413](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ *Nichts wird verzeichnet, wenn sich nichts geändert hat — ein Textfeld sendet **immer**, und
     * bei jedem Seitenspeichern ([D-488](../../../docs/NewConcept/90-decision-log.md)). Ein Journal,
     * das Nicht-Ereignisse aufschreibt, liest niemand.*
     */
    private function note(Label $label, ?string $was, ?string $now, ?int $version): void
    {
        if ($this->changelog === null || $was === $now) {
            return;
        }

        // ⚠️ *Der Text steht **zuletzt**, weil er das einzige Feld ist, das Leerzeichen enthalten
        // darf; {@see FrozenState} verweigert jede andere Reihenfolge.*
        $state = static fn (?string $text): ?string => $text === null ? null : FrozenState::of([
            'role'   => $label->role->value,
            'number' => $label->number,
            'locale' => $label->locale,
            'text'   => $text,
        ])->write();

        $this->changelog->record(
            $label->ownerId,
            // ⚠️ **Der Raum wird gelesen, nicht mehr erraten** (`INF-035`, Fassung 31). *Hier stand
            // «gibt es einen Knoten mit dieser Nummer? dann `node`, sonst `relation`» — ein Griff, der
            // schweigend falsch antwortet, sobald eine Kante die Nummer eines Knotens trägt, und
            // **genau das ist am 2026-09-05 gemessen worden**. Die Zeile nennt ihren Raum jetzt selbst
            // ([D-597](../../../docs/NewConcept/90-decision-log.md)).*
            $label->ownerKind->value,
            $now === null ? 'label cleared' : 'label set',
            $state($was),
            $state($now),
            // ⚠️ **Seit Fassung 31 hat eine Beschriftung eine Version, und hier steht sie**
            // ([D-634](../../../docs/NewConcept/90-decision-log.md)). *Hier stand `null` mit dem Befund
            // «`labels` trägt als einzige Tabelle keine solche Spalte»; die Spalte gibt es jetzt.
            // **`null` bleibt für den einen Fall, in dem es keine Zeile mehr gibt, deren Nummer man
            // nennen könnte** — ein Löschen ohne vorhandene Zeile.*
            $version
        );
    }

    /**
     * @return list<Label> Everything stored for this owner, for a screen that lists them.
     *
     * ⚠️ *Der Raum gehört zur Frage (`INF-035`): eine Maske zeigt die Beschriftungen **eines**
     * Knotens oder **einer** Kante, und sie weiss, was sie gerade offen hat.*
     */
    public function storedFor(int $ownerId, IdentitySpace $ownerKind): array
    {
        return $this->labels->forOwners([$ownerId], $ownerKind);
    }

    /**
     * @param list<Label> $labels
     *
     * @return array<string,Label>
     */
    private function indexed(array $labels): array
    {
        $byKey = [];

        foreach ($labels as $label) {
            $byKey[$label->role->value . "\0" . $label->number . "\0" . $label->locale] = $label;
        }

        return $byKey;
    }
}
