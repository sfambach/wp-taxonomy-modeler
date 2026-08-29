<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Wer diese Datensatzzeile geschrieben hat — ein Mensch, der Autor, oder das Bauen.
 *
 * ⚠️ **[C65](../../../docs/NewConcept/10-domain-core.md) hatte es schon, in seinem eigenen Entwurf:**
 * *«How test data come about is open — a checkbox **«is test data» / «is default value»** would do
 * it.»* **Drei Zustände, und `records.is_test` konnte nur zwei.**
 *
 * ⚠️ *Ich hatte diese Aufzählung am 2026-08-29 schon einmal gebaut und dann **zurückgedreht**, weil
 * ich sein «keine Parallelwelten erzeugen» als «keine Marke am Datensatz» gelesen habe. Er meinte
 * **keinen zweiten Speicher** — keine zweite Tabelle neben `records`. **Die Marke war nie das
 * Problem**, und sie stand seit dem 2026-08-23 im Konzept.*
 *
 * ⚠️ **Was hier *nicht* steht, ist der Unterschied zwischen Vorgabe und Einstellung.** *Der kommt
 * aus dem Block, in dem das Feld steht ([D-519](../../../docs/NewConcept/90-decision-log.md),
 * [D-521](../../../docs/NewConcept/90-decision-log.md)): **derselbe** Autorenwert liest sich unter
 * *Fields* als Vorgabe und unter *Settings* als Einstellung. Ihn hier zweimal zu benennen wäre die
 * Doppelung, die er verhindert hat.*
 *
 * @see docs/NewConcept/02-field-and-setting.md
 */
enum RecordKind: string
{
    /**
     * Ein Mensch hat es eingegeben.
     *
     * ⚠️ *Der Fall ohne Markierung, und der einzige, der heute vorkommt: gemessen tragen **29 von
     * 29** Zeilen `is_test = 0`. Eine Vorgabe, die für alles Bestehende stimmt, braucht keine
     * Wanderung — nur die Umschrift des Namens.*
     */
    case User = 'user';

    /**
     * Der Wert des Autors — unter *Fields* die Vorgabe, unter *Settings* die Einstellung.
     *
     * ⚠️ **Das ist die Zeile, die heute als `default`-Einstellung verkleidet ist.** *Seine
     * Beobachtung: «wir legen eigentlich Records im Modell an, tun aber so, als wären es Defaults.»
     * Gemessen 21 `default`-Zeilen, **14 davon die Exponenten der Präfixe** — `kilo` trägt seine 3
     * genauso, wie eine Adresse ihre Strasse trägt.*
     *
     * ⚠️ *Sie ist es auch, die eine Vorgabe für `Parts List.Name` von den echten Stücklisten trennt:
     * **genau ein neuer Datensatz, und die Marke weist ihn aus.***
     */
    case Default = 'default';

    /**
     * Zum Bauen eingetragen, nicht echt.
     *
     * ⚠️ *Was `is_test` bisher meinte, unverändert — es steuert die **Sichtbarkeit vorn** und sonst
     * nichts ([D-028](../../../docs/NewConcept/90-decision-log.md)). Sein Wort dafür ist «example».*
     */
    case Example = 'example';

    /** Was gilt, wenn niemand etwas gesagt hat. */
    public static function standard(): self
    {
        return self::User;
    }

    /**
     * Aus dem, was in der Spalte steht.
     *
     * ⚠️ *`tryFrom` mit Rückfall statt `from`: eine Spalte kann nach einem Rückrollen einen Wert
     * einer neueren Fassung tragen, und dann ist «gewöhnliche Eingabe» die harmlose Antwort und
     * keine Ausnahme mitten im Zeichnen.*
     */
    public static function fromStorage(?string $stored): self
    {
        return $stored === null || $stored === ''
            ? self::standard()
            : self::tryFrom($stored) ?? self::standard();
    }

    /**
     * Ob diese Zeile in der Vorschau als **Notbehelf** gilt.
     *
     * ⚠️ **Die Reihenfolge der Vorschau bleibt, was sie war** ([C65](../../../docs/NewConcept/10-domain-core.md)):
     * *echte Daten gehen vor. **Wo ein Autorenwert dazwischen einzuordnen ist, ist nicht
     * entschieden** — er zählt darum vorerst wie eine gewöhnliche Eingabe, was genau das ist, was
     * `is_test = 0` bisher tat.*
     */
    public function isFallback(): bool
    {
        return $this === self::Example;
    }
}
