<?php
/**
 * Classifies a transaction description into a category (memory-first, then LLM).
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Classification;

use StatementProcessor\Admin\SettingsPage;
use StatementProcessor\AI\LlmClient;
use StatementProcessor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a category term_id for a description using learned rules and optional AI.
 */
class TransactionClassifier {

	/**
	 * LLM client.
	 *
	 * @var LlmClient
	 */
	private $llm_client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->llm_client = new LlmClient();
	}

	/**
	 * Classify a transaction description into a category term_id.
	 * Uses memory first; on miss, calls LLM if configured, then remembers the result.
	 *
	 * @param string $description Transaction description.
	 * @return int|null Term ID for sp-category, or null.
	 */
	public function classify( $description ) {
		$description = is_string( $description ) ? trim( $description ) : '';
		if ( $description === '' ) {
			return null;
		}

		$term_id = CategoryMemory::get_term_id( $description );
		if ( $term_id !== null ) {
			return $term_id;
		}

		if ( ! SettingsPage::is_ai_configured() ) {
			return null;
		}

		$term_id = $this->classify_via_llm( $description );
		if ( $term_id !== null ) {
			CategoryMemory::remember( $description, $term_id );
			return $term_id;
		}

		return null;
	}

	/**
	 * Call LLM to pick one category from the list; map response to term_id.
	 *
	 * @param string $description Transaction description.
	 * @return int|null
	 */
	private function classify_via_llm( $description ) {
		$terms = get_terms(
			[
				'taxonomy'   => Plugin::taxonomy_category(),
				'hide_empty' => false,
			]
		);
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return null;
		}

		$category_names = wp_list_pluck( $terms, 'name' );
		$system         = 'You categorize business/transaction descriptions into exactly one of the given categories. Reply with only the category name, nothing else.';
		$user           = 'Categories: ' . implode( ', ', $category_names ) . "\n\nDescription: " . $description;

		$response = $this->llm_client->generate_text( $system, $user, [ 'max_tokens' => 50 ] );
		if ( $response === null || $response === '' ) {
			return null;
		}

		return $this->match_response_to_term( trim( $response ), $terms );
	}

	/**
	 * Match LLM response string to a term (by name or slug); return term_id or null.
	 *
	 * @param string    $response Trimmed response from LLM.
	 * @param \WP_Term[] $terms    Category terms.
	 * @return int|null
	 */
	private function match_response_to_term( $response, $terms ) {
		$normalized = strtolower( trim( preg_replace( '/[^\p{L}\p{N}\s\/\-]/u', '', $response ) ) );
		$normalized = preg_replace( '/\s+/', ' ', $normalized );
		if ( $normalized === '' ) {
			return null;
		}

		foreach ( $terms as $term ) {
			$name = strtolower( trim( preg_replace( '/[^\p{L}\p{N}\s\/\-]/u', '', $term->name ) ) );
			$slug = strtolower( $term->slug );
			$name = preg_replace( '/\s+/', ' ', $name );
			if ( $normalized === $name || $normalized === $slug ) {
				return (int) $term->term_id;
			}
			// Partial match: response contains term name or vice versa.
			if ( strpos( $normalized, $name ) !== false || strpos( $name, $normalized ) !== false ) {
				return (int) $term->term_id;
			}
		}

		return null;
	}
}
