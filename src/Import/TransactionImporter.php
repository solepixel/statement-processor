<?php
/**
 * Imports parsed transactions into sp-transaction posts.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Import;

use StatementProcessor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates transaction posts with post_date, meta, and source taxonomy; skips duplicates by _transaction_id.
 */
class TransactionImporter {

	/**
	 * Meta key for transaction ID (deduplication).
	 */
	const META_TRANSACTION_ID = '_transaction_id';

	/**
	 * Meta key for amount.
	 */
	const META_AMOUNT = '_amount';

	/**
	 * Meta key for description.
	 */
	const META_DESCRIPTION = '_description';

	/**
	 * Meta key for source filename (origination).
	 */
	const META_ORIGINATION = '_origination';

	/**
	 * Description phrases that are not transactions (balance lines, headers, etc.).
	 *
	 * @var string[]
	 */
	private static $excluded_descriptions = [
		'beginning balance',
		'begining balance',
		'starting balance',
		'ending balance',
		'opening balance',
		'closing balance',
		'previous balance',
		'current balance',
		'balance forward',
		'balance brought forward',
		'total credits',
		'total debits',
		'credit total',
		'debit total',
		'payment due date',
		'statement closing date',
		'past due amount',
		'trans date',
		'post date',
		'reference',
		'transactions',
		'description',
		'amount',
		'total fees for this period',
		'interest charged',
		'interest charge on purchases',
		'interest charge on cash advances',
		'total interest for this period',
		'fees',
		'fsf1',
		'fsfq',
	];

	/**
	 * Description phrases that indicate a credit/deposit/payment (money in) — stored as positive.
	 *
	 * @var string[]
	 */
	private static $credit_deposit_phrases = [
		'payment',
		'credit',
		'deposit',
		'refund',
		'reimbursement',
		'reward',
		'cash back',
		'cashback',
		'interest credit',
		'direct deposit',
		'payroll',
		'transfer from',
		'payment received',
		'credit adjustment',
		'credit balance',
		'early pay',
		'ach from',
		'ach deposit',
		'check deposit',
		'pos credit',
	];

	/**
	 * Normalize amount signs for display/import: credits/deposits = positive, others = negative.
	 * Call before showing the review table so the user sees the values that will be imported.
	 *
	 * @param array<int, array{date: string, time?: string, description: string, amount: string|float, origination?: string, source_name?: string, source_term_id?: int}> $transactions Parsed transactions.
	 * @return array<int, array{date: string, time: string, description: string, amount: string, origination?: string, source_name?: string, source_term_id?: int}>
	 */
	public function prepare_transactions_for_review( array $transactions ) {
		$out = [];
		foreach ( $transactions as $t ) {
			$amount       = isset( $t['amount'] ) ? $this->normalize_amount( $t['amount'] ) : '0';
			$description  = isset( $t['description'] ) ? sanitize_text_field( $t['description'] ) : '';
			$amount       = $this->normalize_amount_sign( $amount, $description );
			$row          = [
				'date'        => isset( $t['date'] ) ? $t['date'] : '',
				'time'        => isset( $t['time'] ) && $t['time'] !== '' ? $t['time'] : '00:00:00',
				'description' => $description,
				'amount'      => $amount,
				'origination' => isset( $t['origination'] ) ? sanitize_file_name( $t['origination'] ) : '',
			];
			if ( isset( $t['source_term_id'] ) ) {
				$row['source_term_id'] = (int) $t['source_term_id'];
			}
			if ( isset( $t['source_name'] ) ) {
				$row['source_name'] = sanitize_text_field( $t['source_name'] );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Import an array of parsed transactions.
	 *
	 * Each item must have: date (Y-m-d), time (optional), description, amount (numeric string or number).
	 *
	 * @param array<int, array{date: string, time?: string, description: string, amount: string|float, source_term_id?: int}> $transactions Parsed transactions (each may have source_term_id when using per-row source).
	 * @param int|null                                                                                  $source_term_id Optional single source term ID for all rows (when not using per-row source).
	 * @return array{imported: int, skipped: int, errors: string[]}
	 */
	public function import( array $transactions, $source_term_id = null ) {
		$imported = 0;
		$skipped  = 0;
		$errors   = [];

		foreach ( $transactions as $t ) {
			$row_source_term_id = isset( $t['source_term_id'] ) ? (int) $t['source_term_id'] : null;
			$term_id = $row_source_term_id !== null && $row_source_term_id > 0 ? $row_source_term_id : $source_term_id;
			if ( $term_id === null || $term_id <= 0 ) {
				continue;
			}

			$date        = isset( $t['date'] ) ? $t['date'] : '';
			$time        = isset( $t['time'] ) ? $t['time'] : '00:00:00';
			$description = isset( $t['description'] ) ? sanitize_text_field( $t['description'] ) : '';
			$amount       = isset( $t['amount'] ) ? $this->normalize_amount( $t['amount'] ) : '0';
			$amount       = $this->normalize_amount_sign( $amount, $description );
			$origination  = isset( $t['origination'] ) ? sanitize_file_name( $t['origination'] ) : '';

			if ( empty( $date ) || $description === '' ) {
				continue;
			}

			if ( $this->is_excluded_description( $description ) ) {
				continue;
			}

			$transaction_id = $this->generate_transaction_id( $date, $time, $description, $amount );
			if ( $this->find_existing_by_transaction_id( $transaction_id ) ) {
				++$skipped;
				continue;
			}

			$post_date = $date;
			if ( ! empty( $time ) ) {
				$post_date .= ' ' . $time;
			} else {
				$post_date .= ' 00:00:00';
			}

			$title = $description . ' ' . $amount;

			$post_id = wp_insert_post(
				[
					'post_type'   => Plugin::post_type(),
					'post_title'  => $title,
					'post_status' => 'publish',
					'post_date'   => $post_date,
					'post_author' => get_current_user_id(),
				],
				true
			);

			if ( is_wp_error( $post_id ) ) {
				$errors[] = $post_id->get_error_message();
				continue;
			}

			update_post_meta( $post_id, self::META_TRANSACTION_ID, $transaction_id );
			update_post_meta( $post_id, self::META_AMOUNT, $amount );
			update_post_meta( $post_id, self::META_DESCRIPTION, $description );
			if ( $origination !== '' ) {
				update_post_meta( $post_id, self::META_ORIGINATION, $origination );
			}
			wp_set_object_terms( $post_id, (int) $term_id, Plugin::taxonomy_source() );
			++$imported;
		}

		return [ 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors ];
	}

	/**
	 * Whether the description indicates a non-transaction line (balance, totals, etc.).
	 *
	 * @param string $description Sanitized description.
	 * @return bool
	 */
	private function is_excluded_description( $description ) {
		$normalized = strtolower( trim( $description ) );
		if ( $normalized === '' ) {
			return true;
		}
		$excluded = apply_filters( 'statement_processor_excluded_descriptions', self::$excluded_descriptions );
		foreach ( (array) $excluded as $phrase ) {
			$phrase = strtolower( trim( (string) $phrase ) );
			if ( $phrase !== '' && strpos( $normalized, $phrase ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the description indicates a payment, credit, or deposit (money in).
	 *
	 * @param string $description Sanitized description.
	 * @return bool
	 */
	private function is_credit_or_deposit( $description ) {
		$normalized = strtolower( trim( $description ) );
		if ( $normalized === '' ) {
			return false;
		}
		$phrases = apply_filters( 'statement_processor_credit_deposit_phrases', self::$credit_deposit_phrases );
		foreach ( (array) $phrases as $phrase ) {
			$phrase = strtolower( trim( (string) $phrase ) );
			if ( $phrase !== '' && strpos( $normalized, $phrase ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize amount sign for consistency: credits/deposits/payments = positive, all others = negative.
	 *
	 * @param string $amount      Amount string (e.g. "50.00" or "-50.00").
	 * @param string $description Transaction description.
	 * @return string
	 */
	private function normalize_amount_sign( $amount, $description ) {
		$num = (float) $amount;
		if ( $num === 0.0 ) {
			return '0.00';
		}
		$is_credit = $this->is_credit_or_deposit( $description );
		if ( $is_credit ) {
			$num = abs( $num );
		} else {
			$num = -1 * abs( $num );
		}
		return number_format( $num, 2, '.', '' );
	}

	/**
	 * Generate a deterministic transaction ID for deduplication.
	 *
	 * @param string $date        Date Y-m-d.
	 * @param string $time        Time.
	 * @param string $description Description.
	 * @param string $amount      Amount.
	 * @return string
	 */
	public function generate_transaction_id( $date, $time, $description, $amount ) {
		$payload = $date . '|' . $time . '|' . $description . '|' . $amount;
		return 'sp-' . md5( $payload );
	}

	/**
	 * Find an existing transaction post by _transaction_id meta.
	 *
	 * @param string $transaction_id Generated ID.
	 * @return int|null Post ID or null.
	 */
	private function find_existing_by_transaction_id( $transaction_id ) {
		$query = new \WP_Query(
			[
				'post_type'      => Plugin::post_type(),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'   => self::META_TRANSACTION_ID,
						'value' => $transaction_id,
					],
				],
			]
		);
		$ids = $query->posts;
		return ! empty( $ids ) ? (int) $ids[0] : null;
	}

	/**
	 * Normalize amount to a string with two decimal places.
	 * Strips commas (thousands), spaces, and handles parentheses for negative.
	 *
	 * @param string|float $amount Raw amount.
	 * @return string
	 */
	private function normalize_amount( $amount ) {
		if ( is_numeric( $amount ) ) {
			return number_format( (float) $amount, 2, '.', '' );
		}
		$amount = trim( (string) $amount );
		$amount = str_replace( ',', '', $amount );
		$amount = preg_replace( '/\s+/', '', $amount );
		$amount = preg_replace( '/[^\d.\-\(\)]/', '', $amount );
		$amount = str_replace( [ '(', ')' ], [ '-', '' ], $amount );
		if ( $amount === '' || $amount === '-' ) {
			return '0.00';
		}
		return number_format( (float) $amount, 2, '.', '' );
	}
}
