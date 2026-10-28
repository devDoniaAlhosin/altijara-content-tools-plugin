<?php
/**
 * Front-end language visibility filtering + cache key language part.
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
		add_filter( 'rest_post_query', array( __CLASS__, 'filter_rest_args' ), 20, 2 );
		add_filter( 'altijara_cache_key_parts', array( __CLASS__, 'cache_key_parts' ), 10, 3 );
		add_filter( 'posts_clauses', array( __CLASS__, 'posts_clauses_lang' ), 25, 2 );
	}

	/**
	 * Language code excluded on the current front view (opposite of current).
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
	 * Whether a WP_Query should receive language filtering.
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	public static function should_filter_query( $query ) {
		// Admin UI + admin-ajax (Section Manager pickers): never language-filter.
		// Front listings use SSR / REST instead.
		if ( is_admin() ) {
			return false;
		}
		if ( ! empty( $query->get( 'altijara_ct_skip_lang' ) ) ) {
			return false;
		}
		// Singles: allow direct URL access regardless of language.
		if ( $query->is_main_query() && $query->is_singular() ) {
			return false;
		}

		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) ) {
			// Main category/home/search often leave post_type empty (= post).
			return true;
		}
		$types = (array) $post_type;
		if ( in_array( 'any', $types, true ) ) {
			return true;
		}
		return in_array( 'post', $types, true );
	}

	/**
	 * Mark front queries so posts_clauses can exclude the opposite language.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_front_queries( $query ) {
		if ( ! self::should_filter_query( $query ) ) {
			return;
		}
		$exclude = self::excluded_lang();
		if ( '' === $exclude ) {
			return;
		}
		$query->set( 'altijara_ct_exclude_lang', $exclude );
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
	 * SQL exclusion for language meta (front) or exact match (admin list).
	 *
	 * Missing meta is treated as "both".
	 *
	 * @param array<string, string> $clauses Clauses.
	 * @param WP_Query              $query   Query.
	 * @return array<string, string>
	 */
	public static function posts_clauses_lang( $clauses, $query ) {
		global $wpdb;

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

		$exclude = $query->get( 'altijara_ct_exclude_lang' );
		if ( ! $exclude || ! in_array( $exclude, array( 'ar', 'en' ), true ) ) {
			return $clauses;
		}

		// Only constrain rows that are posts; leave other post types in mixed queries alone.
		$meta = ALTIJARA_CT_LANG_META;
		$clauses['where'] .= $wpdb->prepare(
			" AND (
				{$wpdb->posts}.post_type <> 'post'
				OR NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} act_xlang
					WHERE act_xlang.post_id = {$wpdb->posts}.ID
					AND act_xlang.meta_key = %s
					AND act_xlang.meta_value = %s
				)
			)",
			$meta,
			$exclude
		);

		return $clauses;
	}

	/**
	 * REST post collection: mark for language exclusion via posts_clauses.
	 *
	 * @param array<string, mixed> $args    Query args.
	 * @param WP_REST_Request      $request Request.
	 * @return array<string, mixed>
	 */
	public static function filter_rest_args( $args, $request ) {
		unset( $request );
		if ( is_admin() ) {
			return $args;
		}
		$exclude = self::excluded_lang();
		if ( '' === $exclude ) {
			return $args;
		}
		$args['altijara_ct_exclude_lang'] = $exclude;
		return $args;
	}

	/**
	 * Include current language in theme cache keys.
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
