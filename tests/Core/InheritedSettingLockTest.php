<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\PlainRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RenderedSetting;
use Taxmod\Core\Renderer\RendererRegistry;
use Taxmod\Core\Renderer\RenderResult;
use Taxmod\Core\Renderer\SettingsRenderer;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\Surroundings;

/**
 * Geerbt ist gesperrt, unzulässig geerbt ist ein Konflikt, und die Sperre fällt im Konflikt von selbst.
 *
 * ⚠️ **[D-687](../../docs/NewConcept/90-decision-log.md), [D-688](../../docs/NewConcept/90-decision-log.md),
 * [D-689](../../docs/NewConcept/90-decision-log.md) — drei Sätze des Eigentümers an einem Tag.** *Der
 * Rand und die Datenbank sind im Randlauf geprüft (`setting-lock-check`); hier steht, was der Kern
 * allein zusagen kann: die vier Zustände einer Zeile, die Zulässigkeit des Geerbten, und dass die
 * Tafel einen Haken zeichnet, wo sie sperrt.*
 */
final class InheritedSettingLockTest extends TestCase
{
    #[Test]
    public function the_four_states_of_a_row_are_told_apart(): void
    {
        $gesetzt     = new ResolvedSetting('renderer', TypedValue::ofText('compact'), 7, true);
        $geerbt      = new ResolvedSetting('renderer', TypedValue::ofText('compact'), 3, false);
        $niemand     = new ResolvedSetting('renderer', TypedValue::nothing(), 0, false);
        $automatisch = new ResolvedSetting('renderer', TypedValue::ofText('spinner'), 0, false, true, 'compact');

        self::assertFalse($gesetzt->isLocked());
        self::assertTrue($geerbt->isLocked(), 'geerbt von einem Kettenglied ist gesperrt (D-689)');
        self::assertFalse($niemand->isLocked(), '«niemand hat es gesagt» ist frei, nicht gesperrt');
        self::assertFalse($automatisch->isLocked(), 'eine automatische Wahl ist frei (D-688)');
        self::assertTrue($automatisch->automatic);
        self::assertSame('compact', $automatisch->insteadOf);
    }

    #[Test]
    public function an_inherited_renderer_that_is_not_permitted_here_does_not_apply(): void
    {
        $registry = ShippedRenderers::registry();
        $subject  = Node::create(1, 'Zahl', null);

        // `compact` zeichnet Strukturen, nicht Ganzzahlen — an einem `int` ist es nicht zulässig.
        $compact = $registry->byName('compact');
        self::assertFalse($registry->permits($compact, SimpleType::Int));

        $geerbt = ['renderer' => new ResolvedSetting('renderer', TypedValue::ofText('compact'), 3, false)];
        $eigen  = ['renderer' => new ResolvedSetting('renderer', TypedValue::ofText('compact'), 1, true)];

        self::assertSame(
            $registry->defaultFor(SimpleType::Int)->name(),
            $registry->chosenFor($subject, $geerbt, Purpose::Display, SimpleType::Int)?->name(),
            'geerbt und unzulässig: der Typ-Standard gilt (D-687, D-688)'
        );
        self::assertSame(
            'compact',
            $registry->chosenFor($subject, $eigen, Purpose::Display, SimpleType::Int)?->name(),
            'hier gewählt bleibt hier gewählt — Rat, kein Zaun (D-360)'
        );
    }

    #[Test]
    public function permitted_means_what_eligible_means(): void
    {
        $registry = ShippedRenderers::registry();
        $subject  = Node::create(1, 'Zahl', null);

        foreach ($registry->eligibleFor($subject, SimpleType::Int) as $one) {
            self::assertTrue($registry->permits($one, SimpleType::Int), $one->name());
        }

        self::assertTrue($registry->permits($registry->byName('compact'), null), 'ohne einfachen Typ ist zulässig, was Strukturen zeichnet');
        self::assertFalse($registry->permits(new PlainRenderer(), null));
    }

    #[Test]
    public function the_panel_locks_an_inherited_row_and_offers_the_override(): void
    {
        $tafel   = new SettingsRenderer();
        $subject = Node::create(9, 'Kind', null);

        $zeile = static fn (ResolvedSetting $angabe): RenderedSetting => (new RenderedSetting(
            $angabe->key,
            SettingShape::Words,
            SimpleType::Text,
            $angabe,
            RenderResult::of('<input name="v">'),
            PlainRenderer::NAME,
            SimpleType::Text
        ))->withOrigin('Integer', 'taxmod_value_override[42]');

        $worte = [
            new Control('word:inherited_from', '', 'geerbt von %s'),
            new Control('word:override', '', 'überschreiben'),
            new Control('word:automatic', '', 'automatisch gewählt — %s ist hier nicht zulässig'),
        ];

        $markup = static fn (ResolvedSetting $angabe) => $tafel->render(
            $subject,
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::nothing(),
                level: Level::Admin,
                surroundings: new Surroundings(actions: $worte, configured: ['label_role' => $zeile($angabe)], formId: 'f'),
            )
        )->markup;

        $geerbt = $markup(new ResolvedSetting('label_role', TypedValue::ofText('symbol'), 3, false));
        self::assertStringContainsString('taxmod-setting-locked', $geerbt);
        self::assertStringContainsString('name="taxmod_value_override[42]"', $geerbt);
        self::assertStringNotContainsString('value="1" checked', $geerbt, 'der Haken ist nicht gesetzt — das sagt der Mensch');
        self::assertStringContainsString('geerbt von Integer', $geerbt);
        self::assertStringNotContainsString('↑', $geerbt, 'in Worten, nicht als Pfeil (D-689)');

        $eigen = $markup(new ResolvedSetting('label_role', TypedValue::ofText('symbol'), 9, true));
        self::assertStringNotContainsString('taxmod-setting-locked', $eigen);
        self::assertStringNotContainsString('taxmod_value_override', $eigen);

        $automatisch = $markup(new ResolvedSetting('label_role', TypedValue::ofText('form'), 0, false, true, 'symbol'));
        self::assertStringContainsString('taxmod-setting-automatic', $automatisch);
        self::assertStringNotContainsString('taxmod-setting-locked', $automatisch, 'im Konflikt fällt die Sperre (D-689)');
        self::assertStringContainsString('value="1" checked', $automatisch, 'und der Haken ist vom System gesetzt');
        self::assertStringContainsString('automatisch gewählt — symbol ist hier nicht zulässig', $automatisch);
    }
}
