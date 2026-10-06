<?php declare(strict_types=1);

/**
 * Hilfetexte für die Modellknoten, Datentypen und zusammengesetzten Teile (2026-09-22).
 *
 *     php scripts/dev/hilfetexte.php            # nur zeigen
 *     php scripts/dev/hilfetexte.php --write    # übernehmen
 *
 * ⚠️ *Zu [D-905](../../docs/NewConcept/90-decision-log.md), sein Wort: «ergänze mal die help texte in Esmplar steht nix zumindest bei den
 * modellen und datntypen sollte etwas stehen». Die Hilfe eines Feldes ist die Hilfe des Knotens, auf den es zeigt (hintsOfFields) — also
 * erklärt ein Text an «Models» auch das Feld «Modell» im Exemplar. Geschrieben über {@see \Taxmod\Core\Service\Labels::put()} wie beim
 * Speichern der Seite, in die Sprache, in der die Namen stehen. Konstanten nur, wo ihr Name nicht für sich spricht.*
 *
 * Wiederholbar: ein Knoten, der schon eine Hilfe trägt, wird nicht überschrieben — auch nicht seine eigenen vier Texte.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\Labels $labels */
$labels = (new ReflectionProperty($screen, 'labels'))->getValue($screen);

// Knoten-Id ⇒ Hilfetext. Gemessen am 2026-09-22.
const HILFEN = [
    // ── Model ───────────────────────────────────────────────────────────────────────────────────────
    402 => 'Wurzel aller Dinge, die hier beschrieben werden. Jeder Satz darunter hat eine Bezeichnung, auf Wunsch Beschreibung, Bilder, Quellen und die Angabe, woher das Wissen stammt.',
    18507 => 'Eigene Elektronikprojekte: die Projekte selbst, ihre Platinen und Revisionen und die Bauteile, aus denen sie bestehen.',
    3634 => 'Elektronische Bauteile für die Stücklisten der Projekte. Die Bezeichnung setzt sich aus den Kennwerten zusammen und wird nicht getippt.',
    3636 => 'Passive Bauteile — sie verstärken oder schalten nicht selbst: Widerstände, Kondensatoren, Spulen, Quarze, Sicherungen.',
    3640 => 'Widerstand, beschrieben durch Widerstandswert, Leistung und Toleranz.',
    149000104404 => 'Mehrere Widerstände in einem Gehäuse, meist mit gemeinsamem Anschluss.',
    3800 => 'Spule oder Drossel, beschrieben durch Induktivität und Nennstrom.',
    149000104405 => 'Ferritperle zum Entstören einer Leitung, beschrieben durch ihre Impedanz.',
    149000104406 => 'Schwingquarz als Taktgeber, beschrieben durch Frequenz und Lastkapazität.',
    149000104407 => 'Sicherung, beschrieben durch den Strom, bei dem sie auslöst.',
    3638 => 'Aktive Bauteile — sie schalten oder verstärken: Dioden, Transistoren, integrierte Schaltungen.',
    149000104408 => 'Diode — lässt Strom nur in eine Richtung durch.',
    149000104409 => 'Leuchtdiode, mit ihrer Farbe.',
    149000104410 => 'Z-Diode zum Begrenzen oder Stabilisieren einer Spannung, beschrieben durch ihre Z-Spannung.',
    149000104411 => 'Schottky-Diode — kleine Durchlassspannung, schnell; oft als Verpolschutz.',
    149000104412 => 'Transistor, mit seinem Typ (NPN, PNP, MOSFET).',
    149000104413 => 'Integrierte Schaltung. Die Pinzahl steht hier, die genauere Art in den Knoten darunter.',
    149000104414 => 'Logikbaustein, mit seiner Logikfamilie (74LS, 74HC …).',
    149000104415 => 'Speicherbaustein (EPROM, Flash, RAM …), mit Speicherart und Größe.',
    149000104416 => 'Spannungsregler, mit Ausgangsspannung und Ausgangsstrom.',
    149000104417 => 'Baustein, der eine Schnittstelle umsetzt, etwa RS-232-Pegelwandler oder USB-Seriell-Wandler.',
    149000104418 => 'Stecker, Buchsen und Sockel — beschrieben durch Polzahl, Reihen, Rastermaß und Belegung.',
    149000104419 => 'Stiftleiste (männlich) im Rastermaß.',
    149000104420 => 'Buchsenleiste (weiblich) im Rastermaß.',
    149000104421 => 'Steckbrücke, die zwei Stifte verbindet.',
    149000104422 => 'Sockel, in den ein IC gesteckt statt gelötet wird, mit seiner Breite.',
    149000104423 => 'Steckverbinder zu Geräten und Kabeln, mit Steckertyp und Geschlecht.',
    149000104424 => 'Schalter, mit der Zahl seiner Schaltstellungen.',
    149000104425 => 'Taster — schaltet nur, solange er gedrückt ist.',
    149000104426 => 'Schiebeschalter.',
    149000104427 => 'Reihe kleiner Schalter im DIP-Gehäuse, zum Einstellen auf der Platine.',
    149000104428 => 'Fertiges Modul, das als Ganzes eingesetzt wird (Funkmodul, Wandler, Anzeige …).',
    149000104430 => 'Bauteile, die in keine andere Gruppe passen.',
    149000104431 => 'Halter für Batterien oder Akkus.',
    149000104432 => 'Kabel und Litzen, mit Querschnitt (AWG) und Belegung.',
    149000104440 => 'Eigenes Elektronikprojekt, mit Status, Betriebsspannung und den Platinen, die es braucht oder nutzt.',
    149000104441 => 'Auswahllisten für die Hardwareprojekte.',
    149000104321 => 'Gehäuseform eines Bauteils — getrennt nach SMD (oberflächenmontiert) und THT (bedrahtet).',
    149000104322 => 'Oberflächenmontierte Bauformen (SMD): das Bauteil liegt auf der Platine.',
    149000104330 => 'Bedrahtete Bauformen (THT): die Beine gehen durch die Platine.',
    149000104336 => 'Material zwischen den Platten eines Kondensators.',
    149000104341 => 'Leuchtfarbe einer LED.',
    149000104348 => 'Bauart eines Transistors.',
    149000104353 => 'Logikfamilie eines Logik-ICs; sie bestimmt Pegel, Geschwindigkeit und Stromverbrauch.',
    149000104361 => 'Ob ein Verbinder Stifte (männlich) oder Buchsen (weiblich) hat.',
    149000104364 => 'Abstand der Pinreihen eines IC-Sockels.',
    149000104367 => 'Art eines Steckverbinders.',
    149000104433 => 'Auf welcher Seite der Platine ein Bauteil sitzt.',
    149000104436 => 'Ob eine Position der Stückliste bestückt wird.',
    149000104437 => 'Die Position wird immer bestückt.',
    149000104438 => 'Die Position kann bestückt werden, etwa für eine Zusatzfunktion.',
    149000104439 => 'Die Position bleibt leer, obwohl sie auf der Platine vorgesehen ist.',
    149000105253 => 'Worauf eine Schaltung aufgebaut ist.',
    149000105254 => 'Eigens entworfene, gefertigte Platine.',
    149000108068 => 'Gekauftes, fertiges Modul statt eigener Platine.',
    149000105257 => 'Wie schwer eine Revision aufzubauen ist.',
    149000108064 => 'Wie weit ein Projekt ist.',
    149000108069 => 'Aufgebaut, aber noch nicht geprüft, ob es funktioniert.',
    149000108070 => 'Gefunden und angesehen, noch nicht entschieden, ob es gebaut wird.',
    149000108806 => 'Wofür eine Datei zu einer Platinen-Revision gut ist.',
    149000109344 => 'Bestückungsdaten mit den Positionen der Bauteile (Pick and Place).',
    149000105261 => 'Eine Platine eines Projekts. Ihre Fassungen stehen als Revisionen darunter.',
    149000105262 => 'Eine Fassung einer Platine, mit Stückliste (Positionen), Dateien, Aufwand und geschätzten Kosten.',
    149000109233 => 'Eine Bauteil-Serie eines Herstellers, etwa eine Stecker- oder Gehäusefamilie; Bauteile nennen ihre Serie.',
    149000102257 => 'Alles rund um Computer: Hardware, Software und die Listen dazu.',
    149000102259 => 'Auswahllisten und Nachschlagetabellen für PC-Hardware.',
    149000103870 => 'Schnittstellen und Busse, mit ihren Kennwerten. Anschlüsse von Mainboards und Karten zeigen hierher.',
    149000108006 => 'Busse für Erweiterungskarten (ISA, PCI, AGP …), mit Adressbreite.',
    149000108007 => 'Anschlüsse für Laufwerke (IDE, SCSI, Floppy …).',
    149000108008 => 'Anschlüsse für Geräte außerhalb des Rechners (seriell, parallel, USB, PS/2 …).',
    149000108009 => 'Busse zwischen Bausteinen auf einer Platine (I²C, SPI, 1-Wire).',
    149000108010 => 'Netzwerkanschlüsse, je Stecker einer (BNC, AUI, RJ45).',
    149000108720 => 'Anschlüsse für Bildschirme (MDA, CGA, EGA, VGA, DVI, HDMI …).',
    149000103879 => 'Einbaugröße eines Laufwerks in Zoll.',
    149000104029 => 'Art eines Speichers — ob und wie er beschrieben werden kann.',
    149000104035 => 'Kein eigener Speicher.',
    149000104100 => 'Wie weit zwei Dinge zueinander passen.',
    149000104101 => 'Führt dieselben Befehle aus, braucht aber nicht dieselbe Fassung.',
    149000104102 => 'Gleiche Anschlussbelegung — kann an derselben Stelle eingesetzt werden.',
    149000104103 => 'Passt in denselben Sockel, auch wenn die Belegung abweicht.',
    149000104104 => 'Kann das andere ohne Änderung ersetzen.',
    149000104246 => 'Prozessorarchitektur, zu der eine Prozessor-Familie gehört.',
    149000107683 => 'Stecker, über den ein Mainboard seinen Strom bekommt.',
    149000107687 => 'Formfaktor eines Mainboards (AT, Baby-AT, ATX …), mit Breite und Länge.',
    149000108003 => 'Ob eine Schnittstelle Bit für Bit oder mehrere Bits zugleich überträgt.',
    149000108332 => 'Sockel oder Gehäuse eines Prozessors.',
    149000108333 => 'Befehlssatz eines Prozessors.',
    149000108334 => 'Befehlserweiterungen und Zusatzfunktionen eines Prozessors.',
    149000108718 => 'Art der Speichermodule, die ein Mainboard aufnimmt.',
    149000108719 => 'Was auf einem Mainboard oder einer Karte fest eingebaut ist.',
    149000108729 => 'Serielle, parallele und Laufwerksanschlüsse auf einem Baustein.',
    149000103429 => 'Zeilenarten für Listen innerhalb eines Satzes.',
    149000103430 => 'Eine Änderung zwischen zwei Software-Versionen, mit Überschrift und Beschreibung.',
    149000103431 => 'Alles, was ein Hersteller als Produkt anbietet: Hardware und Software. Ein Modell beschreibt die Bauart; sein eigenes Stück ist ein Exemplar.',
    149000102258 => 'Programme: Betriebssysteme, Treiber, Werkzeuge, Spiele, Benchmarks. Eine Version ist ein eigener Satz.',
    149000102403 => 'Plattenbetriebssysteme für PCs, je Anbieter ein Knoten.',
    149000103213 => 'MS-DOS von Microsoft, je Version ein Satz.',
    149000103276 => 'FreeDOS, das freie DOS, je Version ein Satz.',
    149000103327 => 'PC DOS von IBM, je Version ein Satz.',
    149000103378 => 'DR-DOS von Digital Research, später Novell und Caldera, je Version ein Satz.',
    149000102741 => 'Linux-Distributionen.',
    149000102742 => 'Windows von Microsoft, je Version ein Satz.',
    149000102746 => 'OS/2 von IBM, je Version ein Satz.',
    149000102747 => 'Unix und seine Abkömmlinge.',
    149000102743 => 'Treiber für Hardware.',
    149000102744 => 'Hilfsprogramme und Werkzeuge.',
    149000102745 => 'Spiele.',
    149000109398 => 'Benchmark-Programm. Was es misst, steht unter Messwesen › Messgrößen.',
    149000103001 => 'Gerät oder Baugruppe eines Herstellers, mit Teilenummer und Bauzeit.',
    32483 => 'Was im Rechner steckt: Karten, Laufwerke, Speicher, Prozessoren, Mainboards.',
    32485 => 'Erweiterungskarte, mit Bus, Speicher, Anschlüssen, fest eingebauten Bausteinen und wie sie eingestellt wird.',
    32487 => 'Grafikkarte.',
    32489 => 'Karte mit seriellen, parallelen oder Laufwerksanschlüssen (Multi-I/O).',
    149000109702 => 'Netzwerkkarte.',
    32801 => 'Laufwerk oder Datenträger, mit Kapazität, Schnittstelle und Bauform.',
    32897 => 'CD- oder DVD-Laufwerk, mit Geschwindigkeit.',
    149000103867 => 'Festplatte, mit Drehzahl und Cache.',
    149000103885 => 'Diskettenlaufwerk.',
    149000103886 => 'Bandlaufwerk.',
    149000103887 => 'Laufwerk mit wechselbarem Plattenmedium (ZIP, SyQuest …).',
    149000103888 => 'Halbleiterlaufwerk (SSD).',
    149000103848 => 'Arbeitsspeicher-Module.',
    149000103929 => 'Prozessoren im weiten Sinn — Hauptprozessoren, Koprozessoren, Mikrocontroller —, mit Takt, Familie, Sockel, Bussen und Spannungen.',
    149000103845 => 'Hauptprozessor (CPU), mit Bustakt, Multiplikator, Caches und Befehlserweiterungen.',
    149000103930 => 'Rechenhilfe neben der CPU, meist eine FPU, mit der Familie, zu der sie passt.',
    149000104028 => 'Mikrocontroller: Prozessor mit Programmspeicher, RAM und Ein-/Ausgängen auf einem Chip.',
    149000107688 => 'Chipsatz eines Mainboards, mit den Bausteinen, aus denen er besteht.',
    149000107691 => 'Mainboard-Modell: was das Board kann — Chipsatz, CPUs, Takt, Speicher, Steckplätze, Anschlüsse, BIOS. Die Fassungen stehen als Revisionen darunter.',
    149000107757 => 'Eine Fassung eines Mainboards, mit dem, was an genau dieser Fassung anders ist: verbaute CPU, BIOS, Jumper.',
    149000107758 => 'Eine Reihe zusammengehöriger Mainboard-Modelle eines Herstellers.',
    149000103002 => 'Geräte außerhalb des Rechners: Monitore, Eingabegeräte.',
    149000103849 => 'Monitor.',
    149000108901 => 'Eingabegeräte — Tastaturen, Mäuse, Joysticks.',
    149000108902 => 'Maus.',
    149000109401 => 'Eine Zusammenstellung aus Mainboard, CPU, Speicher, Karten und Laufwerken — ein ganzer PC oder ein Testaufbau ohne Gehäuse. Messwerte der Benchmarks zeigen hierher.',
    149000104245 => 'Prozessor-Familie (etwa 80486 oder Pentium), mit Architektur, Registerbreite und Bussen. Einzelne CPUs nennen ihre Familie.',
    149000104099 => 'Wer zu wem passt: je Satz eine Aussage «A ist kompatibel zu B», mit der Art und einer Einschränkung.',
    149000108211 => 'Quellen, auf die sich Angaben stützen — Webseiten, Datenblätter, Handbücher —, mit Adresse, Art und Abrufdatum.',
    149000108212 => 'Ein Stück, das du wirklich hast: welches Modell es ist, Seriennummer, Datecode, BIOS, Zustand und worin es eingebaut ist. Gekauft wird es über einen Kauf.',
    149000108497 => 'Vorlage für eine Webseite: Titel-Präfix, Vorspann und die Abschnitte, die eine Seite dieser Art hat.',
    149000109399 => 'Messungen: welche Größen ein Benchmark misst und die gemessenen Werte.',
    149000109400 => 'Was ein Benchmark misst: Szenario, Größe, Einheit und ob mehr oder weniger besser ist.',
    149000109402 => 'Ein gemessener Wert: welche Messgröße, an welcher PC-Konfiguration.',
    149000109585 => 'Ein Kauf: was, wann, wo und zu welchem Preis gekauft wurde, und welche Exemplare daraus stammen.',
    // ── Kontakt ─────────────────────────────────────────────────────────────────────────────────────
    3083 => 'Firmen und Personen, mit Anschrift, Webseite und Nachfolge.',
    13 => 'Firma oder andere Organisation.',
    149000102677 => 'Hersteller von Hardware oder Software.',
    149000109086 => 'Händler oder Lieferant, bei dem gekauft wird.',
    14 => 'Person.',
    // ── Datentypen ──────────────────────────────────────────────────────────────────────────────────
    406 => 'Die Grundbausteine, aus denen Felder bestehen: einfache Datentypen, Konstanten, Einheiten und zusammengesetzte Werte.',
    408 => 'Einfache Datentypen — was ein Feld speichern kann.',
    1171 => 'Ganze Zahl.',
    149000102626 => 'Jahreszahl.',
    1173 => 'Dezimalzahl mit Nachkommastellen.',
    1177 => 'Ein einzelnes Zeichen.',
    1179 => 'Ja oder nein.',
    1181 => 'E-Mail-Adresse.',
    1183 => 'Datum, auf Wunsch mit Uhrzeit.',
    1185 => 'Farbe.',
    1187 => 'Versionsnummer wie 1.2.3.',
    1189 => 'Verweis auf einen Knoten des Modells.',
    149000102938 => 'Der Weg eines Satzes durch den Baum, berechnet und nicht getippt.',
    149000104149 => 'Sprung in eine andere Liste, gefiltert auf diesen Satz — gerechnet, nicht gespeichert.',
    149000105224 => 'Datei oder Bild aus der Mediathek, oder eine Adresse im Netz.',
    149000109084 => 'Gespeicherte Zusammenfassung aus anderen Feldern, bei jeder Änderung neu geschrieben.',
    410 => 'Auswahllisten, die überall gebraucht werden.',
    731 => 'Welche Beschriftung ein Feld zeigt: für die Eingabe, die Tabelle, die Auswahl, als Zeichen oder als Hilfe.',
    3988 => 'Vorsätze für Einheiten (kilo, mega, milli …).',
    4030 => 'Maßeinheiten.',
    4032 => 'Einheiten, die einen Vorsatz tragen können (kHz, mA, MB …).',
    4056 => 'Einheiten ohne Vorsatz.',
    8569 => 'Währung eines Preises.',
    32493 => 'Leer; die Auswahllisten für PCs stehen unter PC › Constants.',
    149000108191 => 'Woher das Wissen in einem Satz stammt.',
    149000108192 => 'Durch eine Quelle belegt.',
    149000108193 => 'Selbst gemessen.',
    149000108194 => 'Aus anderen Angaben erschlossen.',
    149000108195 => 'Nicht belegt — eine Annahme, die noch zu prüfen ist.',
    149000108196 => 'Art einer Quelle.',
    149000108204 => 'In welchem Zustand ein Exemplar ist.',
    149000108207 => 'Noch nicht geprüft, ob es funktioniert.',
    149000108486 => 'Welche Art Inhalt ein Abschnitt einer Seitenvorlage trägt.',
    149000108491 => 'Wiederverwendbarer fester Text.',
    149000108492 => 'Die Felder des Modells, als Tabelle auf der Seite.',
    149000108494 => 'Chronologische Einträge, etwa Arbeitsschritte.',
    149000108633 => 'Wie eine andere Bezeichnung zum Ding steht.',
    149000108634 => 'Dasselbe Ding unter anderem Namen.',
    149000108635 => 'Ein anderes Ding, das an seiner Stelle eingesetzt werden kann.',
    149000108636 => 'Ein anderes Ding, das nur ähnlich ist — leicht zu verwechseln.',
    149000109287 => 'Schlagwörter, um Links zu gruppieren.',
    149000109403 => 'Ob bei einer Messgröße ein höherer oder ein niedrigerer Wert besser ist.',
    149000109404 => 'Was eine Messgröße vor allem misst.',
    149000109580 => 'Wie eine Karte eingestellt wird.',
    149000109584 => 'Das System stellt die Karte selbst ein.',
    3984 => 'Zusammengesetzte Werte aus mehreren Feldern — Einheitenwert, Bereich, Anschrift, Link.',
    4232 => 'Zahl mit Vorsatz und Einheit, etwa 33 MHz oder 512 KB.',
    13411 => 'Maße: Länge, Breite, Höhe.',
    66335 => 'Postanschrift.',
    75473 => 'Straße und Hausnummer.',
    75477 => 'Postleitzahl und Ort.',
    149000102740 => 'Telefonnummer.',
    149000104718 => 'Bereich von–bis mit Vorsatz und Einheit, etwa ±5 %.',
    149000107775 => 'Ein Link oder eine Datei mit Beschriftung und Schlagwörtern.',
    149000109167 => 'Ob eine Firma aufgegangen ist und in welcher.',
    // ── Zusammengesetzte Teile ──────────────────────────────────────────────────────────────────────
    404 => 'Teile, die zu einem Satz gehören und nur mit ihm bestehen — Stücklistenposition, Anschluss, Steckplatz.',
    3463 => 'Eine Position der Stückliste: Referenz auf der Platine, Bauteil, Anzahl, Seite und ob bestückt wird.',
    149000107689 => 'Steckplätze eines Mainboards: wie viele von welchem Bus.',
    149000107690 => 'Eine Jumper-Einstellung auf einem Board: welcher Jumper, wofür, in welcher Stellung.',
    149000108496 => 'Ein Abschnitt einer Seitenvorlage, mit Überschrift, Ebene, Art und Vorgabetext.',
    149000108575 => 'Belegung eines Pins: Signal und Aderfarbe.',
    149000108637 => 'Ein anderer Name für das Ding, und wie er dazu steht.',
    149000108721 => 'Ein Anschluss: welche Schnittstelle, wie viele, mit Hinweis.',
    149000108722 => 'Speichersteckplätze eines Mainboards: welche Modulart, wie viele.',
    149000108723 => 'Ein fest eingebauter Baustein: welche Funktion, welcher Chip.',
    149000108811 => 'Eine Datei zu einer Platinen-Revision, mit ihrer Kategorie.',
];

$gesamt = 0;

foreach (HILFEN as $knotenId => $text) {
    $bestand = $labels->storedFor($knotenId, IdentitySpace::Node);
    $hat     = false;
    $sprache = SettingsScreen::neutralLocale();

    foreach ($bestand as $label) {
        if ($label->role === SeededRole::Help && trim($label->text) !== '') {
            $hat = true;
        }
    }

    if ($hat) {
        echo "  #{$knotenId} hat schon eine Hilfe — bleibt\n";
        continue;
    }

    ++$gesamt;
    $schreiben && $labels->put(new Label($knotenId, IdentitySpace::Node, SeededRole::Help, Label::BASE_NUMBER, $sprache, $text));
}

echo "\n{$gesamt} Hilfetexte" . ($schreiben ? ' geschrieben.' : ' — nur gezeigt. Mit --write übernehmen.') . "\n";
