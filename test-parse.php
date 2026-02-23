#!/usr/bin/env php
<?php
/**
 * CLI test for parsers. Run from plugin directory:
 *   php test-parse.php "path/to/file.pdf"
 *   php test-parse.php "path/to/file.csv"
 *
 * Requires ABSPATH so parser files don't exit (WordPress constant).
 */
if ( PHP_SAPI !== 'cli' ) {
	die( 'CLI only.' );
}

define( 'ABSPATH', true );

$file = isset( $argv[1] ) ? $argv[1] : '';
if ( $file === '' || ! is_readable( $file ) ) {
	fwrite( STDERR, "Usage: php test-parse.php <path-to-pdf-or-csv>\n" );
	fwrite( STDERR, "File not found or not readable: " . ( $file ?: '(none)' ) . "\n" );
	exit( 1 );
}

require __DIR__ . '/vendor/autoload.php';

$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

if ( $ext === 'csv' ) {
	$parser = new \StatementProcessor\Parser\CsvParser();
	$rows   = $parser->parse( $file );
	echo "CSV rows from parse(): " . count( $rows ) . "\n";
	if ( ! empty( $rows ) ) {
		$tx = $parser->map_to_transactions( $rows );
		echo "Transactions: " . count( $tx ) . "\n";
		if ( ! empty( $tx ) ) {
			print_r( array_slice( $tx, 0, 2 ) );
		} else {
			echo "First row keys: " . implode( ', ', array_keys( reset( $rows ) ) ) . "\n";
		}
	}
} else {
	$parser = new \StatementProcessor\Parser\PdfParser();
	$text   = ( new \StatementProcessor\Parser\PdfTextExtractor() )->extract( $file );
	echo "PDF extracted text length: " . strlen( $text ) . "\n";
	if ( strlen( $text ) > 0 ) {
		echo "First 500 chars:\n" . substr( $text, 0, 500 ) . "\n...\n";
	}
	$tx = $parser->parse( $file );
	echo "PDF transactions: " . count( $tx ) . "\n";
	if ( ! empty( $tx ) ) {
		print_r( array_slice( $tx, 0, 2 ) );
	}
}
