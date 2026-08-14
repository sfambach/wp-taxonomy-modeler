<?php
/**
 * Model versions admin working page (UR-S1).
 *
 * Taxonomy Tree submenu before Cleanup / Settings:
 * 1) Chooser — pick a structure host (Model schema) with version/instance data
 * 2) Detail — stacked generations (newest first) + bump
 *
 * Selection SoT = structure host (Q98), not instance / Position.
 * Deep-link: ?page=wp-taxonomy-tree-model-versions&host_id={id}
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

	/**
	 * Manual schema bump (POST) — stays on host detail.
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

		$new = Model_Version::bump( $taxonomy, $structure_id, 'manual' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'host_id'     => (string) $structure_id,
					'wtt_bumped'  => '1',
					'wtt_version' => (string) $new,
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
		$focus_host = self::request_focus_host_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bumped = isset( $_GET['wtt_bumped'] ) && '1' === (string) wp_unslash( $_GET['wtt_bumped'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$new_ver = isset( $_GET['wtt_version'] ) ? absint( wp_unslash( $_GET['wtt_version'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err = isset( $_GET['wtt_err'] ) ? sanitize_key( wp_unslash( $_GET['wtt_err'] ) ) : '';

		$detail = null;
		if ( $focus_host > 0 ) {
			$detail = Model_Version::get_host_detail( $taxonomy, $focus_host );
			if ( null === $detail ) {
				$err    = 'bad_host';
				$detail = null;
			}
		}

		?>
		<div class="wrap wtt-model-versions">
			<h1><?php echo esc_html__( 'Model versions', 'wp-taxonomy-tree' ); ?></h1>
			<p class="description">
				<?php
				echo esc_html__(
					'Pick a structure host (Model schema) to inspect its schema generation history. Instance stamps (modelVersion) are compared to the host schema version. Mapping / field migration comes later (Q98 / UR-S1).',
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
					<p><?php echo esc_html__( 'Invalid or unknown structure host.', 'wp-taxonomy-tree' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( null !== $detail ) : ?>
				<?php self::render_detail( $detail ); ?>
			<?php else : ?>
				<?php self::render_chooser( Model_Version::list_host_summaries( $taxonomy ) ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * List / chooser: structure hosts with version or instance data.
	 *
	 * @param list<array<string,mixed>> $hosts
	 */
	private static function render_chooser( array $hosts ): void {
		?>
		<h2><?php echo esc_html__( 'Select a structure host', 'wp-taxonomy-tree' ); ?></h2>
		<p class="description">
			<?php
			echo esc_html__(
				'Selection is the Model structure host (schema), not a single instance or BOM Position. Open a host to see stacked schema versions and conflicts.',
				'wp-taxonomy-tree'
			);
			?>
		</p>
		<?php if ( array() === $hosts ) : ?>
			<p class="description">
				<?php echo esc_html__( 'No structure hosts with attributes or Model_Data instances yet.', 'wp-taxonomy-tree' ); ?>
			</p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped wtt-model-versions__table wtt-row-edit-table">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Structure', 'wp-taxonomy-tree' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Attributes', 'wp-taxonomy-tree' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Schema version', 'wp-taxonomy-tree' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Instances', 'wp-taxonomy-tree' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Conflicts', 'wp-taxonomy-tree' ); ?></th>
					<th scope="col" class="wtt-col-actions"><?php echo esc_html__( 'Actions', 'wp-taxonomy-tree' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $hosts as $host ) : ?>
					<?php
					$host_id   = (int) ( $host['id'] ?? 0 );
					$conflicts = (int) ( $host['conflictCount'] ?? 0 );
					$row_class = $conflicts > 0 ? 'wtt-model-versions__row--conflict' : '';
					$detail_url = self::page_url( $host_id );
					?>
					<tr
						id="<?php echo esc_attr( 'wtt-mv-host-' . (string) $host_id ); ?>"
						class="<?php echo esc_attr( $row_class ); ?>"
						data-host-id="<?php echo esc_attr( (string) $host_id ); ?>"
					>
						<td>
							<a href="<?php echo esc_url( $detail_url ); ?>">
								<strong><?php echo esc_html( (string) ( $host['name'] ?? '' ) ); ?></strong>
							</a>
							<br />
							<span class="description"><?php echo esc_html( (string) ( $host['path'] ?? '' ) ); ?></span>
						</td>
						<td><?php echo esc_html( (string) (int) ( $host['attributeCount'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) (int) ( $host['schemaVersion'] ?? 1 ) ); ?></td>
						<td><?php echo esc_html( (string) (int) ( $host['instanceTotal'] ?? 0 ) ); ?></td>
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
							<a class="button button-primary" href="<?php echo esc_url( $detail_url ); ?>">
								<?php echo esc_html__( 'View versions', 'wp-taxonomy-tree' ); ?>
							</a>
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
		<?php
	}

	/**
	 * Detail: stacked versions for one structure host.
	 *
	 * @param array<string,mixed> $detail
	 */
	private static function render_detail( array $detail ): void {
		$host_id   = (int) ( $detail['id'] ?? 0 );
		$conflicts = (int) ( $detail['conflictCount'] ?? 0 );
		$versions  = (array) ( $detail['versions'] ?? array() );
		?>
		<p class="wtt-model-versions__back">
			<a href="<?php echo esc_url( self::page_url() ); ?>">
				&larr; <?php echo esc_html__( 'Back to structure hosts', 'wp-taxonomy-tree' ); ?>
			</a>
		</p>

		<header class="wtt-model-versions__detail-head">
			<h2>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: structure host name */
						__( 'Versions for %s', 'wp-taxonomy-tree' ),
						(string) ( $detail['name'] ?? '' )
					)
				);
				?>
			</h2>
			<?php if ( '' !== (string) ( $detail['path'] ?? '' ) ) : ?>
				<p class="description"><?php echo esc_html( (string) $detail['path'] ); ?></p>
			<?php endif; ?>
			<ul class="wtt-model-versions__meta">
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: schema version */
							__( 'Current schema version: %d', 'wp-taxonomy-tree' ),
							(int) ( $detail['schemaVersion'] ?? 1 )
						)
					);
					?>
				</li>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: attribute count */
							__( 'Attributes: %d', 'wp-taxonomy-tree' ),
							(int) ( $detail['attributeCount'] ?? 0 )
						)
					);
					?>
				</li>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: instance count */
							__( 'Instances: %d', 'wp-taxonomy-tree' ),
							(int) ( $detail['instanceTotal'] ?? 0 )
						)
					);
					?>
				</li>
				<li>
					<?php if ( $conflicts > 0 ) : ?>
						<span class="wtt-conflict-badge" aria-hidden="true">!</span>
						<span class="wtt-model-versions__conflict">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: conflict count */
									__( 'Conflicts: %d', 'wp-taxonomy-tree' ),
									$conflicts
								)
							);
							?>
						</span>
					<?php else : ?>
						<?php echo esc_html__( 'Conflicts: 0', 'wp-taxonomy-tree' ); ?>
					<?php endif; ?>
				</li>
			</ul>

			<form method="post" action="<?php echo esc_url( self::page_url( $host_id ) ); ?>" class="wtt-model-versions__bump-form">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="taxonomy" value="<?php echo esc_attr( (string) ( $detail['taxonomy'] ?? '' ) ); ?>" />
				<input type="hidden" name="structure_id" value="<?php echo esc_attr( (string) $host_id ); ?>" />
				<button type="submit" name="wtt_bump_model_version" value="1" class="button button-secondary">
					<?php echo esc_html__( 'Bump version', 'wp-taxonomy-tree' ); ?>
				</button>
				<span class="description">
					<?php echo esc_html__( 'Manual bump — structural attribute edits also bump automatically.', 'wp-taxonomy-tree' ); ?>
				</span>
			</form>
		</header>

		<section class="wtt-model-versions__stack" aria-labelledby="wtt-model-versions-stack-heading">
			<h3 id="wtt-model-versions-stack-heading"><?php echo esc_html__( 'Version history', 'wp-taxonomy-tree' ); ?></h3>
			<p class="description">
				<?php
				echo esc_html__(
					'Newest schema generation on top. Dates appear for bumps recorded after the history log was introduced; older generations may be inferred from the current schema or instance stamps.',
					'wp-taxonomy-tree'
				);
				?>
			</p>

			<?php if ( array() === $versions ) : ?>
				<p class="description"><?php echo esc_html__( 'No version entries for this host yet.', 'wp-taxonomy-tree' ); ?></p>
			<?php else : ?>
				<ol class="wtt-model-versions__stack-list">
					<?php foreach ( $versions as $entry ) : ?>
						<?php
						$ver           = (int) ( $entry['version'] ?? 0 );
						$is_current    = ! empty( $entry['isCurrent'] );
						$is_conflict   = ! empty( $entry['isConflict'] );
						$instance_n    = (int) ( $entry['instanceCount'] ?? 0 );
						$known_date    = ! empty( $entry['knownDate'] );
						$bumped_at     = (string) ( $entry['bumpedAt'] ?? '' );
						$source_label  = self::source_label( (string) ( $entry['source'] ?? '' ) );
						$card_classes  = array( 'wtt-model-versions__card' );
						if ( $is_current ) {
							$card_classes[] = 'wtt-model-versions__card--current';
						}
						if ( $is_conflict ) {
							$card_classes[] = 'wtt-model-versions__card--conflict';
						}
						$card_id = 'wtt-mv-ver-' . (string) $ver;
						?>
						<li
							id="<?php echo esc_attr( $card_id ); ?>"
							class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>"
							<?php echo $is_current ? 'data-current="1"' : ''; ?>
						>
							<div class="wtt-model-versions__card-head">
								<strong class="wtt-model-versions__card-version">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: model schema version number */
											__( 'Version %d', 'wp-taxonomy-tree' ),
											$ver
										)
									);
									?>
								</strong>
								<?php if ( $is_current ) : ?>
									<span class="wtt-model-versions__pill wtt-model-versions__pill--current">
										<?php echo esc_html__( 'Current schema', 'wp-taxonomy-tree' ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $is_conflict ) : ?>
									<span class="wtt-model-versions__pill wtt-model-versions__pill--conflict">
										<?php echo esc_html__( 'Instances behind schema', 'wp-taxonomy-tree' ); ?>
									</span>
								<?php endif; ?>
							</div>
							<ul class="wtt-model-versions__card-meta">
								<li>
									<?php
									if ( $known_date && '' !== $bumped_at ) {
										echo esc_html(
											sprintf(
												/* translators: %s: UTC datetime */
												__( 'Bumped: %s (UTC)', 'wp-taxonomy-tree' ),
												$bumped_at
											)
										);
									} else {
										echo esc_html__( 'Bumped: date unknown', 'wp-taxonomy-tree' );
									}
									?>
								</li>
								<li>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: bump source label */
											__( 'Source: %s', 'wp-taxonomy-tree' ),
											$source_label
										)
									);
									?>
								</li>
								<li>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: instance count stamped at this version */
											__( 'Instances on this stamp: %d', 'wp-taxonomy-tree' ),
											$instance_n
										)
									);
									?>
								</li>
							</ul>
							<?php if ( $is_current ) : ?>
								<p class="description wtt-model-versions__card-note">
									<?php
									echo esc_html__(
										'Schema stamp only in this scaffold — full attribute snapshot / diff summary is not stored yet.',
										'wp-taxonomy-tree'
									);
									?>
								</p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>

		<section class="wtt-model-versions__mapping" aria-labelledby="wtt-model-versions-mapping-heading">
			<h3 id="wtt-model-versions-mapping-heading"><?php echo esc_html__( 'Mapping', 'wp-taxonomy-tree' ); ?></h3>
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
		<?php
	}

	/**
	 * Human label for a bump source key.
	 */
	private static function source_label( string $source ): string {
		switch ( $source ) {
			case 'manual':
				return __( 'Manual bump', 'wp-taxonomy-tree' );
			case 'structural':
				return __( 'Structural attribute edit', 'wp-taxonomy-tree' );
			case 'seed':
				return __( 'Initial schema', 'wp-taxonomy-tree' );
			case 'backfill':
				return __( 'Inferred (current schema)', 'wp-taxonomy-tree' );
			case 'instance_stamp':
				return __( 'Inferred from instance stamps', 'wp-taxonomy-tree' );
			default:
				return __( 'Unknown', 'wp-taxonomy-tree' );
		}
	}
}
