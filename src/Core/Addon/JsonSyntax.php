<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/**
 * Where a JSON text first goes wrong — as line and column, because `json_decode()` only says
 * *that* it is wrong ([D-1180](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *`json_decode()` stays the judge: this scanner only runs after it refused, to find the place.
 * Should the two ever disagree, the scanner reports the end of the text rather than nothing.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class JsonSyntax
{
    private string $text = '';

    private int $at = 0;

    /** @return array{line: int, column: int}|null `null` when the text is valid JSON. */
    public function firstError(string $text): ?array
    {
        json_decode($text);

        if (json_last_error() === JSON_ERROR_NONE) {
            return null;
        }

        $this->text = $text;
        $this->at   = 0;

        $offset = $this->scanDocument() ?? strlen($text);

        return $this->position($offset);
    }

    /** @return int|null The offset of the first error, or `null` if the scanner found none. */
    private function scanDocument(): ?int
    {
        $error = $this->scanValue();

        if ($error !== null) {
            return $error;
        }

        $this->skipWhitespace();

        return $this->at < strlen($this->text) ? $this->at : null;
    }

    private function scanValue(): ?int
    {
        $this->skipWhitespace();
        $char = $this->text[$this->at] ?? '';

        return match (true) {
            $char === '{'                                  => $this->scanObject(),
            $char === '['                                  => $this->scanArray(),
            $char === '"'                                  => $this->scanString(),
            $char === '-' || ctype_digit($char)            => $this->scanNumber(),
            $char === 't'                                  => $this->scanWord('true'),
            $char === 'f'                                  => $this->scanWord('false'),
            $char === 'n'                                  => $this->scanWord('null'),
            default                                        => $this->at,
        };
    }

    private function scanObject(): ?int
    {
        $this->at++;
        $this->skipWhitespace();

        if (($this->text[$this->at] ?? '') === '}') {
            $this->at++;

            return null;
        }

        while (true) {
            $this->skipWhitespace();

            if (($this->text[$this->at] ?? '') !== '"') {
                return $this->at;
            }

            $error = $this->scanString();

            if ($error !== null) {
                return $error;
            }

            $this->skipWhitespace();

            if (($this->text[$this->at] ?? '') !== ':') {
                return $this->at;
            }

            $this->at++;
            $error = $this->scanValue();

            if ($error !== null) {
                return $error;
            }

            $this->skipWhitespace();
            $char = $this->text[$this->at] ?? '';

            if ($char === '}') {
                $this->at++;

                return null;
            }

            if ($char !== ',') {
                return $this->at;
            }

            $this->at++;
        }
    }

    private function scanArray(): ?int
    {
        $this->at++;
        $this->skipWhitespace();

        if (($this->text[$this->at] ?? '') === ']') {
            $this->at++;

            return null;
        }

        while (true) {
            $error = $this->scanValue();

            if ($error !== null) {
                return $error;
            }

            $this->skipWhitespace();
            $char = $this->text[$this->at] ?? '';

            if ($char === ']') {
                $this->at++;

                return null;
            }

            if ($char !== ',') {
                return $this->at;
            }

            $this->at++;
        }
    }

    private function scanString(): ?int
    {
        $this->at++;
        $length = strlen($this->text);

        while ($this->at < $length) {
            $char = $this->text[$this->at];

            if ($char === '"') {
                $this->at++;

                return null;
            }

            if (ord($char) < 0x20) {
                return $this->at;
            }

            if ($char === '\\') {
                $next = $this->text[$this->at + 1] ?? '';

                if ($next === 'u') {
                    if (preg_match('/\G[0-9a-fA-F]{4}/', $this->text, $m, 0, $this->at + 2) !== 1) {
                        return $this->at;
                    }

                    $this->at += 6;

                    continue;
                }

                if (strpos('"\\/bfnrt', $next) === false || $next === '') {
                    return $this->at;
                }

                $this->at += 2;

                continue;
            }

            $this->at++;
        }

        return $this->at;
    }

    private function scanNumber(): ?int
    {
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $this->text, $m, 0, $this->at) !== 1) {
            return $this->at;
        }

        $this->at += strlen($m[0]);

        return null;
    }

    private function scanWord(string $word): ?int
    {
        if (substr($this->text, $this->at, strlen($word)) !== $word) {
            return $this->at;
        }

        $this->at += strlen($word);

        return null;
    }

    private function skipWhitespace(): void
    {
        $length = strlen($this->text);

        while ($this->at < $length && strpos(" \t\n\r", $this->text[$this->at]) !== false) {
            $this->at++;
        }
    }

    /** @return array{line: int, column: int} Both counted from 1; the column in characters, not bytes. */
    private function position(int $offset): array
    {
        $before    = substr($this->text, 0, $offset);
        $lineStart = strrpos($before, "\n");
        $lineText  = $lineStart === false ? $before : substr($before, $lineStart + 1);

        return [
            'line'   => substr_count($before, "\n") + 1,
            'column' => mb_strlen($lineText, 'UTF-8') + 1,
        ];
    }
}
