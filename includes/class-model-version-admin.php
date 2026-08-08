<?php
/**
 * Model versions admin working page (UR-S1 shell).
 *
 * Taxonomy Tree submenu before Settings: list structure hosts with schema
 * version, instance stamps, and conflict counts. Mapping UI is a placeholder.
 * Deep-link: ?page=wp-taxonomy-tree-model-versions&host_id={id} focuses a row.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Model versions submenu + bump handler.
 */
final class Model_Version_Admin {

	public const PAGE_SLUG = 'wp-taxonomy-tree-model-versions';

	public const NONCE_ACTION = 'wtt_model_versions';

	public static function register(): void {
		/* Priority 16: after Fill Model Data (15), before Cleanup (17) / Settings (20). */
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 16 );
		add_action( 'admin_init', array( self::class, 'handle_bump' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			Tree_Admin::PAGE_SLUG,
			__( 'Model versions', 'wp-taxonomy-tree' ),
			__( 'Model versions', 'wp-taxonomy-tree' ),
			'manage_categories',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Admin URL for Model versions, optionally focused on a structure host.
	 */
	public static function page_url( int $host_id = 0 ): string {
		$args = array( 'page' => self::PAGE_SLUG );
		if ( $host_id > 0 ) {
			$args['host_id'] = $host_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Focus host id from request (`host_id` or legacy `structureId`).
	 */
	public static function request_focus_host_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['host_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return absint( wp_unslash( $_GET['host_id'] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['structureId'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return absint( wp_unslash( $_GET['structureId'] ) );
		}
		return 0;
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page && false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		$ver = defined( 'WTT_VERSION' ) ? WTT_VERSION : '0.0.1';
		wp_enqueue_style(
			'wtt-model-version-admin',
			WTT_PLUGIN_URL . 'assets/css/model-version-admin.css',
			array(),
			$ver
		);

		$focus = self::request_focus_host_id();
		if ( $focus > 0 ) {
			wp_enqueue_script(
				'wtt-model-version-admin',
				WTT_PLUGIN_URL . 'assets/js/model-version-admin.js',
				array(),
				$ver,
				true
			);
			wp_localize_script(
				'wtt-model-version-admin',
				'wttModelVersions',
				array(
					'focusHostId' => $focus,
				)
			);
		}
	}

	/**
	 * Manual schema bump (POST).
	 */
	public static function handle_bump(): void {
		if ( ! isset( $_POST['wtt_bump_model_version'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to bump model versions.', 'wp-taxonomy-tree' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$taxonomy     = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$structure_id = isset( $_POST['structure_id'] ) ? absint( wp_unslash( $_POST['structure_id'] ) ) : 0;

		if ( ! Taxonomy::is_scaffold( $taxonomy ) || $structure_id <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'wtt_err' => 'bad_host',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$term = get_term( $structure_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'wtt_err' => 'bad_host',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$new = Model_Version::bump( $taxonomy, $structure_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'host_id'       => (string) $structure_id,
					'wtt_bumped'    => '1',
					'wtt_version'   => (string) $new,
					'wtt_structure' => (string) $structure_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-taxonomy-tree' ) );
		}

		$taxonomy   = Taxonomy::default_slug();
		$hosts      = Model_Version::list_host_summaries( $taxonomy );
		$focus_host = self::request_focus_host_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bumped = isset( $_GET['wtt_bumped'] ) && '1' === (string) wp_unslash( $_GET['wtt_bumped'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$new_ver = isset( $_GET['wtt_version'] ) ? absint( wp_unslash( $_GET['wtt_version'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err = isset( $_GET['wtt_err'] ) ? sanitize_key( wp_unslash( $_GET['wtt_err'] ) ) : '';

		?>
		<div class="wrap wtt-model-versions">
			<h1><?php echo esc_html__( 'Model versions', 'wp-taxonomy-tree' ); ?></h1>
			<p class="description">
				<?php
				echo esc_html__(
					'Schema versions for structure hosts that hold Model_Data. Changing the model bumps a host version; instances are stamped with modelVersion on create/save. This page is a thin scaffold — conflict listing only; mapping/migration comes later (UR-S1).',
					'wp-taxonomy-tree'
				);
				?>
			</p>

			<?php if ( $bumped && $new_ver > 0 ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: new schema version number */
								__( 'Schema version bumped to %d. Existing instances keep their stamp until saved again (shown as conflicts).', 'wp-taxonomy-tree' ),
								$new_ver
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( 'bad_host' === $err ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html__( 'Could not bump version: invalid structure host.', 'wp-taxonomy-tree' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $focus_host > 0 ) : ?>
				<p class="description wtt-model-versions__focus-hint">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: structure host term id */
							__( 'Focused on structure host #%d (from conflict badge).', 'wp-taxonomy-tree' ),
							$focus_host
						)
					);
					?>
				</p>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Structure hosts', 'wp-taxonomy-tree' ); ?></h2>
			<?php if ( array() === $hosts ) : ?>
				<p class="description">
					<?php echo esc_html__( 'No structure hosts with attributes or Model_Data instances yet.', 'wp-taxonomy-tree' ); ?>
				</p>
			<?php else : ?>
				<table class="widefat striped wtt-model-versions__table">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Structure', 'wp-taxonomy-tree' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Attributes', 'wp-taxonomy-tree' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Schema version', 'wp-taxonomy-tree' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Instances', 'wp-taxonomy-tree' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'By stamp', 'wp-taxonomy-tree' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Conflicts', 'wp-taxonomy-tree' ); ?></th>
							<th scope="col" class="wtt-col-actions"><?php echo esc_html__( 'Actions', 'wp-taxonomy-tree' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $hosts as $host ) : ?>
							<?php
							$host_id    = (int) ( $host['id'] ?? 0 );
							$conflicts  = (int) ( $host['conflictCount'] ?? 0 );
							$row_class  = $conflicts > 0 ? 'wtt-model-versions__row--conflict' : '';
							if ( $focus_host > 0 && $host_id === $focus_host ) {
								$row_class .= ( '' !== $row_class ? ' ' : '' ) . 'wtt-model-versions__row--focus';
							}
							$stamp_bits = array();
							foreach ( (array) ( $host['countsByVersion'] ?? array() ) as $ver => $count ) {
								$stamp_bits[] = sprintf(
									/* translators: 1: version number, 2: instance count */
									__( 'v%1$d: %2$d', 'wp-taxonomy-tree' ),
									(int) $ver,
									(int) $count
								);
							}
							$stamp_label = array() === $stamp_bits
								? '—'
								: implode( ', ', $stamp_bits );
							$row_id = 'wtt-mv-host-' . (string) $host_id;
							?>
							<tr
								id="<?php echo esc_attr( $row_id ); ?>"
								class="<?php echo esc_attr( $row_class ); ?>"
								data-host-id="<?php echo esc_attr( (string) $host_id ); ?>"
								tabindex="-1"
							>
								<td>
									<strong><?php echo esc_html( (string) ( $host['name'] ?? '' ) ); ?></strong>
									<br />
									<span class="description"><?php echo esc_html( (string) ( $host['path'] ?? '' ) ); ?></span>
								</td>
								<td><?php echo esc_html( (string) (int) ( $host['attributeCount'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) (int) ( $host['schemaVersion'] ?? 1 ) ); ?></td>
								<td><?php echo esc_html( (string) (int) ( $host['instanceTotal'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( $stamp_label ); ?></td>
								<td>
									<?php if ( $conflicts > 0 ) : ?>
										<span class="wtt-conflict-badge" title="<?php echo esc_attr( (string) $conflicts ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: conflict count */ __( '%d model version conflicts', 'wp-taxonomy-tree' ), $conflicts ) ); ?>">!</span>
										<span class="wtt-model-versions__conflict">
											<?php echo esc_html( (string) $conflicts ); ?>
										</span>
									<?php else : ?>
										<?php echo esc_html( '0' ); ?>
									<?php endif; ?>
								</td>
								<td class="wtt-col-actions">
									<form method="post" action="<?php echo esc_url( self::page_url( $host_id ) ); ?>" class="wtt-model-versions__bump-form">
										<?php wp_nonce_field( self::NONCE_ACTION ); ?>
										<input type="hidden" name="taxonomy" value="<?php echo esc_attr( (string) ( $host['taxonomy'] ?? '' ) ); ?>" />
										<input type="hidden" name="structure_id" value="<?php echo esc_attr( (string) $host_id ); ?>" />
										<button type="submit" name="wtt_bump_model_version" value="1" class="button button-secondary">
											<?php echo esc_html__( 'Bump version', 'wp-taxonomy-tree' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php
					echo esc_html__(
						'Conflict = active instance whose modelVersion stamp differs from the host schema version. Saving an instance restamps it to the current schema (no field mapping yet).',
						'wp-taxonomy-tree'
					);
					?>
				</p>
			<?php endif; ?>

			<section class="wtt-model-versions__mapping" aria-labelledby="wtt-model-versions-mapping-heading">
				<h2 id="wtt-model-versions-mapping-heading"><?php echo esc_html__( 'Mapping', 'wp-taxonomy-tree' ); ?></h2>
				<div class="wtt-model-versions__mapping-stub">
					<p class="description">
						<?php
						echo esc_html__(
							'Placeholder for a future mapping UI (rename / type change / default fill when migrating instances across schema versions). Not implemented in this scaffold.',
							'wp-taxonomy-tree'
						);
						?>
					</p>
				</div>
			</section>
		</div>
		<?php
	}
}
