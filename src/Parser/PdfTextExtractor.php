<?php
/**
 * Extracts text from PDF files (native text layer).
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Parser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses smalot/pdfparser or fallback to pdftotext when available.
 */
class PdfTextExtractor {

	/**
	 * Extract text from a PDF file.
	 * Prefers pdftotext when available so columnar layouts (e.g. statements) keep
	 * line/column order; falls back to smalot/pdfparser otherwise.
	 *
	 * @param string $file_path Path to PDF.
	 * @return string Extracted text.
	 */
	public function extract( $file_path ) {
		$text = $this->extract_via_pdftotext( $file_path );
		if ( strlen( trim( $text ) ) >= 50 ) {
			return $text;
		}
		if ( class_exists( '\Smalot\PdfParser\Parser' ) ) {
			return $this->extract_via_smalot( $file_path );
		}
		return $text;
	}

	/**
	 * Extract using smalot/pdfparser.
	 *
	 * @param string $file_path Path to PDF.
	 * @return string
	 */
	private function extract_via_smalot( $file_path ) {
		try {
			$parser = new \Smalot\PdfParser\Parser();
			$pdf    = $parser->parseFile( $file_path );
			return $pdf->getText();
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Extract using pdftotext (poppler-utils) if available.
	 *
	 * @param string $file_path Path to PDF.
	 * @return string
	 */
	private function extract_via_pdftotext( $file_path ) {
		// Use -layout so columnar statements (e.g. PayPal Credit) keep fields on the same line.
		$cmd = 'pdftotext -layout ' . escapeshellarg( $file_path ) . ' - 2>/dev/null';
		$out = @shell_exec( $cmd );
		return is_string( $out ) ? $out : '';
	}
}
