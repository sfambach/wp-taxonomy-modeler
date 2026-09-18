<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Die Körper der Satzauswahl-Dialoge einer Seite — jeder gleiche Körper einmal, als Vorlage; die Felder tragen nur einen Platzhalter.
 *
 * ⚠️ **Sein Wort** zum Vorschlag, den Dialog je Ziel nur einmal zu zeichnen: *«3. ja»* ([D-866](../../../docs/NewConcept/90-decision-log.md)).
 * *Gemessen am 2026-09-19 an «Mikrocontroller»: «Nachfolger → Models» stand zweimal auf der Seite, 120 und 68 KB, dazu gleiche
 * Einheiten-Dialoge — die Seite lag mit 1071 KB über der Decke von 1 MB ([D-818](../../../docs/NewConcept/90-decision-log.md)). Dieselbe
 * Bauart wie der eine Auswahlbaum der Seite ([D-815](../../../docs/NewConcept/90-decision-log.md)); und wie dort braucht das Einsetzen
 * Skript ([D-821](../../../docs/NewConcept/90-decision-log.md)). Die aktuelle Wahl steht immer im Platzhalter, also verliert ein Speichern
 * ohne Skript nichts.*
 *
 * ```mermaid
 * flowchart LR
 *   F1["Feld A · Platzhalter"] -->|"data-taxmod-body"| V["eine Vorlage je Körper"]
 *   F2["Feld B · Platzhalter"] --> V
 *   V -->|"beim Öffnen geklont, Name und Formular gesetzt"| D["Dialog von A oder B"]
 * ```
 *
 * ⚠️ *Ein Exemplar für die ganze Seite: die Kopien der Zeichnung ({@see \Taxmod\Core\Service\Rendering::withSharedRecordBodies()}) teilen es.*
 */
final class SharedBodies
{
    /** @var array<string, string> Schlüssel ⇒ Körper */
    private array $koerper = [];

    /** Den Körper ablegen und seinen Schlüssel geben — gleicher Körper, gleicher Schlüssel. */
    public function share(string $koerper): string
    {
        $schluessel = 'b' . substr(md5($koerper), 0, 12);
        $this->koerper[$schluessel] ??= $koerper;

        return $schluessel;
    }

    /** Die Vorlagen, einmal je Körper — ausserhalb jedes Formulars auszugeben, denn ihre Knöpfe haben noch keinen Namen. */
    public function markup(): string
    {
        $html = '';

        foreach ($this->koerper as $schluessel => $koerper) {
            $html .= '<template class="taxmod-shared-body" data-taxmod-body="' . $schluessel . '">' . $koerper . '</template>';
        }

        return $html;
    }
}
