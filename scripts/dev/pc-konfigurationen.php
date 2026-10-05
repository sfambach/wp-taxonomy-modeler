<?php declare(strict_types=1);

/**
 * Aus den Testaufbauten werden PC-Konfigurationen — und seine Komplettsysteme kommen dazu (2026-09-21).
 *
 *     php scripts/dev/pc-konfigurationen.php            # nur zeigen
 *     php scripts/dev/pc-konfigurationen.php --write    # umbauen
 *
 * ⚠️ *Sein Wort ([D-895](../../docs/NewConcept/90-decision-log.md)): «im Grunde ist ein Testaufbau eine PC-Konfiguration, halt
 * ohne Gehäuse und so, und die PC-Konfigs brauchen wir eh — Beispiel wäre der 386 DX 40 von Escom».*
 *
 * 1. Der Knoten «Testaufbauten» wandert unter «Hardware» und heisst «PC-Konfigurationen». Id, Sätze und Messwerte bleiben;
 *    er erbt damit Hersteller, Erscheinungsjahr und die übrigen Hardware-Felder. Dazu: Soundkarte, weitere Karten,
 *    Laufwerke, Gehäuse.
 * 2. Die 16 Aufbauten ohne Board werden zugeordnet, soweit Board oder CPU im Modell stehen — von Hand gelesen, weil die
 *    Spaltenköpfe keine Modellnamen sind («CompaqDeskproEP (PII)»).
 * 3. Seine zwei Komplettsysteme aus TablePress 28 (Escom Slimline PC, IMC 2000) werden PC-Konfigurationen, sein Stück je
 *    ein Exemplar mit Kaufpreis.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\ModelEditor $editor */
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);
/** @var \Taxmod\Core\Service\DataEntry $data */
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-21
const HARDWARE = 149000103001, TEXT = 1175, BEZEICHNUNG = 149000103839, HERSTELLER = 149000102544, ERSCHIEN = 149000103840;
const HERSTELLERLISTE = 149000102677, HERSTELLER_NAME = 149000102666, EXEMPLARE = 149000108212, MAINBOARDS = 149000107691;
const EX_MODELL = 149000108189, EX_GEKAUFT = 149000108194, EX_PREIS = 149000108195, EX_WAEHRUNG = 149000108196;
const EX_HINWEIS = 149000108198, EX_EINGEBAUT = 149000108193, EURO = 8571, CPUS = 149000103845;

$sagen = static fn (string $satz) => print($satz . "\n");

$knoten = (int) $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name IN ('Testaufbauten', 'PC-Konfigurationen') LIMIT 1");

if ($knoten === 0) {
    exit("Weder «Testaufbauten» noch «PC-Konfigurationen» gefunden\n");
}

$jetzt = $editor->find($knoten);

// ── 1. Umziehen und umbenennen ─────────────────────────────────────────────────────────────────────
if ($jetzt->parentNodeId !== HARDWARE) {
    $sagen("«{$jetzt->name}» wandert unter Hardware");

    if ($schreiben) {
        $editor->move($knoten, HARDWARE);
    }
}

if ($jetzt->name !== 'PC-Konfigurationen') {
    $sagen("«{$jetzt->name}» heisst künftig «PC-Konfigurationen»");

    if ($schreiben) {
        $editor->rename($knoten, 'PC-Konfigurationen');
    }
}

$feldAn = static fn (string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
    $knoten,
    $name
))) === null ? null : (int) $id;

foreach ([['Soundkarte', Multiplicity::ZeroToOne], ['Weitere Karten', Multiplicity::ZeroToMany], ['Laufwerke', Multiplicity::ZeroToMany], ['Gehäuse', Multiplicity::ZeroToOne]] as [$name, $wieOft]) {
    if ($feldAn($name) !== null) {
        continue;
    }

    $sagen("  Feld «{$name}» ({$wieOft->value})");

    if ($schreiben) {
        $kante = $editor->addField($knoten, TEXT, $name, RelationKind::Composition);
        $editor->setMultiplicity($knoten, $kante->id, $wieOft);
    }
}

if (! $schreiben) {
    exit("— nur gezeigt. Mit --write umbauen.\n");
}

$f = [];

foreach (['Mainboard', 'Revision', 'CPU', 'Exemplar', 'Arbeitsspeicher', 'Grafikkarte', 'Festplatte', 'Hinweis', 'Soundkarte', 'Weitere Karten', 'Laufwerke', 'Gehäuse'] as $name) {
    $f[$name] = $feldAn($name);
}

$satzMit = static fn (int $knotenId, int $feldId, string $text): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s LIMIT 1",
    $knotenId,
    $feldId,
    $text
))) === null ? null : (int) $id;

$steht = static fn (int $satz, int $feld): bool => (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
    $satz,
    $feld
)) > 0;

$setzen = static function (int $satz, string $feldName, TypedValue $wert) use ($f, $data, $steht): void {
    $feld = $f[$feldName] ?? null;

    if ($feld !== null && ! $steht($satz, $feld)) {
        $data->put($satz, $feld, $wert);
    }
};

$anhaengen = static function (int $satz, string $feldName, array $texte) use ($f, $data, $steht): void {
    $feld = $f[$feldName] ?? null;

    if ($feld === null || $steht($satz, $feld)) {
        return;
    }

    foreach ($texte as $text) {
        $data->appendValue($satz, $feld, TypedValue::ofText($text));
    }
};

// ── 2. Die 16 Aufbauten ohne Board ─────────────────────────────────────────────────────────────────
// Gemessen am 2026-09-21: Board-Sätze (Mainboards) und CPU-Sätze nach ihrer Bezeichnung.
$zuordnung = [
    'Pentium I 133'                                => [null, 31520, ''],
    'Pentium I 200 MMX'                            => [null, 31599, ''],
    'JT-586IVC4 Rev1 Ohne L2'                      => ['JT-586IV4', null, 'Spaltenkopf «JT-586IVC4» — angenommen, es ist sein JT-586IV4.'],
    'JT-586IVC4 Rev1 Mit 256KB L2 Pentium 100'     => ['JT-586IV4', null, 'Spaltenkopf «JT-586IVC4» — angenommen, es ist sein JT-586IV4. Pentium 100 mit 50 oder 66 MHz Bus: offen.'],
    'CompaqDeskproEP (PII)'                        => ['Deskpro EP (440BX, 3 DIMM)', null, ''],
    'VA-502 (PI)'                                  => ['VT-502', null, 'Spaltenkopf «VA-502»: die Recherche zum Board vermutete schon, dass sein VT-502 ein VA-502 ist (D-875).'],
    'Biostar GF8100 M2 TE (PI)'                    => ['GF8100 M2+ TE', null, ''],
    'MSI PT880NEO MS 7008V1 + Pentium 4'           => ['PT880 Neo', null, ''],
    'FIC 486-HC-HD 486 DX33'                       => ['486-VC-HD', 22921, 'The Retro Web führt «486-HC-HD» als zweiten Namen des 486-VC-HD.'],
    // *Seit D-896 im Modell — nach ihrer Bezeichnung gesucht.*
    '486 DX2 40'                                   => [null, 'i486DX2-40', ''],
    '486 DX2 66'                                   => [null, 'i486DX2-66', ''],
    '486 DX4 100'                                  => [null, 'i486DX4-100', 'Hersteller nicht genannt — angenommen Intel.'],
    'Celeron D 2,8 GHz'                            => [null, 'Celeron D 336 (2,8 GHz)', ''],
    'Celeron D 3,2GHz'                             => [null, 'Celeron D 351 (3,2 GHz)', ''],
    'Celeron D 3,33GHz'                            => [null, 'Celeron D 355 (3,33 GHz)', ''],
    'MSI PM8PM-V MS-7222 V2.0 + Celeron D 2,8 MHz' => [null, 'Celeron D 336 (2,8 GHz)', ''],
];

foreach ($zuordnung as $aufbau => [$board, $cpu, $hinweis]) {
    $satz = $satzMit($knoten, BEZEICHNUNG, $aufbau);

    if ($satz === null) {
        $sagen("  ⚠ Aufbau «{$aufbau}» nicht gefunden");
        continue;
    }

    if ($board !== null && ($boardSatz = $satzMit(MAINBOARDS, BEZEICHNUNG, $board)) !== null) {
        $setzen($satz, 'Mainboard', TypedValue::ofRecordReference($boardSatz));
    }

    if (is_string($cpu)) {
        $cpu = $satzMit(CPUS, BEZEICHNUNG, $cpu);
    }

    if ($cpu !== null) {
        $setzen($satz, 'CPU', TypedValue::ofRecordReference($cpu));
    }

    if ($hinweis !== '') {
        $setzen($satz, 'Hinweis', TypedValue::ofText($hinweis));
    }

    $sagen("Aufbau «{$aufbau}»" . ($board === null ? '' : " → {$board}") . ($cpu === null ? '' : " · CPU #{$cpu}"));
}

// ── 3. Seine Komplettsysteme (TablePress 28) ───────────────────────────────────────────────────────
$hersteller = static function (string $name) use ($satzMit, $data): int {
    $steht = $satzMit(HERSTELLERLISTE, HERSTELLER_NAME, $name);

    if ($steht !== null) {
        return $steht;
    }

    $neu = $data->create(HERSTELLERLISTE, RecordType::User)->id;
    $data->put($neu, HERSTELLER_NAME, TypedValue::ofText($name));

    return $neu;
};

$systeme = [
    [
        'name' => 'Escom Slimline PC', 'hersteller' => 'Escom', 'jahr' => 1992,
        'board' => '386-SC-HG', 'revision' => 33268, 'cpu' => 29802,
        'ram' => '4 × 1 MB, SIMM 30-polig', 'grafik' => 'Cirrus Logic CL-GD5424', 'sound' => 'Sound Blaster 16 (CT2940)',
        'karten' => ['Controller IDE / FDD', 'Modemkarte'], 'platte' => 'Conner, 60 MB', 'laufwerke' => ['3,5"', '5,25"'],
        'gehaeuse' => 'Slimline',
        'hinweis' => 'Aus seiner Tabelle «Retro-PCs» (TablePress 28). CPU «386 DX40»: einen 386DX mit 40 MHz gab es nur von AMD — abgeleitet: Am386DX-40. Ports: 2 seriell, 1 parallel, 2 Gameports.',
        'preis' => '220', 'kaufjahr' => 2025, 'boardSpalte' => 'TablePress 16, Spalte 6',
    ],
    [
        'name' => 'IMC 2000', 'hersteller' => 'IMC Data Systems', 'jahr' => 1990,
        'board' => null, 'revision' => null, 'cpu' => 22903,
        'ram' => '1 MB', 'grafik' => 'Mintek MGP-300 (CGA/EGA, Mono)', 'sound' => null,
        'karten' => ['Controller IDE / FDD'], 'platte' => 'Seagate ST-157A, 44 MB (C/H/S 560/6/26)', 'laufwerke' => ['3,5"'],
        'gehaeuse' => null,
        'hinweis' => 'Aus seiner Tabelle «Retro-PCs» (TablePress 28). Board «HT 12 286» (Headland HT12) steht noch nicht im Modell. CPU Harris 286-16. Ports: 2 seriell, 1 parallel, 1 Gameport.',
        'preis' => '110', 'kaufjahr' => 2026, 'boardSpalte' => null,
    ],
];

foreach ($systeme as $s) {
    $satz = $satzMit($knoten, BEZEICHNUNG, $s['name']);

    if ($satz === null) {
        $satz = $data->create($knoten, RecordType::User)->id;
        $data->put($satz, BEZEICHNUNG, TypedValue::ofText($s['name']));
        $sagen("PC-Konfiguration «{$s['name']}»");
    }

    $data->put($satz, HERSTELLER, TypedValue::ofRecordReference($hersteller($s['hersteller'])));
    $data->put($satz, ERSCHIEN, TypedValue::ofDate($s['jahr'] . '-01-01 00:00:00'));

    if ($s['board'] !== null && ($boardSatz = $satzMit(MAINBOARDS, BEZEICHNUNG, $s['board'])) !== null) {
        $setzen($satz, 'Mainboard', TypedValue::ofRecordReference($boardSatz));
    }

    if ($s['revision'] !== null) {
        $setzen($satz, 'Revision', TypedValue::ofRecordReference($s['revision']));
    }

    $setzen($satz, 'CPU', TypedValue::ofRecordReference($s['cpu']));
    $setzen($satz, 'Arbeitsspeicher', TypedValue::ofText($s['ram']));
    $setzen($satz, 'Grafikkarte', TypedValue::ofText($s['grafik']));

    if ($s['sound'] !== null) {
        $setzen($satz, 'Soundkarte', TypedValue::ofText($s['sound']));
    }

    $anhaengen($satz, 'Weitere Karten', $s['karten']);
    $setzen($satz, 'Festplatte', TypedValue::ofText($s['platte']));
    $anhaengen($satz, 'Laufwerke', $s['laufwerke']);

    if ($s['gehaeuse'] !== null) {
        $setzen($satz, 'Gehäuse', TypedValue::ofText($s['gehaeuse']));
    }

    $setzen($satz, 'Hinweis', TypedValue::ofText($s['hinweis']));

    // *Sein Stück: ein Exemplar mit Kaufpreis — gekauft ist nur das Jahr belegt.*
    $marke = "Retro-PC «{$s['name']}»";
    $ex    = $wpdb->get_var($wpdb->prepare(
        "SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text LIKE %s LIMIT 1",
        EX_HINWEIS,
        '%' . $wpdb->esc_like($marke) . '%'
    ));

    if ($ex === null) {
        $ex = $data->create(EXEMPLARE, RecordType::User)->id;
        $data->put($ex, EX_MODELL, TypedValue::ofRecordReference($satz));
        $data->put($ex, EX_GEKAUFT, TypedValue::ofDate($s['kaufjahr'] . '-01-01 00:00:00'));
        $data->put($ex, EX_PREIS, TypedValue::ofDecimal($s['preis']));
        $data->put($ex, EX_WAEHRUNG, TypedValue::ofReference(EURO));
        $data->put($ex, EX_HINWEIS, TypedValue::ofText("Sein {$marke}, aus TablePress 28. Kaufdatum: nur das Jahr belegt."));
        $sagen("  Exemplar mit {$s['preis']} € ({$s['kaufjahr']})");
    }

    // ⚠️ *Das Board-Exemplar steckt in diesem PC — abgeleitet: seine Tabelle 16 nennt das 386-SC-HG «Retro Hauptplatine», und
    // der Escom trägt dasselbe Board. Ihm gemeldet.*
    if ($s['boardSpalte'] !== null) {
        $boardEx = $wpdb->get_var($wpdb->prepare(
            "SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text LIKE %s LIMIT 1",
            EX_HINWEIS,
            '%' . $wpdb->esc_like($s['boardSpalte']) . '%'
        ));

        if ($boardEx !== null && ! $steht((int) $boardEx, EX_EINGEBAUT)) {
            $data->put((int) $boardEx, EX_EINGEBAUT, TypedValue::ofRecordReference((int) $ex));
            $setzen($satz, 'Exemplar', TypedValue::ofRecordReference((int) $boardEx));
            $sagen('  Board-Exemplar «386-SC-HG» steckt in diesem PC');
        }
    }
}

echo "fertig\n";
