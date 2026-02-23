<?php
/**
 * Exports transactions to CSV with date and source filters.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Export;

use StatementProcessor\Plugin;
use StatementProcessor\Import\TransactionImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries sp-transaction posts and streams CSV download.
 */
class CsvExporter {

	/**
	 * Export transactions to CSV and send download headers.
	 */
	public function export() {
		$year  = isset( $_GET['export_year'] ) ? absint( $_GET['export_year'] ) : 0;
		$month = isset( $_GET['export_month'] ) ? absint( $_GET['export_month'] ) : 0;
		$source_id = isset( $_GET['export_source'] ) ? absint( $_GET['export_source'] ) : 0;

		$args = [
			'post_type'      => Plugin::post_type(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
		];

		$date_query = [];
		if ( $year > 0 ) {
			$date_query['year'] = $year;
		}
		if ( $month > 0 ) {
			$date_query['monthnum'] = $month;
		}
		if ( ! empty( $date_query ) ) {
			$args['date_query'] = [ $date_query ];
		}

		if ( $source_id > 0 ) {
			$args['tax_query'] = [
				[
					'taxonomy' => Plugin::taxonomy_source(),
					'field'    => 'term_id',
					'terms'    => $source_id,
				],
			];
		}

		$query = new \WP_Query( $args );
		$ids   = $query->posts;
		if ( ! is_array( $ids ) ) {
			$ids = [];
		}

		$filename = 'transactions-' . gmdate( 'Y-m-d-His' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		if ( $out === false ) {
			return;
		}

		// UTF-8 BOM for Excel.
		fprintf( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, [ 'Date', 'Time', 'Description', 'Amount', 'Source', 'Transaction ID' ] );

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || $post->post_type !== Plugin::post_type() ) {
				continue;
			}
			$date    = $post->post_date ? gmdate( 'Y-m-d', strtotime( $post->post_date ) ) : '';
			$time    = $post->post_date ? gmdate( 'H:i:s', strtotime( $post->post_date ) ) : '';
			$desc    = get_post_meta( $post_id, TransactionImporter::META_DESCRIPTION, true );
			$amount  = get_post_meta( $post_id, TransactionImporter::META_AMOUNT, true );
			$tx_id   = get_post_meta( $post_id, TransactionImporter::META_TRANSACTION_ID, true );
			$terms   = get_the_terms( $post_id, Plugin::taxonomy_source() );
			$source  = ( $terms && ! is_wp_error( $terms ) && isset( $terms[0] ) ) ? $terms[0]->name : '';
			fputcsv( $out, [ $date, $time, $desc, $amount, $source, $tx_id ] );
		}

		fclose( $out );
	}
}
