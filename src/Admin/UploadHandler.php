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
	 * Transient expiry for review data (1 hour).
	 */
	const REVIEW_TRANSIENT_EXPIRY = 3600;

	/**
	 * Constructor; registers form handling.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'handle_import_selected' ], 5 );
		add_action( 'admin_init', [ $this, 'handle_upload' ], 10 );
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

		$include      = isset( $_POST['include'] ) && is_array( $_POST['include'] ) ? array_map( 'absint', $_POST['include'] ) : [];
		$transactions = $data['transactions'];
		$tx_post      = isset( $_POST['tx'] ) && is_array( $_POST['tx'] ) ? $_POST['tx'] : [];
		$single_source_term_id = isset( $data['source_term_id'] ) ? (int) $data['source_term_id'] : 0;
		$selected     = [];
		foreach ( $include as $index ) {
			$row_source_term_id = 0;
			$raw_source = isset( $tx_post[ $index ]['source_term_id'] ) ? $tx_post[ $index ]['source_term_id'] : '';
			if ( $raw_source === 'add_new' || $raw_source === '' || (int) $raw_source <= 0 ) {
				$new_name = isset( $tx_post[ $index ]['source_new'] ) ? trim( sanitize_text_field( wp_unslash( $tx_post[ $index ]['source_new'] ) ) ) : '';
				if ( $new_name !== '' ) {
					$term = $this->get_or_create_source_term( $new_name );
					$row_source_term_id = ( $term && ! is_wp_error( $term ) ) ? $term->term_id : 0;
				}
			} else {
				$row_source_term_id = absint( $raw_source );
			}
			if ( $row_source_term_id <= 0 && ( isset( $transactions[ $index ]['source_term_id'] ) && (int) $transactions[ $index ]['source_term_id'] > 0 ) ) {
				$row_source_term_id = (int) $transactions[ $index ]['source_term_id'];
			}
			if ( $row_source_term_id <= 0 && $single_source_term_id > 0 ) {
				$row_source_term_id = $single_source_term_id;
			}
			if ( $row_source_term_id <= 0 ) {
				continue;
			}

			if ( isset( $tx_post[ $index ] ) && is_array( $tx_post[ $index ] ) ) {
				$row = [
					'date'           => isset( $tx_post[ $index ]['date'] ) ? sanitize_text_field( wp_unslash( $tx_post[ $index ]['date'] ) ) : '',
					'time'           => isset( $tx_post[ $index ]['time'] ) ? sanitize_text_field( wp_unslash( $tx_post[ $index ]['time'] ) ) : '00:00:00',
					'description'    => isset( $tx_post[ $index ]['description'] ) ? sanitize_textarea_field( wp_unslash( $tx_post[ $index ]['description'] ) ) : '',
					'amount'         => isset( $tx_post[ $index ]['amount'] ) ? sanitize_text_field( wp_unslash( $tx_post[ $index ]['amount'] ) ) : '0',
					'origination'    => isset( $tx_post[ $index ]['origination'] ) ? sanitize_file_name( wp_unslash( $tx_post[ $index ]['origination'] ) ) : '',
					'source_term_id' => $row_source_term_id,
				];
				if ( $row['date'] !== '' || $row['description'] !== '' ) {
					$selected[] = $row;
				}
			} elseif ( isset( $transactions[ $index ] ) ) {
				$row = $transactions[ $index ];
				$row['source_term_id'] = $row_source_term_id;
				$selected[] = $row;
			}
		}

		if ( empty( $selected ) ) {
			delete_transient( $transient_key );
			set_transient( 'statement_processor_notice', __( 'No transactions selected, or some rows have no source. Select at least one row and a source for each.', 'statement-processor' ), 45 );
			wp_safe_redirect( $this->import_page_url() );
			exit;
		}

		$importer = new \StatementProcessor\Import\TransactionImporter();
		$results  = $importer->import( $selected );
		delete_transient( $transient_key );
		$this->set_notice( $results );
		wp_safe_redirect( $this->import_page_url() );
		exit;
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
			if ( $scratch_dir !== '' && is_readable( $file_path ) ) {
				$stored_name = uniqid( 'sp-', true ) . '-' . sanitize_file_name( $original_name );
				$dest        = $scratch_dir . '/' . $stored_name;
				if ( copy( $file_path, $dest ) ) {
					// File stored for reference; transactions still tagged with original filename.
				}
			}
			$parsed = $this->parse_file( $file_path, $original_name );
			foreach ( $parsed['transactions'] as $tx ) {
				$tx['origination'] = $original_name;
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
		exit;
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
			$parser = new \StatementProcessor\Parser\CsvParser();
			$rows   = $parser->parse( $file_path );
			$parsed = $parser->map_to_transactions( $rows );
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
	 * Set admin notice with import results.
	 *
	 * @param array $results Import results.
	 */
	private function set_notice( array $results ) {
		$message = sprintf(
			/* translators: 1: number imported, 2: number skipped */
			__( 'Imported %1$d transaction(s), skipped %2$d duplicate(s).', 'statement-processor' ),
			$results['imported'],
			$results['skipped']
		);
		if ( ! empty( $results['errors'] ) ) {
			$message .= ' ' . implode( ' ', array_slice( $results['errors'], 0, 3 ) );
		}
		set_transient( 'statement_processor_notice', $message, 45 );
	}
}
