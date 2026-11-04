<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * What goes where on a label, written as JSON so a person can change it without a release (D-1153).
 *
 * ⚠️ *His word 2026-11-04: «platz etiketten und material etiketten unterscheiden sich können wir das layout irgndwie in json oder
 * xml fassen» — and on the sketch: «sieht fast aus wie zpl». The keys are English; the German ones of that sketch are read too
 * (`typ`, `feld`, `b`, `fett`, `zeilen`, `unten`), so what he wrote keeps working.*
 *
 * ```mermaid
 * flowchart LR
 *   J["JSON: list of elements"] --> L["LabelLayout"]
 *   V["values of one record: field ⇒ text"] --> D["LabelDocument::renderLayout"]
 *   L --> D --> P["PDF"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1153
 */
final class LabelLayout
{
    private const ALIASES = ['typ' => 'type', 'feld' => 'field', 'b' => 'w', 'fett' => 'bold', 'zeilen' => 'lines', 'unten' => 'bottom', 'beispiel' => 'example'];

    /** @param list<LabelElement> $elements */
    public function __construct(public readonly array $elements)
    {
        if ($elements === []) {
            throw new \InvalidArgumentException('A label layout needs at least one element.');
        }
    }

    public static function fromJson(string $json): self
    {
        try {
            $raw = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('The label layout is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new \InvalidArgumentException('A label layout is a list of elements.');
        }

        $elements = [];
        foreach ($raw as $i => $entry) {
            if (! is_array($entry)) {
                throw new \InvalidArgumentException(sprintf('Element %d of the label layout is not an object.', $i + 1));
            }
            $element = [];
            foreach ($entry as $key => $value) {
                $element[self::ALIASES[$key] ?? $key] = $value;
            }
            $number = static fn (string $key, float $default = 0.0): float => is_numeric($element[$key] ?? null) ? (float) $element[$key] : $default;
            $elements[] = new LabelElement(
                (string) ($element['type'] ?? LabelElement::TEXT),
                trim((string) ($element['field'] ?? '')),
                $number('x'),
                $number('y'),
                $number('w'),
                $number('h', $number('w')),
                $number('pt', 12.0),
                (bool) ($element['bold'] ?? false),
                (int) $number('lines', 1.0),
                (bool) ($element['bottom'] ?? false),
                is_scalar($element['example'] ?? null) ? trim((string) $element['example']) : '',
            );
        }

        return new self($elements);
    }

    public function toJson(): string
    {
        $lines = array_map(static fn (LabelElement $e): string => json_encode($e->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $this->elements);

        return "[\n " . implode(",\n ", $lines) . "\n]";
    }

    /** The boxes must stay on the label; a box outside it is a typo that would print nothing. */
    public function fitsOn(LabelFormat $format): bool
    {
        foreach ($this->elements as $e) {
            if ($e->xMm + $e->widthMm > $format->widthMm + 0.01 || $e->yMm + $e->heightMm > $format->heightMm + 0.01) {
                return false;
            }
        }

        return true;
    }

    /**
     * D-1154, his word 2026-11-04: «ich dachte im jsaon ein feld mit beispiel» — the example values the layout itself carries,
     * the first one written for a field winning.
     *
     * @return array<string, string> field ⇒ example
     */
    public function examples(): array
    {
        $out = [];
        foreach ($this->elements as $e) {
            if ($e->example !== '' && ! isset($out[$e->field])) {
                $out[$e->field] = $e->example;
            }
        }

        return $out;
    }

    /** @return list<string> the fields the layout reads, in order, each once */
    public function fields(): array
    {
        return array_values(array_unique(array_map(static fn (LabelElement $e): string => $e->field, $this->elements)));
    }
}
