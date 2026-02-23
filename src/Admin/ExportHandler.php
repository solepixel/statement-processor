<?php
/**
 * Handles CSV export for Statement Processor.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports transactions to CSV with date and source filters.
 */
class ExportHandler {

	/**
	 * Constructor; registers export action.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'maybe_export' ] );
	}

	/**
	 * If export requested, validate nonce and output CSV.
	 */
	public function maybe_export() {
		if ( ! isset( $_GET['statement_processor_export'] ) || $_GET['statement_processor_export'] !== '1' ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'statement_processor_export' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'statement-processor' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'statement-processor' ) );
		}

		$exporter = new \StatementProcessor\Export\CsvExporter();
		$exporter->export();
		exit;
	}
}
