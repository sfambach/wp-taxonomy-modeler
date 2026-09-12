<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * Markiert eine Eigenschaft einer Klasse als **Attribut im Sinn des Einstellungsmodells** — das,
 * was der Vertrag per Reflection liest (Anforderung 2.4, 3.1).
 *
 * ⚠️ **Sein Wort** ([D-712](../../../../docs/NewConcept/90-decision-log.md)): *«hierzu müsste man nur
 * die klasse per reflection parsen: jeder simple typ bekommt eine simple einstellung, jede klasse
 * schachtelt tiefer.»* Und zur Liste: *«list of Renderer sagt PHP nicht von selbst — das steht in
 * einer Angabe an der Eigenschaft, die die Reflection mitliest.»* **Das ist diese Angabe.**
 *
 * ```php
 * // CONTRACT
 * #[Attribut]                                   public int    $display_size = 20;
 * #[Attribut(listOf: Renderer::class)]          public array  $renderer = [];
 * #[Attribut(refersTo: Constant::class)]        public array  $erlaubte_praefixe = [];   // mit listOf: 'node'
 * #[Attribut(decimal: true)]                    public ?string $factor = null;
 * ```
 *
 * *Der Typ kommt aus der Eigenschaft (`bool`, `int`, `string`, eine Enum-Klasse, eine Wertklasse);
 * die Angabe sagt nur, was PHP nicht sagt: das Listenelement, das Verweisziel, dass ein `string`
 * eine Dezimalzahl ist, und ob zwei Einträge derselben Klasse in einer Liste erlaubt sind (3.4.2).*
 *
 * @see docs/einstellungen-anforderungen.md
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Attribut
{
    /**
     * @param class-string|'node'|null $listOf          Das Element einer Liste: eine Wertklasse, oder
     *                                                   `'node'` für eine Liste von Knotenverweisen.
     *                                                   `null` heisst: keine Liste.
     * @param class-string<NodeClass>|null $refersTo    Welche Knotenklasse ein Verweis (auch je
     *                                                   Listeneintrag) als Ziel haben darf (3.1.4).
     * @param bool $decimal                              Ein `string`, der eine Dezimalzahl ist (D-057:
     *                                                   nie Fliesskomma).
     * @param bool $allowDuplicates                      Ob eine Liste zwei Einträge derselben Klasse
     *                                                   haben darf (3.4.2).
     */
    public function __construct(
        public readonly ?string $listOf = null,
        public readonly ?string $refersTo = null,
        public readonly bool $decimal = false,
        /** Ein `string`, der ein Datum ist — gespeichert als Text in der Form der Werte, geprüft wie eines (D-757). */
        public readonly bool $date = false,
        public readonly bool $allowDuplicates = true,
        /** Aus den Kindern welches Gerüstknotens ein Verweis wählt — statt aus allen der Verweisklasse ([D-728](../../../../docs/NewConcept/90-decision-log.md)). */
        public readonly ?Anchor $from = null,
        /** Attribute mit demselben Band werden als eine Gruppe gezeichnet — *«eine gruppe daraus machen min, max, step»* ([D-736](../../../../docs/NewConcept/90-decision-log.md)). */
        public readonly ?string $band = null,
    ) {
    }
}
