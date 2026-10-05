<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Setting;

/**
 * Ein **Einstellungsobjekt** — der Wert eines komplexen Attributs: ein Renderer, eine Umrechnung.
 *
 * ⚠️ **Sein Wort** ([D-712](../../../../docs/NewConcept/90-decision-log.md), Frage 2a): *ein komplexer
 * Wert ist ein Objekt seiner Klasse mit eigenen Attributwerten; zwei `Compact` in einer Liste mit
 * verschiedener `orientation` können ihre Werte nicht flach am Knoten tragen.* Und der Name:
 * *«settings_object ist damit gesetzt.»*
 *
 * ⚠️ **Es hat keinen Träger** (Anforderung 4.3.2): *wem es gehört, sagt die {@see SettingsValue}, die
 * es als Wert nennt. Ein Objekt, das keine Zeile nennt, darf nicht bestehen bleiben (4.3.3).*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class SettingsObject
{
    /** @param class-string $klasse Die Wertklasse — `CompactRenderer`, `Umrechnung`, … */
    private function __construct(
        public readonly int $id,
        public readonly int $version,
        public readonly string $klasse,
    ) {
    }

    /** Frisch, ohne Id — der Speicher vergibt sie ({@see withAssignedId()}). */
    public static function create(string $klasse): self
    {
        return new self(0, 1, $klasse);
    }

    public static function fromStorage(int $id, int $version, string $klasse): self
    {
        return new self($id, $version, $klasse);
    }

    public function withAssignedId(int $id): self
    {
        return $id === $this->id ? $this : new self($id, $this->version, $this->klasse);
    }
}
