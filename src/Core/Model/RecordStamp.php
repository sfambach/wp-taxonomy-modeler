<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The versions a form or a module read — record id ⇒ version — carried to the save and compared there
 * ([D-1148](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   R["read: record + parts"] --> S["stamp «12:3,40:1»"] --> F["form / change list"]
 *   F --> C{"save: same versions?"}
 *   C -->|yes| W["write, versions +1"]
 *   C -->|no| K["refuse → conflict dialog"]
 * ```
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class RecordStamp
{
    /** @param array<int, int> $versions record id ⇒ version */
    public function __construct(public readonly array $versions)
    {
    }

    /** `12:3,40:1` — what a hidden form field carries; anything else in the text is ignored, not guessed. */
    public static function parse(string $text): self
    {
        $versions = [];

        foreach (explode(',', $text) as $pair) {
            if (preg_match('/^\s*(\d+):(\d+)\s*$/', $pair, $m) === 1 && (int) $m[1] > 0) {
                $versions[(int) $m[1]] = (int) $m[2];
            }
        }

        return new self($versions);
    }

    /** @param list<array{record?: mixed, version?: mixed}> $expect what `taxmod/write-data` takes under `expect` */
    public static function fromList(array $expect): self
    {
        $versions = [];

        foreach ($expect as $one) {
            $id = (int) ($one['record'] ?? 0);

            if ($id > 0 && isset($one['version'])) {
                $versions[$id] = (int) $one['version'];
            }
        }

        return new self($versions);
    }

    public function isEmpty(): bool
    {
        return $this->versions === [];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_keys($this->versions);
    }

    public function text(): string
    {
        $pairs = [];

        foreach ($this->versions as $id => $version) {
            $pairs[] = $id . ':' . $version;
        }

        return implode(',', $pairs);
    }

    /**
     * What moved on since the stamp was taken.
     *
     * @param  array<int, int>      $now record id ⇒ version as stored now
     * @return array<int, int|null> record id ⇒ the version now, `null` when it is gone; empty when nothing changed.
     */
    public function changedAgainst(array $now): array
    {
        $changed = [];

        foreach ($this->versions as $id => $version) {
            if (! isset($now[$id])) {
                $changed[$id] = null;
            } elseif ($now[$id] !== $version) {
                $changed[$id] = $now[$id];
            }
        }

        return $changed;
    }
}
