<?php
/**
 * Ally Bank CSV parser: Date, Description, Credits, Debits with repeating headers and multi-line descriptions.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Parser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses Ally checking/savings CSV exports. Headers repeat; descriptions span multiple lines; Beginning Balance rows are skipped.
 */
class AllyCsvParser {

	/**
	 * Header row (normalized) to detect and skip.
	 *
	 * @var string[]
	 */
	private static $header_cells = array( 'date', 'description', 'credits', 'debits' );

	/**
	 * Check if a file looks like an Ally CSV (first row is Date,Description,Credits,Debits).
	 *
	 * @param string $file_path Path to CSV file.
	 * @return bool
	 */
	public static function is_ally_csv( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return false;
		}
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return false;
		}
		$row = fgetcsv( $handle, 0, ',', '"', '\\' );
		fclose( $handle );
		if ( ! is_array( $row ) || count( $row ) < 4 ) {
			return false;
		}
		$normalized = array_map(
			function ( $c ) {
				return strtolower( trim( preg_replace( '/[\s\-]+/', ' ', (string) $c ) ) );
			},
			array_slice( $row, 0, 4 )
		);
		return self::$header_cells === $normalized;
	}

	/**
	 * Parse Ally CSV into normalized transaction rows.
	 *
	 * - Skips header rows (Date,Description,Credits,Debits) whenever they repeat.
	 * - Skips "Beginning Balance" rows.
	 * - Merges multi-line descriptions into one line, separated by space.
	 *
	 * @param string $file_path Path to CSV file.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	public function parse( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return array();
		}
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return array();
		}

		$transactions = array();
		$current      = null;

		$row = fgetcsv( $handle, 0, ',', '"', '\\' );
		while ( false !== $row ) {
			$row     = array_map( array( $this, 'normalize_cell' ), $row );
			$date    = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
			$desc    = isset( $row[1] ) ? trim( (string) $row[1] ) : '';
			$credits = isset( $row[2] ) ? trim( (string) $row[2] ) : '';
			$debits  = isset( $row[3] ) ? trim( (string) $row[3] ) : '';

			// Skip header rows (repeating).
			if ( $this->is_header_row( $date, $desc, $credits, $debits ) ) {
				$row = fgetcsv( $handle, 0, ',', '"', '\\' );
				continue;
			}

			if ( '' !== $date ) {
				// Flush previous transaction.
				if ( null !== $current ) {
					$transactions[] = $current;
				}
				// Skip Beginning Balance.
				if ( 'Beginning Balance' === $desc ) {
					$current = null;
					$row     = fgetcsv( $handle, 0, ',', '"', '\\' );
					continue;
				}
				$amount  = $this->amount_from_credits_debits( $credits, $debits );
				$current = array(
					'date'        => $this->normalize_date( $date ),
					'time'        => '00:00:00',
					'description' => $desc,
					'amount'      => $amount,
				);
			} elseif ( null !== $current && '' !== $desc ) {
				// Continuation line: append description to current transaction.
				$current['description'] = trim( $current['description'] . ' ' . $desc );
			}

			$row = fgetcsv( $handle, 0, ',', '"', '\\' );
		}

		if ( null !== $current ) {
			$transactions[] = $current;
		}

		fclose( $handle );
		return $transactions;
	}

	/**
	 * Check if row is the header row (Date, Description, Credits, Debits).
	 *
	 * @param string $date Date cell.
	 * @param string $desc Description cell.
	 * @param string $credits Credits cell.
	 * @param string $debits Debits cell.
	 * @return bool
	 */
	private function is_header_row( $date, $desc, $credits, $debits ) {
		$d  = strtolower( preg_replace( '/[\s\-]+/', ' ', $date ) );
		$de = strtolower( preg_replace( '/[\s\-]+/', ' ', $desc ) );
		$c  = strtolower( preg_replace( '/[\s\-]+/', ' ', $credits ) );
		$db = strtolower( preg_replace( '/[\s\-]+/', ' ', $debits ) );
		return 'date' === $d && 'description' === $de && 'credits' === $c && 'debits' === $db;
	}

	/**
	 * Single amount from Credits (positive) or Debits (negative). Prefer non-zero.
	 *
	 * @param string $credits Credits cell (e.g. "$7,569.64").
	 * @param string $debits Debits cell (e.g. "-$12.95").
	 * @return string Normalized amount (e.g. "7569.64" or "-12.95").
	 */
	private function amount_from_credits_debits( $credits, $debits ) {
		$credits_num = $this->parse_amount( $credits );
		$debits_num  = $this->parse_amount( $debits );
		// Credits are positive; debits are stored as negative in file, so debits_num is already negative.
		if ( 0.0 !== $credits_num ) {
			return number_format( $credits_num, 2, '.', '' );
		}
		if ( 0.0 !== $debits_num ) {
			return number_format( $debits_num, 2, '.', '' );
		}
		return '0.00';
	}

	/**
	 * Parse amount string to float (strip $, commas; treat leading minus as negative).
	 *
	 * @param string $value Raw value.
	 * @return float
	 */
	private function parse_amount( $value ) {
		$value = trim( (string) $value );
		$value = str_replace( ',', '', $value );
		$value = preg_replace( '/\s+/', '', $value );
		$value = preg_replace( '/[^\d.\-]/', '', $value );
		if ( '' === $value || '-' === $value ) {
			return 0.0;
		}
		return (float) $value;
	}

	/**
	 * Normalize cell (trim, BOM, encoding, quotes).
	 *
	 * @param string $cell Cell value.
	 * @return string
	 */
	private function normalize_cell( $cell ) {
		$cell = (string) $cell;
		if ( "\xEF\xBB\xBF" === substr( $cell, 0, 3 ) ) {
			$cell = substr( $cell, 3 );
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$enc = mb_detect_encoding( $cell, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252' ), true );
			if ( $enc && 'UTF-8' !== $enc ) {
				$cell = mb_convert_encoding( $cell, 'UTF-8', $enc );
			}
		}
		$cell = trim( $cell );
		if ( strlen( $cell ) >= 2 && '"' === $cell[0] && '"' === substr( $cell, -1 ) ) {
			$cell = substr( $cell, 1, -1 );
		}
		return trim( $cell );
	}

	/**
	 * Normalize date to Y-m-d.
	 *
	 * @param string $date Raw date (e.g. 03/26/2024).
	 * @return string
	 */
	private function normalize_date( $date ) {
		$date = trim( $date );
		if ( '' === $date ) {
			return '';
		}
		$ts = strtotime( $date );
		if ( false === $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d', $ts );
	}
}
