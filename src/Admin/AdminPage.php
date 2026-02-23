<?php
/**
 * Admin page for Statement Processor.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the custom admin page (upload + export).
 */
class AdminPage {

	/**
	 * Import page slug (submenu of Transactions CPT).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'statement-processor-import';

	/**
	 * Parent menu slug for the Transactions CPT.
	 *
	 * @var string
	 */
	const TRANSACTIONS_MENU_SLUG = 'edit.php?post_type=sp-transaction';

	/**
	 * Constructor; hooks into admin.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ], 10, 1 );
	}

	/**
	 * Add the Import submenu page under Transactions.
	 */
	public function add_menu_page() {
		add_submenu_page(
			self::TRANSACTIONS_MENU_SLUG,
			__( 'Import statements', 'statement-processor' ),
			__( 'Import', 'statement-processor' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue scripts and styles only on our page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== 'sp-transaction_page_' . self::PAGE_SLUG ) {
			return;
		}

		$asset_url = STATEMENT_PROCESSOR_PLUGIN_URL . 'assets/';
		wp_enqueue_style(
			'statement-processor-admin',
			$asset_url . 'css/admin.css',
			[],
			STATEMENT_PROCESSOR_VERSION
		);
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
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'importBatchRestUrl' => rest_url( 'statement-processor/v1/import-batch' ),
				'restNonce'          => wp_create_nonce( 'wp_rest' ),
				'nonce'              => wp_create_nonce( 'statement_processor_upload' ),
				'processingText' => __( 'Processing…', 'statement-processor' ),
				'uploadLabels'       => [
					'pending'    => __( 'Pending', 'statement-processor' ),
					'uploading'  => __( 'Uploading…', 'statement-processor' ),
					'processing' => __( 'Processing…', 'statement-processor' ),
					'done'       => __( 'Done', 'statement-processor' ),
					'error'      => __( 'Error', 'statement-processor' ),
				],
				'importLabel'        => __( 'Importing…', 'statement-processor' ),
				'importProgressLabel' => __( 'Importing… %s / %s', 'statement-processor' ),
			]
		);
	}

	/**
	 * Render the admin page content.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'statement-processor' ) );
		}

		$show_review   = false;
		$review_data   = null;
		$review_key    = '';

		if ( isset( $_GET['review'] ) && $_GET['review'] === '1' && ! empty( $_GET['key'] ) ) {
			$review_key    = sanitize_text_field( wp_unslash( $_GET['key'] ) );
			$transient_key = UploadHandler::REVIEW_TRANSIENT_PREFIX . $review_key;
			$data          = get_transient( $transient_key );
			if ( $data !== false && is_array( $data ) && ! empty( $data['transactions'] ) ) {
				$show_review = true;
				$review_data = $data;
			} else {
				set_transient( 'statement_processor_notice', __( 'Review session expired or invalid. Please upload your file(s) again.', 'statement-processor' ), 45 );
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
				exit;
			}
		}

		$sources = get_terms( [ 'taxonomy' => 'sp-source', 'hide_empty' => false ] );
		if ( is_wp_error( $sources ) ) {
			$sources = [];
		}

		include STATEMENT_PROCESSOR_PLUGIN_DIR . 'src/Admin/views/page.php';
	}
}
