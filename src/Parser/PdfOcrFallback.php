<?php
/**
 * OCR fallback for PDFs without extractable text (scanned documents).
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Parser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts PDF pages to images and runs Tesseract OCR when text extraction returns little content.
 */
class PdfOcrFallback {

	/**
	 * Extract text via OCR (Tesseract). Requires Imagick or pdftoppm + Tesseract.
	 *
	 * @param string $file_path Path to PDF.
	 * @return string Extracted text.
	 */
	public function extract( $file_path ) {
		$images = $this->pdf_to_images( $file_path );
		if ( empty( $images ) ) {
			return '';
		}

		$texts = [];
		foreach ( $images as $img_path ) {
			$texts[] = $this->ocr_image( $img_path );
			if ( is_file( $img_path ) ) {
				@unlink( $img_path );
			}
		}

		return implode( "\n", $texts );
	}

	/**
	 * Convert PDF to image files (one per page).
	 *
	 * @param string $file_path PDF path.
	 * @return string[] List of temp image paths.
	 */
	private function pdf_to_images( $file_path ) {
		if ( extension_loaded( 'imagick' ) ) {
			return $this->pdf_to_images_imagick( $file_path );
		}
		return $this->pdf_to_images_pdftoppm( $file_path );
	}

	/**
	 * Convert PDF to images using Imagick.
	 *
	 * @param string $file_path PDF path.
	 * @return string[]
	 */
	private function pdf_to_images_imagick( $file_path ) {
		try {
			$imagick = new \Imagick( $file_path . '[0]' );
			$imagick->setResolution( 150, 150 );
			$imagick->setImageFormat( 'png' );
			$tmp = wp_tempnam( 'sp-pdf-' );
			$imagick->writeImage( $tmp );
			$imagick->clear();
			return [ $tmp ];
		} catch ( \Exception $e ) {
			return [];
		}
	}

	/**
	 * Convert PDF to images using pdftoppm.
	 *
	 * @param string $file_path PDF path.
	 * @return string[]
	 */
	private function pdf_to_images_pdftoppm( $file_path ) {
		$dir = wp_upload_dir();
		$out_dir = $dir['basedir'] . '/statement-processor-ocr/';
		if ( ! wp_mkdir_p( $out_dir ) ) {
			return [];
		}
		$prefix = $out_dir . 'page';
		$cmd = 'pdftoppm -png -r 150 ' . escapeshellarg( $file_path ) . ' ' . escapeshellarg( $prefix ) . ' 2>/dev/null';
		@exec( $cmd );
		$files = glob( $prefix . '-*.png' );
		return is_array( $files ) ? $files : [];
	}

	/**
	 * Run Tesseract OCR on an image file.
	 *
	 * @param string $image_path Path to image.
	 * @return string
	 */
	private function ocr_image( $image_path ) {
		if ( ! is_readable( $image_path ) ) {
			return '';
		}
		if ( class_exists( '\thiagoalessio\TesseractOCR\TesseractOCR' ) ) {
			try {
				return ( new \thiagoalessio\TesseractOCR\TesseractOCR( $image_path ) )->run();
			} catch ( \Exception $e ) {
				return '';
			}
		}
		$out = trim( (string) @shell_exec( 'tesseract ' . escapeshellarg( $image_path ) . ' stdout 2>/dev/null' ) );
		return $out;
	}
}
