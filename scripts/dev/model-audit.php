<?php declare(strict_types=1);

/**
 * The whole model, looked over — the owner: *check the whole tree.*
 *
 * ⚠️ **This is not a test of code but a look at the data**, and it exists because a day of building
 * left real damage in a real database: five empty setting rows on a node minutes old, three
 * `persistent = 0` rows nobody had set, `int` missing a bound it had carried in the morning, and two
 * stray nodes in the trash. **None of it was findable by a green test run.**
 *
 * ⚠️ *It only ever **reports**. A repair that runs itself is how the last damage got in.*
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\SettingKey;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);

global $wpdb;

$prefix   = $wpdb->prefix . 'taxmod_';
$findings = 0;

function say(string $what, int $count, string $detail = ''): void
{
    global $findings;

    if ($count > 0) {
        $findings += $count;
    }

    printf("  %s %-52s %s\n", $count === 0 ? 'ok  ' : '!!  ', $what, $count === 0 ? '' : $count . ($detail === '' ? '' : '  ' . $detail));
}

$plugin    = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
$editor    = (new ReflectionMethod(Plugin::class, 'editor'))->invoke($plugin);
$screen    = (new ReflectionMethod(Plugin::class, 'screen'))->invoke($plugin);

$property = (new ReflectionObject($screen))->getProperty('framework');
$property->setAccessible(true);
$framework = $property->getValue($screen);

$installation = $framework->installationId();
$trash        = $framework->trash();

echo "== Umfang ==\n";
printf("  Knoten        %s\n", $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}nodes"));
printf("  Kanten        %s\n", $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}relations"));
printf("  Settings      %s\n", $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}settings"));
printf("  Labels        %s\n", $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}labels"));
printf("  Datensaetze   %s\n", $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}node_records"));
printf("  Changelog     %s\n", $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}changelog"));

echo "\n== Settings ==\n";

// ⚠️ **An empty row is not «unset»** — it is a row claiming an answer it does not have, and the
// resolver treats it as *set here*, which stops the chain. That is how five of them on a fresh node
// made `persistent` unsettable.
say(
    'Zeilen ohne jeden Wert',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}settings WHERE value_int IS NULL AND value_decimal IS NULL AND value_text IS NULL AND value_date IS NULL AND value_ref IS NULL"),
    'leer gespeichert, gilt aber als hier gesetzt'
);

$known = array_map(static fn (SettingKey $k): string => $k->value, SettingKey::cases());
$free  = ['label_role'];
$in    = "'" . implode("','", array_merge($known, $free)) . "'";

$unknown = $wpdb->get_col("SELECT DISTINCT setting_key FROM {$prefix}settings WHERE setting_key NOT IN ({$in})");

say('Schluessel, die weder Motor noch bekannt frei sind', count($unknown), implode(', ', $unknown));

// A setting must belong to a node, an edge, or the installation — nothing else has an identity.
say(
    'Settings an einem Besitzer, den es nicht gibt',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}settings s WHERE s.owner_id <> {$installation} AND NOT EXISTS (SELECT 1 FROM {$prefix}nodes n WHERE n.id = s.owner_id) AND NOT EXISTS (SELECT 1 FROM {$prefix}relations r WHERE r.id = s.owner_id)")
);

echo "\n== Baum ==\n";

// ⚠️ `path` is derived and rebuildable ([D-014]) — so a path that does not end in its own id is a
// materialisation that drifted, which makes the indexed walk lie.
say(
    'Knoten, deren Pfad nicht auf die eigene Id endet',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}nodes WHERE path NOT LIKE CONCAT('%.', id) AND path <> CAST(id AS CHAR)")
);

say(
    'Kanten, die auf einen fehlenden Knoten zeigen',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}relations r WHERE NOT EXISTS (SELECT 1 FROM {$prefix}nodes n WHERE n.id = r.to_node_id) OR NOT EXISTS (SELECT 1 FROM {$prefix}nodes n2 WHERE n2.id = r.from_node_id)")
);

$parked = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}nodes WHERE path LIKE %s", $trash->path . '.%'));

printf("  --   %-52s %d\n", 'im Trash geparkt (kein Fehler, nur zur Kenntnis)', $parked);

echo "\n== Datensaetze ==\n";

say(
    'Datensaetze, deren Modell fehlt',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}node_records rc WHERE NOT EXISTS (SELECT 1 FROM {$prefix}nodes n WHERE n.id = rc.node_id)")
);

say(
    'Werte, deren Datensatz fehlt',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}relation_records v WHERE NOT EXISTS (SELECT 1 FROM {$prefix}node_records rc WHERE rc.id = v.node_record_id)")
);

say(
    'Werte, deren Kante fehlt',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}relation_records v WHERE NOT EXISTS (SELECT 1 FROM {$prefix}relations r WHERE r.id = v.relation_id)")
);

echo "\n== Changelog ==\n";

// D-081: every object has at least one changelog item, because `creation_date` is read from it.
say(
    'Knoten ohne einen einzigen Changelog-Eintrag',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}nodes n WHERE NOT EXISTS (SELECT 1 FROM {$prefix}changelog c WHERE c.owner_id = n.id)")
);

$kinds = $wpdb->get_col("SELECT DISTINCT owner_kind FROM {$prefix}changelog");

printf("  --   %-52s %s\n", 'owner_kind kommt vor als', implode(', ', $kinds));

$settingEntries = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}changelog WHERE what LIKE 'setting %'");
$settingRows    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}settings");

printf("  --   %-52s %d Eintraege zu %d Zeilen\n", 'Settings im Changelog (seit D-403)', $settingEntries, $settingRows);
echo "       Aeltere Setting-Zeilen haben keinen Eintrag und bekommen keinen — ein Journal wird\n";
echo "       nicht rueckwirkend geschrieben.\n";

echo "\n== Duplikate mit gleichem Namen unter demselben Vater ==\n";

// ⚠️ Names are **not unique** ([D-022]) and duplicates are legitimate — but two siblings with the same
// name are worth seeing, because a duplicate act now makes them on purpose.
$twins = $wpdb->get_results(
    "SELECT SUBSTRING_INDEX(path, '.', LENGTH(path) - LENGTH(REPLACE(path, '.', ''))) AS parent, name, COUNT(*) c
     FROM {$prefix}nodes GROUP BY parent, name HAVING c > 1 ORDER BY c DESC LIMIT 10",
    ARRAY_A
);

if ($twins === []) {
    echo "  ok   keine\n";
} else {
    foreach ($twins as $one) {
        printf("  --   %-30s %sx unter %s\n", $one['name'], $one['c'], $one['parent']);
    }
}

echo "\n", $findings === 0 ? "all green\n" : "{$findings} Auffaelligkeiten\n";

// ⚠️ Exit 0 either way: this reports on **data**, and a person's model is allowed to be surprising.
// A non-zero exit would make it a gate on a boundary run it has no business failing.
exit(0);
