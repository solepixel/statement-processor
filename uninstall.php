<?php
/**
 * Uninstall Statement Processor.
 *
 * Runs when the plugin is deleted. Optionally remove all sp-transaction posts
 * and sp-source terms. Uncomment the block below to enable cleanup.
 *
 * @package StatementProcessor
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
// Remove all transactions and source terms.
$post_ids = get_posts(
	array(
		'post_type'      => 'sp-transaction',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $post_ids as $id ) {
	wp_delete_post( $id, true );
}

$terms = get_terms( array( 'taxonomy' => 'sp-source', 'hide_empty' => false ) );
if ( ! is_wp_error( $terms ) ) {
	foreach ( $terms as $term ) {
		wp_delete_term( $term->term_id, 'sp-source' );
	}
}
*/
