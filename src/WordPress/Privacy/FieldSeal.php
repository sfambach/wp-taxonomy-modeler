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

    /** Former keys, still read: an array key id → key text, for a change of key. */
    public const OLD_KEYS_CONSTANT = 'TAXMOD_DATA_KEYS_OLD';

    /** The id the key entered on the tab seals with (D-1562); `k1` until the first change. */
    public const KEY_ID_OPTION = 'taxmod_data_key_id';

    /** The former keys of the tab, key id → key text, as one JSON sealed with the vault (D-951, D-1562). */
    public const OLD_KEYS_OPTION = 'taxmod_data_keys_old';

    /** Per former key: when it was retired and since when nothing is sealed with it any more — no secret, plain. */
    public const RETIRED_OPTION = 'taxmod_data_keys_retired';

    /**
     * D-1562: a former key may be deleted by hand at the earliest this long after nothing live was sealed with it — the
     * nightly copies (D-1407, seven kept) still hold its values.
     */
    public const DROP_WAIT = 8 * 86400;

    private const OLD_KEYS_CONTEXT = 'taxmod-data-keys-old';

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

        foreach (self::oldKeys() as $oldId => $oldText) {
            try {
                $keys[$oldId] ??= FieldCipher::keyFromText($oldText);
            } catch (FieldDoesNotOpen) {
                // *A damaged former key opens nothing; the values it sealed read as the lock, the current key still works.*
            }
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

    /** The id the current key seals with: wp-config.php first, else the tab's (D-1562), else `k1`. */
    public static function keyId(): string
    {
        if (defined(self::KEY_ID_CONSTANT)) {
            return (string) constant(self::KEY_ID_CONSTANT);
        }

        $id = get_option(self::KEY_ID_OPTION, 'k1');

        return is_string($id) && preg_match('/^[a-z0-9]{1,16}$/', $id) === 1 ? $id : 'k1';
    }

    /**
     * The former keys, still read: those of wp-config.php, then those the tab retired.
     *
     * @return array<string, string> key id → key text
     */
    public static function oldKeys(): array
    {
        $keys     = [];
        $constant = defined(self::OLD_KEYS_CONSTANT) ? constant(self::OLD_KEYS_CONSTANT) : [];

        foreach (is_array($constant) ? $constant : [] as $id => $text) {
            $keys[(string) $id] = (string) $text;
        }

        return $keys + self::storedOldKeys();
    }

    /** @return array<string, string> key id → key text, the keys the tab retired */
    public static function storedOldKeys(): array
    {
        $json = self::oldKeysStore()->value();
        $keys = is_string($json) ? json_decode($json, true) : null;

        return is_array($keys) ? array_map(strval(...), array_filter($keys, is_string(...))) : [];
    }

    /**
     * Per former key of the tab: when it was retired, since when nothing is sealed with it (null while something is).
     *
     * @return array<string, array{retired: int, clean_since: ?int}>
     */
    public static function retired(): array
    {
        $state = get_option(self::RETIRED_OPTION, []);
        $aus   = [];

        foreach (is_array($state) ? $state : [] as $id => $row) {
            $aus[(string) $id] = [
                'retired'     => (int) ($row['retired'] ?? 0),
                'clean_since' => isset($row['clean_since']) ? (int) $row['clean_since'] : null,
            ];
        }

        return $aus;
    }

    /**
     * D-1562: the key in use becomes a former key, still read; the new one seals from now on, under the next free id.
     * Capability, nonce and the owner's ticks are the tab's.
     *
     * @return string the new key id
     */
    public static function changeKey(#[\SensitiveParameter] string $newText): string
    {
        $current = self::storedKey()->value();

        if (self::keyFromConstant() || $current === null || self::cipher() === null) {
            throw FieldDoesNotOpen::withoutKey();
        }

        if (FieldCipher::keyFromText($newText) === FieldCipher::keyFromText($current)) {
            throw FieldDoesNotOpen::sameKey();
        }

        $oldId = self::keyId();
        $used  = [$oldId => true] + array_fill_keys(array_keys(self::oldKeys()), true);
        $n     = 1;

        while (isset($used['k' . $n])) {
            ++$n;
        }

        $newId = 'k' . $n;
        $old   = self::storedOldKeys();

        $old[$oldId] = $current;
        self::oldKeysStore()->save((string) json_encode($old));

        $state         = self::retired();
        $state[$oldId] = ['retired' => time(), 'clean_since' => null];
        update_option(self::RETIRED_OPTION, $state, false);

        update_option(self::KEY_ID_OPTION, $newId, true);
        self::storedKey()->save($newText);
        self::forgetKey();

        return $newId;
    }

    /** From when a former key may be deleted by hand; null while values are still sealed with it. */
    public static function dropAllowedFrom(string $keyId): ?int
    {
        $since = self::retired()[$keyId]['clean_since'] ?? null;

        return $since === null ? null : $since + self::DROP_WAIT;
    }

    /** D-1562: by hand only, never before {@see self::dropAllowedFrom()}. Answers whether the key was deleted. */
    public static function dropOldKey(string $keyId, int $now): bool
    {
        $from = self::dropAllowedFrom($keyId);
        $old  = self::storedOldKeys();

        if ($from === null || $now < $from || ! isset($old[$keyId])) {
            return false;
        }

        unset($old[$keyId]);
        self::oldKeysStore()->save($old === [] ? '{}' : (string) json_encode($old));

        $state = self::retired();
        unset($state[$keyId]);
        update_option(self::RETIRED_OPTION, $state, false);
        self::forgetKey();

        return true;
    }

    /**
     * What the job counted per former key: «clean since» starts when nothing is sealed with it, and stops again if
     * something is (an old backup restored).
     *
     * @param array<string, int> $sealedWith key id → values still sealed with it
     */
    public static function noteSealedWith(array $sealedWith, int $now): void
    {
        $state   = self::retired();
        $changed = false;

        foreach ($state as $id => $row) {
            $left  = $sealedWith[$id] ?? 0;
            $since = $left > 0 ? null : ($row['clean_since'] ?? $now);

            if ($since !== $row['clean_since']) {
                $state[$id]['clean_since'] = $since;
                $changed                   = true;
            }
        }

        if ($changed) {
            update_option(self::RETIRED_OPTION, $state, false);
        }
    }

    private static function oldKeysStore(): StoredSecret
    {
        return new StoredSecret(self::OLD_KEYS_OPTION, self::OLD_KEYS_CONTEXT);
    }

    /**
     * The edges that carry «Vertraulich», read once per request and again after a setting was written.
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

    /**
     * Reads the key and the confidential edges now, before a stream of rows is opened (`Query::each`).
     *
     * ⚠️ *Inside an unbuffered stream the connection takes no other statement: reading the key option there fails, the key
     * would be remembered as missing for the whole request and every value would read as {@see self::LOCKED} (live
     * 21.11.2026, lists in the admin while the edit form read plain).*
     */
    public static function ready(): void
    {
        self::cipher();
        self::confidentialRelations();
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

    /** A chronicle state whose value was sealed with a former key, sealed again with the current one (D-1562). */
    public static function resealState(?string $state): ?string
    {
        $parsed = FrozenState::parse($state);
        $value  = $parsed?->field('value');
        $cipher = self::cipher();

        if ($parsed === null || $value === null || $cipher === null || ! $cipher->needsResealing($value)) {
            return $state;
        }

        $fields          = $parsed->fields();
        $fields['value'] = $cipher->reseal($value);

        return FrozenState::of($fields)->write();
    }

    /** Whether a chronicle state holds a value sealed with a former key. */
    public static function stateNeedsResealing(?string $state): bool
    {
        $value = FrozenState::parse($state)?->field('value');

        return $value !== null && self::cipher()?->needsResealing($value) === true;
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
