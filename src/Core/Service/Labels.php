<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\FrozenState;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\LabelRepository;

/**
 * What a thing is called — and what to say when nobody has said.
 *
 * ```mermaid
 * flowchart LR
 *   A["role · number"] --> B["role · one"] --> D["node.name"]
 * ```
 *
 * ⚠️ **Number before role** (D-153): a missing plural form falls back to the base form of the
 * **same** role before giving up on the role. *Resistances* falling back to *Resistance* is a
 * near miss; falling back to a different role would answer a different question.
 *
 * ⚠️ **`help` is not in the chain** (D-386) — it was, and it made a `form` label nobody wrote
 * inherit the whole help **sentence**. The owner found it on a real node. *The intent of D-020
 * survives — a role only needs storing where it should genuinely differ — but the thing the others
 * are a variation of is the **node's own name**, never the long text.*
 *
 * ⚠️ **The chain ends on `node.name` and never on nothing** (D-020). A screen with an
 * empty cell where a name should be is worse than a screen showing the internal name — and the
 * internal name is always there, because a node cannot exist without one (D-022).
 *
 * @see docs/NewConcept/40-i18n.md
 */
final class Labels
{
    public function __construct(
        private readonly LabelRepository $labels,
        private readonly FrameworkNodes $framework,
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
        // ⚠️ *Nur um `node` von `relation` zu unterscheiden — ein Label hängt an beidem
        // ([D-410](../../../docs/NewConcept/90-decision-log.md)), und die Id allein sagt nicht welches.
        // **Ohne dieses Repository wäre `owner_kind` geraten**, und `Settings::kindOf()` nennt genau das
        // eine Lüge, die es dort schon einmal war.*
        private readonly ?NodeRepository $nodes = null,
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
        string $path = '',
    ): string {
        $stored = $this->indexed($this->labels->forOwners([$node->id]), $path);

        $roleId = $this->framework->roleId($role);

        foreach ($this->attempts($roleId, $number, $locale) as [$tryRole, $tryNumber, $tryLocale]) {
            $found = $stored[$tryRole . "\0" . $tryNumber . "\0" . $tryLocale] ?? null;

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

        foreach ($this->labels->forOwners(array_map(static fn (Node $n): int => $n->id, $nodes)) as $label) {
            if ($label->path !== '') {
                continue;
            }

            $stored[$label->ownerId][$label->roleId . "\0" . $label->number . "\0" . $label->locale] = $label;
        }

        $roleId = $this->framework->roleId($role);
        $order = $this->attempts($roleId, $number, $locale);

        $found = [];

        foreach ($nodes as $node) {
            $found[$node->id] = $node->name;

            foreach ($order as [$tryRole, $tryNumber, $tryLocale]) {
                $label = $stored[$node->id][$tryRole . "\0" . $tryNumber . "\0" . $tryLocale] ?? null;

                if ($label !== null && $label->text !== '') {
                    $found[$node->id] = $label->text;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The order the chain is tried in.
     *
     * ⚠️ **The locale falls back to the neutral row before the role gives way.** A label stored
     * without a locale is one somebody wrote for everybody; using it beats dropping to a
     * different role, which would answer a different question.
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function attempts(int $roleId, string $number, string $locale): array
    {
        $locales = $locale === '' ? [''] : [$locale, ''];
        $numbers = $number === Label::BASE_NUMBER ? [Label::BASE_NUMBER] : [$number, Label::BASE_NUMBER];

        $order = [];

        foreach ($numbers as $tryNumber) {
            foreach ($locales as $tryLocale) {
                $order[] = [$roleId, $tryNumber, $tryLocale];
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
        $was = $this->storedText($label);

        // ⚠️ *Derselbe Wächter wie in {@see Settings::put()}, und aus demselben Grund: **ein Textfeld
        // sendet immer**, und seit [D-488](../../../docs/NewConcept/90-decision-log.md) bei jedem
        // Seitenspeichern. Ohne das schriebe ein Speichern fünf Zeilen neu, von denen niemand eine
        // angefasst hat.*
        if ($was === $label->text) {
            return;
        }

        $this->labels->put($label);

        $this->note($label, $was, $label->text);
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
        $was = $this->storedText($label);

        $this->labels->forget($label->ownerId, $label->path, $label->roleId, $label->number, $label->locale);

        $this->note($label, $was, null);
    }

    /**
     * Was hier gerade gespeichert ist — oder nichts.
     *
     * ⚠️ **Das hier Gespeicherte und nicht die Kette** ([D-488](../../../docs/NewConcept/90-decision-log.md)).
     * *Was im Feld steht, wenn nichts gesetzt ist, ist die **Antwort der Kette** — gegen sie zu
     * vergleichen würde bei jedem Seitenspeichern den Platzhalter als Änderung protokollieren und ihn
     * damit festschreiben.*
     */
    private function storedText(Label $label): ?string
    {
        foreach ($this->labels->forOwners([$label->ownerId]) as $one) {
            if ($one->path === $label->path
                && $one->roleId === $label->roleId
                && $one->number === $label->number
                && $one->locale === $label->locale
            ) {
                return $one->text;
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
    private function note(Label $label, ?string $was, ?string $now): void
    {
        if ($this->changelog === null || $was === $now) {
            return;
        }

        // ⚠️ *Der Text steht **zuletzt**, weil er das einzige Feld ist, das Leerzeichen enthalten
        // darf; {@see FrozenState} verweigert jede andere Reihenfolge.*
        $state = static fn (?string $text): ?string => $text === null ? null : FrozenState::of([
            'role'   => $label->roleId,
            'path'   => $label->path,
            'number' => $label->number,
            'locale' => $label->locale,
            'text'   => $text,
        ])->write();

        $this->changelog->record(
            $label->ownerId,
            // ⚠️ *Ein Label hängt an einem Knoten **oder** an einer Kante ([D-410](../../../docs/NewConcept/90-decision-log.md)),
            // und die Id allein sagt nicht welches. Ohne das Repository bliebe nur Raten — und geraten
            // hat `Settings` diese Spalte schon einmal, was dort als «einfach eine Lüge» steht.*
            $this->nodes === null || $this->nodes->find($label->ownerId) !== null ? 'node' : 'relation',
            $now === null ? 'label cleared' : 'label set',
            $state($was),
            $state($now),
            // ⚠️ **Ein Label hat keine Version, und zwar gemessen: `labels` trägt keine solche
            // Spalte** — anders als `nodes`, `relations`, `records` und `record_values`. *Seit
            // [D-634](../../../docs/NewConcept/90-decision-log.md) muss der Melder das hinschreiben
            // statt es wegzulassen; `null` ist hier die richtige Antwort und zugleich der Befund
            // (`PR-4`): ob Labels versioniert werden, ist nicht entschieden.*
            null
        );
    }

    /** @return list<Label> Everything stored for this owner, for a screen that lists them. */
    public function storedFor(int $ownerId): array
    {
        return $this->labels->forOwners([$ownerId]);
    }

    /**
     * @param list<Label> $labels
     *
     * @return array<string,Label>
     */
    private function indexed(array $labels, string $path): array
    {
        $byKey = [];

        foreach ($labels as $label) {
            if ($label->path !== $path) {
                continue;
            }

            $byKey[$label->roleId . "\0" . $label->number . "\0" . $label->locale] = $label;
        }

        return $byKey;
    }
}
