<?php
/**
 * Export submenu page for Statement Processor.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Export to CSV admin page.
 */
class ExportPage {

	/**
	 * Export page slug (submenu of Transactions CPT).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'statement-processor-export';

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
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 21 );
	}

	/**
	 * Add the Export submenu page under Transactions.
	 */
	public function add_menu_page() {
		add_submenu_page(
			self::TRANSACTIONS_MENU_SLUG,
			__( 'Export transactions', 'statement-processor' ),
			__( 'Export', 'statement-processor' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the Export page content.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'statement-processor' ) );
		}

		$sources = get_terms( [ 'taxonomy' => 'sp-source', 'hide_empty' => false ] );
		if ( is_wp_error( $sources ) ) {
			$sources = [];
		}

		include STATEMENT_PROCESSOR_PLUGIN_DIR . 'src/Admin/views/export-page.php';
	}
}
