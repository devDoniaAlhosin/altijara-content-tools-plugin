<?php
/**
 * Front-end language visibility — works with stock Altijara theme (no theme patches).
 *
 * Theme home sections call WP_Query inside altijara_query_posts() and cache IDs in
 * transients. This class:
 * 1. Purges those transients on front requests so EN/AR never share a stale list
 * 2. Injects include meta_query + over-fetch on every front post WP_Query
 * 3. Trims results to the requested count
 *
 * Optional theme filters (altijara_query_posts_*) are still honored if present.
 *
 * @package AltijaraContentTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Altijara_CT_Lang_Query
 */
class Altijara_CT_Lang_Query {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_admin_list' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_front_queries' ), 25 );
		add_action( 'wp', array( __CLASS__, 'purge_theme_section_cache' ), 1 );
		add_filter( 'rest_post_query', array( __CLASS__, 'filter_rest_args' ), 20, 2 );
		add_filter( 'posts_clauses', array( __CLASS__, 'posts_clauses_lang' ), 25, 2 );
		add_filter( 'the_posts', array( __CLASS__, 'filter_the_posts' ), 20, 2 );

		// Optional: if a customized theme exposes these, use them too.
		add_filter( 'altijara_cache_key_parts', array( __CLASS__, 'cache_key_parts' ), 10, 3 );
		add_filter( 'altijara_query_posts_args', array( __CLASS__, 'filter_theme_query_args' ), 10, 2 );
		add_filter( 'altijara_query_posts_results', array( __CLASS__, 'filter_theme_query_results' ), 10, 3 );
	}

	/**
	 * Opposite language to hide on the current view.
	 *
	 * @return string ''|ar|en
	 */
	public static function excluded_lang() {
		$current = altijara_ct_current_lang();
		if ( 'en' === $current ) {
			return 'ar';
		}
		if ( 'ar' === $current ) {
			return 'en';
		}
		return '';
	}

	/**
	 * Drop theme section caches on front views so language filtering always hits WP_Query.
	 *
	 * Stock theme caches post IDs without a language key; AR and EN would otherwise share lists.
	 */
	public static function purge_theme_section_cache() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( '' === self::excluded_lang() ) {
			return;
		}
		if ( ! function_exists( 'altijara_cache_purge_all' ) ) {
			return;
		}
		altijara_cache_purge_all();
	}

	/**
	 * Whether a post is allowed on the current language view.
	 *
	 * Allowed: both, missing meta, or exact current lang.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $current Current lang en|ar (optional).
	 * @return bool
	 */
	public static function post_allowed_on_view( $post_id, $current = null ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}
		if ( null === $current ) {
			$current = altijara_ct_current_lang();
		}
		$vis = altijara_ct_get_post_lang( $post_id );
		return ( 'both' === $vis || $vis === $current );
	}

	/**
	 * Build include meta_query: missing OR both OR current lang.
	 *
	 * @param string $current en|ar.
	 * @return array<int|string, mixed>
	 */
	public static function include_meta_query( $current ) {
		$current = in_array( $current, array( 'ar', 'en' ), true ) ? $current : 'ar';
		$meta    = ALTIJARA_CT_LANG_META;

		return array(
			'relation' => 'OR',
			array(
				'key'     => $meta,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => $meta,
				'value'   => 'both',
				'compare' => '=',
			),
			array(
				'key'     => $meta,
				'value'   => $current,
				'compare' => '=',
			),
		);
	}

	/**
	 * Merge include language meta_query into existing args.
	 *
	 * @param array<string, mixed> $args    Query args.
	 * @param string               $current en|ar.
	 * @return array<string, mixed>
	 */
	public static function merge_include_meta_query( array $args, $current ) {
		$lang_mq  = self::include_meta_query( $current );
		$existing = ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) ? $args['meta_query'] : array();

		if ( empty( $existing ) ) {
			$args['meta_query'] = $lang_mq;
			return $args;
		}

		$args['meta_query'] = array(
			'relation' => 'AND',
			$existing,
			$lang_mq,
		);
		return $args;
	}

	/**
	 * Whether a WP_Query should get language filtering on the front.
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	public static function should_filter_query( $query ) {
		if ( is_admin() ) {
			return false;
		}
		if ( ! ( $query instanceof WP_Query ) ) {
			return false;
		}
		if ( ! empty( $query->get( 'altijara_ct_skip_lang' ) ) ) {
			return false;
		}
		if ( $query->is_main_query() && $query->is_singular() ) {
			return false;
		}

		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) ) {
			return true;
		}
		$types = (array) $post_type;
		if ( in_array( 'any', $types, true ) ) {
			return true;
		}
		return in_array( 'post', $types, true );
	}

	/**
	 * Front WP_Query (main + theme secondary): include meta + over-fetch.
	 *
	 * Covers stock theme altijara_query_posts() which runs raw WP_Query with no filters.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_front_queries( $query ) {
		if ( ! self::should_filter_query( $query ) ) {
			return;
		}
		if ( '' === self::excluded_lang() ) {
			return;
		}
		// Already handled (e.g. optional theme args filter).
		if ( ! empty( $query->get( 'altijara_ct_lang_include' ) ) ) {
			return;
		}

		$current = altijara_ct_current_lang();
		$query->set( 'altijara_ct_lang_current', $current );
		$query->set( 'altijara_ct_lang_include', 1 );
		$query->set( 'altijara_ct_exclude_lang', self::excluded_lang() );

		$need = (int) $query->get( 'posts_per_page' );
		if ( $need < 1 ) {
			$need = 6;
		}
		$query->set( 'altijara_ct_lang_need', $need );
		if ( $need < 200 ) {
			$query->set( 'posts_per_page', min( 200, max( $need * 5, $need + 30 ) ) );
		}

		$args = array( 'meta_query' => $query->get( 'meta_query' ) );
		if ( ! is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = array();
		}
		$merged = self::merge_include_meta_query( $args, $current );
		$query->set( 'meta_query', $merged['meta_query'] );
	}

	/**
	 * Optional theme choke point: include meta_query + over-fetch.
	 *
	 * @param array<string, mixed> $args        Args.
	 * @param string               $cache_group Group.
	 * @return array<string, mixed>
	 */
	public static function filter_theme_query_args( $args, $cache_group = '' ) {
		unset( $cache_group );
		if ( ! is_array( $args ) ) {
			return array();
		}
		if ( is_admin() || ! empty( $args['altijara_ct_skip_lang'] ) ) {
			return $args;
		}

		$post_types = isset( $args['post_type'] ) ? (array) $args['post_type'] : array( 'post' );
		if ( ! in_array( 'post', $post_types, true ) && ! in_array( 'any', $post_types, true ) ) {
			return $args;
		}

		$current = altijara_ct_current_lang();
		$args['altijara_ct_lang_current'] = $current;
		$args['altijara_ct_lang_include'] = 1;
		$args['altijara_ct_exclude_lang'] = self::excluded_lang();

		$args = self::merge_include_meta_query( $args, $current );

		$need = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 6;
		if ( $need < 1 ) {
			$need = 6;
		}
		$args['altijara_ct_lang_need'] = $need;

		if ( $need < 200 ) {
			$args['posts_per_page'] = min( 200, max( $need * 5, $need + 30 ) );
		}

		return $args;
	}

	/**
	 * Optional theme results filter + trim.
	 *
	 * @param WP_Post[]            $posts       Posts.
	 * @param array<string, mixed> $args        Args.
	 * @param string               $cache_group Group.
	 * @return WP_Post[]
	 */
	public static function filter_theme_query_results( $posts, $args = array(), $cache_group = '' ) {
		unset( $cache_group );
		if ( ! is_array( $posts ) || is_admin() ) {
			return is_array( $posts ) ? $posts : array();
		}
		if ( is_array( $args ) && ! empty( $args['altijara_ct_skip_lang'] ) ) {
			return $posts;
		}

		$current = altijara_ct_current_lang();
		if ( is_array( $args ) && ! empty( $args['altijara_ct_lang_current'] ) ) {
			$cand = (string) $args['altijara_ct_lang_current'];
			if ( in_array( $cand, array( 'ar', 'en' ), true ) ) {
				$current = $cand;
			}
		}

		$out = array();
		foreach ( $posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}
			if ( 'post' === $post->post_type && ! self::post_allowed_on_view( $post->ID, $current ) ) {
				continue;
			}
			$out[] = $post;
		}

		$need = 0;
		if ( is_array( $args ) && isset( $args['altijara_ct_lang_need'] ) ) {
			$need = (int) $args['altijara_ct_lang_need'];
		}
		if ( $need > 0 && count( $out ) > $need ) {
			$out = array_slice( $out, 0, $need );
		}

		return $out;
	}

	/**
	 * Trim over-fetched front queries (theme sections + main archives).
	 *
	 * @param WP_Post[] $posts Posts.
	 * @param WP_Query  $query Query.
	 * @return WP_Post[]
	 */
	public static function filter_the_posts( $posts, $query ) {
		if ( ! is_array( $posts ) || ! ( $query instanceof WP_Query ) ) {
			return $posts;
		}
		if ( empty( $query->get( 'altijara_ct_lang_include' ) ) && ! ( $query->is_main_query() && self::should_filter_query( $query ) ) ) {
			return $posts;
		}
		if ( ! empty( $query->get( 'altijara_ct_skip_lang' ) ) ) {
			return $posts;
		}

		$current = $query->get( 'altijara_ct_lang_current' );
		if ( ! in_array( $current, array( 'ar', 'en' ), true ) ) {
			$current = altijara_ct_current_lang();
		}

		$out = array();
		foreach ( $posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}
			if ( 'post' === $post->post_type && ! self::post_allowed_on_view( $post->ID, $current ) ) {
				continue;
			}
			$out[] = $post;
		}

		$need = (int) $query->get( 'altijara_ct_lang_need' );
		if ( $need > 0 && count( $out ) > $need ) {
			$out = array_slice( $out, 0, $need );
		}

		return $out;
	}

	/**
	 * Admin posts list: filter by altijara_lang query arg.
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

		$lang = isset( $_GET['altijara_lang'] ) ? sanitize_key( wp_unslash( $_GET['altijara_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $lang, altijara_ct_lang_values(), true ) ) {
			return;
		}

		$query->set( 'altijara_ct_admin_lang', $lang );
	}

	/**
	 * SQL for admin list + fallback when meta_query was not applied.
	 *
	 * @param array<string, string> $clauses Clauses.
	 * @param WP_Query              $query   Query.
	 * @return array<string, string>
	 */
	public static function posts_clauses_lang( $clauses, $query ) {
		global $wpdb;

		if ( ! ( $query instanceof WP_Query ) ) {
			return $clauses;
		}

		$admin_lang = $query->get( 'altijara_ct_admin_lang' );
		if ( $admin_lang && in_array( $admin_lang, altijara_ct_lang_values(), true ) ) {
			$meta = ALTIJARA_CT_LANG_META;
			if ( 'both' === $admin_lang ) {
				$clauses['where'] .= $wpdb->prepare(
					" AND (
						NOT EXISTS (
							SELECT 1 FROM {$wpdb->postmeta} act_lang
							WHERE act_lang.post_id = {$wpdb->posts}.ID
							AND act_lang.meta_key = %s
						)
						OR EXISTS (
							SELECT 1 FROM {$wpdb->postmeta} act_lang2
							WHERE act_lang2.post_id = {$wpdb->posts}.ID
							AND act_lang2.meta_key = %s
							AND act_lang2.meta_value = %s
						)
					)",
					$meta,
					$meta,
					'both'
				);
			} else {
				$clauses['where'] .= $wpdb->prepare(
					" AND EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} act_lang
						WHERE act_lang.post_id = {$wpdb->posts}.ID
						AND act_lang.meta_key = %s
						AND act_lang.meta_value = %s
					)",
					$meta,
					$admin_lang
				);
			}
			return $clauses;
		}

		// meta_query already applied via pre_get_posts / theme args.
		if ( ! empty( $query->get( 'altijara_ct_lang_include' ) ) ) {
			return $clauses;
		}

		if ( ! self::should_filter_query( $query ) ) {
			return $clauses;
		}

		$current = $query->get( 'altijara_ct_lang_current' );
		if ( ! in_array( $current, array( 'ar', 'en' ), true ) ) {
			$current = altijara_ct_current_lang();
		}

		$meta = ALTIJARA_CT_LANG_META;
		$clauses['where'] .= $wpdb->prepare(
			" AND (
				{$wpdb->posts}.post_type <> 'post'
				OR NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} act_miss
					WHERE act_miss.post_id = {$wpdb->posts}.ID
					AND act_miss.meta_key = %s
				)
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} act_both
					WHERE act_both.post_id = {$wpdb->posts}.ID
					AND act_both.meta_key = %s
					AND act_both.meta_value = 'both'
				)
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} act_cur
					WHERE act_cur.post_id = {$wpdb->posts}.ID
					AND act_cur.meta_key = %s
					AND act_cur.meta_value = %s
				)
			)",
			$meta,
			$meta,
			$meta,
			$current
		);

		return $clauses;
	}

	/**
	 * REST: apply same include meta_query.
	 *
	 * @param array<string, mixed> $args    Query args.
	 * @param WP_REST_Request      $request Request.
	 * @return array<string, mixed>
	 */
	public static function filter_rest_args( $args, $request ) {
		unset( $request );
		if ( is_admin() || ! is_array( $args ) ) {
			return $args;
		}
		$current = altijara_ct_current_lang();
		$args['altijara_ct_lang_current'] = $current;
		$args['altijara_ct_lang_include'] = 1;
		return self::merge_include_meta_query( $args, $current );
	}

	/**
	 * Optional: include current language in theme cache keys (customized themes only).
	 *
	 * @param array<string, mixed> $parts Extra parts.
	 * @param string               $group Group.
	 * @param array<string, mixed> $args  Args.
	 * @return array<string, mixed>
	 */
	public static function cache_key_parts( $parts, $group, $args ) {
		unset( $group, $args );
		if ( ! is_array( $parts ) ) {
			$parts = array();
		}
		$parts['lang'] = altijara_ct_current_lang();
		return $parts;
	}
}
