<?php
/**
 * Transaction list column and edit-screen meta box.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Admin;

use StatementProcessor\Classification\CategoryMemory;
use StatementProcessor\Classification\TransactionClassifier;
use StatementProcessor\Plugin;
use StatementProcessor\Import\TransactionImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds Amount column to the Transactions list and a meta box on the edit screen.
 */
class TransactionAdminUI {

	/**
	 * Constructor; registers hooks.
	 */
	public function __construct() {
		add_filter( 'manage_sp-transaction_posts_columns', [ $this, 'add_amount_column' ] );
		add_action( 'manage_sp-transaction_posts_custom_column', [ $this, 'render_amount_column' ], 10, 2 );
		add_action( 'add_meta_boxes', [ $this, 'add_transaction_meta_box' ] );
		add_action( 'save_post_sp-transaction', [ $this, 'save_transaction_meta_box' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_edit_scripts' ] );
		add_action( 'wp_ajax_sp_suggest_category', [ $this, 'ajax_suggest_category' ] );
		add_filter( 'bulk_actions-edit-sp-transaction', [ $this, 'add_bulk_actions' ] );
		add_filter( 'handle_bulk_actions-edit-sp-transaction', [ $this, 'handle_bulk_actions' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'bulk_action_admin_notices' ] );
	}

	/**
	 * Add Amount column to the Transactions list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_amount_column( $columns ) {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['sp_amount'] = _x( 'Amount', 'column name', 'statement-processor' );
			}
		}
		if ( ! isset( $new['sp_amount'] ) ) {
			$new['sp_amount'] = _x( 'Amount', 'column name', 'statement-processor' );
		}
		return $new;
	}

	/**
	 * Output the Amount column value.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_amount_column( $column, $post_id ) {
		if ( $column !== 'sp_amount' ) {
			return;
		}
		$amount = get_post_meta( $post_id, TransactionImporter::META_AMOUNT, true );
		if ( $amount !== '' && $amount !== false && is_numeric( str_replace( ',', '', $amount ) ) ) {
			$num = (float) str_replace( ',', '', $amount );
			echo esc_html( '$' . number_format( $num, 2 ) );
		} else {
			echo '—';
		}
	}

	/**
	 * Register the Transaction details meta box on the edit screen.
	 */
	public function add_transaction_meta_box() {
		add_meta_box(
			'sp_transaction_details',
			__( 'Transaction details', 'statement-processor' ),
			[ $this, 'render_transaction_meta_box' ],
			Plugin::post_type(),
			'normal',
			'high'
		);
	}

	/**
	 * Render the Transaction details meta box content.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_transaction_meta_box( $post ) {
		$amount       = get_post_meta( $post->ID, TransactionImporter::META_AMOUNT, true );
		$description  = get_post_meta( $post->ID, TransactionImporter::META_DESCRIPTION, true );
		$origination  = get_post_meta( $post->ID, TransactionImporter::META_ORIGINATION, true );
		$origination_file = get_post_meta( $post->ID, TransactionImporter::META_ORIGINATION_FILE, true );
		$origination_url  = '';
		if ( $origination_file !== '' && $origination_file !== false ) {
			$upload_dir = wp_upload_dir();
			if ( ! empty( $upload_dir['baseurl'] ) ) {
				$origination_url = $upload_dir['baseurl'] . '/scratch/' . $origination_file;
			}
		}
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="sp_meta_amount"><?php esc_html_e( 'Amount', 'statement-processor' ); ?></label>
				</th>
				<td>
					<input type="text" id="sp_meta_amount" name="sp_meta_amount" value="<?php echo esc_attr( $amount ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="sp_meta_description"><?php esc_html_e( 'Description', 'statement-processor' ); ?></label>
				</th>
				<td>
					<input type="text" id="sp_meta_description" name="sp_meta_description" value="<?php echo esc_attr( $description ); ?>" class="large-text" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="sp_meta_origination"><?php esc_html_e( 'Origination', 'statement-processor' ); ?></label>
				</th>
				<td>
					<input type="text" id="sp_meta_origination" name="sp_meta_origination" value="<?php echo esc_attr( $origination ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Source filename', 'statement-processor' ); ?>" />
					<?php if ( $origination_url !== '' ) : ?>
						<p class="description">
							<?php esc_html_e( 'Source file:', 'statement-processor' ); ?>
							<a href="<?php echo esc_url( $origination_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $origination !== '' ? $origination : $origination_file ); ?></a>
						</p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'File this transaction was imported from.', 'statement-processor' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Category', 'statement-processor' ); ?></th>
				<td>
					<input type="hidden" name="sp_category_was_suggested" id="sp_category_was_suggested" value="0" />
					<button type="button" id="sp_suggest_category_btn" class="button"><?php esc_html_e( 'Suggest category', 'statement-processor' ); ?></button>
					<span id="sp_suggest_category_status" class="sp-suggest-status" style="margin-left:8px;"></span>
					<p class="description"><?php esc_html_e( 'Use the Categories box (below or in the sidebar) to set or change the category. Click "Suggest category" to fill it from memory or AI, then Update to save.', 'statement-processor' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save meta box values when the transaction is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param \WP_Post $post   Post object.
	 */
	public function save_transaction_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['sp_meta_amount'] ) || ! isset( $_POST['sp_meta_description'] ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( $post->post_type !== Plugin::post_type() ) {
			return;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-post_' . $post_id ) ) {
			return;
		}

		$amount       = isset( $_POST['sp_meta_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['sp_meta_amount'] ) ) : '';
		$description = isset( $_POST['sp_meta_description'] ) ? sanitize_text_field( wp_unslash( $_POST['sp_meta_description'] ) ) : '';
		$origination  = isset( $_POST['sp_meta_origination'] ) ? sanitize_text_field( wp_unslash( $_POST['sp_meta_origination'] ) ) : '';

		update_post_meta( $post_id, TransactionImporter::META_AMOUNT, $amount );
		update_post_meta( $post_id, TransactionImporter::META_DESCRIPTION, $description );
		update_post_meta( $post_id, TransactionImporter::META_ORIGINATION, $origination );

		// If user had clicked "Suggest category", mark so we don't re-run batch classification.
		if ( ! empty( $_POST['sp_category_was_suggested'] ) ) {
			update_post_meta( $post_id, TransactionImporter::META_CATEGORY_ATTEMPTED, '1' );
		}

		// Remember description → category for future (when user sets a category).
		$tax = Plugin::taxonomy_category();
		if ( isset( $_POST['tax_input'] ) && is_array( $_POST['tax_input'] ) && isset( $_POST['tax_input'][ $tax ] ) ) {
			$raw_tax   = wp_unslash( $_POST['tax_input'][ $tax ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$tax_input = is_array( $raw_tax ) ? $raw_tax : array( $raw_tax );
			$term_ids  = array_filter( array_map( 'absint', (array) $tax_input ) );
			if ( '' !== $description && ! empty( $term_ids ) ) {
				CategoryMemory::remember( $description, (int) $term_ids[0] );
			}
		}
	}

	/**
	 * Enqueue script on transaction edit screen for Suggest category.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_edit_scripts( $hook_suffix ) {
		if ( $hook_suffix !== 'post.php' ) {
			return;
		}
		global $post;
		if ( ! $post || $post->post_type !== Plugin::post_type() ) {
			return;
		}
		$asset_url = STATEMENT_PROCESSOR_PLUGIN_URL . 'assets/';
		wp_enqueue_script(
			'statement-processor-admin',
			$asset_url . 'js/admin.js',
			[ 'jquery' ],
			STATEMENT_PROCESSOR_VERSION,
			true
		);
		wp_localize_script(
			'statement-processor-admin',
			'statementProcessorAdmin',
			[
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'suggestCategoryNonce' => wp_create_nonce( 'sp_suggest_category' ),
			]
		);
	}

	/**
	 * AJAX handler: suggest category for a description.
	 */
	public function ajax_suggest_category() {
		check_ajax_referer( 'sp_suggest_category', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'statement-processor' ) ] );
		}
		$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
		if ( $description === '' ) {
			wp_send_json_error( [ 'message' => __( 'Description is empty.', 'statement-processor' ) ] );
		}
		$classifier = new TransactionClassifier();
		$term_id    = $classifier->classify( $description );
		if ( $term_id === null ) {
			wp_send_json_success( [ 'term_id' => 0, 'message' => __( 'No category suggested.', 'statement-processor' ) ] );
		}
		wp_send_json_success( [ 'term_id' => $term_id ] );
	}

	/**
	 * Add bulk actions for category suggestion.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_actions( $actions ) {
		$actions['sp_suggest_categories'] = __( 'Suggest categories', 'statement-processor' );
		$actions['sp_reclassify']         = __( 'Re-classify', 'statement-processor' );
		return $actions;
	}

	/**
	 * Handle bulk actions for category suggestion and re-classify.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Action name.
	 * @param array  $post_ids    Post IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
		if ( $doaction !== 'sp_suggest_categories' && $doaction !== 'sp_reclassify' ) {
			return $redirect_to;
		}
		if ( ! current_user_can( 'edit_posts' ) || empty( $post_ids ) ) {
			return $redirect_to;
		}
		$classifier = new TransactionClassifier();
		$done       = 0;
		$skipped    = 0;
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				continue;
			}
			if ( get_post_type( $post_id ) !== Plugin::post_type() ) {
				continue;
			}
			$description = get_post_meta( $post_id, TransactionImporter::META_DESCRIPTION, true );
			if ( $doaction === 'sp_reclassify' ) {
				delete_post_meta( $post_id, TransactionImporter::META_CATEGORY_ATTEMPTED );
			} else {
				if ( get_post_meta( $post_id, TransactionImporter::META_CATEGORY_ATTEMPTED, true ) === '1' ) {
					++$skipped;
					continue;
				}
			}
			$term_id = $classifier->classify( $description );
			if ( $term_id !== null ) {
				wp_set_object_terms( $post_id, $term_id, Plugin::taxonomy_category() );
			}
			update_post_meta( $post_id, TransactionImporter::META_CATEGORY_ATTEMPTED, '1' );
			++$done;
		}
		$redirect_to = add_query_arg(
			[
				'sp_bulk_done'    => $done,
				'sp_bulk_skipped' => $doaction === 'sp_suggest_categories' ? $skipped : 0,
			],
			$redirect_to
		);
		return $redirect_to;
	}

	/**
	 * Show admin notice after bulk category actions.
	 */
	public function bulk_action_admin_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'edit-sp-transaction' ) {
			return;
		}
		$done = isset( $_GET['sp_bulk_done'] ) ? (int) $_GET['sp_bulk_done'] : 0;
		$skip = isset( $_GET['sp_bulk_skipped'] ) ? (int) $_GET['sp_bulk_skipped'] : 0;
		if ( $done === 0 && $skip === 0 ) {
			return;
		}
		$message = sprintf(
			/* translators: 1: number processed, 2: number skipped (if any) */
			__( 'Category suggestion: %1$d transaction(s) processed.', 'statement-processor' ),
			$done
		);
		if ( $skip > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: number skipped (already attempted) */
				__( '%d skipped (already attempted).', 'statement-processor' ),
				$skip
			);
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
