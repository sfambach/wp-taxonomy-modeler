<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * What a scanner typed, read back into the label code it came from (D-1032): a kind letter and the record id, or a bare
 * record id. A scanner is a keyboard, so the code arrives as keystrokes and may carry the host's keyboard layout.
 *
 * ```mermaid
 * flowchart LR
 *   S["scanner types «M-48284»<br/>or «Mß48284» on a German layout"] --> R[ScannedCode::read]
 *   R -->|letter O/M/E/K| K["kind + record id"]
 *   R -->|digits only| B["record id, kind unknown"]
 *   R -->|anything else| N[null]
 * ```
 *
 * ⚠️ *A scanner set to a US layout on a German host sends the key of «-», which the host reads as «ß»; any single
 * separator between letter and digits is therefore taken, not only the hyphen.*
 *
 * @see docs/NewConcept/90-decision-log.md D-1032, D-1206
 */
final class ScannedCode
{
    private function __construct(
        public readonly ?LabelPrefix $kind,
        public readonly int $record,
    ) {
    }

    public static function read(string $typed): ?self
    {
        $typed = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $typed));

        if (preg_match('/^(\d{1,18})$/', $typed, $m) === 1) {
            return self::of(null, $m[1]);
        }

        if (preg_match('/[?&]taxmod_open_record=(\d{1,18})(?:&|#|$)/', $typed, $m) === 1) {
            return self::of(null, $m[1]);
        }

        if (preg_match('/^([A-Za-z])\s*[^\p{L}\p{N}\s]?\s*(\d{1,18})$/u', $typed, $m) === 1
            || preg_match('/^([A-Za-z])\s*ß\s*(\d{1,18})$/u', $typed, $m) === 1) {
            $kind = LabelPrefix::tryFrom(strtoupper($m[1]));

            return $kind === null ? null : self::of($kind, $m[2]);
        }

        return null;
    }

    /** The code as the label prints it, or the bare id when the kind is unknown. */
    public function code(): string
    {
        return $this->kind === null ? (string) $this->record : $this->kind->code($this->record);
    }

    private static function of(?LabelPrefix $kind, string $digits): ?self
    {
        $id = (int) ltrim($digits, '0');

        return $id > 0 ? new self($kind, $id) : null;
    }
}
