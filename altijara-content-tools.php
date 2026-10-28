<?php
/**
 * Plugin Name: Altijara Content Tools
 * Description: Language visibility (AR/EN/Both) for TranslatePress, missing featured-image tools, and content health recommendations — designed for the Altijara theme.
 * Version: 1.1.0
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

define( 'ALTIJARA_CT_VERSION', '1.1.0' );
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
 * Current front language code: en | ar | other.
 *
 * Mirrors theme altijara_is_english_view() when present.
 *
 * @return string en|ar|other
 */
function altijara_ct_current_lang() {
	if ( function_exists( 'altijara_is_english_view' ) && altijara_is_english_view() ) {
		return 'en';
	}

	$lang = '';
	if ( altijara_ct_trp_available() ) {
		$lang = (string) trp_get_current_language();
	}
	if ( '' === $lang ) {
		$lang = function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale();
	}
	$lang = strtolower( str_replace( '_', '-', $lang ) );

	if ( 0 === strpos( $lang, 'en' ) ) {
		return 'en';
	}
	if ( 0 === strpos( $lang, 'ar' ) ) {
		return 'ar';
	}
	return 'other';
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

	if ( is_admin() && ! altijara_ct_trp_available() ) {
		add_action( 'admin_notices', 'altijara_ct_notice_trp_optional' );
	}
}
add_action( 'after_setup_theme', 'altijara_ct_bootstrap', 20 );

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
