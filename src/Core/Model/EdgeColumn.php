<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Die zwei **Spalten der Kante, die die Maske wie Angaben zeichnet** — und die keine Einstellungen sind.
 *
 * ⚠️ **Sein Wort** ([D-713](../../../docs/NewConcept/90-decision-log.md), [D-714](../../../docs/NewConcept/90-decision-log.md)):
 * *«bei multiplizität war ich mir eigentlich immer eine spalte der kante vorgestellt»* — und
 * *«kanten-einstellungen an der kante betrifft nur die knoten-überschreibung, alles andere ist teil
 * der kante.»* **Multiplizität und `read_only` sind Eigenschaften des Felds** (Modell), keine Attribute
 * einer Klasse; sie stehen in `relations` und werden dort gelesen und geschrieben.
 *
 * ⚠️ *Sie tragen trotzdem einen Namen, weil die Feldzeile sie **neben** den Einstellungen zeichnet und
 * das Formular sie unter diesem Namen schickt — wie `kind`, das denselben Weg schon geht. Der Name ist
 * eine Adresse in der Maske, nicht ein Schlüssel im Einstellungsmodell: {@see SettingKey} kennt beide
 * seit Schritt 2 des Bauplans nicht mehr.*
 *
 * @see docs/modell-anforderungen.md
 */
final class EdgeColumn
{
    public const MULTIPLICITY = 'multiplicity';

    public const READ_ONLY = 'read_only';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::MULTIPLICITY, self::READ_ONLY];
    }

    public static function isOne(string $name): bool
    {
        return in_array($name, self::all(), true);
    }
}
