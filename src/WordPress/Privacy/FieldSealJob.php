<?php declare(strict_types=1);

namespace Taxmod\WordPress\Privacy;

use Taxmod\WordPress\Persistence\FieldSealRows;

/**
 * Seals what a field held before it became confidential (D-1514): its live rows, its history rows and its values in the
 * chronicle — in batches, so a large table never holds one request long.
 *
 * ```mermaid
 * flowchart LR
 *   T["admin_init, höchstens alle 5 Min"] --> J["runBatch()"]
 *   J --> L[("relation_records")] & H[("relation_records_history")] & C[("changelog")]
 *   J -->|Arbeit übrig| T
 * ```
 *
 * ⚠️ *INFERRED: sealing changes the stored form, not the value, so it is no act, raises no version and writes no shadow
 * row — an undo must never bring a plain text back. The rows stay where they are, with the same id.*
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class FieldSealJob
{
    public const THROTTLE = 'taxmod_field_seal_next';

    private const BATCH = 500;

    /** For the first, node-blind look at the chronicle: a state counts as a candidate whatever its record's node says. */
    private const ANY_NODE = [];

    /** Hooked on admin_init: a short look at most every five minutes, at once again while work is left. */
    public static function onAdmin(): void
    {
        if (get_transient(self::THROTTLE) !== false || FieldSeal::confidentialRelations() === [] || FieldSeal::cipher() === null) {
            return;
        }

        $done = self::runBatch();

        if ($done === 0) {
            FieldSeal::noteSealedWith(self::sealedWithRetired(), time());
        }

        set_transient(self::THROTTLE, 1, $done >= self::BATCH ? 1 : 5 * MINUTE_IN_SECONDS);
    }

    /**
     * One batch over the three tables — values of a former key resealed (D-1562), plain values that are confidential for their
     * record's node sealed (D-1514, D-1578), and, only when asked (`$unseal`), sealed values that are no longer confidential
     * there opened again; answers how many rows it changed.
     */
    public static function runBatch(int $limit = self::BATCH, bool $unseal = false): int
    {
        FieldSeal::ready();
        $cipher = FieldSeal::cipher();

        if ($cipher === null) {
            return 0;
        }

        $done = self::resealBatch($cipher, $limit);

        foreach (['relation_records', 'relation_records_history'] as $tabelle) {
            $rows  = self::wanted($tabelle, false, max(0, $limit - $done));
            $texte = FieldSealRows::textsOf($tabelle, array_column($rows, 'id'));

            foreach ($rows as $zeile) {
                $text = (string) ($texte[$zeile['id']] ?? '');
                FieldSealRows::replaceText($tabelle, $zeile['id'], $zeile['version'], (string) FieldSeal::sealText($zeile['relation_id'], $text, $zeile['node_id']));
                $done++;
            }
        }

        foreach (self::journalWanted(false, max(0, $limit - $done)) as [$zeile, $nodes]) {
            FieldSealRows::replaceJournalStates($zeile['id'], self::sealStateAt($zeile['before_state'], $nodes), self::sealStateAt($zeile['after_state'], $nodes));
            $done++;
        }

        if (! $unseal) {
            return $done;
        }

        foreach (['relation_records', 'relation_records_history'] as $tabelle) {
            $rows  = self::wanted($tabelle, true, max(0, $limit - $done));
            $texte = FieldSealRows::textsOf($tabelle, array_column($rows, 'id'));

            foreach ($rows as $zeile) {
                try {
                    FieldSealRows::replaceText($tabelle, $zeile['id'], $zeile['version'], $cipher->open((string) ($texte[$zeile['id']] ?? '')));
                    $done++;
                } catch (\Taxmod\Core\Exception\FieldDoesNotOpen) {
                    // *What no key here opens stays as it is.*
                }
            }
        }

        foreach (self::journalWanted(true, max(0, $limit - $done)) as [$zeile, $nodes]) {
            try {
                FieldSealRows::replaceJournalStates(
                    $zeile['id'],
                    FieldSeal::stateIsNeedlesslySealed($zeile['before_state'], $nodes) ? FieldSeal::unsealState($zeile['before_state']) : $zeile['before_state'],
                    FieldSeal::stateIsNeedlesslySealed($zeile['after_state'], $nodes) ? FieldSeal::unsealState($zeile['after_state']) : $zeile['after_state']
                );
                $done++;
            } catch (\Taxmod\Core\Exception\FieldDoesNotOpen) {
                // *As above.*
            }
        }

        return $done;
    }

    /**
     * D-1578: the rows of a value table that have to change — plain and confidential at their node, or (`$sealed`) sealed
     * and not confidential there. Paged by id, so rows that rightly stay as they are never block the ones behind them.
     *
     * @return list<array{id: int, version: ?int, relation_id: int, node_id: int}>
     */
    private static function wanted(string $tabelle, bool $sealed, int $limit): array
    {
        $kanten = $sealed ? self::edgesWithSealedValues() : array_keys(FieldSeal::confidentialRelations());
        $aus    = [];
        $nach   = 0;

        while ($limit > 0 && $kanten !== []) {
            $seite = FieldSealRows::rowsWithNode($tabelle, $kanten, $sealed, $nach, self::BATCH);

            foreach ($seite as $zeile) {
                if (FieldSeal::isConfidentialAt($zeile['relation_id'], $zeile['node_id']) !== $sealed) {
                    $aus[] = $zeile;

                    if (count($aus) >= $limit) {
                        return $aus;
                    }
                }
            }

            if (count($seite) < self::BATCH) {
                break;
            }

            $nach = $seite[array_key_last($seite)]['id'];
        }

        return $aus;
    }

    /**
     * The chronicle rows that have to change, each with the nodes of the records it names (one read for all of them).
     *
     * @return list<array{0: array{id: int, before_state: ?string, after_state: ?string}, 1: array<int, int>}>
     */
    private static function journalWanted(bool $sealed, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $kandidaten = $sealed
            ? FieldSealRows::journalSealedRows(static fn (): bool => true, PHP_INT_MAX)
            : FieldSealRows::journalRowsOn(array_keys(FieldSeal::confidentialRelations()), static fn (?string $before, ?string $after): bool => FieldSeal::stateIsPlain($before, self::ANY_NODE) || FieldSeal::stateIsPlain($after, self::ANY_NODE), PHP_INT_MAX);

        $saetze = [];

        foreach ($kandidaten as $zeile) {
            foreach ([$zeile['before_state'], $zeile['after_state']] as $state) {
                $parsed = \Taxmod\Core\Model\FrozenState::parse($state);
                $record = $parsed === null ? null : FieldSeal::recordOf($parsed);

                if ($record !== null) {
                    $saetze[] = $record;
                }
            }
        }

        $nodes = FieldSealRows::nodesOfRecords($saetze);
        $aus   = [];

        foreach ($kandidaten as $zeile) {
            $passt = $sealed
                ? FieldSeal::stateIsNeedlesslySealed($zeile['before_state'], $nodes) || FieldSeal::stateIsNeedlesslySealed($zeile['after_state'], $nodes)
                : FieldSeal::stateIsPlain($zeile['before_state'], $nodes) || FieldSeal::stateIsPlain($zeile['after_state'], $nodes);

            if ($passt) {
                $aus[] = [$zeile, $nodes];

                if (count($aus) >= $limit) {
                    break;
                }
            }
        }

        return $aus;
    }

    /**
     * A state sealed where it is confidential for the node of the record it names.
     *
     * @param array<int, int> $nodes
     */
    private static function sealStateAt(?string $state, array $nodes): ?string
    {
        $parsed = \Taxmod\Core\Model\FrozenState::parse($state);
        $record = $parsed === null ? null : FieldSeal::recordOf($parsed);

        return FieldSeal::sealState($state, $record === null ? null : ($nodes[$record] ?? null));
    }

    /** @return list<int> the edges on which some live or history row is sealed */
    private static function edgesWithSealedValues(): array
    {
        return FieldSealRows::sealedEdges();
    }

    /** D-1562: values sealed with a former key, sealed again with the current one — no act, as sealing is none. */
    private static function resealBatch(\Taxmod\Core\Privacy\FieldCipher $cipher, int $limit): int
    {
        $done  = 0;
        $keyId = $cipher->sealingKeyId();

        foreach (['relation_records', 'relation_records_history'] as $tabelle) {
            foreach (FieldSealRows::sealedOtherRows($tabelle, $keyId, $limit) as $zeile) {
                try {
                    FieldSealRows::replaceText($tabelle, $zeile['id'], $zeile['version'], $cipher->reseal($zeile['text']));
                    $done++;
                } catch (\Taxmod\Core\Exception\FieldDoesNotOpen) {
                    // *A value no key here opens (a key deleted, a foreign copy) stays as it is and reads as the lock.*
                }
            }
        }

        $journal = FieldSealRows::journalSealedRows(
            static fn (?string $before, ?string $after): bool => FieldSeal::stateNeedsResealing($before) || FieldSeal::stateNeedsResealing($after),
            $limit
        );

        foreach ($journal as $zeile) {
            try {
                FieldSealRows::replaceJournalStates($zeile['id'], FieldSeal::resealState($zeile['before_state']), FieldSeal::resealState($zeile['after_state']));
                $done++;
            } catch (\Taxmod\Core\Exception\FieldDoesNotOpen) {
                // *As above: what does not open is left.*
            }
        }

        return $done;
    }

    /**
     * D-1562: what is still sealed with a former key — live, history, chronicle.
     *
     * @return array{live: int, history: int, journal: int}
     */
    public static function resealLeft(): array
    {
        $cipher = FieldSeal::cipher();

        if ($cipher === null) {
            return ['live' => 0, 'history' => 0, 'journal' => 0];
        }

        $keyId = $cipher->sealingKeyId();

        return [
            'live'    => FieldSealRows::sealedCount('relation_records', $keyId),
            'history' => FieldSealRows::sealedCount('relation_records_history', $keyId),
            'journal' => count(FieldSealRows::journalSealedRows(
                static fn (?string $before, ?string $after): bool => FieldSeal::stateNeedsResealing($before) || FieldSeal::stateNeedsResealing($after),
                PHP_INT_MAX
            )),
        ];
    }

    /**
     * Per former key of the tab: how many values are still sealed with it (live, history, chronicle together).
     *
     * @return array<string, int>
     */
    public static function sealedWithRetired(): array
    {
        $aus = [];

        foreach (array_keys(FieldSeal::retired()) as $keyId) {
            $aus[$keyId] = FieldSealRows::sealedCount('relation_records', $keyId, true)
                + FieldSealRows::sealedCount('relation_records_history', $keyId, true)
                + FieldSealRows::journalSealedWithCount($keyId);
        }

        return $aus;
    }

    /**
     * What is still plain although it is confidential for its record's node — the number the check reads.
     *
     * @return array{live: int, history: int, journal: int}
     */
    public static function plainLeft(): array
    {
        return [
            'live'    => count(self::wanted('relation_records', false, PHP_INT_MAX)),
            'history' => count(self::wanted('relation_records_history', false, PHP_INT_MAX)),
            'journal' => count(self::journalWanted(false, PHP_INT_MAX)),
        ];
    }

    /**
     * D-1578: what is sealed although it is not confidential for its record's node (an organisation's name after the switch).
     *
     * @return array{live: int, history: int, journal: int}
     */
    public static function unsealLeft(): array
    {
        if (FieldSeal::cipher() === null) {
            return ['live' => 0, 'history' => 0, 'journal' => 0];
        }

        return [
            'live'    => count(self::wanted('relation_records', true, PHP_INT_MAX)),
            'history' => count(self::wanted('relation_records_history', true, PHP_INT_MAX)),
            'journal' => count(self::journalWanted(true, PHP_INT_MAX)),
        ];
    }
}
