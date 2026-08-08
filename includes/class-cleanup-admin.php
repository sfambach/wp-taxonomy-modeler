<?php
/**
 * Cleanup admin working page (v1 health shell).
 *
 * Taxonomy Tree submenu before Settings: shows model/host version conflict
 * count and links to Model versions to resolve. No purge / mapping yet.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Cleanup submenu (health summary only).
 */
final class Cleanup_Admin {

	public const PAGE_SLUG = 'wp-taxonomy-tree-cleanup';

	public static function register(): void {
		/* Priority 17: after Model versions (16), before Settings (20). */
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 17 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			Tree_Admin::PAGE_SLUG,
			__( 'Cleanup', 'wp-taxonomy-tree' ),
			__( 'Cleanup', 'wp-taxonomy-tree' ),
			'manage_categories',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page && false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		$ver = defined( 'WTT_VERSION' ) ? WTT_VERSION : '0.0.1';
		wp_enqueue_style(
			'wtt-cleanup-admin',
			WTT_PLUGIN_URL . 'assets/css/cleanup-admin.css',
			array(),
			$ver
		);
	}

	/**
	 * Count structure hosts that have at least one version conflict.
	 *
	 * Reuses {@see Model_Version::list_host_summaries()} conflict summaries.
	 */
	public static function count_hosts_with_conflicts( string $taxonomy = '' ): int {
		if ( ! class_exists( Model_Version::class ) ) {
			return 0;
		}

		$tax   = '' !== $taxonomy ? $taxonomy : Taxonomy::default_slug();
		$hosts = Model_Version::list_host_summaries( $tax );
		$count = 0;

		foreach ( $hosts as $host ) {
			if ( (int) ( $host['conflictCount'] ?? 0 ) > 0 ) {
				++$count;
			}
		}

		return $count;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-taxonomy-tree' ) );
		}

		$conflict_hosts = self::count_hosts_with_conflicts();
		$versions_url   = Model_Version_Admin::page_url();

		?>
		<div class="wrap wtt-cleanup">
			<h1><?php echo esc_html__( 'Cleanup', 'wp-taxonomy-tree' ); ?></h1>
			<p class="description">
				<?php
				echo esc_html__(
					'Health checks for taxonomy model data. This first step reports model version conflicts only — no automatic purge or mapping yet.',
					'wp-taxonomy-tree'
				);
				?>
			</p>

			<section class="wtt-cleanup__health" aria-labelledby="wtt-cleanup-health-heading">
				<h2 id="wtt-cleanup-health-heading"><?php echo esc_html__( 'Model version health', 'wp-taxonomy-tree' ); ?></h2>

				<?php if ( 0 === $conflict_hosts ) : ?>
					<p class="wtt-cleanup__ok">
						<?php echo esc_html__( 'No model version conflicts.', 'wp-taxonomy-tree' ); ?>
					</p>
				<?php else : ?>
					<p class="wtt-cleanup__conflict">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of structure hosts with version conflicts */
								_n(
									'%d model/host has version conflicts.',
									'%d models/hosts have version conflicts.',
									$conflict_hosts,
									'wp-taxonomy-tree'
								),
								$conflict_hosts
							)
						);
						?>
					</p>
				<?php endif; ?>

				<p class="wtt-cleanup__actions">
					<a class="button button-primary" href="<?php echo esc_url( $versions_url ); ?>">
						<?php echo esc_html__( 'Open Model versions', 'wp-taxonomy-tree' ); ?>
					</a>
				</p>
			</section>
		</div>
		<?php
	}
}
