<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Was ein Bedienelement öffnet, bevor es handelt: ein Dialog mit Titel, Inhalt, Bestätigen und Abbruch.
 *
 * ⚠️ **Sein Wort am 2026-09-12** ([D-730](../../../docs/NewConcept/90-decision-log.md)): *«nur ein + bei beiden
 * im baum und es kommt ein dialog hoch, lässt den benutzer den type des knoten wählen und mit ok anlegen /
 * abbruch auch möglich».* Der Inhalt kommt vom Rand (Felder mit Übersetzung), die Form vom Renderer
 * ({@see ControlMarkup::button()}, {@see DialogMarkup}); der Kern kennt keine Wörter.
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class Dialog
{
    public function __construct(
        /** Eindeutig auf der Seite — der Schalter des Dialogs trägt sie als `id`. */
        public readonly string $id,
        public readonly string $title,
        /** Fertiges Markup der Felder; sie stehen im Formular des Bedienelements, das den Dialog öffnet. */
        public readonly string $body,
        public readonly string $confirm,
        public readonly string $cancel,
    ) {
    }
}
