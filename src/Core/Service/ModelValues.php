<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * Was der Autor am Modell gesagt hat — gelesen aus Datensätzen statt aus der Settings-Tabelle.
 *
 * ⚠️ **Die Brücke für [D-529](../../../docs/NewConcept/90-decision-log.md).** *Die Settings-Tabelle
 * fällt, und die Angaben ziehen einzeln um. **Solange beides existiert, muss der Leser beide Stellen
 * kennen** — und die neue gewinnt, weil ein Umzug sonst nichts ändern würde.*
 *
 * ⚠️ **Diese Klasse ist der Grund, warum der Umzug am 2026-08-30 zurückgedreht wurde.** *Die Daten
 * wanderten zuerst, der Leser blieb — jeder Knoten hätte seinen Renderer verloren, und **alle 305
 * Randprüfungen wären grün geblieben**. Seither ist die Reihenfolge festgelegt: **Wächter, Leser,
 * Daten.***
 *
 * ⚠️ *Sie antwortet vorläufig nur zum **Renderer**, weil nur der umgezogen ist. Die übrigen Angaben
 * kommen hier dazu, sobald sie wandern — jede in einer eigenen Methode, damit sichtbar bleibt, welche
 * schon an der neuen Stelle liegt.*
 *
 * ⚠️ **Sie löst über die Kette auf** ([D-602](../../../docs/NewConcept/90-decision-log.md)):
 * *Datensatz der Kante → Datensatz des Zielknotens → Datensätze der Vorfahren, von nah nach fern →
 * Rückfall im Kode. **Die Installationsstufe ist nicht dabei** — ihre Vorgaben stehen im Kode.*
 *
 * ```mermaid
 * flowchart LR
 *   K1["Kante"] --> K2["Zielknoten"] --> K3["Vorfahren, nah → fern"] --> K4["Rückfall im Kode"]
 * ```
 *
 * ```mermaid
 * flowchart LR
 *   N["Knoten"] --> R["sein Datensatz"]
 *   R -->|"Pfad = renderer-Kante"| T["Teil: DisplayOption"]
 *   T -->|"Feld render"| K["Knoten unter Renderer"]
 *   K --> M["sein Name ist die Antwort"]
 * ```
 *
 * @see docs/NewConcept/02-field-and-setting.md
 */
final class ModelValues
{
    /**
     * Die Zielknoten der zwei Kanten — **nur** für den ersten Lauf.
     *
     * ⚠️ **Sie sind kein Schlüssel mehr** ([D-543](../../../docs/NewConcept/90-decision-log.md)), auf sein
     * Wort: *«ja, Id — Name war nie erlaubt.»* *Hier stehen sie, damit der Notnagel überhaupt etwas zu
     * suchen hat, wenn noch keine Id aufgeschrieben ist. **Sobald sie dasteht, sieht niemand mehr auf
     * einen Namen**, und eine Umbenennung ist wieder das, was sie sein soll: eine Beschriftung.*
     */
    private const CARRIER_NODE = 'DisplayOption';

    private const VALUE_NODE = 'Renderer';

    private ?int $rendererRelation = null;

    private ?int $renderRelation = null;

    private bool $gesucht = false;

    public function __construct(
        private readonly RecordRepository $records,
        private readonly RelationRepository $relations,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
    ) {
    }


    /**
     * Ob dieser Erbe diese Einstellungskante **nicht** erbt — [D-607](../../../docs/NewConcept/90-decision-log.md).
     *
     * ⚠️ **Die Regel ist die des Ziels, nicht die der Marke.** *Sein Einwand hat die erste Fassung
     * («ein Knoten mit `kind = setting` erbt nichts», [D-605](../../../docs/NewConcept/90-decision-log.md))
     * umgeworfen: «aber das ist falsch, weil Renderer converter hat und jeder Kindknoten muss ihn
     * erben». **Schädlich sind genau die Kanten, deren Ziel der erbende Knoten selbst ist** — gemessen
     * am 2026-09-04 zwei von elf: `Root --validator--> Validator` und `Root --read_only--> read_only`.
     * `Renderer --converter--> Converter` ist nicht darunter, wenn `compact` fragt.*
     *
     * ⚠️ **Es geht ums Erben, nicht ums Haben.** *`fromNodeId !== $heirId`: eine Kante, die jemand
     * absichtlich von einem Knoten auf sich selbst legt, steht da, weil er sie hingeschrieben hat, und
     * bleibt erlaubt ([D-608](../../../docs/NewConcept/90-decision-log.md) nennt genau das als Ausweg).*
     *
     * ⚠️ *Statisch, weil {@see \Taxmod\Core\Service\Rendering} die Sperre für die **Anzeige** braucht
     * und dort kein {@see ModelValues} gesetzt sein muss — **eine Fassung der Regel und nicht zwei**
     * (`CD`).*
     */
    public static function inheritanceBlocked(Relation $relation, int $heirId): bool
    {
        return $relation->isSetting()
            && $relation->toNodeId === $heirId
            && $relation->fromNodeId !== $heirId;
    }

    /**
     * Die Angaben, die dieser **Knoten** am Modell trägt.
     *
     * @return array<string,ResolvedSetting>
     */
    public function forNode(Node $node): array
    {
        return $this->kette($node, true);
    }

    /**
     * Stufe 2 und 3 der Auflösungskette: der Knoten selbst, dann seine **Vorfahren von nah nach fern**.
     *
     * ⚠️ **Das ist [D-602](../../../docs/NewConcept/90-decision-log.md), und es stellt her, was
     * [D-579](../../../docs/NewConcept/90-decision-log.md) genommen hat.** *Sein Wort: «ja, Vorfahren
     * sollen wieder funktionieren.» **Sein eigenes Modell begründet es:** `Prefixes` trägt
     * `label_role = symbol`, damit `kilo` als `k` erscheint — für alle Präfixe, nicht je einzeln.*
     *
     * ⚠️ **Näher schlägt ferner, und die Kante schlägt alles.** *Deshalb `??=`: der erste Fund gewinnt,
     * und gegangen wird von nah nach fern. Die Kante steht vor dieser Kette
     * ({@see self::forUseSite()}), also überschreibt hier nichts mehr, was sie gesetzt hat.*
     *
     * ⚠️ **Die Installationsstufe kommt ausdrücklich *nicht* zurück** ([D-602](../../../docs/NewConcept/90-decision-log.md)):
     * *die Zeile, die sie trug, ist mit [D-579](../../../docs/NewConcept/90-decision-log.md)
     * weggefallen, ihre Vorgaben stehen im Kode. **Und «Modellwurzel» braucht keine eigene Stufe**,
     * weil die Wurzel ein Vorfahr ist.*
     *
     * ⚠️ **Alle Sätze der Kette in *einer* Abfrage** (`CD-7`). *Ein Satz je Vorfahrenstufe wäre eine
     * Abfrage je Stufe — die Tiefe des Baums als Zahl der Läufe, genau das N+1, das
     * `package7-check.php` misst. {@see Node::ancestorIds()} gibt die Kette auf einmal, sie kostet
     * keine eigene Abfrage.*
     *
     * @param  bool $amKopfGesetzt Ob der Knoten selbst «hier gesetzt» heisst — von einer Kante aus nicht.
     * @return array<string,ResolvedSetting>
     */
    private function kette(Node $node, bool $amKopfGesetzt): array
    {
        // ⚠️ *Von nah nach fern, und die Kette kommt von {@see self::erbkette()} — dort wohnt der
        // Schnitt an der Wurzel des Settings-Astes ([D-545](../../../docs/NewConcept/90-decision-log.md)).*
        // ⚠️ **Die Installationsstufe steht bewusst nicht in dieser Kette**
        // ([D-602](../../../docs/NewConcept/90-decision-log.md): *«die Installationsstufe kommt
        // ausdrücklich nicht zurück … ihre Vorgaben stehen jetzt im Kode»*). *Sie ist auch nicht
        // versehentlich drin: **gemessen am 2026-09-04 ist {@see FrameworkNodes::installationId()}
        // eine eigene Nummer und steht in keinem Knotenpfad**, also kann kein Vorfahr sie sein. Die
        // **Modellwurzel** dagegen ist ein Vorfahr und gehört dazu — genau das sagt D-602.*
        $kette     = $this->erbkette($node);
        $vorfahren = array_values(array_diff(array_reverse($kette), [$node->id]));

        $this->vorladen($kette);

        $aus = $this->stufe($node, $node->id, $amKopfGesetzt);

        foreach ($vorfahren as $vorfahr) {
            foreach ($this->stufe($node, $vorfahr, false) as $schluessel => $angabe) {
                $aus[$schluessel] ??= $angabe;
            }
        }

        return $aus;
    }

    /**
     * Was **ein** Glied der Kette sagt — die Sätze eines Knotens, gelesen mit der Kantenkunde des
     * gefragten Knotens.
     *
     * ⚠️ *Gelesen wird mit `$subject` und nicht mit dem Träger: die Einstellungskante ist am Vorfahren
     * erklärt, der Wert kann am Nachfahren liegen, und umgekehrt. {@see self::settingRelation()} kennt für
     * `$subject` die ganze Kette und findet beides.*
     *
     * @return array<string,ResolvedSetting>
     */
    private function stufe(Node $subject, int $traeger, bool $gesetzt): array
    {
        $saetze = $this->recordsOf($traeger);

        // ⚠️ **Die Spalte wird auch dann gefragt, wenn der Knoten keine eigenen Sätze hat** —
        // *gefunden vom umgeschriebenen `setting-write-check.php` am 2026-09-05. Der frühe Ausstieg
        // stammt aus der Zeit, in der **jede** Angabe in einem Satz des Knotens lag; seit
        // [D-584](../../../docs/NewConcept/90-decision-log.md) liegt der Renderer in
        // `nodes.settings_record_id`, und der Satz dahinter gehört dem **Renderer**, nicht diesem
        // Knoten. **Ein frisch eingestellter Knoten hat darum keinen eigenen Satz** — und verlor
        // seinen Renderer beim Lesen, obwohl die Spalte richtig stand.*
        $ausSpalte = $this->rendererNameOfNode($traeger);

        if ($saetze === []) {
            return $ausSpalte === null
                ? []
                : ['renderer' => new ResolvedSetting('renderer', TypedValue::ofText($ausSpalte), $traeger, $gesetzt)];
        }

        $aus = $this->settingsAt($subject, $saetze, $traeger, []);

        // ⚠️ **Zuerst die Spalte** ([D-584](../../../docs/NewConcept/90-decision-log.md), TASK-020):
        // *der Einstellungsdatensatz eines Knotens hängt an `nodes.settings_record_id`, und seine
        // `node_id` sagt, welcher Renderer es ist. **Der alte Weg über die Trägerkante bleibt als
        // Rückfall stehen**, solange Daten ihn noch benutzen — ein Leser, der die Altform nicht mehr
        // kennt, macht bestehende Einstellungen unsichtbar, ohne dass jemand etwas geändert hätte.*
        $name = $ausSpalte ?? $this->rendererNameAt($saetze, []);

        if ($name !== null) {
            $aus['renderer'] = new ResolvedSetting('renderer', TypedValue::ofText($name), $traeger, true);
        }

        if ($gesetzt) {
            return $aus;
        }

        // ⚠️ *«Geerbt» und «hier gesetzt» müssen auf dem Bildschirm verschieden aussehen
        // ([D-266](../../../docs/NewConcept/90-decision-log.md)) — also wird die Herkunft hier
        // umgeschrieben und nicht bloss der Wert durchgereicht.*
        foreach ($aus as $schluessel => $angabe) {
            $aus[$schluessel] = new ResolvedSetting($angabe->key, $angabe->value, $traeger, false);
        }

        return $aus;
    }

    /**
     * Die Sätze dieser Knoten und ihre Werte **auf einmal** ins Gedächtnis holen (`CD-7`).
     *
     * @param list<int> $nodeIds
     */
    private function vorladen(array $nodeIds): void
    {
        $offen = array_values(array_filter(
            array_unique($nodeIds),
            fn (int $id): bool => ! isset($this->satzGedaechtnis[$id])
        ));

        if ($offen === []) {
            return;
        }

        $saetze = [];

        foreach ($this->records->ofNodes($offen) as $nodeId => $seine) {
            $this->satzGedaechtnis[$nodeId] = $seine;

            foreach ($seine as $satz) {
                $saetze[] = $satz->id;
            }
        }

        // ⚠️ *Auch die zu füllen, die keine Sätze haben — sonst fragt der nächste Lauf sie erneut.*
        foreach ($offen as $id) {
            $this->satzGedaechtnis[$id] ??= [];
        }

        // ⚠️ **Die Spalte für die ganze Kette in einem Zug** (`CD-7`, TASK-020). *Sonst wäre der
        // Renderer wieder eine Abfrage je Vorfahrenstufe — dasselbe N+1, das dieser Vorladelauf
        // überhaupt erst beseitigt hat.*
        $spalten = $this->nodes->settingsRecordIdsOf($offen);

        foreach ($offen as $id) {
            $this->spalteAmKnoten[$id] = $spalten[$id] ?? 0;
        }

        $fehlend = array_values(array_filter(
            $saetze,
            fn (int $id): bool => ! isset($this->wertGedaechtnis[$id])
        ));

        foreach ($this->records->valuesOfMany($fehlend) as $satzId => $werte) {
            $this->wertGedaechtnis[$satzId] = $werte;
        }

        // ⚠️ **Und die Teile gleich mit** (`CD-7`). *Der Renderer steht eine Stufe tiefer: die
        // Trägerkante zeigt auf einen **Teil**, und dessen Feld `render` nennt den Knoten
        // ([D-511](../../../docs/NewConcept/90-decision-log.md)). {@see self::rendererNameAt()} steigt
        // dort hinein — je Glied der Kette einmal. **Gemessen, was das kostet: 31 Abfragen für 7
        // Felder und 59 für 14**, also linear, weil jeder Vorfahr seinen eigenen Teil hat.*
        $this->findRelations();

        $teile = [];

        foreach ($fehlend as $satzId) {
            foreach ($this->wertGedaechtnis[$satzId] ?? [] as $wert) {
                if ($wert->relationId === $this->rendererRelation
                    && $wert->value->reference !== null
                    && ! isset($this->wertGedaechtnis[$wert->value->reference])
                ) {
                    $teile[] = $wert->value->reference;
                }
            }
        }

        if ($teile === []) {
            return;
        }

        foreach ($this->records->valuesOfMany(array_values(array_unique($teile))) as $satzId => $werte) {
            $this->wertGedaechtnis[$satzId] = $werte;
        }
    }

    /**
     * Jede Einstellungskante, die in diesen Datensätzen einen Wert hat — nicht nur der Renderer.
     *
     * ⚠️ **Der Eigentümer hat gefragt, und es war ein Fehler.** *«Warum wird das in `Integer` trotzdem
     * nicht aufgelöst? Das verstehe ich nicht. Ist da ein Fehler?»* **Ja.** *Gemessen: `read_only` steht
     * als `1..1`-Einstellungskante an `Root`, und drei Knoten tragen ihren Wert im `default`-Satz —
     * `Integer`, `Root`, `Electronic Parts`, alle mit `0`. **Diese Klasse konnte genau einen Schlüssel
     * beantworten**, `renderer`, und liess die anderen liegen.*
     *
     * ⚠️ **Damit war es der siebte Fall derselben Sache:** *die Daten sind umgezogen, der Leser ist
     * stehengeblieben — und nichts wurde rot, weil der Schalter einfach «nichts gesetzt» zeigte. `PR-12`
     * nennt genau das. **Und der Docblock dieser Klasse hat es selbst angekündigt** («sie antwortet
     * vorläufig nur zum Renderer, weil nur der umgezogen ist») — die Ankündigung stimmte nicht mehr,
     * sobald `read_only` und `with_label` wanderten.*
     *
     * ⚠️ **Der Schlüssel ist der Name der Kante**, und das ist keine neue Erfindung: die
     * Einstellungskanten heissen `read_only`, `with_label`, `label_role`, `orientation` — genau wie
     * {@see SettingKey}. *Der Name ist hier **die Angabe selbst** und nicht eine Beschriftung; ihn
     * umzubenennen heisst, eine andere Angabe zu meinen. Dieselbe Unterscheidung, die
     * {@see self::findRelations()} für die zwei Renderer-Kanten trifft.*
     *
     * ⚠️ **Die Trägerkante bleibt aussen vor.** *Ihr Wert ist ein Verweis auf einen **Teil** und nicht
     * auf einen Knoten; {@see self::rendererNameAt()} steigt dort hinein. Sie hier auch auszugeben,
     * hiesse dieselbe Angabe zweimal zu melden, einmal als Satz-Id.*
     *
     * ⚠️ *Diese Methode liest **ein** Glied der Kette — die Sätze, die man ihr gibt. Die Kette selbst
     * baut {@see self::kette()} ([D-602](../../../docs/NewConcept/90-decision-log.md)); hier steht
     * bewusst keine zweite Fassung derselben Regel (`CD`).*
     *
     * ⚠️ **Die Adresse einer Verwendungsstelle ist eine andere als die des Knotens, und das habe ich im
     * ersten Zug falsch gemacht.** *Am Knoten ist der Pfad die Einstellungskante allein; an einer
     * Verwendungsstelle steht die Kante der Stelle davor — `<Stelle>.<Einstellung>`, genau die Form, die
     * {@see self::rendererNameAt()} baut. **Ohne den Vorlauf hätte `forUseSite()` die Angabe des Knotens
     * als die der Stelle gemeldet** — und dann wäre `read_only` an einem Feld die Antwort seines
     * Besitzers, was der ganzen Unterscheidung widerspricht.*
     *
     * @param  list<int> $recordIds
     * @param  list<int> $vorlauf   Kanten vor der Einstellungskante — leer für den Knoten selbst.
     * @return array<string,ResolvedSetting>
     */
    private function settingsAt(Node|Relation $subject, array $recordIds, int $owner, array $vorlauf): array
    {
        if ($recordIds === []) {
            return [];
        }

        $this->findRelations();

        $aus    = [];
        $anfang = $vorlauf === [] ? '' : implode('.', $vorlauf) . '.';

        foreach ($recordIds as $recordId) {
            foreach ($this->valuesOf($recordId) as $wert) {
                // ⚠️ *Genau diese Adresse und keine tiefere: ein Pfad, der weitergeht, liegt in einem
                // Teil, und dessen Werte gehören der Kante des Teils, nicht dieser hier.*
                if ($wert->path !== $anfang . $wert->relationId || $wert->value->isNothing()) {
                    continue;
                }

                if ($wert->relationId === $this->rendererRelation) {
                    continue;
                }

                $kante = $this->settingRelation($subject, $wert->relationId);

                if ($kante === null || isset($aus[$kante->name])) {
                    continue;
                }

                // ⚠️ **Die Sperre aus [D-607](../../../docs/NewConcept/90-decision-log.md), an der
                // einen Stelle, an der ein Wert in die Kette kommt.** *Ohne sie zeigte der Knoten
                // `read_only` `read_only` als seine eigene, geerbte Einstellung — sein Befund am
                // Bildschirm: «setting cannot inherit itself». Sie greift nur für einen **Knoten**;
                // eine Verwendungsstelle ist nie das Ziel ihrer eigenen Einstellungskante.*
                if ($subject instanceof Node && self::inheritanceBlocked($kante, $subject->id)) {
                    continue;
                }

                $aus[$kante->name] = new ResolvedSetting($kante->name, $wert->value, $owner, true);
            }
        }

        return $aus;
    }

    /**
     * Die Kante dieser Id, wenn sie eine **Einstellungskante** an diesem Träger ist.
     *
     * ⚠️ **Alle Kanten des Trägers und seiner Vorfahren in *einer* Abfrage** (`CD-7`). *Der Wert liegt
     * im Satz des Knotens, die Kante gehört aber dem Vorfahren, der sie erklärt hat — `read_only` steht
     * an `Root` und sein Wert an `Integer`. Ohne die Vorfahren wäre die Kante nicht zu finden; eine
     * Abfrage je Wert wäre das N+1, das `package7-check.php` misst.*
     */
    private function settingRelation(Node|Relation $subject, int $relationId): ?Relation
    {
        // ⚠️ **Auch die Vorfahren, und bei einer Verwendungsstelle habe ich das im ersten Zug
        // vergessen.** *`read_only` ist an `Root` erklärt und sein Wert steht am Knoten — ohne die
        // Vorfahren war die Kante nicht zu finden, und die Angabe fiel still weg. **Gemessen an
        // `preview-check.php`, das genau deshalb rot blieb.***
        $kette = $this->erbkette($subject);

        $this->kantenVorladen($kette);

        foreach ($kette as $besitzer) {
            $kante = $this->kantenNachBesitzer[$besitzer][$relationId] ?? null;

            if ($kante !== null) {
                return $kante->isSetting() ? $kante : null;
            }
        }

        return null;
    }

    /**
     * Wer für diesen Träger vererbt — die eine Stelle, an der [D-545](../../../docs/NewConcept/90-decision-log.md)
     * wohnt.
     *
     * ⚠️ *Nicht `ancestorIds()` von Hand: der Schnitt an der Wurzel des Settings-Astes gehört
     * {@see FrameworkNodes::inheritanceOwnersOf()}, und eine zweite Fassung derselben Regel wäre
     * genau das, was `CD` verbietet.*
     *
     * @return list<int> Von fern nach nah, der Träger selbst zuletzt.
     */
    private function erbkette(Node|Relation $subject): array
    {
        $traeger = $subject instanceof Node ? $subject->id : $subject->fromNodeId;

        if (isset($this->ketteGedaechtnis[$traeger])) {
            return $this->ketteGedaechtnis[$traeger];
        }

        $knoten = $subject instanceof Node ? $subject : $this->knoten($traeger);

        return $this->ketteGedaechtnis[$traeger] = $knoten === null
            ? [$traeger]
            : $this->framework->inheritanceOwnersOf($knoten);
    }

    /**
     * Die Feldkanten dieser Besitzer in **einer** Abfrage (`CD-7`).
     *
     * @param list<int> $besitzer
     */
    private function kantenVorladen(array $besitzer): void
    {
        $offen = array_values(array_filter(
            array_unique($besitzer),
            fn (int $id): bool => ! isset($this->kantenNachBesitzer[$id])
        ));

        if ($offen === []) {
            return;
        }

        // ⚠️ *Erst leer setzen, dann füllen — sonst fragt der nächste Lauf jeden Besitzer erneut, der
        // gar keine Kanten hat.*
        foreach ($offen as $id) {
            $this->kantenNachBesitzer[$id] = [];
        }

        foreach ($this->relations->fieldRelationsOf($offen) as $eine) {
            $this->kantenNachBesitzer[$eine->fromNodeId][$eine->id] = $eine;
        }
    }

    /** @var array<int,array<int,Relation>> Besitzer-Id => seine **eigenen** Feldkanten */
    private array $kantenNachBesitzer = [];

    /** @var array<int,list<int>> Träger-Id => wer für ihn vererbt */
    private array $ketteGedaechtnis = [];

    /** @var array<int,?Node> Knoten-Id => der Knoten, oder null wenn es ihn nicht gibt */
    private array $knotenGedaechtnis = [];

    private function knoten(int $id): ?Node
    {
        if (! array_key_exists($id, $this->knotenGedaechtnis)) {
            $this->knotenGedaechtnis[$id] = $this->nodes->find($id);
        }

        return $this->knotenGedaechtnis[$id];
    }

    /**
     * Alles, was diese Gegenstände zusammen brauchen, in **einem** Zug holen.
     *
     * ⚠️ **Ohne das ist die Kette ein N+1** (`CD-7`). *Gemessen an `package7-check.php`, das genau
     * dafür da ist: **«sieben Felder kosten keine sieben Läufe»** — mit einem Zug je Feld waren es
     * 19 Abfragen für 7 Felder und 35 für 14, also linear mit der Zahl der Felder. Ein Formular fragt
     * alle seine Felder nacheinander, und die Ketten überschneiden sich fast vollständig.*
     *
     * @param list<Node|Relation> $subjects
     */
    public function preload(array $subjects): void
    {
        $ziele   = [];
        $traeger = [];

        foreach ($subjects as $subject) {
            if ($subject instanceof Node) {
                $this->knotenGedaechtnis[$subject->id] = $subject;
                $ziele[]                               = $subject->id;

                continue;
            }

            $ziele[]   = $subject->toNodeId;
            $traeger[] = $subject->fromNodeId;
        }

        $unbekannt = array_values(array_filter(
            array_unique([...$ziele, ...$traeger]),
            fn (int $id): bool => ! array_key_exists($id, $this->knotenGedaechtnis)
        ));

        if ($unbekannt !== []) {
            $gefunden = $this->nodes->byIds($unbekannt);

            foreach ($unbekannt as $id) {
                $this->knotenGedaechtnis[$id] = $gefunden[$id] ?? null;
            }
        }

        $alle = $traeger;

        foreach ([...$ziele, ...$traeger] as $id) {
            $knoten = $this->knoten($id);

            $alle = [
                ...$alle,
                ...($knoten === null ? [$id] : $this->framework->inheritanceOwnersOf($knoten)),
            ];
        }

        $alle = array_values(array_unique($alle));

        $this->kantenVorladen($alle);
        $this->vorladen($alle);
    }

    /**
     * Die Angaben, die diese **Verwendungsstelle** am Modell trägt.
     *
     * ⚠️ *Der Datensatz gehört dem **Besitzer** der Kante, und die Adresse nennt die Kante — kein
     * Datensatz an der Kante. Der Eigentümer, als ich einen erfinden wollte: «wir haben alle Mittel,
     * einer Kanten-Knoten-Kombination in jeglicher Schachtelung Daten zuzuweisen».*
     *
     * @return array<string,ResolvedSetting>
     */
    public function forUseSite(Relation $relation): array
    {
        $aus = $this->settingsAt($relation, $this->recordsOf($relation->fromNodeId), $relation->id, [$relation->id]);

        // ⚠️ **Auch hier zuerst die Spalte** ([D-586](../../../docs/NewConcept/90-decision-log.md)):
        // *`relations.settings_record_id` trägt den eigenen Renderer der Kante. Der zweistufige Pfad
        // am Datensatz des Besitzers bleibt als Rückfall.*
        $name = $this->rendererNameOfRelation($relation->id)
            ?? $this->rendererNameAt($this->recordsOf($relation->fromNodeId), [$relation->id]);

        if ($name !== null) {
            $aus['renderer'] = new ResolvedSetting('renderer', TypedValue::ofText($name), $relation->id, true);
        }

        // ⚠️ **Stufe 2 und 3 der Kette** ([D-602](../../../docs/NewConcept/90-decision-log.md)): *was
        // die Kante nicht selbst sagt, sagt der **Zielknoten**, und was der nicht sagt, seine
        // Vorfahren. Vom Standpunkt der Kante ist beides geerbt — deshalb `false`.*
        $ziel = $this->knoten($relation->toNodeId);

        if ($ziel === null) {
            return $aus;
        }

        foreach ($this->kette($ziel, false) as $schluessel => $angabe) {
            $aus[$schluessel] ??= $angabe;
        }

        return $aus;
    }

    /**
     * Welcher Renderer steht hinter diesem Verweis?
     *
     * WICHTIG: Seit D-583 legt die Wahl eines Renderers einen *Datensatz* an, keinen Knotenverweis --
     * der Eigentuemer: «wenn ich den Renderer auswaehle, muss ein Datensatz geaendert werden».
     * Welcher Renderer es ist, sagt dann die node_id dieses Datensatzes (D-584).
     *
     * WICHTIG: Der Knotenverweis bleibt als zweiter Weg stehen, weil die vorhandenen Daten ihn noch
     * benutzen. Er verschwindet mit TASK-024, nicht vorher -- ein Leser, der die Altform nicht mehr
     * kennt, macht bestehende Einstellungen unsichtbar, ohne dass jemand etwas geaendert haette.
     */
    private function rendererNodeBehind(int $reference): ?Node
    {
        // ⚠️ **Gemerkt, weil die Kette dieselbe Antwort mehrfach braucht** (`CD-7`,
        // [D-602](../../../docs/NewConcept/90-decision-log.md)). *Gemessen an drei Feldern:
        // **sechzehn Abfragen, zehn davon hier** — derselbe Renderer viermal nachgeschlagen, einmal je
        // Glied der Kette. Die Zahl der Renderer ist klein und fest; die der Felder ist es nicht.*
        if (array_key_exists($reference, $this->rendererGedaechtnis)) {
            return $this->rendererGedaechtnis[$reference];
        }

        $satz = $this->records->find($reference);

        return $this->rendererGedaechtnis[$reference] = $satz !== null
            ? $this->knoten($satz->nodeId)
            : $this->knoten($reference);
    }

    /** @var array<int,?Node> Verweis => der Knoten des Renderers dahinter */
    private array $rendererGedaechtnis = [];

    /** @var array<int,int> Knoten-Id => Satz-Id aus der Spalte; `0` heisst «gefragt, nichts da» */
    private array $spalteAmKnoten = [];

    /** @var array<int,int> Kanten-Id => Satz-Id aus der Spalte */
    private array $spalteAnDerKante = [];

    /**
     * Der Renderer, den die **Spalte** dieses Knotens nennt — oder `null`.
     *
     * ⚠️ **Das ist die Form aus [D-584](../../../docs/NewConcept/90-decision-log.md)** und der Ort,
     * an den TASK-020 die 29 Halter gebracht hat: *`Knoten.settings_record_id → Satz`, und die
     * `node_id` des Satzes ist der Renderer.* **Eine Stufe weniger als vorher** — die Trägerkante
     * «Display Option» dazwischen ist mit ihrem Hüllknoten gefallen.
     *
     * ⚠️ *Gemerkt, weil die Kette aus [D-602](../../../docs/NewConcept/90-decision-log.md) jeden
     * Vorfahren fragt und dieselbe Antwort mehrfach braucht (`CD-7`).*
     */
    private function rendererNameOfNode(int $nodeId): ?string
    {
        if (! array_key_exists($nodeId, $this->spalteAmKnoten)) {
            $this->spalteAmKnoten[$nodeId] = $this->nodes->settingsRecordIdsOf([$nodeId])[$nodeId] ?? 0;
        }

        return $this->rendererNameOfRecord($this->spalteAmKnoten[$nodeId]);
    }

    /** Dasselbe an einer Kante ([D-586](../../../docs/NewConcept/90-decision-log.md)). */
    private function rendererNameOfRelation(int $relationId): ?string
    {
        if (! array_key_exists($relationId, $this->spalteAnDerKante)) {
            $this->spalteAnDerKante[$relationId] = $this->relations->settingsRecordIdsOfRelations([$relationId])[$relationId]['own'] ?? 0;
        }

        return $this->rendererNameOfRecord($this->spalteAnDerKante[$relationId]);
    }

    /**
     * Welcher Renderer ist dieser Einstellungsdatensatz?
     *
     * ⚠️ *Ein Zeiger auf einen Satz, den es nicht mehr gibt, heisst «nichts» und nicht «Absturz» —
     * genau der Fall, den [D-604](../../../docs/NewConcept/90-decision-log.md) beschreibt.*
     */
    private function rendererNameOfRecord(int $recordId): ?string
    {
        if ($recordId === 0) {
            return null;
        }

        return $this->rendererNodeBehind($recordId)?->name;
    }

    /**
     * Der Name des Renderers unter dieser Adresse, oder `null`.
     *
     * @param list<int> $vorlauf Kanten vor der Renderer-Kante — leer für den Knoten selbst.
     */
    private function rendererNameAt(array $recordIds, array $vorlauf): ?string
    {
        $this->findRelations();

        if ($this->rendererRelation === null || $this->renderRelation === null || $recordIds === []) {
            return null;
        }

        $pfad = implode('.', [...$vorlauf, $this->rendererRelation]);

        foreach ($recordIds as $recordId) {
            foreach ($this->valuesOf($recordId) as $wert) {
                if ($wert->path !== $pfad || $wert->value->reference === null) {
                    continue;
                }

                // ⚠️ *Eine Stufe tiefer: der Teil trägt das Feld `render`, und dessen Verweis ist ein
                // **Knoten** unter `Renderer` — sein Name ist der Renderer ([D-511](../../../docs/NewConcept/90-decision-log.md)).*
                foreach ($this->valuesOf($wert->value->reference) as $imTeil) {
                    if ($imTeil->relationId !== $this->renderRelation || $imTeil->value->reference === null) {
                        continue;
                    }

                    $knoten = $this->rendererNodeBehind($imTeil->value->reference);

                    if ($knoten !== null) {
                        return $knoten->name;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Der **Vorgabewert**, den das Modell für dieses Feld an diesem Knoten nennt.
     *
     * ⚠️ **Nur aus Datensätzen der Art `default`** ([D-524](../../../docs/NewConcept/90-decision-log.md)).
     * *Ein Benutzerdatensatz an derselben Adresse wäre ein Wert und keine Vorgabe — und
     * [D-026](../../../docs/NewConcept/90-decision-log.md) sagt es scharf: «at model level there are
     * no values, only defaults».*
     *
     * ⚠️ *Die Adresse ist der Pfad der Kante am Datensatz des **Knotens** — genau die Form, die
     * `settings.path` schon benutzte: 20 Exponenten lagen dort unter der Id des Feldes
     * `Prefixes.exponent`.*
     */
    public function defaultFor(Node $node, Relation $relation): ?TypedValue
    {
        $pfad = (string) $relation->id;

        // ⚠️ *Auch hier über das Gedächtnis: `nonPersistentValue()` wird je Feld gefragt, und ohne
        // das wäre es dasselbe N+1, das `package7-check.php` eben gemeldet hat.*
        foreach ($this->saetzeVon($node->id) as $record) {
            if ($record->recordType !== RecordType::Default) {
                continue;
            }

            foreach ($this->valuesOf($record->id) as $wert) {
                if ($wert->path === $pfad && ! $wert->value->isNothing()) {
                    return $wert->value;
                }
            }
        }

        return null;
    }

    /**
     * @var array<int,list<\Taxmod\Core\Model\NodeRecord>> Knoten-Id => seine Datensätze
     *
     * ⚠️ **Ohne dieses Gedächtnis ist diese Klasse ein N+1, und `CD-7` verbietet das.** *Gemessen von
     * `package7-check.php`, das genau dafür da ist: **«sieben Felder kosten keine sieben Läufe» —
     * 15 Abfragen für 7 Felder**, sobald die Quelle je Feld einzeln nachsah. Ein Formular fragt alle
     * seine Felder nacheinander, und alle gehören **einem** Knoten: einmal laden reicht.*
     */
    private array $satzGedaechtnis = [];

    /** @var array<int,list<\Taxmod\Core\Model\RelationRecord>> Datensatz-Id => seine Wertzeilen */
    private array $wertGedaechtnis = [];

    /** Die Datensätze eines Knotens — einmal geholt. @return list<\Taxmod\Core\Model\NodeRecord> */
    private function saetzeVon(int $nodeId): array
    {
        return $this->satzGedaechtnis[$nodeId] ??= $this->records->ofNode($nodeId);
    }

    /** @return list<int> */
    private function recordsOf(int $nodeId): array
    {
        $ids = [];

        foreach ($this->saetzeVon($nodeId) as $record) {
            $ids[] = $record->id;
        }

        return $ids;
    }

    /** @return list<\Taxmod\Core\Model\RelationRecord> */
    private function valuesOf(int $recordId): array
    {
        return $this->wertGedaechtnis[$recordId] ??= $this->records->valuesOf($recordId);
    }

    /**
     * Die zwei Kanten finden — über die **Feldnamen** ab der Wurzel, nicht über Knotennamen.
     *
     * ⚠️ **Der Unterschied ist wichtig:** *die Saat findet rund 80 Knoten über ihren Namen, und das
     * steht als [Zeile 80](../../../docs/NewConcept/97-implementation-plan.md#the-working-list) auf
     * der Liste, weil ein umbenannter Knoten die Saat blind macht. **Ein Feldname ist etwas anderes:**
     * er ist die Angabe selbst — «das Feld `renderer` der Wurzel» —, und ihn umzubenennen heisst, eine
     * andere Angabe zu meinen.*
     *
     * ⚠️ *Genau **einmal** gesucht, weil sonst jede gezeichnete Zeile zwei Abfragen kostete (`CD-7`).
     * Fehlt eine der beiden Kanten, antwortet diese Klasse «nichts» und der alte Weg trägt weiter —
     * **kein Absturz, solange der Umzug läuft**.*
     */
    private function findRelations(): void
    {
        if ($this->gesucht) {
            return;
        }

        $this->gesucht = true;

        // ⚠️ **Zuerst die aufgeschriebenen Ids** ([D-543](../../../docs/NewConcept/90-decision-log.md)).
        // *Stehen sie da, wird kein Name mehr angesehen, und Umbenennen ist frei.*
        $aussen = $this->framework->settingRelationId(SettingKey::Renderer);
        $innen  = $this->framework->settingValueRelationId(SettingKey::Renderer);

        if ($aussen !== 0 && $innen !== 0) {
            $this->rendererRelation = $aussen;
            $this->renderRelation   = $innen;

            return;
        }

        $wurzel = $this->framework->root();
        $kanten = $this->relations->fieldRelationsOf([$wurzel->id]);

        // ⚠️ **Der Notnagel, und er läuft genau einmal** — dieselbe Form wie
        // {@see \Taxmod\Core\Repository\TypeNodes::remember()}. *Danach steht die Id da und dieser
        // Block wird nie wieder betreten.*
        //
        // ⚠️ **Am **Zielknoten** und nicht am Kantennamen, und das ist gemessen und nicht überlegt.**
        // *Zuerst stand hier der Kantenname — und als diese Behebung gebaut wurde, hatte der
        // Eigentümer die Kante schon zum zweiten Mal umbenannt. **Ein Notnagel, der genau das nicht
        // überlebt, wofür er gebaut wird, ist keiner.** Der Zielknoten dagegen ist ein
        // Rahmenwerksknoten und geschützt ({@see \Taxmod\Core\Repository\FrameworkNodes::isProtected()}),
        // und Knoten so zu finden ist der Weg, den die Saat an rund achtzig Stellen ohnehin geht
        // ([Zeile 80](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)) — also keine
        // neue Art von Brüchigkeit, sondern die vorhandene, einmal.*
        $traeger = $this->carrierAmong($kanten, self::CARRIER_NODE);

        if ($traeger === null) {
            return;
        }

        [$this->rendererRelation, $ziel] = $traeger;

        $tragender = $this->nodes->find($ziel);

        if ($tragender === null) {
            return;
        }

        $innen = $this->carrierAmong(
            $this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($tragender)),
            self::VALUE_NODE
        );

        if ($innen !== null) {
            $this->renderRelation = $innen[0];
        }

        if ($this->rendererRelation !== null && $this->renderRelation !== null) {
            $this->framework->rememberSettingRelations(
                SettingKey::Renderer,
                $this->rendererRelation,
                $this->renderRelation
            );
        }
    }

    /**
     * Die Kante aus dieser Liste, die auf einen Knoten dieses Namens zeigt.
     *
     * ⚠️ *Ein Zug für alle Ziele zusammen (`CD-7`) — die Liste ist kurz, aber ein Nachschlag je Kante
     * wäre eine Abfrage je Zeile, und diese Klasse ist genau deswegen einmal umgebaut worden.*
     *
     * @param  list<Relation>       $kanten
     * @return array{int, int}|null Kanten-Id und Ziel-Id, oder nichts.
     */
    private function carrierAmong(array $kanten, string $zielName): ?array
    {
        if ($kanten === []) {
            return null;
        }

        $ziele = $this->nodes->byIds(array_map(static fn (Relation $e): int => $e->toNodeId, $kanten));

        foreach ($kanten as $kante) {
            if (($ziele[$kante->toNodeId] ?? null)?->name === $zielName) {
                return [$kante->id, $kante->toNodeId];
            }
        }

        return null;
    }
}
