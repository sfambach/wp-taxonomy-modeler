<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Addon\ChosenAddon;
use Taxmod\Core\Addon\RangeValidator;
use Taxmod\Core\Addon\ShapeValidator;
use Taxmod\Core\Addon\ShippedAddons;
use Taxmod\Core\Addon\AddonSite;

/**
 * Die Zusatzfunktionen (D-845) — die Prüfungen beim Speichern, und die Grenzen, die sie **nicht** überschreiten.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class AddonTest extends TestCase
{
    // ------------------------------------------------------------ der Bereich

    #[Test]
    public function a_number_inside_its_bounds_is_not_complained_about(): void
    {
        $bounds = [
            'min' => TypedValue::ofInt(1),
            'max' => TypedValue::ofInt(10),
        ];

        self::assertSame([], (new RangeValidator())->check(TypedValue::ofInt(5), SimpleType::Int, $bounds));
    }

    #[Test]
    public function below_the_minimum_and_above_the_maximum_each_name_the_bound(): void
    {
        $validator = new RangeValidator();

        $bounds = [
            'min' => TypedValue::ofInt(3),
            'max' => TypedValue::ofInt(7),
        ];

        $tooSmall = $validator->check(TypedValue::ofInt(2), SimpleType::Int, $bounds);
        $tooBig   = $validator->check(TypedValue::ofInt(9), SimpleType::Int, $bounds);

        self::assertCount(1, $tooSmall);
        self::assertSame('below_min', $tooSmall[0]->key);
        self::assertSame('3', $tooSmall[0]->values['min']);

        self::assertCount(1, $tooBig);
        self::assertSame('above_max', $tooBig[0]->key);
        self::assertSame('7', $tooBig[0]->values['max']);
    }

    /**
     * ⚠️ **Der Platzhalter ist benannt, und das ist [R36b](../../docs/NewConcept/30-renderer.md#r36b--a-validator-message-is-a-label-and-there-is-one-per-validator)
     * wörtlich:** *«Platzhalter überleben, und das benannte Format bleibt Pflicht — ein Satz, der aus
     * Bruchstücken zusammengesetzt wird, ist in eine Sprache mit anderer Wortstellung nicht
     * übersetzbar.»* Eine Liste nach Position wäre genau dieser Fehler.
     */
    #[Test]
    public function a_complaint_carries_a_key_and_named_placeholders_and_never_a_sentence(): void
    {
        $complaints = (new RangeValidator())->check(
            TypedValue::ofInt(0),
            SimpleType::Int,
            ['min' => TypedValue::ofInt(5)]
        );

        self::assertSame('range', $complaints[0]->validator);
        self::assertSame(['min' => '5'], $complaints[0]->values);
        self::assertArrayHasKey('min', $complaints[0]->values);
    }

    /**
     * ⚠️ **Eine Dezimalzahl wird nie zu `float`** ([D-057](../../docs/NewConcept/90-decision-log.md)).
     * *`1.10` gegen `1.9` ist der Fall, der einen Textvergleich verrät: als Text ist `1.10 > 1.9`,
     * als Zahl nicht.*
     */
    #[Test]
    public function decimals_compare_by_digits_and_not_as_text(): void
    {
        $bounds = ['max' => TypedValue::ofDecimal('1.9')];

        self::assertSame([], (new RangeValidator())->check(TypedValue::ofDecimal('1.10'), SimpleType::Decimal, $bounds));

        $over = (new RangeValidator())->check(TypedValue::ofDecimal('2.00'), SimpleType::Decimal, $bounds);

        self::assertCount(1, $over);
    }

    /** ⚠️ *Auch bei Zeitangaben — die Form `YYYY-MM-DD …` ordnet alphabetisch wie zeitlich.* */
    #[Test]
    public function a_date_outside_its_window_is_complained_about(): void
    {
        $bounds = [
            'min' => TypedValue::ofDate('2026-01-01 00:00:00'),
            'max' => TypedValue::ofDate('2026-12-31 23:59:59'),
        ];

        $validator = new RangeValidator();

        self::assertSame([], $validator->check(TypedValue::ofDate('2026-08-31 12:00:00'), SimpleType::DateTime, $bounds));
        self::assertCount(1, $validator->check(TypedValue::ofDate('2025-12-31 23:00:00'), SimpleType::DateTime, $bounds));
    }

    /**
     * ⚠️ **Ein fehlender Wert ist keine Beanstandung** — *ob einer da sein **muss**, sagt die
     * Multiplizität ([D-549](../../docs/NewConcept/90-decision-log.md)), und sie sagt es allein. Ein
     * Validator, der «leer» beanstandet, wäre die zweite Heimat dieser Regel.*
     */
    #[Test]
    public function nothing_is_never_complained_about(): void
    {
        $bounds = ['min' => TypedValue::ofInt(3)];

        self::assertSame([], (new RangeValidator())->check(TypedValue::nothing(), SimpleType::Int, $bounds));
    }

    /** ⚠️ *Ohne Grenzen gibt es nichts zu fragen — und dann wird auch nichts erfunden.* */
    #[Test]
    public function without_bounds_there_is_nothing_to_ask(): void
    {
        self::assertSame([], (new RangeValidator())->check(TypedValue::ofInt(999999), SimpleType::Int, []));
    }

    // ------------------------------------------------------------ die Form

    /**
     * ⚠️ **Der Fall, der den ersten Entwurf verraten hat.** *Er prüfte «passt irgendeine der drei
     * Formen» — und `1.2.3` ist eine gültige Fassungsnummer, also kam es als **E-Mail** durch. Ein
     * Validator, der drei Regeln kennt und nicht weiss, welche gilt, ist keine Prüfung.*
     */
    #[Test]
    public function a_version_number_is_not_an_email_address(): void
    {
        $complaints = (new ShapeValidator())->check(TypedValue::ofText('1.2.3'), SimpleType::Email, []);

        self::assertCount(1, $complaints);
        self::assertSame('wrong_shape', $complaints[0]->key);
    }

    #[Test]
    public function the_three_loose_types_get_their_shape_checked(): void
    {
        $validator = new ShapeValidator();

        // Gemessen am 2026-08-31: der Typ selbst nimmt jeden dieser Werte an.
        foreach ([
            ['kein-at', SimpleType::Email],
            ['@b.example', SimpleType::Email],
            ['rot', SimpleType::Color],
            ['#f00', SimpleType::Color],
            ['eins', SimpleType::Version],
            ['1.2', SimpleType::Version],
        ] as [$characters, $type]) {
            self::assertCount(
                1,
                $validator->check(TypedValue::ofText($characters), $type, []),
                'sollte beanstandet werden: ' . $characters
            );
        }

        foreach ([
            ['a@b.example', SimpleType::Email],
            ['#ff0000', SimpleType::Color],
            ['1.2.3', SimpleType::Version],
        ] as [$characters, $type]) {
            self::assertSame(
                [],
                $validator->check(TypedValue::ofText($characters), $type, []),
                'sollte durchgehen: ' . $characters
            );
        }
    }

    /**
     * ⚠️ **Die fünf Typen, die sich selbst verteidigen, bekommen keine zweite Regel.** *Gemessen: `int`,
     * `decimal`, `char`, `bool` und `datetime` verweigern einen unmöglichen Wert schon beim Lesen. Ein
     * Formvalidator dort wäre eine zweite Heimat — und die zweite läuft irgendwann anders.*
     */
    #[Test]
    public function a_type_that_defends_itself_gets_no_shape_rule(): void
    {
        $validator = new ShapeValidator();

        foreach ([SimpleType::Int, SimpleType::Decimal, SimpleType::Char, SimpleType::Bool, SimpleType::DateTime] as $type) {
            self::assertNotContains($type, $validator->handles(), $type->value . ' braucht keinen Formvalidator');
            self::assertSame([], $validator->check(TypedValue::ofText('irgendwas'), $type, []));
        }
    }

    // ------------------------------------------------------------ die Registratur

    #[Test]
    public function the_shipped_set_is_preset_add_several_and_the_two_checks(): void
    {
        self::assertSame(['preset', 'pick_rows', 'range', 'shape'], ShippedAddons::registry()->names());
    }

    /** ⚠️ *Jede Funktion sagt selbst, wo sie hängen darf (D-845): «Mehrere hinzufügen» nur an einer mehrfachen Kante.* */
    #[Test]
    public function each_addon_says_where_it_may_be_chosen(): void
    {
        $namen = static fn (array $orte): array => array_map(static fn ($a): string => $a->name(), ShippedAddons::registry()->forSites($orte));

        self::assertSame(['preset', 'range', 'shape'], $namen([AddonSite::Node]));
        self::assertSame(['range', 'shape'], $namen([AddonSite::Edge]));
        self::assertSame(['preset', 'pick_rows', 'range', 'shape'], $namen([AddonSite::Edge, AddonSite::ManyEdge, AddonSite::RecordEdge]));
    }

    /**
     * ⚠️ **Alle, nicht der erste** ([D-158](../../docs/NewConcept/90-decision-log.md)): *«ein Attribut
     * kann mehrere Validatoren tragen … also gibt es drei Meldungen statt einer».* Beim ersten Treffer
     * aufzuhören hiesse, einen Menschen dreimal speichern zu lassen, um drei Dinge zu erfahren.
     */
    #[Test]
    public function both_bounds_are_reported_together(): void
    {
        // Ein widersprüchliches Fenster: darunter **und** darüber.
        $complaints = ShippedAddons::registry()->complaintsAbout(
            TypedValue::ofInt(5),
            SimpleType::Int,
            [new ChosenAddon(RangeValidator::class, 1, [], true)],
            [
                'min' => TypedValue::ofInt(8),
                'max' => TypedValue::ofInt(2),
            ]
        );

        self::assertCount(2, $complaints);
        self::assertSame(['below_min', 'above_max'], array_map(static fn ($c): string => $c->key, $complaints));
    }

    /**
     * ⚠️ *Eine gewählte Funktion, deren Klasse niemand mehr kennt, wird **übersprungen**: ein verschwundenes Plugin darf nicht das
     * Speichern einer ganzen Seite verhindern. Und eine, die nicht prüft (die Vorbelegung), beanstandet nichts.*
     */
    #[Test]
    public function an_unknown_or_non_checking_addon_is_skipped_rather_than_thrown(): void
    {
        $complaints = ShippedAddons::registry()->complaintsAbout(
            TypedValue::ofInt(1),
            SimpleType::Int,
            [new ChosenAddon('Gibt\Es\Nicht', 1, [], true), new ChosenAddon(\Taxmod\Core\Addon\PresetAddon::class, 2, [], true)],
            []
        );

        self::assertSame([], $complaints);
    }

    /** ⚠️ *Wer sie aber **absichtlich** beim Namen holt, bekommt einen Fehler — `CD-10`, wie beim Konverter.* */
    #[Test]
    public function asking_for_an_unknown_addon_by_name_refuses(): void
    {
        $this->expectException(NotAPossibleTarget::class);

        ShippedAddons::registry()->byName('gibt-es-nicht');
    }

    /**
     * ⚠️ *Eine Prüfung, die den Typ nicht kann, urteilt nicht — sonst beanstandete der
     * Bereichsvalidator jeden Text, weil er ihn nicht vergleichen kann.*
     */
    #[Test]
    public function a_validator_that_cannot_judge_this_type_stays_silent(): void
    {
        $complaints = ShippedAddons::registry()->complaintsAbout(
            TypedValue::ofText('hallo'),
            SimpleType::Text,
            [new ChosenAddon(RangeValidator::class, 1, [], true)],
            ['min' => TypedValue::ofInt(3)]
        );

        self::assertSame([], $complaints);
    }

    /** ⚠️ *Das Vergleichspaar für sich (D-844): «filter» behält nur Treffer, «sort» behält alle und nennt die Treffer.* */
    #[Test]
    public function a_preset_pair_filters_or_sorts_and_says_nothing_without_a_value(): void
    {
        $paar    = new \Taxmod\Core\Addon\PresetAddon();
        $angebot = [1, 2];
        $werte   = [1 => [20 => [TypedValue::ofText('SMD 0603')]], 2 => [20 => [TypedValue::ofText('THT')]]];
        $gleich  = static fn (TypedValue $wert, TypedValue $gesucht): bool => str_contains((string) $wert->text, (string) $gesucht->text);
        $nichts  = static fn (TypedValue $gesucht): ?string => null;
        $felder  = static fn (string $modus): array => ['source_field' => TypedValue::ofRelationReference(10), 'offered_field' => TypedValue::ofRelationReference(20), 'mode' => TypedValue::ofText($modus)];

        $sortiert = $paar->judge($angebot, $werte, [10 => TypedValue::ofText('SMD')], $felder('sort'), $gleich, $nichts);
        self::assertNull($sortiert->keep);
        self::assertSame([1 => true], $sortiert->first);

        $gefiltert = $paar->judge($angebot, $werte, [10 => TypedValue::ofText('SMD')], $felder('sort'), $gleich, static fn (TypedValue $g): ?string => 'filter');
        self::assertSame([1 => true], $gefiltert->keep, 'der Wert sagt «filter» und schlägt das Paar');

        self::assertNull($paar->judge($angebot, $werte, [], $felder('filter'), $gleich, $nichts)->keep, 'ohne Wert kein Urteil');
    }

    /** ⚠️ *«danach bitte Datum Restriktionen» (D-757): min und max am Datum, abgelegt als Text, geprüft als Datum.* */
    #[Test]
    public function a_date_below_min_or_above_max_is_complained_about(): void
    {
        $bounds = [
            'min' => TypedValue::ofText('2020-01-01 00:00:00'),
            'max' => TypedValue::ofText('2020-12-31 00:00:00'),
        ];

        self::assertSame([], (new RangeValidator())->check(TypedValue::ofDate('2020-06-15 12:00:00'), SimpleType::DateTime, $bounds));
        self::assertSame('below_min', (new RangeValidator())->check(TypedValue::ofDate('2019-12-31 23:59:00'), SimpleType::DateTime, $bounds)[0]->key);
        self::assertSame('above_max', (new RangeValidator())->check(TypedValue::ofDate('2021-01-01 00:00:00'), SimpleType::DateTime, $bounds)[0]->key);
    }
}
