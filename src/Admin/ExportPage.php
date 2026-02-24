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
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_export_assets' ), 10, 1 );
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
	 * Enqueue scripts and styles for the Export page (column sortable).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_export_assets( $hook_suffix ) {
		if ( $hook_suffix !== 'sp-transaction_page_' . self::PAGE_SLUG ) {
			return;
		}
		wp_enqueue_script( 'jquery-ui-sortable' );
	}

	/**
	 * Render the Export page content.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'statement-processor' ) );
		}

		$sources = get_terms( array( 'taxonomy' => 'sp-source', 'hide_empty' => false ) );
		if ( is_wp_error( $sources ) ) {
			$sources = array();
		}

		$available_columns = \StatementProcessor\Export\CsvExporter::get_available_columns();
		$default_columns   = \StatementProcessor\Export\CsvExporter::DEFAULT_COLUMNS;

		include STATEMENT_PROCESSOR_PLUGIN_DIR . 'src/Admin/views/export-page.php';
	}
}
