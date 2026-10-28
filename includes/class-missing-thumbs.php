<?php
/**
 * Posts without featured image — admin list filter + counts.
 *
 * @package AltijaraContentTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Altijara_CT_Missing_Thumbs
 */
class Altijara_CT_Missing_Thumbs {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_admin_list' ), 21 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'list_filter' ), 11 );
		add_filter( 'manage_post_posts_columns', array( __CLASS__, 'ensure_categories_column' ), 20 );
	}

	/**
	 * Admin URL for the no-thumb posts list.
	 *
	 * @return string
	 */
	public static function list_url() {
		return add_query_arg(
			array(
				'post_type'         => 'post',
				'altijara_no_thumb' => '1',
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Count published posts missing a featured image.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			WHERE p.post_type = 'post'
			AND p.post_status = 'publish'
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID
				AND pm.meta_key = '_thumbnail_id'
				AND pm.meta_value <> ''
				AND pm.meta_value <> '0'
			)"
		);

		return absint( $count );
	}

	/**
	 * Dropdown + checkbox-style filter on posts list.
	 *
	 * @param string $post_type Post type.
	 */
	public static function list_filter( $post_type ) {
		if ( 'post' !== $post_type ) {
			return;
		}
		$on = isset( $_GET['altijara_no_thumb'] ) && '1' === (string) wp_unslash( $_GET['altijara_no_thumb'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<label class="altijara-ct-no-thumb-filter" style="margin-inline-start:6px;display:inline-flex;align-items:center;gap:4px;">';
		printf(
			'<input type="checkbox" name="altijara_no_thumb" value="1" %s />',
			checked( $on, true, false )
		);
		echo esc_html__( 'No featured image', 'altijara-content-tools' );
		echo '</label>';
	}

	/**
	 * Apply no-thumb filter on admin posts list.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_admin_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		$post_type = $query->get( 'post_type' );
		if ( $post_type && 'post' !== $post_type ) {
			return;
		}
		if ( empty( $_GET['altijara_no_thumb'] ) || '1' !== (string) wp_unslash( $_GET['altijara_no_thumb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_thumbnail_id',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_thumbnail_id',
				'value'   => '',
				'compare' => '=',
			),
			array(
				'key'     => '_thumbnail_id',
				'value'   => '0',
				'compare' => '=',
			),
		);
		$query->set( 'meta_query', $meta_query );
		// Theme front filter must not apply in admin; allow thumbs missing.
		$query->set( 'altijara_allow_no_thumb', true );
	}

	/**
	 * Keep Categories column visible (WP default) when filtering.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function ensure_categories_column( $columns ) {
		if ( ! isset( $columns['categories'] ) ) {
			$columns['categories'] = __( 'Categories', 'altijara-content-tools' );
		}
		return $columns;
	}
}
