<?php declare(strict_types=1);

namespace Taxmod\WordPress\Privacy;

use Taxmod\Core\Exception\FieldDoesNotOpen;
use Taxmod\Core\Model\FrozenState;
use Taxmod\Core\Privacy\FieldCipher;
use Taxmod\WordPress\Import\StoredSecret;
use Taxmod\WordPress\Persistence\FieldSealRows;

/**
 * Where a confidential field is sealed and opened (D-1514): the record repository on every value row, the chronicle on
 * every frozen value. Which fields are confidential says the add-on «Vertraulich» at the edge; the key is entered on the
 * Configuration tab «Datenschlüssel» and stored sealed with the vault (D-951), or stands in wp-config.php as
 * `TAXMOD_DATA_KEY`, which goes first.
 *
 * ```mermaid
 * flowchart LR
 *   W["putValue / Chronik"] -->|sealText / sealState| DB[("enc1:…")]
 *   DB -->|openText / openState| R["Leser: Klartext"]
 *   A["Zusatzfunktion «Vertraulich» an der Kante"] -.-> W
 *   K["Schlüssel: Tresor-Option oder TAXMOD_DATA_KEY"] -.-> W & R
 * ```
 *
 * ⚠️ *Without the key nothing confidential is written (refused, never stored plain) and a sealed value reads as
 * {@see self::LOCKED}; the lock itself is refused as a value, so a form saved without the key cannot overwrite the data.*
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class FieldSeal
{
    /** What a sealed value reads as when it cannot be opened here. */
    public const LOCKED = '🔒';

    public const KEY_CONSTANT = 'TAXMOD_DATA_KEY';

    public const KEY_ID_CONSTANT = 'TAXMOD_DATA_KEY_ID';

    /** The option that holds the key entered on the Configuration tab, sealed with the vault. */
    public const KEY_OPTION = 'taxmod_data_key';

    private const VAULT_CONTEXT = 'taxmod-data-key';

    /** The confidential edges, remembered between requests (a cache of the settings). */
    /** Former keys, still read: an array key id → key text, for a change of key. */
    public const OLD_KEYS_CONSTANT = 'TAXMOD_DATA_KEYS_OLD';

    private static ?FieldCipher $cipher = null;

    private static bool $cipherRead = false;

    /** @var array<int, true>|null */
    private static ?array $confidential = null;

    public static function cipher(): ?FieldCipher
    {
        if (self::$cipherRead) {
            return self::$cipher;
        }

        self::$cipherRead = true;

        // *D-951: a constant in wp-config.php goes first, else the key stored with the vault of the module secrets.*
        $text = self::storedKey()->value();

        if ($text === null || trim($text) === '') {
            return self::$cipher = null;
        }

        try {
            $keys = [self::keyId() => FieldCipher::keyFromText($text)];
        } catch (FieldDoesNotOpen) {
            return self::$cipher = null;
        }

        $old  = defined(self::OLD_KEYS_CONSTANT) ? constant(self::OLD_KEYS_CONSTANT) : [];

        foreach (is_array($old) ? $old : [] as $oldId => $text) {
            $keys[(string) $oldId] ??= FieldCipher::keyFromText((string) $text);
        }

        return self::$cipher = new FieldCipher($keys);
    }

    /** Where the key is kept: the constant first, else the option sealed with the vault (D-951). */
    public static function storedKey(): StoredSecret
    {
        return new StoredSecret(self::KEY_OPTION, self::VAULT_CONTEXT, self::KEY_CONSTANT);
    }

    public static function keyFromConstant(): bool
    {
        return self::storedKey()->slot()->fromConstant();
    }

    /** The id the current key seals with; `k1` unless wp-config.php names another. */
    public static function keyId(): string
    {
        return defined(self::KEY_ID_CONSTANT) ? (string) constant(self::KEY_ID_CONSTANT) : 'k1';
    }

    /**
     * The edges that carry «Vertraulich», kept in an autoloaded option and read again after a setting was written.
     *
     * ⚠️ *Remembered across requests because every save asks it: a query of its own per save raised the statement count of
     * a five-field save (save-record-reads-check, CD-7). The option is a cache of the settings, never their source.*
     *
     * @return array<int, true>
     */
    public static function confidentialRelations(): array
    {
        if (self::$confidential !== null) {
            return self::$confidential;
        }

        // ⚠️ *Only per request, never in an option: an option written inside a transaction that is rolled back stays in WordPress' option cache and would name edges that are no longer confidential — or miss new ones.*
        return self::$confidential = array_fill_keys(array_map(intval(...), FieldSealRows::confidentialRelationIds()), true);
    }

    public static function isConfidential(int $relationId): bool
    {
        return isset(self::confidentialRelations()[$relationId]);
    }

    /** After a setting was written or an act rolled back: the confidential edges are read again. */
    public static function forget(): void
    {
        self::$confidential = null;
    }

    /** For a check that defines the key itself: the key is read again. */
    public static function forgetKey(): void
    {
        self::$cipher     = null;
        self::$cipherRead = false;
    }

    /** The text as it is stored: sealed on a confidential edge, as it is elsewhere. An empty text stays empty. */
    public static function sealText(int $relationId, ?string $text): ?string
    {
        if ($text === null || $text === '' || FieldCipher::isSealed($text) || ! self::isConfidential($relationId)) {
            return $text;
        }

        if ($text === self::LOCKED) {
            throw FieldDoesNotOpen::wrongKey();
        }

        return (self::cipher() ?? throw FieldDoesNotOpen::withoutKey())->seal($text);
    }

    /** The text as a reader sees it. A value that does not open reads as the lock, never as its sealed form. */
    public static function openText(?string $stored): ?string
    {
        if (! FieldCipher::isSealed($stored)) {
            return $stored;
        }

        $cipher = self::cipher();

        if ($cipher === null) {
            return self::LOCKED;
        }

        try {
            return $cipher->open((string) $stored);
        } catch (FieldDoesNotOpen) {
            return self::LOCKED;
        }
    }

    /**
     * A chronicle state with its value sealed, when it names a confidential edge (FrozenState: `relation`, `value`).
     *
     * ⚠️ *Rows written before the address was an edge name it `path` — the same number (Fassung 39, D-667).*
     */
    public static function sealState(?string $state): ?string
    {
        $parsed = FrozenState::parse($state);
        $edge   = $parsed === null ? null : self::edgeOf($parsed);

        if ($parsed === null || $edge === null || ! $parsed->has('value')) {
            return $state;
        }

        $value  = (string) $parsed->field('value');
        $sealed = self::sealText($edge, $value);

        if ($sealed === $value) {
            return $state;
        }

        // *`value` keeps its place, the last one — the only field that may hold a space (FrozenState).*
        $fields          = $parsed->fields();
        $fields['value'] = (string) $sealed;

        return FrozenState::of($fields)->write();
    }

    /** Whether a chronicle state still holds a plain, non-empty value of a confidential edge — without sealing it. */
    public static function stateIsPlain(?string $state): bool
    {
        $parsed = FrozenState::parse($state);
        $edge   = $parsed === null ? null : self::edgeOf($parsed);
        $value  = $parsed?->field('value');

        return $edge !== null && $value !== null && $value !== '' && ! FieldCipher::isSealed($value) && self::isConfidential($edge);
    }

    private static function edgeOf(FrozenState $state): ?int
    {
        $edge = $state->field('relation') ?? $state->field('path');

        return $edge !== null && ctype_digit($edge) ? (int) $edge : null;
    }

    /** A chronicle state as a reader sees it. */
    public static function openState(?string $state): ?string
    {
        if ($state === null || ! str_contains($state, 'value=' . FieldCipher::PREFIX)) {
            return $state;
        }

        $parsed = FrozenState::parse($state);

        if ($parsed === null || ! $parsed->has('value')) {
            return $state;
        }

        $fields          = $parsed->fields();
        $fields['value'] = (string) self::openText((string) $parsed->field('value'));

        return FrozenState::of($fields)->write();
    }
}
