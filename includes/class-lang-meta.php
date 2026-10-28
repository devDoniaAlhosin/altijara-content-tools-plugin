<?php
/**
 * Language visibility post meta, list column, filters, quick/bulk edit.
 *
 * @package AltijaraContentTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Altijara_CT_Lang_Meta
 */
class Altijara_CT_Lang_Meta {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save_meta' ), 10, 2 );
		add_filter( 'manage_post_posts_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'list_filter' ) );
		add_action( 'quick_edit_custom_box', array( __CLASS__, 'quick_edit' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( __CLASS__, 'bulk_edit' ), 10, 2 );
		add_action( 'save_post_post', array( __CLASS__, 'save_quick_edit' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_list_js' ) );
	}

	/**
	 * Register meta for REST / classic.
	 */
	public static function register_meta() {
		register_post_meta(
			'post',
			ALTIJARA_CT_LANG_META,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => 'both',
				'show_in_rest'      => true,
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
				'sanitize_callback' => 'altijara_ct_sanitize_lang',
			)
		);
	}

	/**
	 * Side meta box.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'altijara_ct_lang_visibility',
			__( 'Language visibility', 'altijara-content-tools' ),
			array( __CLASS__, 'render_meta_box' ),
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Meta box markup.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'altijara_ct_save_lang', 'altijara_ct_lang_nonce' );
		$current = altijara_ct_get_post_lang( $post->ID );
		$labels  = altijara_ct_lang_labels();
		echo '<p class="description">' . esc_html__( 'Control which TranslatePress language this post appears in on the front end. Listings only — direct links still work.', 'altijara-content-tools' ) . '</p>';
		foreach ( $labels as $value => $label ) {
			printf(
				'<p><label><input type="radio" name="altijara_ct_lang" value="%1$s" %2$s> %3$s</label></p>',
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Save classic meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_meta( $post_id, $post ) {
		unset( $post );
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['altijara_ct_lang_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['altijara_ct_lang_nonce'] ) ), 'altijara_ct_save_lang' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['altijara_ct_lang'] ) ) {
			return;
		}
		$value = altijara_ct_sanitize_lang( wp_unslash( $_POST['altijara_ct_lang'] ) );
		update_post_meta( $post_id, ALTIJARA_CT_LANG_META, $value );
	}

	/**
	 * List table column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['altijara_ct_lang'] = __( 'Language', 'altijara-content-tools' );
			}
		}
		if ( ! isset( $out['altijara_ct_lang'] ) ) {
			$out['altijara_ct_lang'] = __( 'Language', 'altijara-content-tools' );
		}
		return $out;
	}

	/**
	 * Column cell.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Post ID.
	 */
	public static function column_content( $column, $post_id ) {
		if ( 'altijara_ct_lang' !== $column ) {
			return;
		}
		$value  = altijara_ct_get_post_lang( $post_id );
		$labels = altijara_ct_lang_labels();
		$short  = array(
			'both' => 'Both',
			'ar'   => 'AR',
			'en'   => 'EN',
		);
		printf(
			'<span class="altijara-ct-lang-badge altijara-ct-lang-badge--%1$s" data-lang="%1$s" title="%2$s">%3$s</span>',
			esc_attr( $value ),
			esc_attr( $labels[ $value ] ?? $value ),
			esc_html( $short[ $value ] ?? $value )
		);
	}

	/**
	 * Dropdown filter on posts list.
	 *
	 * @param string $post_type Post type.
	 */
	public static function list_filter( $post_type ) {
		if ( 'post' !== $post_type ) {
			return;
		}
		$raw = isset( $_GET['altijara_lang'] ) ? sanitize_key( wp_unslash( $_GET['altijara_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<select name="altijara_lang" id="altijara_lang_filter">';
		echo '<option value="">' . esc_html__( 'All languages', 'altijara-content-tools' ) . '</option>';
		foreach ( altijara_ct_lang_labels() as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $raw, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Quick edit field.
	 *
	 * @param string $column    Column.
	 * @param string $post_type Post type.
	 */
	public static function quick_edit( $column, $post_type ) {
		if ( 'altijara_ct_lang' !== $column || 'post' !== $post_type ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="inline-edit-group">
					<span class="title"><?php esc_html_e( 'Language', 'altijara-content-tools' ); ?></span>
					<select name="altijara_ct_lang_quick">
						<?php foreach ( altijara_ct_lang_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Bulk edit field.
	 *
	 * @param string $column    Column.
	 * @param string $post_type Post type.
	 */
	public static function bulk_edit( $column, $post_type ) {
		if ( 'altijara_ct_lang' !== $column || 'post' !== $post_type ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="inline-edit-group">
					<span class="title"><?php esc_html_e( 'Language', 'altijara-content-tools' ); ?></span>
					<select name="altijara_ct_lang_bulk">
						<option value=""><?php esc_html_e( '— No change —', 'altijara-content-tools' ); ?></option>
						<?php foreach ( altijara_ct_lang_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Persist quick/bulk edit.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save_quick_edit( $post_id, $post ) {
		unset( $post );
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_REQUEST['altijara_ct_lang_bulk'] ) && '' !== $_REQUEST['altijara_ct_lang_bulk'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value = altijara_ct_sanitize_lang( wp_unslash( $_REQUEST['altijara_ct_lang_bulk'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			update_post_meta( $post_id, ALTIJARA_CT_LANG_META, $value );
			return;
		}

		if ( isset( $_REQUEST['altijara_ct_lang_quick'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value = altijara_ct_sanitize_lang( wp_unslash( $_REQUEST['altijara_ct_lang_quick'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			update_post_meta( $post_id, ALTIJARA_CT_LANG_META, $value );
		}
	}

	/**
	 * Inline script so quick edit prefills language from the row badge.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue_list_js( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}
		$js = <<<'JS'
(function ($) {
  if (typeof inlineEditPost === 'undefined') return;
  var $wp_inline_edit = inlineEditPost.edit;
  inlineEditPost.edit = function (id) {
    $wp_inline_edit.apply(this, arguments);
    var postId = 0;
    if (typeof id === 'object') postId = parseInt(this.getId(id), 10);
    else postId = parseInt(id, 10);
    if (!postId) return;
    var $row = $('#post-' + postId);
    var lang = $row.find('.altijara-ct-lang-badge').attr('data-lang') || 'both';
    $('#edit-' + postId).find('select[name="altijara_ct_lang_quick"]').val(lang);
  };
})(jQuery);
JS;
		wp_add_inline_script( 'inline-edit-post', $js );
	}
}
