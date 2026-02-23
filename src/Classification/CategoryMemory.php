<?php
/**
 * In-memory (option-backed) store of description → category term_id for learned classifications.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Classification;

use StatementProcessor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and retrieves description-to-category mappings so we don't re-call the LLM for known descriptions.
 */
class CategoryMemory {

	const OPTION_NAME = 'statement_processor_category_rules';

	/**
	 * Normalize a transaction description for consistent lookup (lowercase, trim, collapse whitespace).
	 *
	 * @param string $description Raw description.
	 * @return string
	 */
	public static function normalize_description( $description ) {
		$s = is_string( $description ) ? $description : '';
		$s = trim( $s );
		$s = preg_replace( '/\s+/', ' ', $s );
		return strtolower( $s );
	}

	/**
	 * Get the stored term_id for a description, or null if not learned.
	 *
	 * @param string $description Transaction description.
	 * @return int|null Term ID or null.
	 */
	public static function get_term_id( $description ) {
		$key = self::normalize_description( $description );
		if ( $key === '' ) {
			return null;
		}
		$rules = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $rules ) ) {
			return null;
		}
		$term_id = isset( $rules[ $key ] ) ? (int) $rules[ $key ] : 0;
		if ( $term_id <= 0 ) {
			return null;
		}
		$term = get_term( $term_id, Plugin::taxonomy_category() );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		return $term_id;
	}

	/**
	 * Remember a description → term_id mapping.
	 *
	 * @param string $description Transaction description.
	 * @param int    $term_id     Category term ID (must exist in sp-category).
	 * @return bool True if saved.
	 */
	public static function remember( $description, $term_id ) {
		$key = self::normalize_description( $description );
		if ( $key === '' ) {
			return false;
		}
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return false;
		}
		$term = get_term( $term_id, Plugin::taxonomy_category() );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		$rules = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $rules ) ) {
			$rules = [];
		}
		$rules[ $key ] = $term_id;
		return update_option( self::OPTION_NAME, $rules );
	}
}
