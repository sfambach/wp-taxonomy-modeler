<?php
/**
 * One-time repair: stripped JSON unicode escapes in term / Relation names.
 *
 * Symptom: "Währung" stored/displayed as "Wu00e4hrung" (backslash lost from `\u00e4`
 * when wp_json_encode output passed through update_*_meta without wp_slash).
 *
 * Usage (WordPress root, Laragon PHP + wp-cli):
 *   wp eval-file C:\devel\wordpress\source\wp-taxonomy-tree\scripts\repair-stripped-unicode-names.php
 *
 * Safe to re-run. Clears the one-shot option so ensure can skip after success.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via wp eval-file.\n" );
	exit( 1 );
}

use WTT\Json_Meta;
use WTT\Taxonomy;

$taxonomy = Taxonomy::FS;
$result   = Json_Meta::repair_taxonomy( $taxonomy );
update_option( Json_Meta::OPTION_UNICODE_REPAIRED, 1, false );

echo 'taxonomy=' . $taxonomy . "\n";
echo 'edges=' . (int) $result['edges'] . "\n";
echo 'terms=' . (int) $result['terms'] . "\n";
echo 'repaired=' . ( ! empty( $result['repaired'] ) ? 'yes' : 'no' ) . "\n";
echo "ok\n";
