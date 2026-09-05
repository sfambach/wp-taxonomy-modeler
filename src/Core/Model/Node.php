<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

use Taxmod\Core\Renderer\Renderable;

use Taxmod\Core\Exception\InvalidName;

/**
 * A node — the one kind of thing the model is built from.
 *
 * Exactly four fixed attributes and no more (sentence 3 of the core on one page). Everything
 * else a node appears to have is a **relation** seen from the node that owns it (D-031), and a
 * node's **type** is its position in the inheritance tree, not a column (D-041, D-042).
 *
 * ⚠️ **Nicht mehr `final`, und das ist [D-620](../../../docs/NewConcept/90-decision-log.md).** *Der
 * Eigentümer: «einen int-Knoten und eine int-Klasse zusätzlich zu führen, reisst was auseinander, das
 * eigentlich zusammengehört.» **Die spezialisierte Klasse ist die Klasse des Knotens** — also erbt
 * {@see Type\SpecialisedType} von hier, und ebenso {@see \Taxmod\Core\Renderer\RendererNode}, «weil
 * wir sie ja auch einfach zuweisen».*
 *
 * ⚠️ **Der Preis stand schon in [D-484](../../../docs/NewConcept/90-decision-log.md) und ist hier
 * bezahlt:** *die Endgültigkeit fällt, das feste `self` wird zur späten statischen Bindung — ein
 * umbenannter `IntType` bleibt ein `IntType` —, und die Hydrierung braucht ihren Unterscheider. **Er
 * steht in der Zeile**: `implemented_by` nennt die Klasse ({@see fromStorage()}).*
 *
 * ⚠️ **Nicht jeder Knoten bekommt eine Klasse** (D-620 in eigenen Worten). *Es sind die **gesäten**
 * Typ- und Renderer-Knoten; alles andere ist ein schlichtes `Node`, und das ist die überwiegende
 * Mehrheit — Inhalt des Eigentümers, den kein Code umsetzt.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
class Node extends Identity implements Renderable
{
    /**
     * @param int    $id      From the model identity space, shared with relations (C11).
     *                        Meaningless, stable, never resolved on.
     * @param int    $version Raised on every change that actually changed something (D-282).
     * @param string $name    Required, deliberately **not** unique (D-022).
     * @param string $path    Materialised ancestor path, ids separated by `.`, own id last.
     *                        **Derived** and rebuildable — never a second truth (D-014).
     */
    protected function __construct(
        int $id,
        int $version,
        string $name,
        public readonly string $path,
        /**
         * Die PHP-Klasse, die diesen Knoten umsetzt — voll qualifiziert, oder `null`.
         *
         * ⚠️ **Der Klassenname und keine Marke** (TASK-008,
         * [`package.md` §3.2](../../../docs/pakete/modelltabellen/package.md)). *Der Eigentümer:
         * «wenn das ohne Factory geht, weil der Klassenname da drinsteht, perfekt.» Damit sagt der
         * **Knoten**, welcher Renderer, Konverter oder Validator er ist — statt einer WordPress-Option
         * ausserhalb des Modells (`AR-1`, TASK-009).*
         *
         * ⚠️ **`null` heisst «keine Klasse setzt ihn um», nicht «unbekannt».** *Die überwiegende
         * Mehrheit der Knoten ist Inhalt des Eigentümers und hat keine Entsprechung im Code — ein
         * Vorfahrenlauf wie bei der Feldsorte wäre hier falsch: **eine Klasse erbt sich nicht.***
         *
         * ⚠️ *Ein Klassenname in den Daten bindet die Zeile an den Code, und das ist der Preis. Der
         * Ausgleich steht als Wächter daneben: `implemented-by-check.php` wird rot, sobald eine Zeile
         * eine Klasse nennt, die es nicht gibt.*
         */
        public readonly ?string $implementedBy = null,
        /**
         * Unter wem dieser Knoten hängt — `null` genau für die Wurzel
         * ([D-581](../../../docs/NewConcept/90-decision-log.md), TASK-018).
         *
         * ⚠️ **Vererbung ist keine Kantenart mehr, sondern eine Spalte.** *Sein Satz: «Vererbung ist
         * so unterschiedlich zu Relation, eigentlich würde hier eine `parent_node_id` im Knoten
         * reichen, um das abzubilden, und wäre selektionstechnisch billiger.»*
         *
         * ⚠️ *Sie steht **am Kind**, nicht als Liste am Elternteil — eine Liste von Ids in einer
         * Spalte wäre ein zusammengesetzter Wert, dasselbe Muster wie der Pfad.*
         */
        public readonly ?int $parentNodeId = null,
        /**
         * An welcher Stelle unter dem Elternteil — die zweite Hälfte von
         * [D-581](../../../docs/NewConcept/90-decision-log.md).
         *
         * ⚠️ *Seine Annahme, er habe sie bei Vererbung «noch nie» benutzt, war falsch: **gemessen
         * hatten alle Elternknoten mit mehreren Kindern lauter verschiedene Stellen**. Etwas hat
         * dort sortiert.*
         */
        public readonly int $sortOrder = 0,
        /**
         * Ob diese Stelle im Baum gezeichnet wird.
         *
         * ⚠️ **Die Spalte kommt von der Vererbungskante hierher** (TASK-018). *[D-467](../../../docs/NewConcept/90-decision-log.md)
         * hatte sie «auf die Kante allein» gelegt — **und [D-581](../../../docs/NewConcept/90-decision-log.md)
         * hält fest, dass damit alle ihre Benutzer Vererbungskanten sind**. Fällt die Kante, muss die
         * Angabe mitkommen, sonst geht sie verloren.*
         *
         * ⚠️ *Was `hide` **ist** — Spalte oder Einstellung —, hat der Eigentümer ausdrücklich
         * vertagt: «das besprechen wir, wenn wir Settings nochmal umwerfen». Dieser Umzug beantwortet
         * die Frage nicht, er bewahrt nur die Angabe.*
         */
        public readonly bool $hide = false,
    ) {
        // ⚠️ *`id` und `version` gehoeren beiden und wohnen darum bei {@see Identity} — C86s
        // «whatever serves those two purposes, and nothing else», D-080s zwei Felder.*
        parent::__construct($id, $version, $name);
    }

    /**
     * Rebuild a node from what storage holds. No validation beyond the name — storage is
     * trusted, input is not.
     *
     * ⚠️ **Und hier steht der Unterscheider** ([D-620](../../../docs/NewConcept/90-decision-log.md),
     * angekündigt von [D-484](../../../docs/NewConcept/90-decision-log.md)). *Nennt die Zeile eine
     * Klasse, die selbst ein `Node` ist, kommt der Knoten **als diese Klasse** an — ein Typknoten als
     * {@see Type\IntType}, ein Renderer-Knoten als seine Renderer-Klasse. **Es braucht keinen
     * Vorfahrenlauf**: die Klasse steht in der Zeile, weil nur die gesäten Typ- und Renderer-Knoten
     * eine tragen.*
     *
     * ⚠️ *Nennt sie etwas anderes — einen Konverter, einen Validator, oder eine Klasse, die es nicht
     * mehr gibt —, bleibt es ein schlichtes `Node`. **Ein fehlender Klassenname darf keinen Absturz
     * geben**, dafür ist `implemented-by-check.php` der Wächter und nicht die Hydrierung.*
     */
    public static function fromStorage(
        int $id,
        int $version,
        string $name,
        string $path,
        ?string $implementedBy = null,
        ?int $parentNodeId = null,
        int $sortOrder = 0,
        bool $hide = false,
    ): self {
        $class = self::classHydrating($implementedBy) ?? static::class;

        return new $class($id, $version, $name, $path, $implementedBy, $parentNodeId, $sortOrder, $hide);
    }

    /**
     * Derselbe Knoten mit einzelnen geänderten Angaben — **benannt statt der Reihe nach**.
     *
     * ⚠️ **Aus demselben Anlass wie {@see Relation::copy()}, und der Anlass war ein Fehler.** *Mit
     * neun Angaben ist ein `new static(...)` der Reihe nach eine Falle: der Umzug von TASK-018 hat
     * drei davon hinzugefügt, und jede Stelle, die sie vergisst, setzt still eine Wurzel ohne
     * Elternteil auf Stelle 0. **Die Fassung zählt der Aufrufer**, nicht diese Methode — ein
     * Umbenennen hebt sie, ein Nachziehen der Id nicht.*
     */
    private function copy(
        ?int $id = null,
        ?int $version = null,
        ?string $name = null,
        ?string $path = null,
        bool $clearImplementedBy = false,
        ?string $implementedBy = null,
        bool $clearParent = false,
        ?int $parentNodeId = null,
        ?int $sortOrder = null,
        ?bool $hide = null,
    ): static {
        return new static(
            $id ?? $this->id,
            $version ?? $this->version,
            $name ?? $this->name,
            $path ?? $this->path,
            $clearImplementedBy ? null : ($implementedBy ?? $this->implementedBy),
            $clearParent ? null : ($parentNodeId ?? $this->parentNodeId),
            $sortOrder ?? $this->sortOrder,
            $hide ?? $this->hide,
        );
    }

    /** @var array<string, class-string<self>|null> Einmal je Klassenname gefragt, nicht je Zeile. */
    private static array $hydrators = [];

    /**
     * Die Klasse, als die eine Zeile mit diesem `implemented_by` ankommt — oder `null`.
     *
     * @return class-string<self>|null
     */
    private static function classHydrating(?string $implementedBy): ?string
    {
        if ($implementedBy === null || $implementedBy === '') {
            return null;
        }

        if (array_key_exists($implementedBy, self::$hydrators)) {
            return self::$hydrators[$implementedBy];
        }

        $passt = class_exists($implementedBy)
            && is_subclass_of($implementedBy, self::class)
            && ! (new \ReflectionClass($implementedBy))->isAbstract();

        return self::$hydrators[$implementedBy] = $passt ? $implementedBy : null;
    }

    /**
     * A node as it is first created: version 1, and its path decided by its parent.
     *
     * @param string|null $parentPath Null for a root, whose path is its own id.
     *
     * ⚠️ *Seit TASK-018 kommen Elternteil und Stelle **mit**, statt gleich danach als Kante
     * geschrieben zu werden ([D-581](../../../docs/NewConcept/90-decision-log.md)). Der Pfad allein
     * sagte den Vater schon, die Stelle nicht — und zwei Schreibwege für eine Einordnung sind zwei
     * Gelegenheiten, verschieden zu antworten.*
     */
    public static function create(int $id, string $name, ?string $parentPath, ?int $parentNodeId = null, int $sortOrder = 0): self
    {
        $name = self::cleanName($name);

        return new static(
            $id,
            1,
            $name,
            $parentPath === null ? (string) $id : $parentPath . '.' . $id,
            null,
            $parentNodeId,
            $sortOrder,
        );
    }

    /**
     * The same node under a different name, one version on.
     *
     * ⚠️ Returns the **same** instance when nothing changed, so an unchanged save cannot raise
     * the version (D-282). Callers compare identity, not equality.
     */
    public function renamedTo(string $name): self
    {
        $name = self::cleanName($name);

        if ($name === $this->name) {
            return $this;
        }

        return $this->copy(version: $this->version + 1, name: $name);
    }

    /**
     * The same node moved under a new parent, one version on.
     *
     * The path of every descendant changes with it; that is the repository's job, because it is
     * one statement in the database and would be N+1 here (`CD-7`).
     */
    public function movedUnder(?string $parentPath, ?int $parentNodeId = null, ?int $sortOrder = null): self
    {
        $path = $parentPath === null ? (string) $this->id : $parentPath . '.' . $this->id;

        $vater  = $parentNodeId ?? $this->parentNodeId;
        $stelle = $sortOrder ?? $this->sortOrder;

        if ($path === $this->path && $vater === $this->parentNodeId && $stelle === $this->sortOrder) {
            return $this;
        }

        return $this->copy(
            version: $this->version + 1,
            path: $path,
            clearParent: $vater === null,
            parentNodeId: $vater,
            sortOrder: $stelle,
        );
    }

    /**
     * Derselbe Knoten an einer anderen Stelle unter demselben Elternteil — eine Fassung weiter.
     *
     * ⚠️ *Die Ablösung von `Relation::movedTo()` für den Baum (TASK-018,
     * [D-581](../../../docs/NewConcept/90-decision-log.md)): die Reihenfolge steht am Kind, also
     * ändert sie der Knoten.*
     */
    public function movedTo(int $sortOrder): self
    {
        if ($sortOrder === $this->sortOrder) {
            return $this;
        }

        return $this->copy(version: $this->version + 1, sortOrder: $sortOrder);
    }

    /**
     * Dieselbe Stelle im Baum, gezeichnet oder nicht — eine Fassung weiter.
     *
     * ⚠️ *Kam mit TASK-018 von {@see Relation::withHide()} hierher, weil alle ihre Benutzer
     * Vererbungskanten waren ([D-581](../../../docs/NewConcept/90-decision-log.md)). **Die Frage, ob
     * `hide` eine Einstellung sein soll, ist damit nicht beantwortet** — der Eigentümer hat sie
     * vertagt.*
     */
    public function withHide(bool $hide): self
    {
        if ($hide === $this->hide) {
            return $this;
        }

        return $this->copy(version: $this->version + 1, hide: $hide);
    }

    /**
     * Derselbe Knoten, der eine andere PHP-Klasse nennt — eine Fassung weiter.
     *
     * ⚠️ *Gibt **dasselbe** Exemplar zurück, wenn sich nichts ändert
     * ([D-282](../../../docs/NewConcept/90-decision-log.md)). Ein leerer Name wird zu `null`, damit
     * «nichts» genau eine Schreibweise hat — sonst stünden `''` und `NULL` nebeneinander und sagten
     * dasselbe, die Falle aus {@see \Taxmod\WordPress\Persistence\WpdbNodeRepository}.*
     */
    public function implementedBy(?string $className): self
    {
        $className = $className === null || trim($className) === '' ? null : trim($className);

        if ($className === $this->implementedBy) {
            return $this;
        }

        return $this->copy(version: $this->version + 1, clearImplementedBy: $className === null, implementedBy: $className);
    }

    // ⚠️ *`withHide()` stood here and is gone to {@see Relation::withHide()} alone
    // ([D-467](../../../docs/NewConcept/90-decision-log.md)). **Hiding is about a placement, not
    // about a thing** — the owner: «I do not simply create a model node and then say I will not draw
    // it, that would be nonsense». A node is hidden by hiding the inheritance relation that puts it in
    // the tree, which is one thing to hide rather than two that can disagree.*

    /**
     * Derselbe frische Knoten, nachdem seine Tabelle ihm die Id vergeben hat (TASK-004).
     *
     * ⚠️ **Seit `identities` gestrichen ist, kommt die Id aus dem `AUTO_INCREMENT` der eigenen
     * Tabelle** — also erst beim Schreiben und nicht davor
     * ([`package.md` §6](../../../docs/pakete/modelltabellen/package.md)). *Der Pfad trägt die eigene
     * Id als letztes Glied ([D-014](../../../docs/NewConcept/90-decision-log.md)), und genau deshalb
     * gibt es diese Methode: **das letzte Glied wird nachgezogen**, sobald die Nummer feststeht.*
     *
     * ⚠️ *Nur für eine Zeile gedacht, die gerade erst entstanden ist. Eine bestehende Id zu
     * verbiegen wäre eine Umnummerierung, und die gibt es nicht — die Fassung bleibt darum
     * unberührt.*
     */
    public function withAssignedId(int $id): self
    {
        if ($id === $this->id) {
            return $this;
        }

        $segmente = explode('.', $this->path);
        array_pop($segmente);
        $segmente[] = (string) $id;

        return $this->copy(id: $id, path: implode('.', $segmente));
    }

    /**
     * The ids of this node's ancestors, nearest last, without the node itself.
     *
     * @return list<int>
     */
    public function ancestorIds(): array
    {
        $ids = array_map(intval(...), explode('.', $this->path));
        array_pop($ids);

        return $ids;
    }

    public function parentId(): ?int
    {
        $ancestors = $this->ancestorIds();

        return $ancestors === [] ? null : (int) end($ancestors);
    }

    public function isDescendantOf(self $other): bool
    {
        return str_starts_with($this->path, $other->path . '.');
    }

    /**
     * Trim what a person cannot see, then refuse what is left only if it is nothing.
     *
     * The trimming is deliberate: a trailing space is a mistake nobody makes on purpose and
     * every later comparison would carry it. Refusing it instead would be a needless error.
     */
    private static function cleanName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw InvalidName::empty();
        }

        return $name;
    }

    /**
     * Was ein Leser fuer diesen Knoten liest — der Name, weil der Modellbaum den rohen Namen zeigt
     * ([D-369](../../../docs/NewConcept/90-decision-log.md)).
     */
    public function label(): string
    {
        return $this->name;
    }

    /**
     * Leer, und das ist eine Antwort: ein Knoten haelt keinen eigenen Wert. Der Wert eines Feldes
     * gehoert einem **Datensatz** ([D-232](../../../docs/NewConcept/90-decision-log.md)).
     */
    public function content(): string
    {
        return '';
    }
}
