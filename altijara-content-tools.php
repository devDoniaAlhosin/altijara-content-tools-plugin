<?php
/**
 * Plugin Name: Altijara Content Tools
 * Description: Language visibility (AR/EN/Both) for TranslatePress, missing featured-image tools, and content health recommendations — designed for the Altijara theme.
 * Version: 1.1.8
 * Author: O2Nations
 * Author URI: https://o2nations.com/
 * Text Domain: altijara-content-tools
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package AltijaraContentTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALTIJARA_CT_VERSION', '1.1.8' );
define( 'ALTIJARA_CT_FILE', __FILE__ );
define( 'ALTIJARA_CT_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALTIJARA_CT_URI', plugin_dir_url( __FILE__ ) );
define( 'ALTIJARA_CT_LANG_META', '_altijara_lang_visibility' );

/**
 * Whether the Altijara theme (or a child) is active.
 *
 * @return bool
 */
function altijara_ct_theme_active() {
	$template = (string) get_template();
	return 'altijara-theme' === $template || 'altijara' === $template;
}

/**
 * Whether TranslatePress language API is available.
 *
 * @return bool
 */
function altijara_ct_trp_available() {
	return function_exists( 'trp_get_current_language' );
}

/**
 * Whether a language code string is English.
 *
 * @param string $lang Raw language / locale.
 * @return bool
 */
function altijara_ct_code_is_english( $lang ) {
	$lang = strtolower( str_replace( '_', '-', trim( (string) $lang ) ) );
	if ( '' === $lang ) {
		return false;
	}
	return ( 0 === strpos( $lang, 'en' ) );
}

/**
 * Whether a URL path contains a TranslatePress /en/ segment.
 *
 * Avoids false positives like /energy/.
 *
 * @param string $uri URI or path.
 * @return bool
 */
function altijara_ct_path_has_english_segment( $uri ) {
	$path = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
	if ( '' === $path ) {
		$path = (string) $uri;
	}
	return (bool) preg_match( '#/(?:en)(?:/|$|\?)#i', $path );
}

/**
 * Detect English from the current request URL (TranslatePress /en/).
 *
 * @return bool
 */
function altijara_ct_request_is_english_path() {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return false;
	}
	$uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	return altijara_ct_path_has_english_segment( $uri );
}

/**
 * Raw debug snapshot of language detection signals.
 *
 * @return array<string, mixed>
 */
function altijara_ct_lang_debug_info() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$trp = '';
	if ( ! empty( $GLOBALS['TRP_LANGUAGE'] ) ) {
		$trp = (string) $GLOBALS['TRP_LANGUAGE'];
	} elseif ( function_exists( 'trp_get_current_language' ) ) {
		$trp = (string) trp_get_current_language();
	}
	$resolved = altijara_ct_current_lang();
	return array(
		'resolved'     => $resolved,
		'include'      => 'both|' . $resolved . '|missing',
		'exclude'      => class_exists( 'Altijara_CT_Lang_Query', false ) ? Altijara_CT_Lang_Query::excluded_lang() : '',
		'request_uri'  => $uri,
		'url_is_en'    => altijara_ct_request_is_english_path(),
		'trp_language' => $trp,
		'trp_is_en'    => altijara_ct_code_is_english( $trp ),
		'wp_action'    => did_action( 'wp' ) ? 'yes' : 'no',
		'locale'       => function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale(),
	);
}

/**
 * Resolve front language without caching (URL → TRP → ar).
 *
 * @return string en|ar
 */
function altijara_ct_resolve_current_lang() {
	// 1) Visible /en/ in the request path.
	if ( altijara_ct_request_is_english_path() ) {
		return 'en';
	}

	// 2) TranslatePress may know English even if the server URI was rewritten.
	$trp = '';
	if ( ! empty( $GLOBALS['TRP_LANGUAGE'] ) ) {
		$trp = (string) $GLOBALS['TRP_LANGUAGE'];
	} elseif ( altijara_ct_trp_available() ) {
		$trp = (string) trp_get_current_language();
	}
	if ( altijara_ct_code_is_english( $trp ) ) {
		return 'en';
	}

	// 3) Default front language = Arabic.
	return 'ar';
}

/**
 * Current front language code: en | ar.
 *
 * Priority (never use get_locale — it breaks Arabic default):
 * 1. URL path contains /en/
 * 2. TranslatePress current language is English
 * 3. Otherwise Arabic
 *
 * Static cache only after `wp` so TRP is ready before home sections query.
 *
 * @return string en|ar
 */
function altijara_ct_current_lang() {
	static $resolved = null;

	// Before main WP query finishes, always re-resolve (avoid locking AR too early).
	if ( ! did_action( 'wp' ) ) {
		return altijara_ct_resolve_current_lang();
	}

	if ( null !== $resolved ) {
		return $resolved;
	}

	$resolved = altijara_ct_resolve_current_lang();
	return $resolved;
}

/**
 * Allowed language visibility values.
 *
 * @return string[]
 */
function altijara_ct_lang_values() {
	return array( 'both', 'ar', 'en' );
}

/**
 * Sanitize visibility value; empty / unknown → both.
 *
 * @param mixed $value Raw value.
 * @return string both|ar|en
 */
function altijara_ct_sanitize_lang( $value ) {
	$value = sanitize_key( (string) $value );
	return in_array( $value, altijara_ct_lang_values(), true ) ? $value : 'both';
}

/**
 * Get post language visibility (missing meta = both).
 *
 * @param int $post_id Post ID.
 * @return string both|ar|en
 */
function altijara_ct_get_post_lang( $post_id ) {
	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return 'both';
	}
	$raw = get_post_meta( $post_id, ALTIJARA_CT_LANG_META, true );
	if ( '' === $raw || false === $raw || null === $raw ) {
		return 'both';
	}
	return altijara_ct_sanitize_lang( $raw );
}

/**
 * Human labels for language values.
 *
 * @return array<string, string>
 */
function altijara_ct_lang_labels() {
	return array(
		'both' => __( 'Both', 'altijara-content-tools' ),
		'ar'   => __( 'Arabic only', 'altijara-content-tools' ),
		'en'   => __( 'English only', 'altijara-content-tools' ),
	);
}

/**
 * Load plugin modules when the theme is active.
 */
function altijara_ct_bootstrap() {
	load_plugin_textdomain( 'altijara-content-tools', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! altijara_ct_theme_active() ) {
		add_action( 'admin_notices', 'altijara_ct_notice_theme_required' );
		return;
	}

	require_once ALTIJARA_CT_DIR . 'includes/class-lang-meta.php';
	require_once ALTIJARA_CT_DIR . 'includes/class-lang-query.php';
	require_once ALTIJARA_CT_DIR . 'includes/class-missing-thumbs.php';
	require_once ALTIJARA_CT_DIR . 'includes/class-bulk-assign.php';
	require_once ALTIJARA_CT_DIR . 'includes/class-admin-hub.php';

	Altijara_CT_Lang_Meta::init();
	Altijara_CT_Lang_Query::init();
	Altijara_CT_Missing_Thumbs::init();
	Altijara_CT_Bulk_Assign::init();
	Altijara_CT_Admin_Hub::init();

	altijara_ct_maybe_bust_theme_cache();

	add_action( 'wp_footer', 'altijara_ct_debug_lang_footer', 99 );

	if ( is_admin() && ! altijara_ct_trp_available() ) {
		add_action( 'admin_notices', 'altijara_ct_notice_trp_optional' );
	}
}
add_action( 'after_setup_theme', 'altijara_ct_bootstrap', 20 );

/**
 * Admin-only language debug: append ?altijara_debug_lang=1 to any front URL.
 */
function altijara_ct_debug_lang_footer() {
	if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $_GET['altijara_debug_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$info = altijara_ct_lang_debug_info();
	echo "\n<!-- altijara_ct_lang_debug " . esc_html( wp_json_encode( $info ) ) . " -->\n";
	echo '<div id="altijara-ct-lang-debug" style="position:fixed;z-index:999999;bottom:12px;inset-inline-start:12px;max-width:420px;padding:12px 14px;border-radius:10px;background:#1a222d;color:#fff;font:12px/1.45 monospace;box-shadow:0 8px 24px rgba(0,0,0,.35);">';
	echo '<strong style="display:block;margin-bottom:6px;color:#1897b8;">Altijara CT language debug</strong>';
	foreach ( $info as $key => $val ) {
		echo esc_html( (string) $key ) . ': <b>' . esc_html( is_bool( $val ) ? ( $val ? 'true' : 'false' ) : (string) $val ) . '</b><br>';
	}
	echo '<span style="opacity:.75">Expected on /en/: resolved=en, include=both|en|missing</span>';
	echo '</div>';
}

/**
 * One-time theme section cache purge after language-filter fixes.
 */
function altijara_ct_maybe_bust_theme_cache() {
	$flag = 'altijara_ct_cache_bust';
	if ( ALTIJARA_CT_VERSION === get_option( $flag, '' ) ) {
		return;
	}
	if ( function_exists( 'altijara_cache_purge_all' ) ) {
		altijara_cache_purge_all();
	}
	update_option( $flag, ALTIJARA_CT_VERSION, false );
}

/**
 * Admin notice: theme required.
 */
function altijara_ct_notice_theme_required() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Altijara Content Tools requires the Altijara theme to be active.', 'altijara-content-tools' );
	echo '</p></div>';
}

/**
 * Admin notice: TranslatePress recommended.
 */
function altijara_ct_notice_trp_optional() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ( 'plugins' !== $screen->id && false === strpos( (string) $screen->id, 'altijara-content-tools' ) ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-info is-dismissible"><p>';
	echo esc_html__( 'TranslatePress is not detected. Language visibility still works using the site locale; install TranslatePress for full bilingual switching.', 'altijara-content-tools' );
	echo '</p></div>';
}
