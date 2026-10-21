<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Service\ReferenceCurrency;
use Taxmod\WordPress\Currency\EcbRates;

final class EcbRatesParseTest extends TestCase
{
    public function testColumnsAreFoundByTheirHeader(): void
    {
        $csv = "KEY,FREQ,CURRENCY,CURRENCY_DENOM,EXR_TYPE,EXR_SUFFIX,TIME_PERIOD,OBS_VALUE,OBS_STATUS\r\n"
            . "EXR.D.USD.EUR.SP00.A,D,USD,EUR,SP00,A,2026-09-18,1.1634,A\r\n"
            . "EXR.D.USD.EUR.SP00.A,D,USD,EUR,SP00,A,2026-09-21,NaN,M\r\n";

        self::assertSame([['currency' => 'USD', 'day' => '2026-09-18', 'quote' => '1.1634']], EcbRates::parse($csv));
        self::assertSame([], EcbRates::parse("no,header\n1,2"));
    }

    public function testTheReferenceAmountIsTheAmountDividedByTheQuote(): void
    {
        self::assertSame('19.16', ReferenceCurrency::converted('22.29', '1.1634'));
        self::assertNull(ReferenceCurrency::converted('22.29', '0'));
    }
}
