<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * The items of several proposals of one order, the same item (article number, or the start of its title) once —
 * the longer, uncut title wins. Carried over from tax_mail's LineCollector.
 *
 * @see docs/pakete/import/konzept.md §3.8
 */
final class LineUnion
{
    private const TITLE_HEAD = 40;

    /**
     * @param list<ProposalLine> $lines
     * @return list<ProposalLine>
     */
    public static function of(array $lines): array
    {
        $union = [];

        foreach ($lines as $line) {
            foreach ($union as $index => $known) {
                $sameArticle = $line->articleNumber !== null && $known->articleNumber === $line->articleNumber;
                $sameTitle = $known->articleNumber === $line->articleNumber
                    && mb_substr($known->title, 0, self::TITLE_HEAD) === mb_substr($line->title, 0, self::TITLE_HEAD);

                if ($sameArticle || $sameTitle) {
                    $longer = mb_strlen($line->title) > mb_strlen($known->title) && ! str_ends_with($line->title, '...') && ! str_ends_with($line->title, '…');
                    $union[$index] = new ProposalLine(
                        $longer ? $line->title : $known->title,
                        $line->quantity ?? $known->quantity,
                        $line->unitPrice ?? $known->unitPrice,
                        $known->articleNumber,
                    );

                    continue 2;
                }
            }

            $union[] = $line;
        }

        return $union;
    }
}
