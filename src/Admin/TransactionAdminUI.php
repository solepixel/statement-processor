<?php
/**
 * Transaction list column and edit-screen meta box.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Admin;

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
		$amount      = get_post_meta( $post->ID, TransactionImporter::META_AMOUNT, true );
		$description = get_post_meta( $post->ID, TransactionImporter::META_DESCRIPTION, true );
		$origination = get_post_meta( $post->ID, TransactionImporter::META_ORIGINATION, true );
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
					<p class="description"><?php esc_html_e( 'File this transaction was imported from.', 'statement-processor' ); ?></p>
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

		$amount       = isset( $_POST['sp_meta_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['sp_meta_amount'] ) ) : '';
		$description = isset( $_POST['sp_meta_description'] ) ? sanitize_text_field( wp_unslash( $_POST['sp_meta_description'] ) ) : '';
		$origination = isset( $_POST['sp_meta_origination'] ) ? sanitize_file_name( wp_unslash( $_POST['sp_meta_origination'] ) ) : '';

		update_post_meta( $post_id, TransactionImporter::META_AMOUNT, $amount );
		update_post_meta( $post_id, TransactionImporter::META_DESCRIPTION, $description );
		update_post_meta( $post_id, TransactionImporter::META_ORIGINATION, $origination );
	}
}
