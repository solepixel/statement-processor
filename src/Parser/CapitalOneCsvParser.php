<?php
/**
 * Capital One bank statement CSV parser: DATE, DESCRIPTION, CATEGORY, AMOUNT.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Parser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses Capital One CSV exports. Headers repeat; no year on dates (inferred from filename).
 * Credits: "Credit" and "+"; Debits: "Debit" and "-". Skips Opening Balance and blank rows.
 */
class CapitalOneCsvParser {

	/**
	 * Header row (normalized) to detect and skip.
	 *
	 * @var string[]
	 */
	private static $header_cells = array( 'date', 'description', 'category', 'amount' );

	/**
	 * Check if a file looks like a Capital One CSV (first row is DATE,DESCRIPTION,CATEGORY,AMOUNT).
	 *
	 * @param string $file_path Path to CSV file.
	 * @return bool
	 */
	public static function is_capital_one_csv( $file_path ) {
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
	 * Extract 4-digit year from filename (e.g. capitalone-20240401-Bank statement.csv → 2024).
	 *
	 * @param string $original_name Original filename.
	 * @return string 4-digit year.
	 */
	public static function year_from_filename( $original_name ) {
		if ( preg_match( '/20\d{2}/', $original_name, $m ) ) {
			return $m[0];
		}
		if ( preg_match( '/(\d{4})/', $original_name, $m ) ) {
			return $m[1];
		}
		return gmdate( 'Y' );
	}

	/**
	 * Parse Capital One CSV into normalized transaction rows.
	 *
	 * - Skips header rows (DATE,DESCRIPTION,CATEGORY,AMOUNT) whenever they repeat.
	 * - Skips blank rows and "Opening Balance".
	 * - Infers year from original filename (e.g. 2024 from capitalone-20240401-Bank statement.csv).
	 * - Amount: Credit/+ → positive, Debit/- → negative.
	 *
	 * @param string $file_path     Path to CSV file.
	 * @param string $original_name Original filename (for year extraction).
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	public function parse( $file_path, $original_name = '' ) {
		if ( ! is_readable( $file_path ) ) {
			return array();
		}
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return array();
		}

		$year         = self::year_from_filename( $original_name );
		$transactions = array();

		$row = fgetcsv( $handle, 0, ',', '"', '\\' );
		while ( false !== $row ) {
			$row        = array_map( array( $this, 'normalize_cell' ), $row );
			$date       = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
			$desc       = isset( $row[1] ) ? trim( (string) $row[1] ) : '';
			$cat        = isset( $row[2] ) ? trim( (string) $row[2] ) : '';
			$amount_raw = isset( $row[3] ) ? trim( (string) $row[3] ) : '';

			// Skip blank rows.
			if ( '' === $date && '' === $desc && '' === $cat && '' === $amount_raw ) {
				$row = fgetcsv( $handle, 0, ',', '"', '\\' );
				continue;
			}

			// Skip header rows (repeating).
			if ( $this->is_header_row( $date, $desc, $cat, $amount_raw ) ) {
				$row = fgetcsv( $handle, 0, ',', '"', '\\' );
				continue;
			}

			// Skip Opening Balance.
			if ( 'Opening Balance' === $desc ) {
				$row = fgetcsv( $handle, 0, ',', '"', '\\' );
				continue;
			}

			// Need at least date and description for a transaction.
			if ( '' === $date || '' === $desc ) {
				$row = fgetcsv( $handle, 0, ',', '"', '\\' );
				continue;
			}

			$date_str  = $date . ', ' . $year;
			$date_norm = $this->normalize_date( $date_str );
			if ( '' === $date_norm ) {
				$row = fgetcsv( $handle, 0, ',', '"', '\\' );
				continue;
			}

			$amount         = $this->parse_amount( $amount_raw );
			$transactions[] = array(
				'date'        => $date_norm,
				'time'        => '00:00:00',
				'description' => $desc,
				'amount'      => $amount,
			);

			$row = fgetcsv( $handle, 0, ',', '"', '\\' );
		}

		fclose( $handle );
		return $transactions;
	}

	/**
	 * Check if row is the header row (DATE, DESCRIPTION, CATEGORY, AMOUNT).
	 *
	 * @param string $date  Date cell.
	 * @param string $desc  Description cell.
	 * @param string $cat   Category cell.
	 * @param string $amount Amount cell.
	 * @return bool
	 */
	private function is_header_row( $date, $desc, $cat, $amount ) {
		$d  = strtolower( preg_replace( '/[\s\-]+/', ' ', $date ) );
		$de = strtolower( preg_replace( '/[\s\-]+/', ' ', $desc ) );
		$c  = strtolower( preg_replace( '/[\s\-]+/', ' ', $cat ) );
		$a  = strtolower( preg_replace( '/[\s\-]+/', ' ', $amount ) );
		return 'date' === $d && 'description' === $de && 'category' === $c && 'amount' === $a;
	}

	/**
	 * Parse AMOUNT cell: "Credit" and "+" → positive, "Debit" and "-" → negative. Strip $ and commas.
	 *
	 * @param string $value Raw amount (e.g. "- $102.34" or "+ $2,700.00").
	 * @return string Normalized amount (e.g. "-102.34" or "2700.00").
	 */
	private function parse_amount( $value ) {
		$value  = trim( (string) $value );
		$value  = str_replace( array( '$', ',' ), array( '', '' ), $value );
		$value  = preg_replace( '/\s+/', '', $value );
		$signed = 1;
		if ( preg_match( '/^\-/', $value ) ) {
			$signed = -1;
			$value  = ltrim( $value, '-' );
		} elseif ( preg_match( '/^\+/', $value ) ) {
			$value = ltrim( $value, '+' );
		}
		$value = preg_replace( '/[^\d.]/', '', $value );
		if ( '' === $value ) {
			return '0.00';
		}
		$num = (float) $value * $signed;
		return number_format( $num, 2, '.', '' );
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
	 * Normalize date to Y-m-d (e.g. "Apr 1, 2024" → 2024-04-01).
	 *
	 * @param string $date Raw date.
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
