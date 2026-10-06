<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

use Taxmod\Core\Exception\NotAFrozenState;

/**
 * What a changelog row froze, as named fields — one builder and one reader for both state columns.
 *
 * ```mermaid
 * flowchart LR
 *   B["of(['key' => …, 'path' => …])"] --> S["key=… path=… type=… value=…"]
 *   S --> P["parse() · field('path')"]
 *   L["a row written before this class<br/>«10» · «1.189.191» · «3 parked»"] --> P
 *   P --> V["plainValue()"]
 * ```
 *
 * ⚠️ **This exists because a journal entry carried a value and no address**
 * ([D-427](../../../docs/NewConcept/90-decision-log.md)). *Measured on the real table: a setting
 * row read `what = "setting min set"`, `before = NULL`, `after = "10"` — so a replay could say
 * something set `min` to 10 and not **for which place** ([D-413](../../../docs/NewConcept/90-decision-log.md)).
 * [D-061](../../../docs/NewConcept/90-decision-log.md)'s claim that «the changelog **is** the
 * migration script» was false against `path`, and the fix is that these two columns stop being
 * prose.*
 *
 * ⚠️ **One place builds and one place reads, because there were already three dialects and two
 * copies of the reader.** *Measured over 10745 rows: `id=… version=… name=… path=…` for a node,
 * `name=… to=… kind=… parked=…` for an relation, a bare value for a setting and for a promotion, and
 * prose for a trash sweep. The `path` was dug out with `strrpos(' path=')` — in
 * {@see \Taxmod\WordPress\Persistence\WpdbChangelog} **and** again in the test double. A format
 * with two readers is a format that will disagree with itself.*
 *
 * ⚠️ **The contract, and it is what makes the format parseable without escaping: keys are
 * lowercase tokens, values are separated by a single space, and only the *last* field may contain
 * whitespace.** *{@see of()} refuses anything else rather than writing a row that cannot be read
 * back. Measured: **844** existing rows carry a node name with a space in it, so this is not a
 * hypothetical — it is why `name` moved behind `path` in the node state and behind `parked` in the
 * relation state.*
 *
 * ⚠️ **Every row written before this class stays readable and none is rewritten.** *A field list
 * begins with `key=`; anything else is a {@see plainValue()} — measured across all 10745 rows,
 * **not one** contains a `=` without beginning with `key=`, so the discriminator is not a guess.
 * A rewrite was considered and refused: the address the old setting rows lack **cannot be
 * recovered**, and stamping `path=` on them would falsify at least the 20 rows whose owner and key
 * carry a non-empty path today. [D-476](../../../docs/NewConcept/90-decision-log.md) is the rule —
 * a step that destroys has to write down what it destroys, and here nothing can be written down.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class FrozenState
{
    /** A key is a lowercase token, so it can never be confused with a word inside a value. */
    private const KEY = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param array<string, string> $fields
     * @param ?string               $plain  The whole content of a row that is not a field list.
     */
    private function __construct(
        private readonly array $fields,
        private readonly ?string $plain,
    ) {
    }

    /**
     * Build a state out of named fields, in the order they are to be written.
     *
     * ⚠️ *Refuses rather than escapes. A value with a space in a non-final position would come back
     * glued to the field before it, and a format that silently loses a character is worse than one
     * that throws — this is the guard the round-trip test exercises.*
     *
     * @param array<string, string|int> $fields
     */
    public static function of(array $fields): self
    {
        if ($fields === []) {
            throw NotAFrozenState::withoutFields();
        }

        $written = [];
        $last    = array_key_last($fields);

        foreach ($fields as $key => $value) {
            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                throw NotAFrozenState::withTheKey((string) $key);
            }

            $text = (string) $value;

            if ($key !== $last && preg_match('/\s/', $text) === 1) {
                throw NotAFrozenState::withWhitespaceBeforeTheLastField($key);
            }

            $written[$key] = $text;
        }

        return new self($written, null);
    }

    /**
     * A row whose whole content is one unnamed value — a bare path, a position, a summary sentence.
     *
     * ⚠️ *Kept as a first-class case rather than treated as a parse failure: `promoted` rows store a
     * bare path on purpose and a restore compares against it, so «no fields» is an answer here and
     * not an error.*
     */
    public static function plain(string $value): self
    {
        return new self([], $value);
    }

    /** Read a stored column, or nothing where the column was null or empty. */
    public static function parse(?string $stored): ?self
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9_]*=/', $stored) !== 1) {
            return self::plain($stored);
        }

        $fields  = [];
        $current = null;

        foreach (explode(' ', $stored) as $token) {
            $at  = strpos($token, '=');
            $key = $at === false || $at === 0 ? null : substr($token, 0, $at);

            // ⚠️ *A token opens a field only when its key is **new**. That is what carries a value
            // with a space in it — `name=__path Text` — and what stops a value that happens to
            // contain ` path=` from being read as a second address. Measured: **0** of 10745 rows
            // hold ` path=` twice, so this rule and the `strrpos()` it replaces agree on every row
            // that exists — which the boundary check asserts row by row rather than in principle.*
            if ($key !== null && preg_match(self::KEY, $key) === 1 && ! array_key_exists($key, $fields)) {
                $fields[$key] = substr($token, $at + 1);
                $current      = $key;

                continue;
            }

            if ($current === null) {
                return self::plain($stored);
            }

            $fields[$current] .= ' ' . $token;
        }

        return new self($fields, null);
    }

    /** The string to store. */
    public function write(): string
    {
        if ($this->plain !== null) {
            return $this->plain;
        }

        $parts = [];

        foreach ($this->fields as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return implode(' ', $parts);
    }

    /** One field, or null where the row does not name it — an old row names none of them. */
    public function field(string $key): ?string
    {
        return $this->fields[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->fields);
    }

    /** The whole content of a row that is not a field list, or null where it is one. */
    public function plainValue(): ?string
    {
        return $this->plain;
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
