<?php
/**
 * CSV statement parser with convention-based column detection.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Parser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses CSV files and maps rows to normalized transaction arrays.
 */
class CsvParser {

	/**
	 * Convention-based header aliases for column detection (lowercase).
	 *
	 * @var array<string, string[]>
	 */
	private $header_aliases = [
		'date'        => [ 'date', 'transaction date', 'trans date', 'posting date', 'posting date', 'trans_date' ],
		'time'        => [ 'time', 'transaction time' ],
		'description' => [ 'description', 'merchant', 'payee', 'memo', 'details', 'name', 'narrative', 'type' ],
		'amount'      => [ 'amount', 'debit', 'credit', 'transaction amount', 'sum', 'total', 'net', 'gross' ],
	];

	/**
	 * Parse a CSV file into rows (associative arrays if header row detected).
	 *
	 * @param string $file_path Path to CSV file.
	 * @return array<int, array<string, string>> Rows as associative arrays keyed by header, or numeric if no header match.
	 */
	public function parse( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return [];
		}

		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return [];
		}

		$rows   = [];
		$header = null;

		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
			$row = array_map( [ $this, 'normalize_cell' ], $row );
			if ( $header === null ) {
				$header = $this->detect_header( $row );
				if ( $header !== null ) {
					continue;
				}
			}
			if ( $header !== null && count( $row ) >= count( $header ) ) {
				$rows[] = array_combine( array_slice( $header, 0, count( $row ) ), array_slice( $row, 0, count( $header ) ) ) ?: [];
			} elseif ( $header === null ) {
				$rows[] = $row;
			}
		}

		fclose( $handle );
		return $rows;
	}

	/**
	 * Map parsed rows to normalized transaction arrays.
	 *
	 * @param array<int, array<string, string>> $rows Rows from parse().
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	public function map_to_transactions( array $rows ) {
		$transactions = [];
		$map          = $this->infer_column_map( $rows );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$date        = $this->get_mapped_value( $row, $map, 'date' );
			$time        = $this->get_mapped_value( $row, $map, 'time' );
			$description = $this->get_mapped_value( $row, $map, 'description' );
			$amount      = $this->get_mapped_value( $row, $map, 'amount' );

			$date = $this->normalize_date( $date );
			if ( $date === '' ) {
				continue;
			}
			$amount = $this->normalize_amount_string( $amount );
			$description = trim( $description );
			if ( $description === '' ) {
				continue;
			}

			$transactions[] = [
				'date'        => $date,
				'time'        => $time ? $this->normalize_time( $time ) : '00:00:00',
				'description' => $description,
				'amount'      => $amount,
			];
		}

		return $transactions;
	}

	/**
	 * Detect if the first row is a header by checking for known column names.
	 *
	 * @param array<int, string> $row First row.
	 * @return array<int, string>|null Header keys (normalized) or null.
	 */
	private function detect_header( array $row ) {
		$normalized = array_map( function ( $cell ) {
			return strtolower( trim( preg_replace( '/[\s\-]+/', ' ', $cell ) ) );
		}, $row );
		$found = 0;
		foreach ( $this->header_aliases as $key => $aliases ) {
			foreach ( $normalized as $cell ) {
				if ( in_array( $cell, $aliases, true ) || $cell === $key ) {
					++$found;
					break;
				}
			}
		}
		if ( $found >= 2 ) {
			return array_map( function ( $cell ) {
				$c = strtolower( trim( preg_replace( '/[\s\-]+/', '_', $cell ) ) );
				foreach ( $this->header_aliases as $key => $aliases ) {
					if ( in_array( $c, $aliases, true ) || $c === $key ) {
						return $key;
					}
				}
				return $c ?: 'col';
			}, $row );
		}
		return null;
	}

	/**
	 * Infer column map from rows (convention-based or positional).
	 *
	 * @param array $rows Rows.
	 * @return array<string, int|string> Map of field => column index or key.
	 */
	private function infer_column_map( array $rows ) {
		$map = [ 'date' => 0, 'time' => null, 'description' => 1, 'amount' => 2 ];
		$first = reset( $rows );
		if ( $first && is_array( $first ) && $this->is_assoc( $first ) ) {
			$keys = array_keys( $first );
			$has_name = in_array( 'name', $keys, true ) || in_array( 'Name', $keys, true );
			foreach ( $keys as $i => $k ) {
				$k_lower = is_string( $k ) ? strtolower( preg_replace( '/[\s\-]+/', '_', $k ) ) : '';
				if ( $k_lower === '' ) {
					continue;
				}
				foreach ( array_keys( $this->header_aliases ) as $field ) {
					if ( $k_lower === $field || in_array( $k_lower, $this->header_aliases[ $field ], true ) ) {
						$map[ $field ] = $k;
						break;
					}
				}
			}
			// Prefer Name over Type for description when both exist (e.g. PayPal CSV).
			$desc_col = $map['description'] ?? null;
			if ( $desc_col !== null && is_string( $desc_col ) ) {
				$desc_key_lower = strtolower( preg_replace( '/[\s\-]+/', '_', $desc_col ) );
				if ( $desc_key_lower === 'type' && $has_name ) {
					foreach ( array_keys( $first ) as $k ) {
						if ( strtolower( preg_replace( '/[\s\-]+/', '_', $k ) ) === 'name' ) {
							$map['description'] = $k;
							break;
						}
					}
				}
			}
		}
		return $map;
	}

	/**
	 * Check if array is associative.
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private function is_assoc( array $arr ) {
		if ( empty( $arr ) ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Get value from row by map (key or index).
	 *
	 * @param array       $row Row.
	 * @param array       $map Map.
	 * @param string      $field Field name.
	 * @return string
	 */
	private function get_mapped_value( array $row, array $map, $field ) {
		$key = isset( $map[ $field ] ) ? $map[ $field ] : null;
		if ( $key === null ) {
			return '';
		}
		if ( is_int( $key ) && isset( $row[ $key ] ) ) {
			return (string) $row[ $key ];
		}
		if ( isset( $row[ $key ] ) ) {
			return (string) $row[ $key ];
		}
		return '';
	}

	/**
	 * Normalize cell encoding.
	 *
	 * @param string $cell Cell value.
	 * @return string
	 */
	private function normalize_cell( $cell ) {
		$cell = (string) $cell;
		// Strip UTF-8 BOM so header detection matches "Date" etc.
		if ( substr( $cell, 0, 3 ) === "\xEF\xBB\xBF" ) {
			$cell = substr( $cell, 3 );
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$enc = mb_detect_encoding( $cell, [ 'UTF-8', 'ISO-8859-1', 'Windows-1252' ], true );
			if ( $enc && $enc !== 'UTF-8' ) {
				$cell = mb_convert_encoding( $cell, 'UTF-8', $enc );
			}
		}
		$cell = trim( $cell );
		// Strip surrounding double quotes from CSV-quoted cells so "Date" becomes Date.
		if ( strlen( $cell ) >= 2 && $cell[0] === '"' && substr( $cell, -1 ) === '"' ) {
			$cell = substr( $cell, 1, -1 );
		}
		return trim( $cell );
	}

	/**
	 * Normalize date to Y-m-d.
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	private function normalize_date( $date ) {
		$date = trim( $date );
		if ( $date === '' ) {
			return '';
		}
		$ts = strtotime( $date );
		if ( $ts === false ) {
			return '';
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Normalize time to H:i:s.
	 *
	 * @param string $time Raw time.
	 * @return string
	 */
	private function normalize_time( $time ) {
		$time = trim( $time );
		if ( $time === '' ) {
			return '00:00:00';
		}
		$ts = strtotime( '1970-01-01 ' . $time );
		if ( $ts === false ) {
			return '00:00:00';
		}
		return gmdate( 'H:i:s', $ts );
	}

	/**
	 * Normalize amount string (strip currency, commas; handle parentheses for negative).
	 *
	 * @param string $amount Raw amount.
	 * @return string
	 */
	private function normalize_amount_string( $amount ) {
		$amount = trim( (string) $amount );
		$amount = str_replace( ',', '', $amount );
		$amount = preg_replace( '/\s+/', '', $amount );
		$amount = preg_replace( '/[^\d.\-\(\)]/', '', $amount );
		$amount = str_replace( [ '(', ')' ], [ '-', '' ], $amount );
		if ( $amount === '' || $amount === '-' ) {
			return '0.00';
		}
		$num = (float) $amount;
		return number_format( $num, 2, '.', '' );
	}
}
