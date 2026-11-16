<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

/**
 * Die Änderungsnummer, die eine geschriebene Zeile trägt — auch bevor der Akt seine Nummer hat.
 *
 * ⚠️ **Die Nummer eines Akts ist die Id seiner ersten Journalzeile** ([D-348](../../../docs/NewConcept/90-decision-log.md)), und
 * die entsteht erst *nach* dem Schreiben. *Bis dahin trägt jede angelegte und jede abgelegte Zeile eine Platzhalter-Nummer; die erste
 * Journalzeile setzt sie in einem Schritt je berührter Tabelle auf die echte ({@see self::settle()}). Kein zweiter Zähler, keine
 * zweite Tabelle ([D-1410](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * ```mermaid
 * flowchart LR
 *   W["Zeile schreiben"] --> P["Platzhalter"]
 *   P --> J["erste Journalzeile: Nummer N"]
 *   J --> S["Platzhalter → N"]
 *   S --> R["Restore::act(N)"]
 * ```
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class ActStamp
{
    /** Platzhalter liegen oberhalb dieser Grenze — eine echte Journal-Id erreicht sie nie. */
    public const PLACEHOLDER_FLOOR = 9_000_000_000_000_000_000;

    /** Die Nummer des offenen Akts, sobald seine erste Journalzeile steht. */
    private static ?int $known = null;

    private static int $placeholder = 0;

    /** @var array<string, string> Tabelle => Spalte, die den Platzhalter trägt */
    private static array $pending = [];

    /** Die Nummer für eine Zeile, die gerade in `$table` geschrieben wird; `$column` ist die Spalte, die sie trägt. */
    public static function for(string $table, string $column): int
    {
        if (self::$known !== null) {
            return self::$known;
        }

        if (self::$placeholder === 0) {
            self::$placeholder = self::PLACEHOLDER_FLOOR + random_int(1, 200_000_000_000_000_000);
        }

        self::$pending[$table] = $column;

        return self::$placeholder;
    }

    /**
     * Die Platzhalter dieses Akts bekommen ihre echte Nummer.
     *
     * ⚠️ *`$stillOpen`: in einer Klammer schreiben die nächsten Zeilen gleich mit der echten Nummer; ohne Klammer ist jede
     * Journalzeile ihr eigener Akt, und was danach geschrieben wird, gehört schon zum nächsten.*
     */
    public static function settle(int $group, bool $stillOpen): void
    {
        global $wpdb;

        foreach (self::$pending as $table => $column) {
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Schema::table($table) . " SET {$column} = %d WHERE {$column} = %d",
                $group,
                self::$placeholder
            ));
        }

        self::$pending     = [];
        self::$placeholder = 0;
        self::$known       = $stillOpen ? $group : null;
    }

    /** Die äusserste Klammer ist zu: der nächste Akt bekommt eine neue Nummer. */
    public static function close(): void
    {
        self::$known = null;
    }

    /** Ob seit der letzten Journalzeile Zeilen ohne Nummer geschrieben wurden. */
    public static function hasPending(): bool
    {
        return self::$pending !== [];
    }
}
