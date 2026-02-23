<?php
/**
 * PDF statement parser: text extraction with OCR fallback.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Parser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts text from PDFs (native or OCR) and parses into transaction arrays.
 */
class PdfParser {

	/**
	 * Minimum character count from text extraction to consider parsing (skip OCR).
	 */
	const MIN_TEXT_LENGTH = 50;

	/**
	 * Text extractor.
	 *
	 * @var PdfTextExtractor
	 */
	private $text_extractor;

	/**
	 * OCR fallback.
	 *
	 * @var PdfOcrFallback|null
	 */
	private $ocr_fallback;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->text_extractor = new PdfTextExtractor();
		if ( class_exists( 'StatementProcessor\Parser\PdfOcrFallback' ) ) {
			$this->ocr_fallback = new PdfOcrFallback();
		}
	}

	/**
	 * Parse a PDF file and return normalized transaction arrays.
	 *
	 * @param string $file_path Path to PDF file.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	public function parse( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return [];
		}

		$text = $this->text_extractor->extract( $file_path );
		if ( strlen( $text ) < self::MIN_TEXT_LENGTH && $this->ocr_fallback !== null ) {
			$text = $this->ocr_fallback->extract( $file_path );
		}

		if ( strlen( trim( $text ) ) < 10 ) {
			return [];
		}

		// When AI parsing is enabled and this looks like a Discover statement, use LLM extraction first.
		if ( class_exists( 'StatementProcessor\Admin\SettingsPage' ) && \StatementProcessor\Admin\SettingsPage::is_ai_configured()
			&& class_exists( 'StatementProcessor\Parser\DiscoverPdfAiParser' )
			&& DiscoverPdfAiParser::is_discover_statement( $text ) ) {
			$ai_parser = new DiscoverPdfAiParser();
			$ai_rows   = $ai_parser->parse( $file_path );
			if ( ! empty( $ai_rows ) ) {
				return $ai_rows;
			}
		}

		// When AI is enabled and this looks like an Ally Bank statement, use LLM extraction first; fall back to text parsing.
		if ( class_exists( 'StatementProcessor\Admin\SettingsPage' ) && \StatementProcessor\Admin\SettingsPage::is_ai_configured()
			&& class_exists( 'StatementProcessor\Parser\AllyPdfAiParser' )
			&& AllyPdfAiParser::is_ally_statement( $text ) ) {
			$ally_ai = new AllyPdfAiParser();
			$ally_rows = $ally_ai->parse( $file_path );
			if ( ! empty( $ally_rows ) ) {
				return $ally_rows;
			}
		}

		return $this->parse_text_to_transactions( $text );
	}

	/**
	 * Parse extracted text into transaction lines (convention-based: date, amount, description patterns).
	 *
	 * @param string $text Raw text.
	 * @return array<int, array{date: string, time: string, description: string, amount: string, source_name?: string}>
	 */
	private function parse_text_to_transactions( $text ) {
		$text = $this->normalize_ally_concatenated_rows( $text );
		// Try Discover before Capital One (both can contain "ACCOUNT SUMMARY").
		$discover = $this->parse_discover_activity( $text );
		if ( ! empty( $discover ) ) {
			return $discover;
		}
		$capital_one = $this->parse_capital_one_multi_account( $text );
		if ( ! empty( $capital_one ) ) {
			return $capital_one;
		}
		$paypal_credit = $this->parse_paypal_credit( $text );
		if ( ! empty( $paypal_credit ) ) {
			return $paypal_credit;
		}
		$is_ally_combined = $this->is_ally_combined_statement_text( $text );
		$ally_activity   = $this->parse_ally_activity_table( $text );
		if ( ! empty( $ally_activity ) ) {
			return $this->filter_ally_junk_transactions( $ally_activity );
		}
		$ally_rows = $this->parse_ally_row_style_transactions( $text );
		if ( ! empty( $ally_rows ) ) {
			return $this->filter_ally_junk_transactions( $ally_rows );
		}
		// For Ally combined statements, fall back to generic parsers then filter junk so user gets some transactions.
		if ( $is_ally_combined ) {
			$table_style = $this->parse_table_style_transactions( $text );
			$line_style  = $this->parse_line_style_transactions( $text );
			$merged      = array_merge( $table_style, $line_style );
			$filtered    = $this->filter_ally_junk_transactions( $merged );
			if ( ! empty( $filtered ) ) {
				return $filtered;
			}
			return [];
		}
		$table_style = $this->parse_table_style_transactions( $text );
		if ( ! empty( $table_style ) ) {
			return $table_style;
		}
		return $this->parse_line_style_transactions( $text );
	}

	/**
	 * Whether the text is from an Ally Bank combined customer statement (Activity table with Credits/Debits/Balance).
	 *
	 * @param string $text Extracted PDF text.
	 * @return bool
	 */
	private function is_ally_combined_statement_text( $text ) {
		return ( preg_match( '/Ally\s+Bank/i', $text ) !== 0 || preg_match( '/COMBINED\s+CUST OMER ST AT EMENT|COMBINED\s+CUSTOMER\s+STATEMENT/i', $text ) !== 0 )
			&& preg_match( '/\bActivity\b/i', $text ) !== 0
			&& preg_match( '/Date\s+Description/i', $text ) !== 0
			&& preg_match( '/Credits/i', $text ) !== 0
			&& preg_match( '/Debits/i', $text ) !== 0;
	}

	/**
	 * Filter out junk rows from Ally parsing (balance lines, addresses, page numbers, account headers).
	 *
	 * @param array<int, array{date: string, time: string, description: string, amount: string}> $transactions Parsed Ally transactions.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	private function filter_ally_junk_transactions( array $transactions ) {
		$junk = [
			'/^Beginning\s+Balance/i',
			'/^Ending\s+Balance/i',
			'/Beginning\s+Balance,?\s+as\s+of/i',
			'/Ending\s+Balance,?\s+as\s+of/i',
			'/P\.O\.\s*Box\s+\d+/i',
			'/^Account\s+Number\s*:/i',
			'/Open\s+Date\s*:/i',
			'/^\d+\s+of\s+\d+\s*$/',
			'/^\d{1,4}$/',  // e.g. "15" (page number)
			'/^Days\s+In\s+Statement\s+Period/i',
			'/^Summary\s+For\s*:/i',
			'/^Statement\s+Date\s*$/i',
			'/^Page\s+\d+/i',
			'/^\d{4}-\d{2}-\d{2}\s*$/',  // date-only line misparsed as description
			'/^[\d\-\/]+\s*$/',  // date or number only
		];
		$out = [];
		foreach ( $transactions as $row ) {
			$desc = isset( $row['description'] ) ? trim( (string) $row['description'] ) : '';
			$skip = false;
			foreach ( $junk as $pattern ) {
				if ( preg_match( $pattern, $desc ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip || $desc === '' ) {
				continue;
			}
			// Drop if description is only numbers and punctuation (e.g. "$0.00 -$10.00" or "2014.00").
			if ( preg_match( '/^[\d\s\$\.\,\-\+]+$/', $desc ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Parse Discover bank statements (ACCOUNT ACTIVITY with Eff. Date Syst. Date Description Amount).
	 * Statement does not show +/- for amounts; section (Deposits vs Withdrawals) and description determine sign.
	 *
	 * @param string $text Raw extracted text.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	private function parse_discover_activity( $text ) {
		if ( ! preg_match( '/Eff\.\s*Date|Description\s+Amount/i', $text ) || ! preg_match( '/ACCOUNT\s+ACTIVITY/i', $text ) ) {
			return [];
		}

		$statement_year = $this->infer_statement_year( $text );
		if ( preg_match( '/Statement Period:.*?(\d{4})/i', $text, $ym ) ) {
			$statement_year = $ym[1];
		} elseif ( preg_match( '/\b(20\d{2})\b/', $text, $ym ) ) {
			$statement_year = $ym[1];
		}

		$months_nc  = '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)';
		$months_cap = '(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)';

		// Try line-by-line first (pdftotext-style layout).
		$transactions = $this->parse_discover_line_by_line( $text, $statement_year, $months_nc, $months_cap );
		if ( ! empty( $transactions ) ) {
			return $transactions;
		}

		// Fallback: some PDF extractors put one token per line; join and match in full text.
		$joined = preg_replace( '/\s*\r?\n\s*/', ' ', $text );
		$pos_deposits = stripos( $joined, 'Deposits and Credits' );
		$pos_debit    = stripos( $joined, 'ATM and Debit' );
		$pos_elect    = stripos( $joined, 'Electronic Withdrawals' );
		$pos_service  = stripos( $joined, 'Service Charges' );
		$first_debit  = $pos_debit;
		if ( $first_debit === false || ( $pos_elect !== false && $pos_elect < $first_debit ) ) {
			$first_debit = $pos_elect;
		}
		if ( $first_debit === false || ( $pos_service !== false && $pos_service < $first_debit ) ) {
			$first_debit = $pos_service;
		}

		// Only search in the activity section (after "ACCOUNT ACTIVITY") to avoid matching summary/statement period.
		$search_start = stripos( $joined, 'ACCOUNT ACTIVITY' );
		if ( $search_start === false ) {
			$search_start = $pos_deposits !== false ? $pos_deposits : 0;
		}
		$to_search = $search_start > 0 ? substr( $joined, $search_start ) : $joined;
		$pos_total_deposits = stripos( $to_search, 'TOTAL DEPOSITS AND CREDITS' );

		// Split by transaction start (Month Day Month Day); collect rows and amounts separately then pair.
		$split_pattern = '/(?=' . $months_cap . '\s+\d{1,2}\s+' . $months_nc . '\s+\d{1,2}\b)/is';
		$segments = preg_split( $split_pattern, $to_search, -1, PREG_SPLIT_NO_EMPTY );
		$rows = [];
		$offset = 0;
		foreach ( $segments as $seg ) {
			$seg_len = strlen( $seg );
			$seg = trim( $seg );
			if ( ! preg_match( '/^' . $months_cap . '\s+(\d{1,2})\s+' . $months_nc . '\s+\d{1,2}\s+(.*)/is', $seg, $head ) ) {
				$offset += $seg_len;
				continue;
			}
			$rest = $head[2];
			if ( preg_match( '/^(?:Eff\.\s*Date|Syst\.\s*Date|Description\s+Amount)\b/i', $rest ) ) {
				$offset += $seg_len;
				continue;
			}
			if ( preg_match( '/TOTAL\s+(?:DEPOSITS|ATM|ELECTRONIC|SERVICE)/i', $rest ) ) {
				$offset += $seg_len;
				continue;
			}
			$row_offset_in_to_search = $offset;
			$is_credit_row = ( $pos_total_deposits === false || $row_offset_in_to_search < $pos_total_deposits );
			$rows[] = [
				'month'     => $head[1],
				'day'       => $head[2],
				'rest'      => $rest,
				'offset'    => $search_start + $offset,
				'is_credit' => $is_credit_row,
			];
			$offset += $seg_len;
		}
		// Build set of positions to skip: only the amount immediately after each "TOTAL ..." (within 25 chars).
		$skip_amount_positions = [];
		if ( preg_match_all( '/TOTAL\s+(?:DEPOSITS\s+AND\s+CREDITS|ATM\s+AND\s+DEBIT\s+CARD\s+WITHDRAWALS|ELECTRONIC\s+WITHDRAWALS|SERVICE\s+CHARGES)/i', $to_search, $tot_m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $tot_m[0] as $tot ) {
				$end = $tot[1] + strlen( $tot[0] );
				$window = substr( $to_search, $end, 25 );
				if ( preg_match( '/[\d,]+\.\d{2}\b/', $window, $amt_after, PREG_OFFSET_CAPTURE ) ) {
					$skip_amount_positions[ $end + $amt_after[0][1] ] = true;
				}
			}
		}
		preg_match_all( '/[\d,]+\.\d{2}\b/', $to_search, $amt_matches, PREG_OFFSET_CAPTURE );
		$amounts_ordered = [];
		foreach ( $amt_matches[0] as $am ) {
			if ( ! empty( $skip_amount_positions[ $am[1] ] ) ) {
				continue;
			}
			$amounts_ordered[] = $am[0];
		}
		if ( count( $rows ) === 0 || count( $amounts_ordered ) < count( $rows ) ) {
			return [];
		}
		// Extract real descriptions from activity block (e.g. "Debit Purchase 1031 0693 SHELL SERVICE S ALABASTER AL US").
		$desc_pattern = '/(Debit Purchase|POS Credit|POS w\/ Cash|Check Deposit|ACH Withdrawal|ACH Deposit From|Early Pay|ATM W\/D)\s+(?:\d+\s+\d+\s+)?(.+?)(?=\s+(?:Debit Purchase|POS Credit|POS w\/ Cash|Check Deposit|ACH (?:Withdrawal|Deposit From)|Early Pay|ATM W\/D)|\s+[\d,]+\.\d{2}\b|\s+TOTAL\b)/si';
		$descriptions_ordered = [];
		if ( preg_match_all( $desc_pattern, $to_search, $desc_m ) ) {
			$months_trailer = '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}';
			$months_only = '/^(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}\s*$/i';
			foreach ( $desc_m[2] as $i => $d ) {
				$d = preg_replace( '/\s+Amount\s+.*$/i', '', $d );
				$d = preg_replace( '/\s+' . $months_trailer . '\s*$/i', '', $d );
				$d = preg_replace( '/\s+\$\s*$/i', '', $d );
				$d = preg_replace( '/^[\d,]+\.\d{2}\s+/', '', $d );
				$d = trim( preg_replace( '/\s+/', ' ', $d ) );
				if ( $d === '' || preg_match( $months_only, $d ) ) {
					$d = isset( $desc_m[1][ $i ] ) ? trim( $desc_m[1][ $i ] ) : 'Transaction';
				}
				$descriptions_ordered[] = $d;
			}
		}
		$transactions = [];
		foreach ( $rows as $idx => $row ) {
			$use_amt = $amounts_ordered[ $idx ];
			$is_credit = ! empty( $row['is_credit'] );
			$date_str  = $row['month'] . ' ' . $row['day'] . ', ' . $statement_year;
			$date_norm = $this->normalize_date( $date_str );
			if ( $date_norm === '' ) {
				continue;
			}
			// Use extracted description when available and in sync with row count; otherwise fall back to segment rest.
			$desc = isset( $descriptions_ordered[ $idx ] ) ? $descriptions_ordered[ $idx ] : '';
			if ( $desc === '' ) {
				$rest = $row['rest'];
				$desc = preg_replace( '/\s*[\d,]+\.\d{2}\b.*$/s', '', $rest );
				$desc = preg_replace( '/^(?:Description\s+)?(.*?)(?:\s+Amount)?$/i', '$1', $desc );
				$desc = trim( preg_replace( '/\s+/', ' ', $desc ) );
				if ( $desc !== '' && preg_match( '/^\d{1,2}$/', $desc ) ) {
					$desc = 'Transaction';
				}
			}
			$amt  = $this->normalize_amount_string( str_replace( ',', '', $use_amt ) );
			if ( $amt === '0.00' ) {
				continue;
			}
			if ( ! $is_credit ) {
				$amt = number_format( -1 * (float) $amt, 2, '.', '' );
			}
			$transactions[] = [
				'date'        => $date_norm,
				'time'        => '00:00:00',
				'description' => $desc,
				'amount'      => $amt,
			];
		}

		return $transactions;
	}

	/**
	 * Parse Discover line-by-line (one transaction per line).
	 *
	 * @param string $text             Full text.
	 * @param string $statement_year  Year.
	 * @param string $months_nc        Non-capturing months pattern.
	 * @param string $months_cap       Capturing months pattern.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	private function parse_discover_line_by_line( $text, $statement_year, $months_nc, $months_cap ) {
		$lines         = preg_split( '/\r\n|\r|\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$transactions  = [];
		$is_credit_section = false;

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			if ( preg_match( '/Deposits\s+and\s+Credits/i', $line ) && ! preg_match( '/\d+\.\d{2}/', $line ) ) {
				$is_credit_section = true;
				continue;
			}
			if ( preg_match( '/(?:ATM\s+and\s+Debit\s+Card\s+Withdrawals|Electronic\s+Withdrawals|Service\s+Charges)/i', $line ) ) {
				$is_credit_section = false;
				continue;
			}
			if ( preg_match( '/^Eff\.\s*Date|^Syst\.\s*Date|^Description\s*$|^Amount\s*$/i', $line ) || preg_match( '/^\s*\$?\s*$/i', $line ) ) {
				continue;
			}
			if ( preg_match( '/^TOTAL\s+/i', $line ) || preg_match( '/^Continued\s+on\s+Page/i', $line ) ) {
				continue;
			}
			$line_for_match = preg_replace( '/^\s*\$?\s*/', '', $line );
			if ( preg_match( '/^' . $months_cap . '\s+(\d{1,2})\s+' . $months_nc . '\s+\d{1,2}\s+(.+)\s+([\d,]+\.\d{2})\s*$/i', $line_for_match, $m ) ) {
				$date_str  = $m[1] . ' ' . $m[2] . ', ' . $statement_year;
				$date_norm = $this->normalize_date( $date_str );
				if ( $date_norm === '' ) {
					continue;
				}
				$desc = trim( preg_replace( '/\s+/', ' ', $m[3] ) );
				$amt  = $this->normalize_amount_string( str_replace( ',', '', $m[4] ) );
				if ( $amt === '0.00' ) {
					continue;
				}
				if ( ! $is_credit_section ) {
					$amt = number_format( -1 * (float) $amt, 2, '.', '' );
				}
				$transactions[] = [
					'date'        => $date_norm,
					'time'        => '00:00:00',
					'description' => $desc,
					'amount'      => $amt,
				];
			}
		}

		return $transactions;
	}

	/**
	 * Parse Capital One multi-account statements (Account Name - number sections, DATE DESCRIPTION AMOUNT rows).
	 *
	 * @param string $text Raw extracted text.
	 * @return array<int, array{date: string, time: string, description: string, amount: string, source_name?: string}>
	 */
	private function parse_capital_one_multi_account( $text ) {
		if ( ! preg_match( '/Account\s+Summary|ACCOUNT\s+NAME/i', $text ) ) {
			return [];
		}
		$statement_year = $this->infer_statement_year( $text );
		$lines          = preg_split( '/\r\n|\r|\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$transactions   = [];
		$current_account = '';

		$months_nc  = '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)';
		$months_cap = '(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)';
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			// Account section header: "Bills - 36286714191" or "Shared - 36230695284"
			if ( preg_match( '/^([A-Za-z0-9][A-Za-z0-9\s\.]+?)\s*-\s*\d{8,}\s*$/u', $line, $acc_m ) ) {
				$current_account = trim( preg_replace( '/\s+/', ' ', $acc_m[1] ) );
				continue;
			}
			if ( preg_match( '/^DATE\s+DESCRIPTION\s+CATEGORY\s+AMOUNT/i', $line ) ) {
				continue;
			}
			if ( $current_account === '' ) {
				continue;
			}
			// Skip balance-only lines (not transactions).
			if ( preg_match( '/^' . $months_nc . '\s+\d{1,2}\s+(?:Opening|Closing)\s+Balance\s+/i', $line ) ) {
				continue;
			}
			// Transaction: "Apr 1 Withdrawal from X Debit - $106.37 $6,178.58" or "Apr 17 Deposit from X ACH Credit + $50.00 $342.81"
			if ( preg_match( '/^' . $months_cap . '\s+(\d{1,2})\s+(.+?)\s+(?:Debit\s*-\s*\$?([\d,]+\.\d{2})|Credit\s*\+\s*\$?([\d,]+\.\d{2}))(?:\s+\$[\d,]+\.\d{2})?\s*$/us', $line, $m ) ) {
				$date_str  = $m[1] . ' ' . $m[2] . ', ' . $statement_year;
				$date_norm = $this->normalize_date( $date_str );
				if ( $date_norm === '' ) {
					continue;
				}
				$desc = $this->clean_capital_one_description( trim( preg_replace( '/\s+/', ' ', $m[3] ) ) );
				if ( $desc === '' || preg_match( '/^(Apr\s+\d+\s+-\s+Apr|Here\'s your|Opening\s+Balance|Closing\s+Balance)/i', $desc ) ) {
					continue;
				}
				$amt  = isset( $m[4] ) && $m[4] !== '' ? '-' . str_replace( ',', '', $m[4] ) : ( isset( $m[5] ) && $m[5] !== '' ? str_replace( ',', '', $m[5] ) : '0.00' );
				$transactions[] = [
					'date'        => $date_norm,
					'time'        => '00:00:00',
					'description' => $desc,
					'amount'      => $this->normalize_amount_string( $amt ),
					'source_name' => $current_account,
				];
				continue;
			}
			// Wrapped line: description may be long; amount still at end (Debit/Credit).
			if ( preg_match( '/^' . $months_cap . '\s+(\d{1,2})\s+(.+)\s+(?:Debit\s*-\s*\$?([\d,]+\.\d{2})|Credit\s*\+\s*\$?([\d,]+\.\d{2}))(?:\s+\$[\d,]+\.\d{2})?\s*$/us', $line, $m ) ) {
				$date_str  = $m[1] . ' ' . $m[2] . ', ' . $statement_year;
				$date_norm = $this->normalize_date( $date_str );
				if ( $date_norm !== '' ) {
					$desc = $this->clean_capital_one_description( trim( preg_replace( '/\s+/', ' ', $m[3] ) ) );
					if ( $desc !== '' && ! preg_match( '/^(Apr\s+\d+\s+-\s+Apr|Here\'s your|Opening\s+Balance|Closing\s+Balance)/i', $desc ) ) {
						$amt  = isset( $m[4] ) && $m[4] !== '' ? '-' . str_replace( ',', '', $m[4] ) : ( isset( $m[5] ) && $m[5] !== '' ? str_replace( ',', '', $m[5] ) : '0.00' );
						$transactions[] = [
							'date'        => $date_norm,
							'time'        => '00:00:00',
							'description' => $desc,
							'amount'      => $this->normalize_amount_string( $amt ),
							'source_name' => $current_account,
						];
					}
				}
			}
		}

		// Fallback: one token per line; join and parse.
		if ( empty( $transactions ) ) {
			$transactions = $this->parse_capital_one_joined( $text, $statement_year, $months_nc, $months_cap );
		}

		return $transactions;
	}

	/**
	 * Parse Capital One from joined text (token-per-line extractors).
	 *
	 * @param string $text             Full text.
	 * @param string $statement_year  Year.
	 * @param string $months_nc       Non-capturing months.
	 * @param string $months_cap       Capturing months.
	 * @return array<int, array{date: string, time: string, description: string, amount: string, source_name: string}>
	 */
	private function parse_capital_one_joined( $text, $statement_year, $months_nc, $months_cap ) {
		$joined = preg_replace( '/\s*\r?\n\s*/', ' ', $text );
		$transactions = [];
		$pattern = '/' . $months_cap . '\s+(\d{1,2})\s+(.+?)\s+(?:Debit\s*-\s*\$?([\d,]+\.\d{2})|Credit\s*\+\s*\$?([\d,]+\.\d{2}))(?:\s+\$[\d,]+\.\d{2})?\s*(?=' . $months_cap . '\s+\d{1,2}|$)/is';
		if ( ! preg_match_all( $pattern, $joined, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return [];
		}
		$account_pattern = '/([A-Za-z0-9][A-Za-z0-9\s\.]+?)\s*-\s*\d{8,}/';
		$last_account    = '';
		foreach ( $all as $m ) {
			$pos = $m[0][1];
			if ( preg_match_all( $account_pattern, substr( $joined, 0, $pos ), $acc_m, PREG_SET_ORDER ) ) {
				$last = end( $acc_m );
				$last_account = trim( preg_replace( '/\s+/', ' ', $last[1] ) );
			}
			$date_str  = $m[1][0] . ' ' . $m[2][0] . ', ' . $statement_year;
			$date_norm = $this->normalize_date( $date_str );
			if ( $date_norm === '' ) {
				continue;
			}
			$desc = $this->clean_capital_one_description( trim( preg_replace( '/\s+/', ' ', $m[3][0] ) ) );
			if ( $desc === '' || preg_match( '/^(Apr\s+\d+\s+-\s+Apr|Here\'s your|Opening\s+Balance|Closing\s+Balance)/i', $desc ) ) {
				continue;
			}
			$amt  = isset( $m[4][0] ) && $m[4][0] !== '' ? '-' . str_replace( ',', '', $m[4][0] ) : ( isset( $m[5][0] ) && $m[5][0] !== '' ? str_replace( ',', '', $m[5][0] ) : '0.00' );
			$transactions[] = [
				'date'        => $date_norm,
				'time'        => '00:00:00',
				'description' => $desc,
				'amount'      => $this->normalize_amount_string( $amt ),
				'source_name' => $last_account ?: 'Account',
			];
		}
		return $transactions;
	}

	/**
	 * Trim Capital One description to remove footer/header/summary text.
	 *
	 * @param string $desc Raw description.
	 * @return string Cleaned description.
	 */
	private function clean_capital_one_description( $desc ) {
		$junk = '\s*(?:Page\s+\d+\s+of\s+\d+|capitalone\.com|1-\d{3}-\d{3}-\d{4}|STATEMENT\s+PERIOD|DATE\s+DESCRIPTION|CATEGORY\s+AMOUNT|BALANCE\b|TOTAL\s+[A-Z\s]+|Opening\s+Balance|Closing\s+Balance|Apr\s+\d+\s+-\s+Apr\s+\d+.*|Here\'s your.*|Account\s+Summary|Cashflow\s+Summary|P\.O\.\s+Box.*|Brian\s+Dichiara).*$';
		$desc = preg_replace( '/' . $junk . '/si', '', $desc );
		return trim( preg_replace( '/\s+/', ' ', $desc ) );
	}

	/**
	 * Split Ally-style concatenated rows where amount is glued to next row's date (e.g. "...$600.208/27 08/27...").
	 *
	 * @param string $text Raw extracted text.
	 * @return string Text with newlines inserted before trans date when preceded by amount.
	 */
	private function normalize_ally_concatenated_rows( $text ) {
		// After a dollar amount (digits.digits) we see MM/DD followed by space and another MM/DD (trans + post date) — split so next row starts on its own line.
		$text = preg_replace( '/(\.\d{2})\s*(\d{1,2}\/\d{1,2}\s+\d{1,2}\/\d{1,2}\s+[A-Z0-9])/u', '$1' . "\n" . '$2', $text );
		// Header "Amount" sometimes merged with first date (e.g. "Amoun08/27 08/27...") — split so first data row starts on its own line.
		$text = preg_replace( '/(Amoun)(\d{1,2}\/\d{1,2}\s+\d{1,2}\/\d{1,2}\s+[A-Z0-9])/iu', '$1' . "\n" . '$2', $text );
		return $text;
	}

	/**
	 * Parse Ally checking/savings "Activity" table: Date, Description, Credits, Debits, Balance.
	 *
	 * - Credits = deposit (positive amount). Debits = withdrawal (negative amount). Balance is never used.
	 * - Description must contain only the Description column text; no amount values go into the description.
	 *
	 * Layout: Activity header, then "Date  Description  Credits  Debits  Balance", then rows (multi-line
	 * or single-line). Beginning/Ending Balance rows are skipped.
	 *
	 * @param string $text Raw extracted text.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	private function parse_ally_activity_table( $text ) {
		// Some extractors omit the Balance column; require Activity + Date/Description + Credits/Debits headers.
		if ( ! preg_match( '/\bActivity\b/i', $text )
			|| ! preg_match( '/Date\s+Description/i', $text )
			|| ! preg_match( '/Credits/i', $text )
			|| ! preg_match( '/Debits/i', $text ) ) {
			return [];
		}

		$lines        = preg_split( '/\r\n|\r|\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$transactions = [];
		$in_table     = false;
		$current_date = null;
		$current_desc = [];

		// Amount columns can extract as 3 values (Credits, Debits, Balance) or sometimes only 2 (Credits, Debits).
		$two_amounts   = '/^(\$?-?[\d,]+\.\d{2})\s+(\$?-?[\d,]+\.\d{2})\s*$/';
		$three_amounts = '/^(\$?-?[\d,]+\.\d{2})\s+(\$?-?[\d,]+\.\d{2})\s+(\$?-?[\d,]+\.\d{2})\s*$/';

		foreach ( $lines as $raw_line ) {
			$line = trim( $raw_line );
			if ( $line === '' ) {
				continue;
			}

			// Start table when we see the full header or just the amount columns header (in case "Date Description" is on previous line).
			if ( ! $in_table && ( preg_match( '/Date\s+Description\s+Credits\s+Debits\s+Balance/i', $line ) || preg_match( '/Credits\s+Debits\s+Balance/i', $line ) ) ) {
				$in_table     = true;
				$current_date = null;
				$current_desc = [];
				continue;
			}
			if ( preg_match( '/^Activity\s*$/i', $line ) ) {
				$current_date = null;
				$current_desc = [];
				continue;
			}
			if ( ! $in_table ) {
				continue;
			}

			// Stop parsing when we leave the activity section for this account.
			if ( preg_match( '/^--\s*\d+\s+of\s+\d+\s*--$/', $line ) || preg_match( '/^Ally\s+Bank\s+Member\s+FDIC/i', $line ) ) {
				break;
			}

			// Amount-only line (no date): Credits/Debits[/Balance]. Must be checked before appending to description.
			if ( $current_date !== null && ( preg_match( $three_amounts, $line, $m ) || preg_match( $two_amounts, $line, $m ) ) ) {
				$credits = $this->normalize_amount_string( $m[1] );
				$debits  = $this->normalize_amount_string( $m[2] );
				$amount  = ( (float) $debits !== 0.0 ) ? $debits : $credits;

				$date_norm = $this->normalize_date( $current_date );
				if ( $date_norm !== '' ) {
					$desc = preg_replace( '/\s+/', ' ', implode( ' ', $current_desc ) );
					$desc = trim( preg_replace( '/\s*\$?-?[\d,]+\.\d{2}\s+\$?-?[\d,]+\.\d{2}(?:\s+\$?-?[\d,]+\.\d{2})?\s*$/', '', $desc ) );
					if ( $desc !== '' && ! preg_match( '/^(Beginning\s+Balance|Ending\s+Balance)$/i', $desc ) ) {
						$transactions[] = [
							'date'        => $date_norm,
							'time'        => '00:00:00',
							'description' => $desc,
							'amount'      => $amount,
						];
					}
				}

				$current_date = null;
				$current_desc = [];
				continue;
			}

			// Single-line row with Date, Description, Credits, Debits (Balance may be omitted by extractor).
			if ( preg_match( '/^(\d{1,2}\/\d{1,2}\/\d{4})\s+(.+?)\s+(\$?-?[\d,]+\.\d{2})\s+(\$?-?[\d,]+\.\d{2})\s*$/u', $line, $m ) ) {
				$desc_raw = trim( $m[2] );
				if ( preg_match( '/^(Beginning\s+Balance|Ending\s+Balance)$/i', $desc_raw ) ) {
					$current_date = null;
					$current_desc = [];
					continue;
				}
				$credits = $this->normalize_amount_string( $m[3] );
				$debits  = $this->normalize_amount_string( $m[4] );
				$amount  = ( (float) $debits !== 0.0 ) ? $debits : $credits;
				$date_norm = $this->normalize_date( $m[1] );
				if ( $date_norm !== '' ) {
					$transactions[] = [
						'date'        => $date_norm,
						'time'        => '00:00:00',
						'description' => preg_replace( '/\s+/', ' ', $desc_raw ),
						'amount'      => $amount,
					];
				}
				$current_date = null;
				$current_desc = [];
				continue;
			}

			// Single-line row with all five columns: Date, Description, Credits, Debits, Balance.
			if ( preg_match( '/^(\d{1,2}\/\d{1,2}\/\d{4})\s+(.+?)\s+(\$?-?[\d,]+\.\d{2})\s+(\$?-?[\d,]+\.\d{2})\s+(\$?-?[\d,]+\.\d{2})\s*$/u', $line, $m ) ) {
				$desc_raw = trim( $m[2] );
				if ( preg_match( '/^(Beginning\s+Balance|Ending\s+Balance)$/i', $desc_raw ) ) {
					$current_date = null;
					$current_desc = [];
					continue;
				}
				$credits = $this->normalize_amount_string( $m[3] );
				$debits  = $this->normalize_amount_string( $m[4] );
				$amount  = ( (float) $debits !== 0.0 ) ? $debits : $credits;
				$date_norm = $this->normalize_date( $m[1] );
				if ( $date_norm !== '' ) {
					$transactions[] = [
						'date'        => $date_norm,
						'time'        => '00:00:00',
						'description' => preg_replace( '/\s+/', ' ', $desc_raw ),
						'amount'      => $amount,
					];
				}
				$current_date = null;
				$current_desc = [];
				continue;
			}

			// One-amount line (Beginning/Ending Balance only): skip.
			if ( preg_match( '/^(\d{1,2}\/\d{1,2}\/\d{4})\s+(Beginning\s+Balance|Ending\s+Balance)\s+(\$?-?[\d,]+\.\d{2})\s*$/i', $line ) ) {
				$current_date = null;
				$current_desc = [];
				continue;
			}

			// Date + start of description; amounts on a later line. (Do not match if line is only 2/3 amounts.)
			if ( preg_match( '/^(\d{1,2}\/\d{1,2}\/\d{4})\s+(.+)$/', $line, $m )
				&& ! preg_match( $three_amounts, $line )
				&& ! preg_match( $two_amounts, $line ) ) {
				$current_date = $m[1];
				$current_desc = [ trim( $m[2] ) ];
				continue;
			}

			// Continuation of description (no date at start, not an amount-only line).
			if ( $current_date !== null
				&& ! preg_match( '/^\d{1,2}\/\d{1,2}\/\d{4}\s/', $line )
				&& ! preg_match( $three_amounts, $line )
				&& ! preg_match( $two_amounts, $line ) ) {
				$current_desc[] = $line;
			}
		}

		return $transactions;
	}

	/**
	 * Parse PayPal Credit / Synchrony-style statements (CURRENT ACTIVITY: PAYMENTS & CREDITS, PURCHASES & ADJUSTMENTS, FEES).
	 *
	 * @param string $text Raw extracted text.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	private function parse_paypal_credit( $text ) {
		if ( ! preg_match( '/CURRENT\s+ACTIVITY/i', $text ) || ! preg_match( '/PAYMENTS\s*&\s*CREDITS|PURCHASES\s*&\s*ADJUSTMENTS/i', $text ) ) {
			return [];
		}

		$lines       = preg_split( '/\r\n|\r|\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$section    = '';
		$transactions = [];

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}

			if ( preg_match( '/^PAYMENTS\s*&\s*CREDITS\s*$/i', $line ) ) {
				$section = 'payments';
				continue;
			}
			if ( preg_match( '/^PURCHASES\s*&\s*ADJUSTMENTS\s*$/i', $line ) ) {
				$section = 'purchases';
				continue;
			}
			if ( preg_match( '/^FEES\s*$/i', $line ) ) {
				$section = 'fees';
				continue;
			}
			if ( preg_match( '/Total\s+Purchases\s*&|Total\s+Fees\s*$/i', $line ) ) {
				$section = '';
				continue;
			}
			// Skip column header lines.
			if ( preg_match( '/^Tran\s+Date\s+Posting\s+Date\s+Reference/i', $line ) || preg_match( '/^Tran\s+Date\s+Posting\s+Date\s+Description/i', $line ) ) {
				continue;
			}

			// PAYMENTS & CREDITS: "11/30/25  11/30/25  P928300...  WALMART COM  -$1.17"
			if ( $section === 'payments' && preg_match( '/^(\d{1,2}\/\d{1,2}\/\d{2})\s+\d{1,2}\/\d{1,2}\/\d{2}\s+P[A-Z0-9]+\s+(.+?)\s+-\$?([\d,]+\.\d{2})\s*$/s', $line, $m ) ) {
				$date_norm = $this->normalize_date( trim( $m[1] ) );
				if ( $date_norm !== '' ) {
					$desc  = trim( preg_replace( '/\s+/', ' ', $m[2] ) );
					$amt   = $this->normalize_amount_string( $m[3] );
					$transactions[] = [
						'date'        => $date_norm,
						'time'        => '00:00:00',
						'description' => $desc,
						'amount'      => $amt,
					];
				}
				continue;
			}

			// PURCHASES & ADJUSTMENTS: "12/02/25  12/02/25  P...  Standard  WALMART COM  $69.85" or "... Deferred  LOWES.COM  No Interest If Paid In Full  $240.90"
			if ( $section === 'purchases' && preg_match( '/^(\d{1,2}\/\d{1,2}\/\d{2})\s+\d{1,2}\/\d{1,2}\/\d{2}\s+P[A-Z0-9]+\s+(?:Standard|Deferred)\s+(.+)\s+\$?([\d,]+\.\d{2})\s*$/s', $line, $m ) ) {
				$date_norm = $this->normalize_date( trim( $m[1] ) );
				if ( $date_norm !== '' ) {
					$desc = trim( preg_replace( '/\s+/', ' ', $m[2] ) );
					$desc = preg_replace( '/\s*No\s+Interest\s+If\s+Paid\s+In\s+Full\s*$/i', '', $desc );
					$desc = trim( $desc );
					$amt  = $this->normalize_amount_string( $m[3] );
					$amt  = number_format( -1 * (float) $amt, 2, '.', '' );
					$transactions[] = [
						'date'        => $date_norm,
						'time'        => '00:00:00',
						'description' => $desc,
						'amount'      => $amt,
					];
				}
				continue;
			}

			// FEES: "12/16/25  12/16/25  Late Fee  $41.00"
			if ( $section === 'fees' && preg_match( '/^(\d{1,2}\/\d{1,2}\/\d{2})\s+\d{1,2}\/\d{1,2}\/\d{2}\s+(.+?)\s+\$?([\d,]+\.\d{2})\s*$/s', $line, $m ) ) {
				$date_norm = $this->normalize_date( trim( $m[1] ) );
				if ( $date_norm !== '' ) {
					$desc = trim( preg_replace( '/\s+/', ' ', $m[2] ) );
					$amt  = $this->normalize_amount_string( $m[3] );
					$amt  = number_format( -1 * (float) $amt, 2, '.', '' );
					$transactions[] = [
						'date'        => $date_norm,
						'time'        => '00:00:00',
						'description' => $desc,
						'amount'      => $amt,
					];
				}
				continue;
			}
		}

		return $transactions;
	}

	/**
	 * Parse Ally-style rows: each line is "Trans Date Post Date Reference Description Amount".
	 *
	 * @param string $text Normalized text (concatenated rows already split).
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	private function parse_ally_row_style_transactions( $text ) {
		if ( ! preg_match( '/Trans\s*Date/i', $text ) || ! preg_match( '/Post\s*Date|Reference|Description/i', $text ) ) {
			return [];
		}

		$statement_year = $this->infer_statement_year( $text );
		$lines          = preg_split( '/\r\n|\r|\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$transactions   = [];

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			// Ally row: trans_date post_date [ref] description amount (amount at end: $X,XXX.XX or -X.XX).
			if ( ! preg_match( '/^\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\s+\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\s+(?:[A-Z0-9]{8,}\s+)?(.+)\s+(\$?-?[\d,]+\.\d{2})\s*$/u', $line, $m ) ) {
				continue;
			}

			$desc_raw = trim( $m[1] );
			// Skip footer / page codes (short all-caps alphanumeric, no spaces).
			if ( preg_match( '/^[A-Z0-9]{3,8}$/', $desc_raw ) ) {
				continue;
			}
			// Skip header remnants.
			if ( preg_match( '/^(Trans\s*Date|Post\s*Date|Reference|Description|Amount)$/i', $desc_raw ) ) {
				continue;
			}

			$date_str = substr( $line, 0, strpos( $line, ' ' ) );
			if ( strlen( $date_str ) <= 5 ) {
				$date_str = $date_str . '/' . $statement_year;
			}
			$date_norm = $this->normalize_date( $date_str );
			if ( $date_norm === '' ) {
				continue;
			}

			$amount_norm = $this->normalize_amount_string( $m[2] );
			$transactions[] = [
				'date'        => $date_norm,
				'time'        => '00:00:00',
				'description' => preg_replace( '/\s+/', ' ', $desc_raw ),
				'amount'      => $amount_norm,
			];
		}

		return $transactions;
	}

	/**
	 * Parse PDFs with columnar layout (e.g. Trans Date, Description, Amount in separate columns).
	 *
	 * @param string $text Raw extracted text.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	private function parse_table_style_transactions( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$lines = array_map( 'trim', $lines );
		$lines = array_values( array_filter( $lines ) );

		$statement_year = $this->infer_statement_year( $text );
		$dates          = [];
		$descriptions   = [];
		$amounts        = [];

		$i = 0;
		$n = count( $lines );

		while ( $i < $n ) {
			$line = $lines[ $i ];
			// Match "Trans Date" exactly or as the only significant text (allow extra spaces).
			if ( preg_match( '/^Trans\s*Date$/i', $line ) ) {
				$i++;
				while ( $i < $n && preg_match( '/^\d{1,2}\/\d{1,2}(\/\d{2,4})?$/', $lines[ $i ] ) ) {
					$d = $lines[ $i ];
					if ( strlen( $d ) <= 5 ) {
						$d = $d . '/' . $statement_year;
					}
					$norm = $this->normalize_date( $d );
					if ( $norm !== '' ) {
						$dates[] = $norm;
					}
					$i++;
				}
				continue;
			}
			// Also match line that contains "Trans Date" and possibly dates on same line (some extractors).
			if ( preg_match( '/Trans\s*Date/i', $line ) && ! preg_match( '/^Amount$/i', $line ) ) {
				if ( preg_match_all( '/\d{1,2}\/\d{1,2}(\/\d{2,4})?/', $line, $date_matches ) ) {
					foreach ( $date_matches[0] as $d ) {
						if ( strlen( $d ) <= 5 ) {
							$d = $d . '/' . $statement_year;
						}
						$norm = $this->normalize_date( $d );
						if ( $norm !== '' ) {
							$dates[] = $norm;
						}
					}
				}
				$i++;
				while ( $i < $n && preg_match( '/^\d{1,2}\/\d{1,2}(\/\d{2,4})?$/', $lines[ $i ] ) ) {
					$d = $lines[ $i ];
					if ( strlen( $d ) <= 5 ) {
						$d = $d . '/' . $statement_year;
					}
					$norm = $this->normalize_date( $d );
					if ( $norm !== '' ) {
						$dates[] = $norm;
					}
					$i++;
				}
				continue;
			}
			if ( preg_match( '/^(Transactions|Description)\s*(\(continued\))?$/i', $line ) || ( preg_match( '/^Description\s*$/i', $line ) && $i > 0 ) ) {
				$i++;
				while ( $i < $n ) {
					$ln = $lines[ $i ];
					if ( preg_match( '/^Amount$/i', $ln ) || preg_match( '/^\$?-?[\d,]+\.\d{2}\s*$/', $ln ) ) {
						break;
					}
					if ( preg_match( '/^Reference$/i', $ln ) || preg_match( '/^Post\s+Date$/i', $ln ) || preg_match( '/^PAGE\s+\d+\s+of\s+\d+/i', $ln ) || preg_match( '/^(Description|Transactions)\s*$/i', $ln ) ) {
						$i++;
						continue;
					}
					// Skip footer / page codes (e.g. FSF1, FSFQ) — short all-caps alphanumeric, no spaces.
					if ( preg_match( '/^[A-Z0-9]{3,8}$/', $ln ) ) {
						$i++;
						continue;
					}
					if ( strlen( $ln ) >= 4 && preg_match( '/[A-Za-z]/', $ln ) && ! preg_match( '/^\d{1,2}\/\d{1,2}(\/\d{2,4})?$/', $ln ) && ! preg_match( '/^\$?-?[\d,]+\.\d{2}\s*$/', $ln ) && ! preg_match( '/^PAGE\s+/i', $ln ) && ! preg_match( '/^[A-Z0-9]{10,}$/', $ln ) ) {
						$descriptions[] = preg_replace( '/\s+/', ' ', $ln );
					}
					$i++;
				}
				continue;
			}
			if ( preg_match( '/^Amount$/i', $line ) ) {
				$i++;
				while ( $i < $n && preg_match( '/^\$?-?[\d,]+\.\d{2}\s*$/', $lines[ $i ] ) ) {
					$amt = trim( $lines[ $i ] );
					$amt = $this->normalize_amount_string( $amt );
					$amounts[] = $amt;
					$i++;
				}
				continue;
			}
			$i++;
		}

		$count = min( count( $dates ), count( $descriptions ), count( $amounts ) );
		if ( $count === 0 ) {
			return [];
		}
		$transactions = [];
		for ( $j = 0; $j < $count; $j++ ) {
			$transactions[] = [
				'date'        => $dates[ $j ],
				'time'        => '00:00:00',
				'description' => $descriptions[ $j ],
				'amount'      => $amounts[ $j ],
			];
		}
		return $transactions;
	}

	/**
	 * Infer 4-digit statement year from text (e.g. from "Statement Closing Date 09/21/2025").
	 *
	 * @param string $text Full extracted text.
	 * @return string
	 */
	private function infer_statement_year( $text ) {
		if ( preg_match( '/\d{1,2}\/\d{1,2}\/(\d{4})/', $text, $m ) ) {
			return $m[1];
		}
		return gmdate( 'Y' );
	}

	/**
	 * Parse PDFs where each line has date + description + amount.
	 *
	 * @param string $text Raw extracted text.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	private function parse_line_style_transactions( $text ) {
		$transactions = [];
		$lines        = preg_split( '/\r\n|\r|\n/', $text, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$line_for_amount = str_replace( ',', '', $line );
			$line_for_amount = str_replace( [ '(', ')' ], [ '-', '' ], $line_for_amount );
			if ( preg_match( '/(\d{1,4}[-\/]\d{1,2}[-\/]\d{1,4}|\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})/', $line, $date_m ) &&
				 preg_match( '/-?\d+\.?\d{0,2}\s*$|-?\d+\.\d{2}/', $line_for_amount, $amount_m ) ) {
				$date_str = $date_m[1];
				$date     = $this->normalize_date( $date_str );
				$amount   = trim( $amount_m[0] );
				$amount   = preg_replace( '/\s/', '', $amount );
				$amount   = $this->normalize_amount_string( $amount );
				$rest = trim( preg_replace( '/' . preg_quote( $date_str, '/' ) . '/', '', $line, 1 ) );
				$rest = trim( preg_replace( '/\s*-?\$?\s*-?[\d,\s]+\.\d{2}\s*$/', '', $rest ) );
				$rest = trim( preg_replace( '/\s*\(\s*[\d,]+\s*\.?\d*\s*\)\s*$/', '', $rest ) );
				$description = preg_replace( '/\s+/', ' ', $rest );
				if ( $date !== '' && $description !== '' ) {
					$transactions[] = [
						'date'        => $date,
						'time'        => '00:00:00',
						'description' => $description,
						'amount'      => $amount,
					];
				}
			}
		}
		return $transactions;
	}

	/**
	 * Normalize date to Y-m-d.
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	private function normalize_date( $date ) {
		$ts = strtotime( $date );
		if ( $ts === false ) {
			return '';
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Normalize amount string.
	 *
	 * @param string $amount Raw amount.
	 * @return string
	 */
	private function normalize_amount_string( $amount ) {
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
