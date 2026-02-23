<?php
/**
 * Transaction Source taxonomy registration.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Taxonomy;

use StatementProcessor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the sp-source taxonomy for transaction sources (bank/card).
 */
class TransactionSource {

	/**
	 * Taxonomy key.
	 *
	 * @var string
	 */
	const TAXONOMY = 'sp-source';

	/**
	 * Register the taxonomy.
	 */
	public function register() {
		register_taxonomy(
			self::TAXONOMY,
			Plugin::post_type(),
			[
				'labels'            => [
					'name'              => _x( 'Sources', 'taxonomy general name', 'statement-processor' ),
					'singular_name'     => _x( 'Source', 'taxonomy singular name', 'statement-processor' ),
					'search_items'      => __( 'Search Sources', 'statement-processor' ),
					'all_items'         => __( 'All Sources', 'statement-processor' ),
					'edit_item'         => __( 'Edit Source', 'statement-processor' ),
					'update_item'       => __( 'Update Source', 'statement-processor' ),
					'add_new_item'      => __( 'Add New Source', 'statement-processor' ),
					'new_item_name'     => __( 'New Source Name', 'statement-processor' ),
					'menu_name'         => __( 'Sources', 'statement-processor' ),
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
	}
}
