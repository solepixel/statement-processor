<?php
/**
 * Transaction Category taxonomy registration.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Taxonomy;

use StatementProcessor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the sp-category taxonomy for transaction categories (tax-deductible etc.).
 */
class TransactionCategory {

	/**
	 * Taxonomy key.
	 *
	 * @var string
	 */
	const TAXONOMY = 'sp-category';

	/**
	 * Default category names (initial set when taxonomy has no terms).
	 *
	 * @var string[]
	 */
	const DEFAULT_CATEGORIES = [
		'Equipment',
		'Office Supplies',
		'Plugins/Themes',
		'Development Testing',
		'Taxes',
		'Subcontract',
		'Software/Services',
		'Travel',
		'Domains/Email',
		'Marketing/Research',
		'SSL',
		'Gas/Automobile',
		'Meal/Entertainment',
		'Other',
		'Fees',
		'Personal Development',
		'Payroll Fees',
	];

	/**
	 * Register the taxonomy and ensure default terms exist.
	 */
	public function register() {
		register_taxonomy(
			self::TAXONOMY,
			Plugin::post_type(),
			[
				'labels'            => [
					'name'              => _x( 'Categories', 'taxonomy general name', 'statement-processor' ),
					'singular_name'     => _x( 'Category', 'taxonomy singular name', 'statement-processor' ),
					'search_items'      => __( 'Search Categories', 'statement-processor' ),
					'all_items'         => __( 'All Categories', 'statement-processor' ),
					'edit_item'         => __( 'Edit Category', 'statement-processor' ),
					'update_item'       => __( 'Update Category', 'statement-processor' ),
					'add_new_item'      => __( 'Add New Category', 'statement-processor' ),
					'new_item_name'     => __( 'New Category Name', 'statement-processor' ),
					'menu_name'         => __( 'Categories', 'statement-processor' ),
				],
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_rest'      => false,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'rewrite'           => false,
				'query_var'         => false,
			]
		);

		$this->ensure_default_terms();
	}

	/**
	 * Insert default category terms if the taxonomy has no terms.
	 */
	private function ensure_default_terms() {
		$existing = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'number'     => 1,
			]
		);
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return;
		}

		foreach ( self::DEFAULT_CATEGORIES as $name ) {
			$slug = sanitize_title( $name );
			if ( term_exists( $slug, self::TAXONOMY ) ) {
				continue;
			}
			wp_insert_term( $name, self::TAXONOMY, [ 'slug' => $slug ] );
		}
	}
}
