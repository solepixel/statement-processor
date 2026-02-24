<?php
/**
 * Handles file upload and import for Statement Processor.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes uploaded PDF/CSV files; step 1 parses and shows review, step 2 imports selected.
 */
class UploadHandler {

	/**
	 * Transient key prefix for review data.
	 */
	const REVIEW_TRANSIENT_PREFIX = 'statement_processor_review_';

	/**
	 * Transient key prefix for multi-file upload session (array of parsed results per file).
	 */
	const UPLOAD_SESSION_PREFIX = 'statement_processor_upload_session_';

	/**
	 * Transient expiry for review data (1 hour).
	 */
	const REVIEW_TRANSIENT_EXPIRY = 3600;

	/**
	 * Transient expiry for upload session (10 minutes).
	 */
	const UPLOAD_SESSION_EXPIRY = 600;

	/**
	 * REST namespace for import batch (avoids admin-ajax returning HTML in some environments).
	 */
	const REST_NAMESPACE = 'statement-processor/v1';

	/**
	 * Option name for import history (list of { date, files }).
	 */
	const IMPORT_HISTORY_OPTION = 'statement_processor_import_history';

	/**
	 * Max number of import history entries to keep.
	 */
	const IMPORT_HISTORY_MAX = 100;

	/**
	 * Constructor; registers form handling and REST route for import batch.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_rest_import_batch' ] );
		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'handle_import_selected' ], 5 );
			add_action( 'admin_init', [ $this, 'handle_upload' ], 10 );
			add_action( 'admin_init', [ $this, 'log_ajax_request_action' ], 0 );
			add_action( 'wp_ajax_statement_processor_upload_one_file', [ $this, 'ajax_upload_one_file' ] );
			add_action( 'wp_ajax_statement_processor_import_batch', [ $this, 'ajax_import_batch' ] );
		}
	}

	/**
	 * Register REST route for import batch (returns JSON only, no admin HTML).
	 */
	public function register_rest_import_batch() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/import-batch',
			[
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => [ $this, 'rest_import_batch' ],
			]
		);
	}

	/**
	 * REST API handler for import batch; same logic as ajax_import_batch, returns JSON response.
	 *
	 * @param \WP_REST_Request $request Request (params read from $_POST for FormData).
	 * @return \WP_REST_Response
	 */
	public function rest_import_batch( $request ) {
		$post = isset( $_POST ) && is_array( $_POST ) ? $_POST : [];
		if ( ! isset( $post['statement_processor_import_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $post['statement_processor_import_nonce'] ) ), 'statement_processor_import_selected' ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'data' => [ 'message' => __( 'Invalid security token.', 'statement-processor' ) ] ], 400 );
		}
		$review_key   = isset( $post['statement_processor_review_key'] ) ? sanitize_text_field( wp_unslash( $post['statement_processor_review_key'] ) ) : '';
		$batch_number = isset( $post['batch_number'] ) ? (int) $post['batch_number'] : 0;
		$total_batches = isset( $post['total_batches'] ) ? (int) $post['total_batches'] : 0;
		if ( $review_key === '' || $total_batches < 1 ) {
			return new \WP_REST_Response( [ 'success' => false, 'data' => [ 'message' => __( 'Invalid request.', 'statement-processor' ) ] ], 400 );
		}
		$transient_key = self::REVIEW_TRANSIENT_PREFIX . $review_key;
		$data          = get_transient( $transient_key );
		if ( $data === false || ! is_array( $data ) || empty( $data['transactions'] ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'data' => [ 'message' => __( 'Review session expired or invalid.', 'statement-processor' ) ] ], 400 );
		}
		$include = isset( $post['include'] ) && is_array( $post['include'] ) ? array_map( 'absint', $post['include'] ) : [];
		$tx_post = $this->get_tx_post_from_request();
		try {
			$selected = $this->build_selected_transactions( $include, $tx_post, $data );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'success' => false, 'data' => [ 'message' => __( 'Error preparing transactions.', 'statement-processor' ) ] ], 500 );
		}
		$imported = 0;
		$skipped  = 0;
		$errors   = [];
		$skipped_transactions = [];
		if ( ! empty( $selected ) ) {
			try {
				$importer = new \StatementProcessor\Import\TransactionImporter();
				$results  = $importer->import( $selected );
				$imported = isset( $results['imported'] ) ? (int) $results['imported'] : 0;
				$skipped  = isset( $results['skipped'] ) ? (int) $results['skipped'] : 0;
				$errors   = isset( $results['errors'] ) && is_array( $results['errors'] ) ? $results['errors'] : [];
				$skipped_transactions = isset( $results['skipped_transactions'] ) && is_array( $results['skipped_transactions'] ) ? $results['skipped_transactions'] : [];
			} catch ( \Throwable $e ) {
				return new \WP_REST_Response( [ 'success' => false, 'data' => [ 'message' => __( 'Error during import.', 'statement-processor' ) ] ], 500 );
			}
		}
		$batch_totals_key = 'statement_processor_batch_totals_' . $review_key;
		$totals           = get_transient( $batch_totals_key );
		if ( ! is_array( $totals ) ) {
			$totals = [ 'imported' => 0, 'skipped' => 0, 'skipped_transactions' => [] ];
		}
		$totals['imported'] += $imported;
		$totals['skipped']  += $skipped;
		if ( ! empty( $skipped_transactions ) && is_array( $skipped_transactions ) ) {
			$totals['skipped_transactions'] = array_merge(
				isset( $totals['skipped_transactions'] ) ? $totals['skipped_transactions'] : [],
				$skipped_transactions
			);
		}
		set_transient( $batch_totals_key, $totals, self::REVIEW_TRANSIENT_EXPIRY );
		$is_last = ( $batch_number + 1 ) >= $total_batches;
		if ( $is_last ) {
			delete_transient( $transient_key );
			delete_transient( $batch_totals_key );
			$this->record_import_history( $selected );
			$this->set_notice( [
				'imported'             => $totals['imported'],
				'skipped'              => $totals['skipped'],
				'errors'               => $errors,
				'skipped_transactions' => isset( $totals['skipped_transactions'] ) ? $totals['skipped_transactions'] : [],
			] );
			return new \WP_REST_Response( [
				'success' => true,
				'data'    => [
					'done'          => true,
					'redirect_url'  => $this->import_page_url(),
					'imported'      => $totals['imported'],
					'skipped'       => $totals['skipped'],
				],
			], 200 );
		}
		return new \WP_REST_Response( [
			'success' => true,
			'data'    => [
				'done'     => false,
				'imported' => $imported,
				'skipped'  => $skipped,
			],
		], 200 );
	}

	/**
	 * Debug: log which action admin-ajax received (only when WP_DEBUG_LOG and DOING_AJAX).
	 */
	public function log_ajax_request_action() {
		if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( $action === 'statement_processor_import_batch' || $action === '' ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Statement Processor] admin-ajax request: action=' . ( $action === '' ? '(empty)' : $action ) . ' method=' . ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) );
		}
	}

	/**
	 * Build selected transaction rows from include indices and tx form data.
	 *
	 * @param int[] $include   Indices of selected rows.
	 * @param array $tx_post   Form tx data keyed by index.
	 * @param array $data      Transient data (transactions, source_term_id).
	 * @return array Rows ready for importer->import().
	 */
	private function build_selected_transactions( array $include, array $tx_post, array $data ) {
		$transactions          = isset( $data['transactions'] ) ? $data['transactions'] : [];
		$single_source_term_id = isset( $data['source_term_id'] ) ? (int) $data['source_term_id'] : 0;
		$selected              = [];
		foreach ( $include as $index ) {
			$row_source_term_id = 0;
			$raw_source         = isset( $tx_post[ $index ]['source_term_id'] ) ? $tx_post[ $index ]['source_term_id'] : '';
			if ( $raw_source === 'add_new' || $raw_source === '' || (int) $raw_source <= 0 ) {
				$new_name = isset( $tx_post[ $index ]['source_new'] ) ? trim( sanitize_text_field( wp_unslash( $tx_post[ $index ]['source_new'] ) ) ) : '';
				if ( $new_name !== '' ) {
					$term             = $this->get_or_create_source_term( $new_name );
					$row_source_term_id = ( $term && ! is_wp_error( $term ) ) ? $term->term_id : 0;
				}
			} else {
				$row_source_term_id = absint( $raw_source );
			}
			if ( $row_source_term_id <= 0 && isset( $transactions[ $index ]['source_term_id'] ) && (int) $transactions[ $index ]['source_term_id'] > 0 ) {
				$row_source_term_id = (int) $transactions[ $index ]['source_term_id'];
			}
			if ( $row_source_term_id <= 0 && $single_source_term_id > 0 ) {
				$row_source_term_id = $single_source_term_id;
			}
			if ( $row_source_term_id <= 0 ) {
				continue;
			}
			if ( isset( $tx_post[ $index ] ) && is_array( $tx_post[ $index ] ) ) {
				$origination = isset( $tx_post[ $index ]['origination'] ) ? sanitize_text_field( wp_unslash( $tx_post[ $index ]['origination'] ) ) : '';
				if ( $origination === '' && isset( $transactions[ $index ]['origination'] ) && $transactions[ $index ]['origination'] !== '' ) {
					$origination = sanitize_text_field( $transactions[ $index ]['origination'] );
				}
				$origination_stored = isset( $tx_post[ $index ]['origination_stored_name'] ) ? sanitize_file_name( wp_unslash( $tx_post[ $index ]['origination_stored_name'] ) ) : '';
				if ( $origination_stored === '' && isset( $transactions[ $index ]['origination_stored_name'] ) && $transactions[ $index ]['origination_stored_name'] !== '' ) {
					$origination_stored = sanitize_file_name( $transactions[ $index ]['origination_stored_name'] );
				}
				$row = [
					'date'                      => isset( $tx_post[ $index ]['date'] ) ? sanitize_text_field( wp_unslash( $tx_post[ $index ]['date'] ) ) : '',
					'time'                      => isset( $tx_post[ $index ]['time'] ) ? sanitize_text_field( wp_unslash( $tx_post[ $index ]['time'] ) ) : '00:00:00',
					'description'                => isset( $tx_post[ $index ]['description'] ) ? sanitize_textarea_field( wp_unslash( $tx_post[ $index ]['description'] ) ) : '',
					'amount'                    => isset( $tx_post[ $index ]['amount'] ) ? sanitize_text_field( wp_unslash( $tx_post[ $index ]['amount'] ) ) : '0',
					'origination'                => $origination,
					'origination_stored_name'    => $origination_stored,
					'source_term_id'             => $row_source_term_id,
				];
				if ( $row['date'] !== '' || $row['description'] !== '' ) {
					$selected[] = $row;
				}
			} elseif ( isset( $transactions[ $index ] ) ) {
				$row                  = $transactions[ $index ];
				$row['source_term_id'] = $row_source_term_id;
				if ( ! isset( $row['origination_stored_name'] ) ) {
					$row['origination_stored_name'] = '';
				}
				$selected[] = $row;
			}
		}
		return $selected;
	}

	/**
	 * Get transaction form data from request (batch JSON or raw POST).
	 * Prefers tx_batch JSON so user edits are not lost to PHP max_input_vars.
	 *
	 * @return array<int|string, array> Tx data keyed by row index.
	 */
	private function get_tx_post_from_request() {
		if ( isset( $_POST['tx_batch'] ) && is_string( $_POST['tx_batch'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['tx_batch'] ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return isset( $_POST['tx'] ) && is_array( $_POST['tx'] ) ? $_POST['tx'] : [];
	}

	/**
	 * Handle "Import selected" from review step: import only checked rows.
	 */
	public function handle_import_selected() {
		if ( ! isset( $_POST['statement_processor_import_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['statement_processor_import_nonce'] ) ), 'statement_processor_import_selected' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$review_key = isset( $_POST['statement_processor_review_key'] ) ? sanitize_text_field( wp_unslash( $_POST['statement_processor_review_key'] ) ) : '';
		if ( $review_key === '' ) {
			return;
		}
		$transient_key = self::REVIEW_TRANSIENT_PREFIX . $review_key;
		$data          = get_transient( $transient_key );
		if ( $data === false || ! is_array( $data ) || empty( $data['transactions'] ) ) {
			set_transient( 'statement_processor_notice', __( 'Review session expired or invalid. Please upload again.', 'statement-processor' ), 45 );
			wp_safe_redirect( $this->import_page_url() );
			exit;
		}
		$include  = isset( $_POST['include'] ) && is_array( $_POST['include'] ) ? array_map( 'absint', $_POST['include'] ) : [];
		$tx_post  = $this->get_tx_post_from_request();
		$selected = $this->build_selected_transactions( $include, $tx_post, $data );
		if ( empty( $selected ) ) {
			delete_transient( $transient_key );
			set_transient( 'statement_processor_notice', __( 'No transactions selected, or some rows have no source. Select at least one row and a source for each.', 'statement-processor' ), 45 );
			wp_safe_redirect( $this->import_page_url() );
			exit;
		}
		$importer = new \StatementProcessor\Import\TransactionImporter();
		$results  = $importer->import( $selected );
		delete_transient( $transient_key );
		$this->record_import_history( $selected );
		$this->set_notice( $results );
		wp_safe_redirect( $this->import_page_url() );
		exit;
	}

	/**
	 * Discard all output buffers so only our JSON is sent (import batch).
	 */
	private function discard_output_buffers() {
		while ( function_exists( 'ob_get_level' ) && ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Log debug message when WP_DEBUG_LOG is enabled (for import batch troubleshooting).
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional context (logged as JSON).
	 */
	private function log_import_debug( $message, array $context = [] ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			return;
		}
		$line = '[Statement Processor] ' . $message;
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}

	/**
	 * AJAX: import one batch of selected transactions; on last batch set notice and return redirect URL.
	 */
	public function ajax_import_batch() {
		$this->log_import_debug( 'ajax_import_batch entered', [
			'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
			'action_get'     => isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '',
			'action_post'    => isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '',
			'doing_ajax'     => defined( 'DOING_AJAX' ) && DOING_AJAX,
		] );
		if ( ! isset( $_POST['statement_processor_import_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['statement_processor_import_nonce'] ) ), 'statement_processor_import_selected' ) ) {
			$this->log_import_debug( 'ajax_import_batch: nonce check failed' );
			$this->discard_output_buffers();
			wp_send_json_error( [ 'message' => __( 'Invalid security token.', 'statement-processor' ) ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->discard_output_buffers();
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'statement-processor' ) ] );
		}
		$review_key   = isset( $_POST['statement_processor_review_key'] ) ? sanitize_text_field( wp_unslash( $_POST['statement_processor_review_key'] ) ) : '';
		$batch_number = isset( $_POST['batch_number'] ) ? (int) $_POST['batch_number'] : 0;
		$total_batches = isset( $_POST['total_batches'] ) ? (int) $_POST['total_batches'] : 0;
		if ( $review_key === '' || $total_batches < 1 ) {
			$this->discard_output_buffers();
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'statement-processor' ) ] );
		}
		$transient_key = self::REVIEW_TRANSIENT_PREFIX . $review_key;
		$data          = get_transient( $transient_key );
		if ( $data === false || ! is_array( $data ) || empty( $data['transactions'] ) ) {
			$this->discard_output_buffers();
			wp_send_json_error( [ 'message' => __( 'Review session expired or invalid.', 'statement-processor' ) ] );
		}
		$include = isset( $_POST['include'] ) && is_array( $_POST['include'] ) ? array_map( 'absint', $_POST['include'] ) : [];
		$tx_post = $this->get_tx_post_from_request();
		try {
			$selected = $this->build_selected_transactions( $include, $tx_post, $data );
		} catch ( \Throwable $e ) {
			$this->discard_output_buffers();
			wp_send_json_error( [ 'message' => __( 'Error preparing transactions.', 'statement-processor' ) ] );
		}
		$imported = 0;
		$skipped  = 0;
		$errors   = [];
		$skipped_transactions = [];
		if ( ! empty( $selected ) ) {
			try {
				$importer = new \StatementProcessor\Import\TransactionImporter();
				$results  = $importer->import( $selected );
				$imported = isset( $results['imported'] ) ? (int) $results['imported'] : 0;
				$skipped  = isset( $results['skipped'] ) ? (int) $results['skipped'] : 0;
				$errors   = isset( $results['errors'] ) && is_array( $results['errors'] ) ? $results['errors'] : [];
				$skipped_transactions = isset( $results['skipped_transactions'] ) && is_array( $results['skipped_transactions'] ) ? $results['skipped_transactions'] : [];
			} catch ( \Throwable $e ) {
				$this->discard_output_buffers();
				wp_send_json_error( [ 'message' => __( 'Error during import.', 'statement-processor' ) ] );
			}
		}
		$batch_totals_key = 'statement_processor_batch_totals_' . $review_key;
		$totals           = get_transient( $batch_totals_key );
		if ( ! is_array( $totals ) ) {
			$totals = [ 'imported' => 0, 'skipped' => 0, 'skipped_transactions' => [] ];
		}
		$totals['imported'] += $imported;
		$totals['skipped']  += $skipped;
		if ( ! empty( $skipped_transactions ) && is_array( $skipped_transactions ) ) {
			$totals['skipped_transactions'] = array_merge(
				isset( $totals['skipped_transactions'] ) ? $totals['skipped_transactions'] : [],
				$skipped_transactions
			);
		}
		set_transient( $batch_totals_key, $totals, self::REVIEW_TRANSIENT_EXPIRY );
		$is_last = ( $batch_number + 1 ) >= $total_batches;
		if ( $is_last ) {
			delete_transient( $transient_key );
			delete_transient( $batch_totals_key );
			$this->record_import_history( $selected );
			$this->set_notice( [
				'imported'            => $totals['imported'],
				'skipped'             => $totals['skipped'],
				'errors'              => $errors,
				'skipped_transactions' => isset( $totals['skipped_transactions'] ) ? $totals['skipped_transactions'] : [],
			] );
			$this->log_import_debug( 'ajax_import_batch: sending JSON success (last batch)' );
			$this->discard_output_buffers();
			wp_send_json_success( [
				'done'         => true,
				'redirect_url' => $this->import_page_url(),
				'imported'     => $totals['imported'],
				'skipped'      => $totals['skipped'],
			] );
		}
		$this->log_import_debug( 'ajax_import_batch: sending JSON success (batch)', [ 'batch' => $batch_number + 1, 'total' => $total_batches ] );
		$this->discard_output_buffers();
		wp_send_json_success( [
			'done'     => false,
			'imported' => $imported,
			'skipped'  => $skipped,
		] );
	}

	/**
	 * Handle upload form: parse files, store in transient, redirect to review.
	 */
	public function handle_upload() {
		if ( ! isset( $_POST['statement_processor_upload_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['statement_processor_upload_nonce'] ) ), 'statement_processor_upload' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$source_select = isset( $_POST['statement_processor_source'] ) ? sanitize_text_field( wp_unslash( $_POST['statement_processor_source'] ) ) : '';
		$detect_auto   = $source_select === 'detect_auto';
		$source_name   = '';
		if ( ! $detect_auto ) {
			if ( $source_select === 'add_new' ) {
				$source_name = isset( $_POST['statement_processor_source_new'] ) ? sanitize_text_field( wp_unslash( $_POST['statement_processor_source_new'] ) ) : '';
			} else {
				$source_name = $source_select;
			}
			if ( empty( $source_name ) ) {
				return;
			}
		}

		$single_term = null;
		if ( ! $detect_auto ) {
			$single_term = $this->get_or_create_source_term( $source_name );
			if ( ! $single_term || is_wp_error( $single_term ) ) {
				return;
			}
		}

		$files = $this->get_uploaded_files();
		if ( empty( $files ) ) {
			return;
		}

		$all_transactions = [];
		$errors          = [];
		$upload_dir      = wp_upload_dir();
		$scratch_dir     = $upload_dir['basedir'] . '/scratch';
		if ( ! wp_mkdir_p( $scratch_dir ) ) {
			$scratch_dir = '';
		}

		foreach ( $files as $original_name => $file_path ) {
			$stored_file_name = '';
			if ( $scratch_dir !== '' && is_readable( $file_path ) ) {
				$stored_name = uniqid( 'sp-', true ) . '-' . sanitize_file_name( $original_name );
				$dest        = $scratch_dir . '/' . $stored_name;
				if ( copy( $file_path, $dest ) ) {
					$stored_file_name = $stored_name;
				}
			}
			$parsed = $this->parse_file( $file_path, $original_name );
			foreach ( $parsed['transactions'] as $tx ) {
				$tx['origination'] = $original_name;
				if ( $stored_file_name !== '' ) {
					$tx['origination_stored_name'] = $stored_file_name;
				}
				$all_transactions[] = $tx;
			}
			if ( ! empty( $parsed['errors'] ) ) {
				$errors = array_merge( $errors, $parsed['errors'] );
			}
		}

		if ( $detect_auto ) {
			foreach ( $all_transactions as $i => $tx ) {
				if ( ! empty( $tx['source_name'] ) ) {
					$term = get_term_by( 'name', $tx['source_name'], 'sp-source' );
					$all_transactions[ $i ]['source_term_id'] = ( $term && ! is_wp_error( $term ) ) ? $term->term_id : 0;
					$all_transactions[ $i ]['source_name']    = $tx['source_name'];
				} else {
					$all_transactions[ $i ]['source_term_id'] = 0;
				}
			}
		} else {
			foreach ( $all_transactions as $i => $tx ) {
				$all_transactions[ $i ]['source_term_id'] = $single_term->term_id;
			}
		}

		$importer = new \StatementProcessor\Import\TransactionImporter();
		$all_transactions = $importer->prepare_transactions_for_review( $all_transactions );

		if ( empty( $all_transactions ) ) {
			$message = __( 'No transactions could be parsed from the uploaded file(s).', 'statement-processor' );
			if ( ! empty( $errors ) ) {
				$message .= ' ' . implode( ' ', array_slice( $errors, 0, 2 ) );
			}
			set_transient( 'statement_processor_notice', $message, 45 );
			return;
		}

		$review_key   = wp_generate_password( 32, false );
		$transient_key = self::REVIEW_TRANSIENT_PREFIX . $review_key;
		$review_payload = [
			'transactions' => array_values( $all_transactions ),
		];
		if ( $single_term ) {
			$review_payload['source_term_id'] = $single_term->term_id;
			$review_payload['source_name']    = $single_term->name;
		} else {
			$review_payload['source_term_id'] = 0;
			$review_payload['source_name']    = _x( 'Multiple (set per row)', 'review source label', 'statement-processor' );
		}
		set_transient( $transient_key, $review_payload, self::REVIEW_TRANSIENT_EXPIRY );

		wp_safe_redirect( add_query_arg( [ 'review' => '1', 'key' => $review_key ], $this->import_page_url() ) );
		exit;
	}

	/**
	 * AJAX: upload and parse one file; accumulate in session; on last file return review key and redirect URL.
	 */
	public function ajax_upload_one_file() {
		if ( ! isset( $_POST['statement_processor_upload_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['statement_processor_upload_nonce'] ) ), 'statement_processor_upload' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid security token.', 'statement-processor' ) ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'statement-processor' ) ] );
		}

		$session_id    = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$file_index    = isset( $_POST['file_index'] ) ? (int) $_POST['file_index'] : 0;
		$total_files   = isset( $_POST['total_files'] ) ? (int) $_POST['total_files'] : 0;
		$source_select = isset( $_POST['statement_processor_source'] ) ? sanitize_text_field( wp_unslash( $_POST['statement_processor_source'] ) ) : '';
		$detect_auto   = $source_select === 'detect_auto';
		$source_name   = '';
		if ( ! $detect_auto ) {
			$source_name = ( $source_select === 'add_new' && isset( $_POST['statement_processor_source_new'] ) )
				? sanitize_text_field( wp_unslash( $_POST['statement_processor_source_new'] ) )
				: $source_select;
		}

		if ( $session_id === '' || $total_files < 1 || $file_index < 0 || $file_index >= $total_files ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'statement-processor' ) ] );
		}

		$single_term = null;
		if ( ! $detect_auto && $source_name !== '' ) {
			$single_term = $this->get_or_create_source_term( $source_name );
			if ( ! $single_term || is_wp_error( $single_term ) ) {
				wp_send_json_error( [ 'message' => __( 'Invalid source.', 'statement-processor' ) ] );
			}
		}

		$file_path     = '';
		$original_name = '';
		if ( ! empty( $_FILES['statement_processor_file'] ) && is_uploaded_file( $_FILES['statement_processor_file']['tmp_name'] ) ) {
			$file_path     = $_FILES['statement_processor_file']['tmp_name'];
			$original_name = isset( $_FILES['statement_processor_file']['name'] ) ? sanitize_file_name( $_FILES['statement_processor_file']['name'] ) : 'file';
			$ext           = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, [ 'pdf', 'csv' ], true ) ) {
				wp_send_json_error( [ 'message' => __( 'Invalid file type.', 'statement-processor' ) ] );
			}
		}
		if ( $file_path === '' ) {
			wp_send_json_error( [ 'message' => __( 'No file received.', 'statement-processor' ) ] );
		}

		$parsed       = $this->parse_file( $file_path, $original_name );
		$transactions = [];
		foreach ( $parsed['transactions'] as $tx ) {
			$tx['origination'] = $original_name;
			$transactions[]    = $tx;
		}

		$session_key  = self::UPLOAD_SESSION_PREFIX . $session_id;
		$session_data = get_transient( $session_key );
		if ( ! is_array( $session_data ) ) {
			$session_data = [
				'files'           => [],
				'detect_auto'     => $detect_auto,
				'single_term_id'  => $single_term ? $single_term->term_id : null,
			];
		}
		$session_data['files'][] = [ 'name' => $original_name, 'transactions' => $transactions ];
		set_transient( $session_key, $session_data, self::UPLOAD_SESSION_EXPIRY );

		$is_last = ( $file_index + 1 ) === $total_files;
		if ( ! $is_last ) {
			wp_send_json_success( [ 'next' => true ] );
		}

		$all_transactions = [];
		foreach ( $session_data['files'] as $f ) {
			foreach ( $f['transactions'] as $tx ) {
				$all_transactions[] = $tx;
			}
		}

		if ( $session_data['detect_auto'] ) {
			foreach ( $all_transactions as $i => $tx ) {
				if ( ! empty( $tx['source_name'] ) ) {
					$term = get_term_by( 'name', $tx['source_name'], 'sp-source' );
					$all_transactions[ $i ]['source_term_id'] = ( $term && ! is_wp_error( $term ) ) ? $term->term_id : 0;
					$all_transactions[ $i ]['source_name']    = $tx['source_name'];
				} else {
					$all_transactions[ $i ]['source_term_id'] = 0;
				}
			}
		} else {
			$tid = (int) $session_data['single_term_id'];
			foreach ( $all_transactions as $i => $tx ) {
				$all_transactions[ $i ]['source_term_id'] = $tid;
			}
		}

		$importer         = new \StatementProcessor\Import\TransactionImporter();
		$all_transactions = $importer->prepare_transactions_for_review( $all_transactions );
		delete_transient( $session_key );

		if ( empty( $all_transactions ) ) {
			wp_send_json_error( [ 'message' => __( 'No transactions could be parsed from the uploaded file(s).', 'statement-processor' ) ] );
		}

		$review_key     = wp_generate_password( 32, false );
		$transient_key  = self::REVIEW_TRANSIENT_PREFIX . $review_key;
		$review_payload = [
			'transactions' => array_values( $all_transactions ),
		];
		if ( $single_term ) {
			$review_payload['source_term_id'] = $single_term->term_id;
			$review_payload['source_name']    = $single_term->name;
		} else {
			$review_payload['source_term_id'] = 0;
			$review_payload['source_name']    = _x( 'Multiple (set per row)', 'review source label', 'statement-processor' );
		}
		set_transient( $transient_key, $review_payload, self::REVIEW_TRANSIENT_EXPIRY );

		wp_send_json_success( [
			'reviewKey'    => $review_key,
			'redirectUrl' => add_query_arg( [ 'review' => '1', 'key' => $review_key ], $this->import_page_url() ),
		] );
	}

	/**
	 * URL for the Import admin page.
	 *
	 * @return string
	 */
	private function import_page_url() {
		return admin_url( 'admin.php?page=' . AdminPage::PAGE_SLUG );
	}

	/**
	 * Get or create the source taxonomy term.
	 *
	 * @param string $name Source name.
	 * @return \WP_Term|\WP_Error|false
	 */
	private function get_or_create_source_term( $name ) {
		$existing = get_term_by( 'name', $name, 'sp-source' );
		if ( $existing ) {
			return $existing;
		}
		$result = wp_insert_term( $name, 'sp-source' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return get_term( $result['term_id'], 'sp-source' );
	}

	/**
	 * Get validated uploaded files (PDF and CSV only).
	 *
	 * @return array List of tmp file paths keyed by name.
	 */
	private function get_uploaded_files() {
		if ( empty( $_FILES['statement_processor_files'] ) || empty( $_FILES['statement_processor_files']['tmp_name'] ) ) {
			return [];
		}

		$files = [];
		$tmp   = $_FILES['statement_processor_files']['tmp_name'];
		$name  = $_FILES['statement_processor_files']['name'];
		$type  = $_FILES['statement_processor_files']['type'];

		$single = ! is_array( $tmp );
		if ( $single ) {
			$tmp   = [ $tmp ];
			$name  = [ $name ];
			$type  = [ $type ];
		}

		$allowed_types = [
			'application/pdf' => true,
			'text/csv'        => true,
			'text/plain'      => true,
		];

		foreach ( $tmp as $i => $path ) {
			if ( empty( $path ) || ! is_uploaded_file( $path ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $name[ $i ], PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, [ 'pdf', 'csv' ], true ) ) {
				continue;
			}
			$mime = $type[ $i ];
			if ( isset( $allowed_types[ $mime ] ) || ( $ext === 'pdf' && strpos( $mime, 'pdf' ) !== false ) || ( $ext === 'csv' && ( strpos( $mime, 'csv' ) !== false || strpos( $mime, 'text' ) !== false ) ) ) {
				$files[ $name[ $i ] ] = $path;
			}
		}

		return $files;
	}

	/**
	 * Parse a single file and return transactions (no import).
	 *
	 * @param string $file_path     Temp file path.
	 * @param string $original_name Original filename (for extension detection).
	 * @return array{transactions: array, errors: string[]}
	 */
	private function parse_file( $file_path, $original_name ) {
		$ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( $ext === 'csv' ) {
			if ( \StatementProcessor\Parser\AllyCsvParser::is_ally_csv( $file_path ) ) {
				$ally_parser = new \StatementProcessor\Parser\AllyCsvParser();
				$parsed     = $ally_parser->parse( $file_path );
			} elseif ( \StatementProcessor\Parser\CapitalOneCsvParser::is_capital_one_csv( $file_path ) ) {
				$co_parser = new \StatementProcessor\Parser\CapitalOneCsvParser();
				$parsed   = $co_parser->parse( $file_path, $original_name );
			} else {
				$parser = new \StatementProcessor\Parser\CsvParser();
				$rows   = $parser->parse( $file_path );
				$parsed = $parser->map_to_transactions( $rows );
			}
		} else {
			$parser = new \StatementProcessor\Parser\PdfParser();
			$parsed = $parser->parse( $file_path );
		}

		if ( empty( $parsed ) ) {
			return [ 'transactions' => [], 'errors' => [ __( 'No transactions could be parsed from this file.', 'statement-processor' ) ] ];
		}

		return [ 'transactions' => $parsed, 'errors' => [] ];
	}

	/**
	 * Set admin notice with import results; store skipped duplicate details for display.
	 *
	 * @param array $results Import results (imported, skipped, errors, skipped_transactions).
	 */
	private function set_notice( array $results ) {
		$message = sprintf(
			/* translators: 1: number imported, 2: number skipped */
			__( 'Imported %1$d transaction(s), skipped %2$d duplicate(s).', 'statement-processor' ),
			isset( $results['imported'] ) ? (int) $results['imported'] : 0,
			isset( $results['skipped'] ) ? (int) $results['skipped'] : 0
		);
		if ( ! empty( $results['errors'] ) ) {
			$message .= ' ' . implode( ' ', array_slice( $results['errors'], 0, 3 ) );
		}
		set_transient( 'statement_processor_notice', $message, 45 );
		if ( ! empty( $results['skipped_transactions'] ) && is_array( $results['skipped_transactions'] ) ) {
			set_transient( 'statement_processor_skipped_duplicates', $results['skipped_transactions'], 300 );
		}
	}

	/**
	 * Append one entry to import history (unique filenames from selected transactions).
	 *
	 * @param array $selected Selected transactions (each may have 'origination').
	 */
	private function record_import_history( array $selected ) {
		$files = array_values( array_unique( array_filter( array_map( function ( $row ) {
			return isset( $row['origination'] ) ? $row['origination'] : null;
		}, $selected ) ) ) );
		if ( empty( $files ) ) {
			return;
		}
		$history = get_option( self::IMPORT_HISTORY_OPTION, [] );
		if ( ! is_array( $history ) ) {
			$history = [];
		}
		array_unshift( $history, [
			'date'  => current_time( 'mysql' ),
			'files' => $files,
		] );
		$history = array_slice( $history, 0, self::IMPORT_HISTORY_MAX );
		update_option( self::IMPORT_HISTORY_OPTION, $history );
	}
}
