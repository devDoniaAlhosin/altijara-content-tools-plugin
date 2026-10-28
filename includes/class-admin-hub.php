<?php
/**
 * Admin hub — analytics, missing thumbs, recommendations.
 *
 * @package AltijaraContentTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Altijara_CT_Admin_Hub
 */
class Altijara_CT_Admin_Hub {

	const PAGE_SLUG = 'altijara-content-tools';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_altijara_ct_delete_empty_term', array( __CLASS__, 'handle_delete_empty_term' ) );
	}

	/**
	 * Top-level admin menu.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Content Tools', 'altijara-content-tools' ),
			__( 'Content Tools', 'altijara-content-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-analytics',
			62
		);
	}

	/**
	 * Whether current screen is this plugin's hub.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	public static function is_hub_screen( $hook ) {
		return false !== strpos( (string) $hook, self::PAGE_SLUG );
	}

	/**
	 * Enqueue theme admin CSS + plugin overlay/JS.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_hub = self::is_hub_screen( $hook );
		$on_list = ( 'edit.php' === $hook && $screen && 'post' === $screen->post_type );

		if ( ! $on_hub && ! $on_list ) {
			return;
		}

		$plugin_css = ALTIJARA_CT_DIR . 'assets/css/admin.css';
		$plugin_js  = ALTIJARA_CT_DIR . 'assets/js/admin.js';
		$css_ver    = file_exists( $plugin_css ) ? (string) filemtime( $plugin_css ) : ALTIJARA_CT_VERSION;
		$js_ver     = file_exists( $plugin_js ) ? (string) filemtime( $plugin_js ) : ALTIJARA_CT_VERSION;

		$deps = array();
		if ( $on_hub && defined( 'ALTIJARA_THEME_DIR' ) ) {
			$theme_css = ALTIJARA_THEME_DIR . '/assets/css/admin-sections.css';
			if ( file_exists( $theme_css ) ) {
				wp_enqueue_style(
					'altijara-admin-sections',
					trailingslashit( get_template_directory_uri() ) . 'assets/css/admin-sections.css',
					array(),
					(string) filemtime( $theme_css )
				);
				$deps[] = 'altijara-admin-sections';
			}
		}

		wp_enqueue_style(
			'altijara-ct-admin',
			ALTIJARA_CT_URI . 'assets/css/admin.css',
			$deps,
			$css_ver
		);

		if ( $on_hub ) {
			wp_enqueue_script(
				'altijara-ct-admin',
				ALTIJARA_CT_URI . 'assets/js/admin.js',
				array(),
				$js_ver,
				true
			);
			wp_localize_script(
				'altijara-ct-admin',
				'AltijaraCTAdmin',
				array(
					'bulkConfirm' => __( 'Assign language visibility to the listed posts?', 'altijara-content-tools' ),
					'bulkTooMany' => sprintf(
						/* translators: %d: max IDs */
						__( 'Too many IDs. Only the first %d will be processed. Continue?', 'altijara-content-tools' ),
						Altijara_CT_Bulk_Assign::max_ids()
					),
					'bulkEmpty'   => __( 'Enter at least one valid post ID.', 'altijara-content-tools' ),
				)
			);
		}
	}

	/**
	 * Tab definitions.
	 *
	 * @return array<string, array{label:string,hint:string,icon:string}>
	 */
	public static function tabs() {
		return array(
			'overview' => array(
				'label' => __( 'Overview', 'altijara-content-tools' ),
				'hint'  => __( 'Language visibility counts for published posts.', 'altijara-content-tools' ),
				'icon'  => 'dashicons-chart-bar',
			),
			'bulk'     => array(
				'label' => __( 'Bulk assign', 'altijara-content-tools' ),
				'hint'  => __( 'Set language visibility for many posts by ID list.', 'altijara-content-tools' ),
				'icon'  => 'dashicons-list-view',
			),
			'thumbs'   => array(
				'label' => __( 'Featured images', 'altijara-content-tools' ),
				'hint'  => __( 'Find published posts missing a featured image.', 'altijara-content-tools' ),
				'icon'  => 'dashicons-format-image',
			),
			'recommendations' => array(
				'label' => __( 'Recommendations', 'altijara-content-tools' ),
				'hint'  => __( 'Empty categories and other clean-up tips.', 'altijara-content-tools' ),
				'icon'  => 'dashicons-lightbulb',
			),
		);
	}

	/**
	 * Language visibility counts for published posts.
	 *
	 * @return array{total:int,both:int,ar:int,en:int}
	 */
	public static function lang_counts() {
		global $wpdb;

		$meta = ALTIJARA_CT_LANG_META;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS lang, COUNT(*) AS cnt
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = 'post'
				AND p.post_status = 'publish'
				AND pm.meta_key = %s
				GROUP BY pm.meta_value",
				$meta
			),
			ARRAY_A
		);

		$ar = 0;
		$en = 0;
		$both_explicit = 0;
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$lang = altijara_ct_sanitize_lang( $row['lang'] ?? '' );
				$cnt  = absint( $row['cnt'] ?? 0 );
				if ( 'ar' === $lang ) {
					$ar = $cnt;
				} elseif ( 'en' === $lang ) {
					$en = $cnt;
				} elseif ( 'both' === $lang ) {
					$both_explicit = $cnt;
				}
			}
		}

		$tagged = $ar + $en + $both_explicit;
		$both   = $both_explicit + max( 0, $total - $tagged );

		return array(
			'total' => $total,
			'both'  => $both,
			'ar'    => $ar,
			'en'    => $en,
		);
	}

	/**
	 * Empty category terms (count 0).
	 *
	 * @return WP_Term[]
	 */
	public static function empty_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			if ( (int) $term->count < 1 && (int) $term->term_id !== (int) get_option( 'default_category' ) ) {
				$out[] = $term;
			}
		}
		return $out;
	}

	/**
	 * Empty tags (count 0), capped.
	 *
	 * @return WP_Term[]
	 */
	public static function empty_tags() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'number'     => 50,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			if ( (int) $term->count < 1 ) {
				$out[] = $term;
			}
		}
		return $out;
	}

	/**
	 * Render hub page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs   = self::tabs();
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'overview';
		}

		$brand_logo = '';
		if ( function_exists( 'altijara_admin_brand_logo_url' ) ) {
			$brand_logo = (string) altijara_admin_brand_logo_url();
		}

		$counts   = self::lang_counts();
		$no_thumb = Altijara_CT_Missing_Thumbs::count();
		$empty_cats = self::empty_categories();
		$empty_tags = self::empty_tags();
		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		$deleted      = isset( $_GET['deleted'] ) ? sanitize_key( wp_unslash( $_GET['deleted'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bulk_result  = ( 'bulk' === $active ) ? Altijara_CT_Bulk_Assign::consume_result() : null;
		$empty_cat_n  = count( $empty_cats );
		?>
		<div class="wrap altijara-admin-wrap altijara-ct-wrap" id="altijara-ct-dashboard" data-active-tab="<?php echo esc_attr( $active ); ?>" data-base-url="<?php echo esc_url( $base_url ); ?>">
			<header class="altijara-admin-hero">
				<div class="altijara-admin-hero__brand">
					<?php if ( $brand_logo ) : ?>
						<img class="altijara-admin-logo" src="<?php echo esc_url( $brand_logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
					<?php else : ?>
						<span class="altijara-admin-logo-fallback"><?php bloginfo( 'name' ); ?></span>
					<?php endif; ?>
					<div class="altijara-admin-hero__text">
						<h1><?php esc_html_e( 'Content Tools', 'altijara-content-tools' ); ?></h1>
						<p><?php esc_html_e( 'Language visibility, featured images, and content health for Altijara.', 'altijara-content-tools' ); ?></p>
					</div>
				</div>
				<div class="altijara-admin-hero__meta">
					<span class="altijara-admin-badge">v<?php echo esc_html( ALTIJARA_CT_VERSION ); ?></span>
					<a class="altijara-admin-credit-link" href="https://o2nations.com/" target="_blank" rel="noopener">O2Nations</a>
				</div>
			</header>

			<?php if ( '1' === $deleted ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Empty term deleted.', 'altijara-content-tools' ); ?></p></div>
			<?php elseif ( '0' === $deleted ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not delete that term (not empty, protected, or permission denied).', 'altijara-content-tools' ); ?></p></div>
			<?php endif; ?>
			<?php Altijara_CT_Bulk_Assign::render_result_notice( $bulk_result ); ?>

			<div class="altijara-admin-shell">
				<aside class="altijara-admin-sidebar" aria-label="<?php esc_attr_e( 'Content tools sections', 'altijara-content-tools' ); ?>">
					<nav class="altijara-admin-nav">
						<?php foreach ( $tabs as $id => $tab ) :
							$on = ( $active === $id );
							?>
							<button type="button"
								class="altijara-nav-item <?php echo $on ? 'is-active' : ''; ?>"
								data-tab="<?php echo esc_attr( $id ); ?>"
								aria-controls="altijara-ct-panel-<?php echo esc_attr( $id ); ?>"
								aria-selected="<?php echo $on ? 'true' : 'false'; ?>"
								title="<?php echo esc_attr( $tab['hint'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
								<span class="altijara-nav-item__label"><?php echo esc_html( $tab['label'] ); ?></span>
							</button>
						<?php endforeach; ?>
					</nav>
				</aside>

				<div class="altijara-admin-main">
					<?php foreach ( $tabs as $id => $tab ) :
						$is_on = ( $active === $id );
						?>
						<section
							id="altijara-ct-panel-<?php echo esc_attr( $id ); ?>"
							class="altijara-panel <?php echo $is_on ? 'is-active' : ''; ?>"
							data-tab="<?php echo esc_attr( $id ); ?>"
							role="tabpanel"
							<?php echo $is_on ? '' : 'hidden'; ?>>
							<div class="altijara-panel__intro">
								<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
								<div>
									<strong class="altijara-panel__title"><?php echo esc_html( $tab['label'] ); ?></strong>
									<p><?php echo esc_html( $tab['hint'] ); ?></p>
								</div>
							</div>

							<?php if ( 'overview' === $id ) : ?>
								<?php self::render_overview( $counts, $no_thumb, $empty_cat_n ); ?>
							<?php elseif ( 'bulk' === $id ) : ?>
								<?php Altijara_CT_Bulk_Assign::render_panel(); ?>
							<?php elseif ( 'thumbs' === $id ) : ?>
								<?php self::render_thumbs( $no_thumb ); ?>
							<?php else : ?>
								<?php self::render_recommendations( $empty_cats, $empty_tags, $no_thumb ); ?>
							<?php endif; ?>
						</section>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Overview / analytics panel.
	 *
	 * @param array{total:int,both:int,ar:int,en:int} $counts      Counts.
	 * @param int                                     $no_thumb    Missing thumbs.
	 * @param int                                     $empty_cat_n Empty categories.
	 */
	private static function render_overview( $counts, $no_thumb = 0, $empty_cat_n = 0 ) {
		$cards = array(
			array(
				'key'   => 'total',
				'label' => __( 'Published posts', 'altijara-content-tools' ),
				'count' => $counts['total'],
				'url'   => admin_url( 'edit.php?post_type=post' ),
				'class' => 'altijara-ct-stat--total',
				'icon'  => 'dashicons-admin-post',
			),
			array(
				'key'   => 'both',
				'label' => __( 'Both languages', 'altijara-content-tools' ),
				'count' => $counts['both'],
				'url'   => admin_url( 'edit.php?post_type=post&altijara_lang=both' ),
				'class' => 'altijara-ct-stat--both',
				'icon'  => 'dashicons-translation',
			),
			array(
				'key'   => 'ar',
				'label' => __( 'Arabic only', 'altijara-content-tools' ),
				'count' => $counts['ar'],
				'url'   => admin_url( 'edit.php?post_type=post&altijara_lang=ar' ),
				'class' => 'altijara-ct-stat--ar',
				'icon'  => 'dashicons-flag',
			),
			array(
				'key'   => 'en',
				'label' => __( 'English only', 'altijara-content-tools' ),
				'count' => $counts['en'],
				'url'   => admin_url( 'edit.php?post_type=post&altijara_lang=en' ),
				'class' => 'altijara-ct-stat--en',
				'icon'  => 'dashicons-flag',
			),
		);
		$bulk_url  = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'bulk' ), admin_url( 'admin.php' ) );
		$thumbs_url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'thumbs' ), admin_url( 'admin.php' ) );
		$reco_url  = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'recommendations' ), admin_url( 'admin.php' ) );
		?>
		<div class="altijara-admin-block">
			<div class="altijara-ct-stat-grid">
				<?php foreach ( $cards as $card ) : ?>
					<a class="altijara-ct-stat-card <?php echo esc_attr( $card['class'] ); ?>" href="<?php echo esc_url( $card['url'] ); ?>">
						<span class="altijara-ct-stat-card__icon dashicons <?php echo esc_attr( $card['icon'] ); ?>" aria-hidden="true"></span>
						<span class="altijara-ct-stat-card__count"><?php echo esc_html( (string) $card['count'] ); ?></span>
						<span class="altijara-ct-stat-card__label"><?php echo esc_html( $card['label'] ); ?></span>
						<span class="altijara-ct-stat-card__hint"><?php esc_html_e( 'Open filtered list', 'altijara-content-tools' ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>

			<div class="altijara-ct-quick-row">
				<a class="altijara-ct-quick-chip" href="<?php echo esc_url( $bulk_url ); ?>">
					<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
					<?php esc_html_e( 'Bulk assign language', 'altijara-content-tools' ); ?>
				</a>
				<a class="altijara-ct-quick-chip <?php echo $no_thumb > 0 ? 'is-warn' : ''; ?>" href="<?php echo esc_url( $thumbs_url ); ?>">
					<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: count */
							__( 'Missing images (%d)', 'altijara-content-tools' ),
							(int) $no_thumb
						)
					);
					?>
				</a>
				<a class="altijara-ct-quick-chip <?php echo $empty_cat_n > 0 ? 'is-warn' : ''; ?>" href="<?php echo esc_url( $reco_url ); ?>">
					<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: count */
							__( 'Empty categories (%d)', 'altijara-content-tools' ),
							(int) $empty_cat_n
						)
					);
					?>
				</a>
			</div>

			<p class="description altijara-ct-footnote">
				<?php esc_html_e( 'Default for every post is Both. English-only posts are hidden on Arabic front-end listings (and vice versa). Direct post URLs still open.', 'altijara-content-tools' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Missing thumbs panel.
	 *
	 * @param int $count Count.
	 */
	private static function render_thumbs( $count ) {
		$url      = Altijara_CT_Missing_Thumbs::list_url();
		$bulk_url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'bulk' ), admin_url( 'admin.php' ) );
		?>
		<div class="altijara-admin-block">
			<div class="altijara-ct-health-hero <?php echo $count > 0 ? 'is-warn' : 'is-ok'; ?>">
				<span class="altijara-ct-health-hero__count"><?php echo esc_html( (string) $count ); ?></span>
				<div>
					<strong><?php echo esc_html( _n( 'published post without a featured image', 'published posts without a featured image', $count, 'altijara-content-tools' ) ); ?></strong>
					<p><?php esc_html_e( 'The theme hides these from public listings. Open the posts list (with Categories column) to fix them.', 'altijara-content-tools' ); ?></p>
				</div>
			</div>
			<div class="altijara-ct-action-row">
				<a class="button button-primary button-hero" href="<?php echo esc_url( $url ); ?>">
					<?php esc_html_e( 'View posts without featured image', 'altijara-content-tools' ); ?>
				</a>
				<a class="button button-secondary" href="<?php echo esc_url( $bulk_url ); ?>">
					<?php esc_html_e( 'Bulk assign language…', 'altijara-content-tools' ); ?>
				</a>
			</div>
			<div class="altijara-ct-tip-card altijara-ct-tip-card--inline">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<div>
					<strong><?php esc_html_e( 'Tip', 'altijara-content-tools' ); ?></strong>
					<p><?php esc_html_e( 'After opening the list, use the Language column or Bulk assign tab to set AR/EN visibility on the IDs you copy from that screen.', 'altijara-content-tools' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Recommendations panel.
	 *
	 * @param WP_Term[] $empty_cats Empty categories.
	 * @param WP_Term[] $empty_tags Empty tags.
	 * @param int       $no_thumb   Missing thumb count.
	 */
	private static function render_recommendations( $empty_cats, $empty_tags, $no_thumb ) {
		?>
		<div class="altijara-admin-block">
			<?php if ( $no_thumb > 0 ) : ?>
				<div class="altijara-ct-reco-callout">
					<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
					<div>
						<strong><?php esc_html_e( 'Missing featured images', 'altijara-content-tools' ); ?></strong>
						<p>
							<?php
							echo esc_html( sprintf(
								/* translators: %d: count */
								_n( '%d published post has no featured image.', '%d published posts have no featured image.', $no_thumb, 'altijara-content-tools' ),
								$no_thumb
							) );
							?>
							<a href="<?php echo esc_url( Altijara_CT_Missing_Thumbs::list_url() ); ?>"><?php esc_html_e( 'Review list', 'altijara-content-tools' ); ?></a>
						</p>
					</div>
				</div>
			<?php endif; ?>

			<h3 class="altijara-ct-reco-heading"><?php esc_html_e( 'Empty categories', 'altijara-content-tools' ); ?></h3>
			<?php if ( empty( $empty_cats ) ) : ?>
				<p class="description"><?php esc_html_e( 'No empty categories found (default category is never listed).', 'altijara-content-tools' ); ?></p>
			<?php else : ?>
				<table class="widefat striped altijara-ct-reco-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Category', 'altijara-content-tools' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'altijara-content-tools' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'altijara-content-tools' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $empty_cats as $term ) : ?>
							<tr>
								<td><?php echo esc_html( $term->name ); ?></td>
								<td><code><?php echo esc_html( $term->slug ); ?></code></td>
								<td class="altijara-ct-reco-actions">
									<a href="<?php echo esc_url( get_edit_term_link( $term->term_id, 'category' ) ); ?>"><?php esc_html_e( 'Edit', 'altijara-content-tools' ); ?></a>
									|
									<a href="<?php echo esc_url( admin_url( 'edit.php?category_name=' . rawurlencode( $term->slug ) ) ); ?>"><?php esc_html_e( 'View posts', 'altijara-content-tools' ); ?></a>
									|
									<?php
									$delete_url = wp_nonce_url(
										add_query_arg(
											array(
												'action'  => 'altijara_ct_delete_empty_term',
												'term_id' => (int) $term->term_id,
												'taxonomy'=> 'category',
											),
											admin_url( 'admin-post.php' )
										),
										'altijara_ct_delete_term_' . (int) $term->term_id
									);
									?>
									<a class="altijara-ct-danger" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this empty category?', 'altijara-content-tools' ) ); ?>');"><?php esc_html_e( 'Delete', 'altijara-content-tools' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3 class="altijara-ct-reco-heading"><?php esc_html_e( 'Unused tags', 'altijara-content-tools' ); ?></h3>
			<?php if ( empty( $empty_tags ) ) : ?>
				<p class="description"><?php esc_html_e( 'No unused tags found (showing up to 50).', 'altijara-content-tools' ); ?></p>
			<?php else : ?>
				<table class="widefat striped altijara-ct-reco-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tag', 'altijara-content-tools' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'altijara-content-tools' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'altijara-content-tools' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $empty_tags as $term ) : ?>
							<tr>
								<td><?php echo esc_html( $term->name ); ?></td>
								<td><code><?php echo esc_html( $term->slug ); ?></code></td>
								<td class="altijara-ct-reco-actions">
									<a href="<?php echo esc_url( get_edit_term_link( $term->term_id, 'post_tag' ) ); ?>"><?php esc_html_e( 'Edit', 'altijara-content-tools' ); ?></a>
									|
									<?php
									$delete_url = wp_nonce_url(
										add_query_arg(
											array(
												'action'   => 'altijara_ct_delete_empty_term',
												'term_id'  => (int) $term->term_id,
												'taxonomy' => 'post_tag',
											),
											admin_url( 'admin-post.php' )
										),
										'altijara_ct_delete_term_' . (int) $term->term_id
									);
									?>
									<a class="altijara-ct-danger" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this unused tag?', 'altijara-content-tools' ) ); ?>');"><?php esc_html_e( 'Delete', 'altijara-content-tools' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Delete empty term action.
	 */
	public static function handle_delete_empty_term() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'altijara-content-tools' ) );
		}

		$term_id  = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $term_id || ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'recommendations', 'deleted' => '0' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		check_admin_referer( 'altijara_ct_delete_term_' . $term_id );

		$ok = false;
		if ( 'category' === $taxonomy && (int) $term_id === (int) get_option( 'default_category' ) ) {
			$ok = false;
		} else {
			$term = get_term( $term_id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) && (int) $term->count < 1 ) {
				$result = wp_delete_term( $term_id, $taxonomy );
				$ok     = ! is_wp_error( $result ) && false !== $result;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'tab'     => 'recommendations',
					'deleted' => $ok ? '1' : '0',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
