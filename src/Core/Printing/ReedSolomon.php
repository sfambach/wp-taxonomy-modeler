<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * Reed-Solomon error correction over GF(256) with the QR polynomial 0x11D.
 *
 * @see docs/NewConcept/90-decision-log.md D-1032
 */
final class ReedSolomon
{
    /**
     * @return list<int> coefficients of the generator polynomial, highest power omitted
     */
    public static function generator(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::multiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::multiply($root, 0x02);
        }
        return $result;
    }

    /**
     * @param list<int> $data
     * @param list<int> $generator
     * @return list<int>
     */
    public static function remainder(array $data, array $generator): array
    {
        $result = array_fill(0, count($generator), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($generator as $i => $coefficient) {
                $result[$i] ^= self::multiply($coefficient, $factor);
            }
        }
        return $result;
    }

    public static function multiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }
}
