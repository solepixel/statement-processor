<?php
/**
 * Transaction custom post type registration.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\PostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the sp-transaction post type.
 */
class TransactionPostType {

	/**
	 * Post type key.
	 *
	 * @var string
	 */
	const POST_TYPE = 'sp-transaction';

	/**
	 * Register the post type.
	 */
	public function register() {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'              => [
					'name'                  => _x( 'Transactions', 'post type general name', 'statement-processor' ),
					'singular_name'         => _x( 'Transaction', 'post type singular name', 'statement-processor' ),
					'menu_name'             => _x( 'Transactions', 'admin menu', 'statement-processor' ),
					'add_new'               => _x( 'Add New', 'transaction', 'statement-processor' ),
					'add_new_item'          => __( 'Add New Transaction', 'statement-processor' ),
					'edit_item'             => __( 'Edit Transaction', 'statement-processor' ),
					'new_item'              => __( 'New Transaction', 'statement-processor' ),
					'view_item'             => __( 'View Transaction', 'statement-processor' ),
					'search_items'          => __( 'Search Transactions', 'statement-processor' ),
					'not_found'             => __( 'No transactions found.', 'statement-processor' ),
					'not_found_in_trash'    => __( 'No transactions found in Trash.', 'statement-processor' ),
					'all_items'             => __( 'All Transactions', 'statement-processor' ),
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'supports'            => [ 'title', 'custom-fields' ],
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_icon'           => 'dashicons-list-view',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			]
		);
	}
}
