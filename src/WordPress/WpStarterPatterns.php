<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Page\PatternSection;
use Taxmod\Core\Page\StarterPattern;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Service\ModelEditor;

/**
 * Meldet jede Seitenvorlage des Modells bei WordPress als Startmuster an ([D-870](../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Sein Wort: «wenn ich einen neuen Beitrag aufmache, verwende dies oder jenes Template» — und: «ich will natürlich nicht WordPress neu
 * erfinden». WordPress kennt Startmuster schon: ein Muster für `core/post-content` bietet der Block-Editor beim neuen Beitrag selbst an.*
 *
 * ⚠️ *Nur im Verwaltungsbereich und für die REST-Schnittstelle, über die der Editor die Muster holt — der öffentliche Seitenaufruf liest
 * nichts. Zwei Abfragen für alle Vorlagen samt Abschnitten (`CD-7`).*
 *
 * @see \Taxmod\Core\Page\StarterPattern
 */
final class WpStarterPatterns
{
    public const CATEGORY = 'taxmod-vorlagen';

    public function __construct(
        private readonly ModelEditor $editor,
        private readonly RecordRepository $records,
    ) {
    }

    public function register(): void
    {
        if (! function_exists('register_block_pattern')) {
            return;
        }

        $muster = $this->patterns();

        if ($muster === []) {
            return;
        }

        if (function_exists('register_block_pattern_category') && ! \WP_Block_Pattern_Categories_Registry::get_instance()->is_registered(self::CATEGORY)) {
            register_block_pattern_category(self::CATEGORY, ['label' => __('Page templates', 'taxmod')]);
        }

        foreach ($muster as $satzId => $vorlage) {
            $name = 'taxmod/vorlage-' . $satzId;

            if (\WP_Block_Patterns_Registry::get_instance()->is_registered($name)) {
                continue;
            }

            register_block_pattern($name, [
                'title'      => $vorlage->name,
                'content'    => $vorlage->markup(),
                // ⚠️ *Das macht es zum Startmuster: der Editor bietet es beim neuen Beitrag an.*
                'blockTypes' => ['core/post-content'],
                'postTypes'  => ['post'],
                'categories' => [self::CATEGORY],
            ]);
        }
    }

    /**
     * Die Vorlagen des Modells — Satz-Id ⇒ Muster.
     *
     * @return array<int, StarterPattern>
     */
    public function patterns(): array
    {
        $knoten = $this->editor->nodeImplementing(StarterPattern::class);

        if ($knoten === null) {
            return [];
        }

        $felder    = self::byName($this->editor->fieldsOf($knoten->id));
        $abschnitt = $felder[StarterPattern::SECTIONS] ?? null;
        $teilFeld  = $abschnitt === null ? [] : self::byName($this->editor->fieldsOf($abschnitt->toNodeId));
        $saetze    = array_values(array_filter($this->records->ofNode($knoten->id), static fn ($s): bool => $s->recordType === RecordType::User));
        $werte     = $this->records->valuesOfMany(array_map(static fn ($s): int => $s->id, $saetze));

        // *Die Abschnitte aller Vorlagen in einer zweiten Abfrage.*
        $teilIds = [];

        foreach ($werte as $zeilen) {
            foreach ($zeilen as $zeile) {
                if ($abschnitt !== null && $zeile->relationId === $abschnitt->id && $zeile->value->reference !== null) {
                    $teilIds[] = $zeile->value->reference;
                }
            }
        }

        $teilWerte = $teilIds === [] ? [] : $this->records->valuesOfMany($teilIds);
        $muster    = [];

        foreach ($saetze as $satz) {
            $text   = self::texts($werte[$satz->id] ?? [], $felder);
            $teile  = [];
            $zeilen = array_filter($werte[$satz->id] ?? [], static fn ($z): bool => $abschnitt !== null && $z->relationId === $abschnitt->id && $z->value->reference !== null);
            usort($zeilen, static fn ($a, $b): int => $a->position <=> $b->position);

            foreach ($zeilen as $zeile) {
                $t       = self::texts($teilWerte[(int) $zeile->value->reference] ?? [], $teilFeld);
                $teile[] = new PatternSection(
                    $t[StarterPattern::HEADING] ?? '',
                    (int) ($t[StarterPattern::LEVEL] ?? 2),
                    $t[StarterPattern::HINT] ?? '',
                    $t[StarterPattern::PRESET] ?? ''
                );
            }

            $name = trim($text[StarterPattern::NAME] ?? '');

            if ($name !== '') {
                $muster[$satz->id] = new StarterPattern($name, $text[StarterPattern::LEAD] ?? '', $teile);
            }
        }

        return $muster;
    }

    /**
     * @param  list<Relation>        $felder
     * @return array<string, Relation>
     */
    private static function byName(array $felder): array
    {
        $aus = [];

        foreach ($felder as $feld) {
            $aus[$feld->name] ??= $feld;
        }

        return $aus;
    }

    /**
     * Die einfachen Werte eines Satzes nach Feldname — Text oder Zahl als Zeichenkette.
     *
     * @param  list<\Taxmod\Core\Model\RelationRecord> $zeilen
     * @param  array<string, Relation>                 $felder
     * @return array<string, string>
     */
    private static function texts(array $zeilen, array $felder): array
    {
        $name = [];

        foreach ($felder as $feldName => $feld) {
            $name[$feld->id] = $feldName;
        }

        $aus = [];

        foreach ($zeilen as $zeile) {
            if (isset($name[$zeile->relationId]) && ! $zeile->value->isNothing() && ! $zeile->value->isAReference()) {
                $aus[$name[$zeile->relationId]] ??= (string) $zeile->value->rawValue();
            }
        }

        return $aus;
    }
}
