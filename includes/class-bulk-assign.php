<?php
/**
 * Bulk assign language visibility by post ID list.
 *
 * @package AltijaraContentTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Altijara_CT_Bulk_Assign
 */
class Altijara_CT_Bulk_Assign {

	const ACTION = 'altijara_ct_bulk_assign_lang';

	const RESULT_TRANSIENT_PREFIX = 'altijara_ct_bulk_result_';

	/**
	 * Max post IDs processed per request.
	 *
	 * @return int
	 */
	public static function max_ids() {
		return max( 1, min( 200, (int) apply_filters( 'altijara_ct_bulk_max_ids', 100 ) ) );
	}

	/**
	 * Max raw textarea length (characters).
	 *
	 * @return int
	 */
	public static function max_input_chars() {
		return max( 200, min( 20000, (int) apply_filters( 'altijara_ct_bulk_max_input_chars', 4000 ) ) );
	}

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Parse a free-form ID list into unique positive ints (order preserved).
	 *
	 * Accepts commas, spaces, newlines, semicolons, pipes.
	 *
	 * @param string $raw Raw input.
	 * @return int[]
	 */
	public static function parse_ids( $raw ) {
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$parts = preg_split( '/[\s,;|]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$ids  = array();
		$seen = array();
		foreach ( $parts as $part ) {
			$part = preg_replace( '/[^0-9]/', '', (string) $part );
			if ( '' === $part ) {
				continue;
			}
			$id = absint( $part );
			if ( $id < 1 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$ids[]       = $id;
		}
		return $ids;
	}

	/**
	 * Validate and apply language meta for a list of IDs.
	 *
	 * @param int[]  $ids  Candidate IDs.
	 * @param string $lang both|ar|en.
	 * @return array{
	 *   updated:int[],
	 *   skipped:array<int, string>,
	 *   truncated:bool,
	 *   requested:int,
	 *   lang:string
	 * }
	 */
	public static function apply( array $ids, $lang ) {
		$lang = altijara_ct_sanitize_lang( $lang );
		$max  = self::max_ids();

		$requested = count( $ids );
		$truncated = false;
		if ( $requested > $max ) {
			$ids       = array_slice( $ids, 0, $max );
			$truncated = true;
		}

		$updated = array();
		$skipped = array();

		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id < 1 ) {
				continue;
			}

			$post = get_post( $id );
			if ( ! $post ) {
				$skipped[ $id ] = __( 'not found', 'altijara-content-tools' );
				continue;
			}
			if ( 'post' !== $post->post_type ) {
				$skipped[ $id ] = __( 'not a post', 'altijara-content-tools' );
				continue;
			}
			if ( 'trash' === $post->post_status ) {
				$skipped[ $id ] = __( 'in trash', 'altijara-content-tools' );
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$skipped[ $id ] = __( 'no permission', 'altijara-content-tools' );
				continue;
			}

			$ok = update_post_meta( $id, ALTIJARA_CT_LANG_META, $lang );
			// update_post_meta returns false when value unchanged; still count as success.
			if ( false === $ok ) {
				$current = altijara_ct_get_post_lang( $id );
				if ( $current === $lang ) {
					$updated[] = $id;
					continue;
				}
				$skipped[ $id ] = __( 'update failed', 'altijara-content-tools' );
				continue;
			}
			$updated[] = $id;
		}

		return array(
			'updated'   => $updated,
			'skipped'   => $skipped,
			'truncated' => $truncated,
			'requested' => $requested,
			'lang'      => $lang,
		);
	}

	/**
	 * Handle form POST.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'altijara-content-tools' ) );
		}

		check_admin_referer( self::ACTION );

		$redirect = add_query_arg(
			array(
				'page' => Altijara_CT_Admin_Hub::PAGE_SLUG,
				'tab'  => 'bulk',
			),
			admin_url( 'admin.php' )
		);

		$raw  = isset( $_POST['altijara_ct_bulk_ids'] ) ? wp_unslash( $_POST['altijara_ct_bulk_ids'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw  = is_string( $raw ) ? $raw : '';
		$lang = isset( $_POST['altijara_ct_bulk_lang'] ) ? altijara_ct_sanitize_lang( wp_unslash( $_POST['altijara_ct_bulk_lang'] ) ) : 'both';

		$max_chars = self::max_input_chars();
		if ( strlen( $raw ) > $max_chars ) {
			self::store_result(
				array(
					'error' => sprintf(
						/* translators: %d: max characters */
						__( 'Input too long. Maximum %d characters.', 'altijara-content-tools' ),
						$max_chars
					),
				)
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$ids = self::parse_ids( $raw );
		if ( empty( $ids ) ) {
			self::store_result(
				array(
					'error' => __( 'No valid post IDs found. Use numbers separated by commas or new lines.', 'altijara-content-tools' ),
				)
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// Soft lock: one bulk run per user every 3 seconds.
		$lock_key = 'altijara_ct_bulk_lock_' . get_current_user_id();
		if ( get_transient( $lock_key ) ) {
			self::store_result(
				array(
					'error' => __( 'Please wait a few seconds before running another bulk assign.', 'altijara-content-tools' ),
				)
			);
			wp_safe_redirect( $redirect );
			exit;
		}
		set_transient( $lock_key, 1, 3 );

		$result = self::apply( $ids, $lang );
		self::store_result( $result );

		if ( function_exists( 'altijara_cache_purge_all' ) && ! empty( $result['updated'] ) ) {
			altijara_cache_purge_all();
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Store result for next hub render.
	 *
	 * @param array<string, mixed> $result Result payload.
	 */
	public static function store_result( array $result ) {
		$key = self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
		set_transient( $key, $result, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Consume stored result (one-shot).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function consume_result() {
		$key = self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
		$val = get_transient( $key );
		if ( false === $val || ! is_array( $val ) ) {
			return null;
		}
		delete_transient( $key );
		return $val;
	}

	/**
	 * Render notices from last bulk run.
	 *
	 * @param array<string, mixed>|null $result Result.
	 */
	public static function render_result_notice( $result ) {
		if ( ! is_array( $result ) ) {
			return;
		}
		if ( ! empty( $result['error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) $result['error'] ) . '</p></div>';
			return;
		}

		$updated   = isset( $result['updated'] ) && is_array( $result['updated'] ) ? $result['updated'] : array();
		$skipped   = isset( $result['skipped'] ) && is_array( $result['skipped'] ) ? $result['skipped'] : array();
		$lang      = isset( $result['lang'] ) ? altijara_ct_sanitize_lang( $result['lang'] ) : 'both';
		$labels    = altijara_ct_lang_labels();
		$truncated = ! empty( $result['truncated'] );
		$requested = isset( $result['requested'] ) ? absint( $result['requested'] ) : 0;

		echo '<div class="notice notice-success is-dismissible"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: updated count, 2: language label */
				__( 'Updated %1$d post(s) to “%2$s”.', 'altijara-content-tools' ),
				count( $updated ),
				$labels[ $lang ] ?? $lang
			)
		);
		if ( $truncated ) {
			echo ' ';
			echo esc_html(
				sprintf(
					/* translators: 1: requested, 2: max */
					__( 'Input listed %1$d IDs; only the first %2$d were processed.', 'altijara-content-tools' ),
					$requested,
					self::max_ids()
				)
			);
		}
		echo '</p></div>';

		if ( ! empty( $skipped ) ) {
			$lines = array();
			$i     = 0;
			foreach ( $skipped as $id => $reason ) {
				if ( $i >= 20 ) {
					$lines[] = sprintf(
						/* translators: %d: remaining */
						__( '…and %d more skipped.', 'altijara-content-tools' ),
						count( $skipped ) - 20
					);
					break;
				}
				$lines[] = sprintf( '#%d — %s', (int) $id, (string) $reason );
				++$i;
			}
			echo '<div class="notice notice-warning is-dismissible"><p><strong>';
			echo esc_html(
				sprintf(
					/* translators: %d: skipped count */
					__( 'Skipped %d ID(s):', 'altijara-content-tools' ),
					count( $skipped )
				)
			);
			echo '</strong></p><ul class="altijara-ct-bulk-skip-list">';
			foreach ( $lines as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/**
	 * Bulk assign panel markup.
	 */
	public static function render_panel() {
		$max_ids   = self::max_ids();
		$max_chars = self::max_input_chars();
		$action    = admin_url( 'admin-post.php' );
		?>
		<div class="altijara-admin-block">
			<div class="altijara-ct-bulk-layout">
				<div class="altijara-ct-bulk-card">
					<strong class="altijara-ct-bulk-card__title"><?php esc_html_e( 'Assign language by post IDs', 'altijara-content-tools' ); ?></strong>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: max IDs, 2: max chars */
								__( 'Paste post IDs separated by commas or new lines. Max %1$d IDs and %2$d characters per run. Only regular posts you can edit are updated.', 'altijara-content-tools' ),
								$max_ids,
								$max_chars
							)
						);
						?>
					</p>

					<form method="post" action="<?php echo esc_url( $action ); ?>" class="altijara-ct-bulk-form" id="altijara-ct-bulk-form" data-max-ids="<?php echo esc_attr( (string) $max_ids ); ?>" data-max-chars="<?php echo esc_attr( (string) $max_chars ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<?php wp_nonce_field( self::ACTION ); ?>

						<div class="altijara-ct-field">
							<label for="altijara_ct_bulk_ids"><strong><?php esc_html_e( 'Post IDs', 'altijara-content-tools' ); ?></strong></label>
							<textarea
								name="altijara_ct_bulk_ids"
								id="altijara_ct_bulk_ids"
								rows="8"
								maxlength="<?php echo esc_attr( (string) $max_chars ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. 1204, 1588, 2011', 'altijara-content-tools' ); ?>"
								required
							></textarea>
							<p class="altijara-ct-bulk-meter" id="altijara-ct-bulk-meter" aria-live="polite">
								<span data-count>0</span>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: max */
										__( ' / %d unique IDs ready', 'altijara-content-tools' ),
										$max_ids
									)
								);
								?>
							</p>
						</div>

						<div class="altijara-ct-field">
							<span class="altijara-ct-field__label"><strong><?php esc_html_e( 'Language visibility', 'altijara-content-tools' ); ?></strong></span>
							<div class="altijara-ct-lang-radios" role="radiogroup">
								<?php foreach ( altijara_ct_lang_labels() as $value => $label ) : ?>
									<label class="altijara-ct-lang-radio">
										<input type="radio" name="altijara_ct_bulk_lang" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, 'both' ); ?>>
										<span><?php echo esc_html( $label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="altijara-ct-bulk-actions">
							<button type="submit" class="button button-primary" id="altijara-ct-bulk-submit">
								<?php esc_html_e( 'Assign language', 'altijara-content-tools' ); ?>
							</button>
							<span class="description"><?php esc_html_e( 'Invalid, non-post, trashed, or forbidden IDs are skipped with a report.', 'altijara-content-tools' ); ?></span>
						</div>
					</form>
				</div>

				<div class="altijara-ct-bulk-aside">
					<div class="altijara-ct-tip-card">
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e( 'Safeguards', 'altijara-content-tools' ); ?></strong>
							<ul>
								<li><?php echo esc_html( sprintf( __( 'Hard cap: %d IDs per request', 'altijara-content-tools' ), $max_ids ) ); ?></li>
								<li><?php esc_html_e( 'Duplicates removed automatically', 'altijara-content-tools' ); ?></li>
								<li><?php esc_html_e( 'Only post type “post”', 'altijara-content-tools' ); ?></li>
								<li><?php esc_html_e( 'Per-ID edit capability check', 'altijara-content-tools' ); ?></li>
								<li><?php esc_html_e( 'Short cooldown between runs', 'altijara-content-tools' ); ?></li>
							</ul>
						</div>
					</div>
					<div class="altijara-ct-tip-card">
						<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e( 'Where to get IDs', 'altijara-content-tools' ); ?></strong>
							<p><?php esc_html_e( 'Open a filtered Posts list (language or no featured image), copy IDs from the URL or list, then paste them here.', 'altijara-content-tools' ); ?></p>
							<p>
								<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=post' ) ); ?>"><?php esc_html_e( 'All posts', 'altijara-content-tools' ); ?></a>
								·
								<a href="<?php echo esc_url( Altijara_CT_Missing_Thumbs::list_url() ); ?>"><?php esc_html_e( 'No featured image', 'altijara-content-tools' ); ?></a>
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
