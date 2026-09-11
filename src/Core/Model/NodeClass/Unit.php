<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * **Einheitswert** — eine Einheit als wählbares Blatt: `Gramm`, `Ohm`, `Celsius`, `Euro`.
 *
 * ⚠️ **Sein Wort** (K1b/K2, [D-719](../../../../docs/NewConcept/90-decision-log.md)): *«temperatur ist
 * aus meiner sicht ein typ = einheiten_wert, einstellung: ohne präfix … blätter sind dann vom typ
 * Einheitswert; mit/ohne präfix ist eine eigenschaft, genauso welche präfixe erlaubt sind.»* Und zu
 * den Währungen: *«K3b Einheitswert ohne Präfix.»*
 *
 * *Die Attribute (`mit_praefix`, `erlaubte_praefixe`, `symbol`, `umrechnung`) kommen mit Schritt 4
 * des Bauplans ([`einstellungen-anforderungen.md`](../../../../docs/einstellungen-anforderungen.md) §3.6.5).*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class Unit implements NodeClass
{
    public static function allowedChildClasses(): array
    {
        return [];
    }

    public static function defaultChildClass(): string
    {
        return Category::class;
    }

    public static function classIcon(): string
    {
        return 'editor-expand';
    }

    public static function classKey(): string
    {
        return 'unit';
    }

    public static function allowedMultiplicities(): array
    {
        return [];
    }
}
