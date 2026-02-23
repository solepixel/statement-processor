<?php
/**
 * Imports parsed transactions into sp-transaction posts.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Import;

use StatementProcessor\Admin\SettingsPage;
use StatementProcessor\Classification\TransactionClassifier;
use StatementProcessor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates transaction posts with post_date, meta, and source taxonomy.
 * Duplicates are only checked against existing DB transactions (previous imports).
 * Within the same import batch, multiple rows with same date/description/amount are all imported (each gets a unique _transaction_id suffix).
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
	 * Meta key for stored source file name (in uploads/scratch) for linking.
	 */
	const META_ORIGINATION_FILE = '_origination_file';

	/**
	 * Meta key indicating classification has been attempted (AI or automation) for this transaction.
	 */
	const META_CATEGORY_ATTEMPTED = '_sp_category_attempted';

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
		'wave',  // Wave (payments/payroll) credits on statements.
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
	 * Phrases that force a transaction to be treated as a debit (money out), even if it matches a credit phrase.
	 *
	 * @var string[]
	 */
	private static $debit_override_phrases = [
		'payroll fees',  // e.g. "Wave Payroll Fees" — fees paid out, not income.
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
				'date'                    => isset( $t['date'] ) ? $t['date'] : '',
				'time'                    => isset( $t['time'] ) && $t['time'] !== '' ? $t['time'] : '00:00:00',
				'description'             => $description,
				'amount'                  => $amount,
				'origination'             => isset( $t['origination'] ) ? sanitize_text_field( $t['origination'] ) : '',
				'origination_stored_name' => isset( $t['origination_stored_name'] ) ? sanitize_file_name( $t['origination_stored_name'] ) : '',
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
		$skipped_transactions = [];

		// Assign unique transaction_ids within this batch so duplicates are only vs existing DB (previous imports).
		$assigned_ids = $this->assign_transaction_ids_for_batch( $transactions, $source_term_id );

		foreach ( $transactions as $idx => $t ) {
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
			$origination   = isset( $t['origination'] ) ? sanitize_text_field( $t['origination'] ) : '';
			$origination_file = isset( $t['origination_stored_name'] ) ? sanitize_file_name( $t['origination_stored_name'] ) : '';

			if ( empty( $date ) || $description === '' ) {
				continue;
			}

			if ( $this->is_excluded_description( $description ) ) {
				continue;
			}

			$transaction_id = isset( $assigned_ids[ $idx ] ) ? $assigned_ids[ $idx ] : $this->generate_transaction_id( $date, $time, $description, $amount );
			if ( $this->find_existing_by_transaction_id( $transaction_id ) ) {
				++$skipped;
				$skipped_transactions[] = [
					'date'        => $date,
					'time'        => $time,
					'description' => $description,
					'amount'      => $amount,
				];
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
			if ( $origination_file !== '' ) {
				update_post_meta( $post_id, self::META_ORIGINATION_FILE, $origination_file );
			}
			wp_set_object_terms( $post_id, (int) $term_id, Plugin::taxonomy_source() );

			$opts = SettingsPage::get_options();
			if ( ! empty( $opts['auto_categorize_on_import'] ) ) {
				$classifier = new TransactionClassifier();
				$category_term_id = $classifier->classify( $description );
				if ( $category_term_id !== null ) {
					wp_set_object_terms( $post_id, $category_term_id, Plugin::taxonomy_category() );
				}
				update_post_meta( $post_id, self::META_CATEGORY_ATTEMPTED, '1' );
			}

			++$imported;
		}

		return [
			'imported'            => $imported,
			'skipped'             => $skipped,
			'errors'              => $errors,
			'skipped_transactions' => $skipped_transactions,
		];
	}

	/**
	 * Assign transaction_ids for this batch so within-batch duplicates get unique ids (only DB is checked for duplicates).
	 *
	 * @param array<int, array{date: string, time?: string, description: string, amount: string|float, source_term_id?: int}> $transactions Same as import().
	 * @param int|null $source_term_id Same as import().
	 * @return array<int, string> Index => assigned _transaction_id (only for processable rows).
	 */
	private function assign_transaction_ids_for_batch( array $transactions, $source_term_id ) {
		$base_ids = []; // index => base transaction_id for each processable row.
		foreach ( $transactions as $idx => $t ) {
			$row_source_term_id = isset( $t['source_term_id'] ) ? (int) $t['source_term_id'] : null;
			$term_id = $row_source_term_id !== null && $row_source_term_id > 0 ? $row_source_term_id : $source_term_id;
			if ( $term_id === null || $term_id <= 0 ) {
				continue;
			}
			$date        = isset( $t['date'] ) ? $t['date'] : '';
			$time        = isset( $t['time'] ) ? $t['time'] : '00:00:00';
			$description = isset( $t['description'] ) ? sanitize_text_field( $t['description'] ) : '';
			$amount      = isset( $t['amount'] ) ? $this->normalize_amount( $t['amount'] ) : '0';
			$amount      = $this->normalize_amount_sign( $amount, $description );
			if ( $date === '' || $description === '' || $this->is_excluded_description( $description ) ) {
				continue;
			}
			$base_ids[ $idx ] = $this->generate_transaction_id( $date, $time, $description, $amount );
		}
		$occurrence = [];
		$assigned   = [];
		foreach ( $base_ids as $idx => $base_id ) {
			$occurrence[ $base_id ] = isset( $occurrence[ $base_id ] ) ? $occurrence[ $base_id ] + 1 : 1;
			$n = $occurrence[ $base_id ];
			$assigned[ $idx ] = $n === 1 ? $base_id : $base_id . '-' . $n;
		}
		return $assigned;
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
	 * Whether the description matches a debit override (money out), taking precedence over credit phrases.
	 *
	 * @param string $description Sanitized description.
	 * @return bool
	 */
	private function is_debit_override( $description ) {
		$normalized = strtolower( trim( $description ) );
		if ( $normalized === '' ) {
			return false;
		}
		$phrases = apply_filters( 'statement_processor_debit_override_phrases', self::$debit_override_phrases );
		foreach ( (array) $phrases as $phrase ) {
			$phrase = strtolower( trim( (string) $phrase ) );
			if ( $phrase !== '' && strpos( $normalized, $phrase ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize amount sign. When the statement identifies debits with a minus and credits without,
	 * the parser passes signed amounts; we preserve that sign. The same description (e.g. "Amazon")
	 * can be either a purchase (debit, "-" on statement) or a refund (credit, no "-"); we use the
	 * value identifier (the amount's existing "-") to decide, not the description.
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
		$preserve_sign = apply_filters( 'statement_processor_preserve_amount_sign_from_statement', true );
		if ( $preserve_sign ) {
			// Use the amount's sign (value identifier): existing "-" = debit, no "-" = credit.
			$num = $num < 0 ? -1 * abs( $num ) : abs( $num );
			return number_format( $num, 2, '.', '' );
		}
		// Fallback: infer from description when parser does not provide sign.
		if ( $this->is_debit_override( $description ) ) {
			$num = -1 * abs( $num );
		} elseif ( $this->is_credit_or_deposit( $description ) ) {
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
