<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

/**
 * Which existing fields look like the one about to be made: same type, same settings, similar name — one logic for the
 * MCP search and, later, the «create field» dialog (konzept.md §8 M-c, U3).
 *
 * ```mermaid
 * flowchart LR
 *   W["wanted: type · settings · name"] --> T{"same type?"}
 *   T -- no --> X["left out"]
 *   T -- yes --> S["settings: how many of the wanted ones match"]
 *   T -- yes --> N["name: 0..1 after folding case, umlauts, signs"]
 *   S --> R["score, best first; uses = fields on the same target"]
 *   N --> R
 * ```
 *
 * ⚠️ *A candidate is kept when its name is similar or every wanted setting matches — the thresholds are assumptions of
 * this build, not decided (listed in the PR).*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class SimilarFields
{
    /** From here on a name counts as similar. */
    public const NAME_THRESHOLD = 0.75;

    /** One name inside the other counts this much, when the shorter has at least {@see MIN_CONTAINED} characters. */
    private const CONTAINED = 0.85;

    private const MIN_CONTAINED = 3;

    /**
     * @param array{type?: string|null, settings?: array<string, string>, name?: string} $wanted
     * @param list<array{relation: int, name: string, type: string|null, target: int, settings: array<string, string>, uses: int}> $fields
     * @return list<array{relation: int, score: float, name_similarity: float, matching_settings: list<string>, differing_settings: list<string>}> best first
     */
    public function among(array $wanted, array $fields, int $limit): array
    {
        $typ      = isset($wanted['type']) && $wanted['type'] !== '' ? $wanted['type'] : null;
        $settings = $wanted['settings'] ?? [];
        $name     = self::folded($wanted['name'] ?? '');
        $treffer  = [];
        $uses     = [];

        foreach ($fields as $field) {
            $uses[$field['relation']] = $field['uses'];

            if ($typ !== null && $field['type'] !== $typ) {
                continue;
            }

            $gleich    = [];
            $anders    = [];

            foreach ($settings as $attribute => $value) {
                if (($field['settings'][$attribute] ?? null) === (string) $value) {
                    $gleich[] = $attribute;
                } else {
                    $anders[] = $attribute;
                }
            }

            $aehnlich   = $name === '' ? 0.0 : self::nameSimilarity($name, self::folded($field['name']));
            $alleGleich = $settings !== [] && $anders === [];

            if ($aehnlich < self::NAME_THRESHOLD && ! $alleGleich && ! ($name === '' && $settings === [])) {
                continue;
            }

            $anteil    = $settings === [] ? null : count($gleich) / count($settings);
            $score     = match (true) {
                $anteil === null => $aehnlich,
                $name === ''     => $anteil,
                default          => ($aehnlich + $anteil) / 2,
            };
            $treffer[] = ['relation' => $field['relation'], 'score' => round($score, 3), 'name_similarity' => round($aehnlich, 3), 'matching_settings' => $gleich, 'differing_settings' => $anders];
        }

        // *Equal score: the more used one first — it is the likelier data element to reuse.*
        usort($treffer, static fn (array $a, array $b): int => [$b['score'], $uses[$b['relation']] ?? 0, $a['relation']] <=> [$a['score'], $uses[$a['relation']] ?? 0, $b['relation']]);

        return array_slice($treffer, 0, max(1, $limit));
    }

    /** 1 for the same name after folding, {@see CONTAINED} when one holds the other, else one minus the edit distance per character. */
    public static function nameSimilarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $kurz = strlen($a) <= strlen($b) ? $a : $b;
        $lang = $kurz === $a ? $b : $a;

        if (strlen($kurz) >= self::MIN_CONTAINED && str_contains($lang, $kurz)) {
            return self::CONTAINED;
        }

        return max(0.0, 1 - levenshtein($a, $b) / strlen($lang));
    }

    /** Lower case, umlauts spelled out, everything but letters and digits dropped: «Max. Größe» and «max groesse» are one name. */
    public static function folded(string $name): string
    {
        $klein = strtr(mb_strtolower($name), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'µ' => 'u', 'é' => 'e', 'è' => 'e']);

        return (string) preg_replace('/[^a-z0-9]+/', '', $klein);
    }
}
