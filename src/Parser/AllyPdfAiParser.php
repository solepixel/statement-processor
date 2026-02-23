<?php
/**
 * Ally Bank (combined customer) statement PDF parser using an LLM to extract transactions.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Parser;

use StatementProcessor\Admin\SettingsPage;
use StatementProcessor\AI\LlmClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts text from an Ally Bank statement PDF and uses an LLM to return
 * a structured list of transactions. Falls back to text-based parsing if AI is off or fails.
 */
class AllyPdfAiParser {

	/**
	 * Minimum text length to attempt AI parsing.
	 */
	const MIN_TEXT_LENGTH = 100;

	/**
	 * Text extractor.
	 *
	 * @var PdfTextExtractor
	 */
	private $text_extractor;

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
		$this->text_extractor = new PdfTextExtractor();
		$this->llm_client     = new LlmClient();
	}

	/**
	 * Check if the extracted text looks like an Ally Bank combined customer statement.
	 *
	 * @param string $text Extracted PDF text.
	 * @return bool
	 */
	public static function is_ally_statement( $text ) {
		return ( preg_match( '/Ally\s+Bank/i', $text ) !== 0 || preg_match( '/COMBINED\s+CUST OMER ST AT EMENT|COMBINED\s+CUSTOMER\s+STATEMENT/i', $text ) !== 0 )
			&& preg_match( '/\bActivity\b/i', $text ) !== 0
			&& preg_match( '/Date\s+Description\s+Credits\s+Debits\s+Balance/i', $text ) !== 0;
	}

	/**
	 * Parse an Ally statement PDF via LLM and return normalized transactions.
	 *
	 * @param string $file_path Path to PDF file.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	public function parse( $file_path ) {
		if ( ! SettingsPage::is_ai_configured() || ! is_readable( $file_path ) ) {
			return [];
		}

		$text = $this->text_extractor->extract( $file_path );
		$text = trim( $text );
		if ( strlen( $text ) < self::MIN_TEXT_LENGTH || ! self::is_ally_statement( $text ) ) {
			return [];
		}

		$system = $this->get_system_prompt();
		$user   = $this->get_user_prompt( $text );

		$response = $this->llm_client->generate_text( $system, $user, [ 'max_tokens' => 16000 ] );
		if ( $response === null || $response === '' ) {
			return [];
		}

		return $this->parse_llm_response( $response );
	}

	/**
	 * System prompt for Ally Activity table extraction.
	 *
	 * @return string
	 */
	private function get_system_prompt() {
		return <<<PROMPT
You are a precise assistant that extracts bank transactions from Ally Bank statement text.

You will receive raw text from an Ally Bank combined customer statement. It has an "Activity" section with columns: Date, Description, Credits, Debits, Balance.

RULES:

1) OUTPUT ONLY REAL TRANSACTIONS. Do NOT include any of the following as transactions:
   - "Beginning Balance" or "Ending Balance"
   - Page numbers (e.g. "Page 1", "-- 1 of 15 --")
   - Address lines (P.O. Box, street, city, state)
   - Section headers (Summary, Activity, Customer Care, etc.)
   - Account summaries (Beginning Balance as of..., Ending Balance as of...)
   - Single numbers that are page or document codes (e.g. "15")
   - Legal or regulatory text, footers, "CHECKS OUTSTANDING", etc.

2) AMOUNTS: Use the Credits and Debits columns only. Ignore the Balance column.
   - If the transaction is a deposit/credit: use the Credits column value as a POSITIVE number.
   - If the transaction is a withdrawal/debit: use the Debits column value as a NEGATIVE number (e.g. -178.19).
   - Never use the Balance column for the transaction amount.

3) DESCRIPTION: Use only the transaction description text (e.g. "Check Card Purchase", "Direct Deposit", merchant name and location). Do not include any dollar amounts, dates, or column headers in the description. Combine multi-line descriptions into one line with spaces.

4) DATE: Use the transaction date from the Date column. Output as YYYY-MM-DD.

5) Output ONLY a valid JSON array of objects. Each object: "date", "description", "amount". No markdown, no explanation.
   - "date": YYYY-MM-DD
   - "description": string (transaction description only)
   - "amount": string with two decimal places, signed (positive for credits, negative for debits)

Example output:
[{"date":"2024-01-02","description":"Check Card Purchase AMAZON.COM SEATTLE, WA, US","amount":"-178.19"},{"date":"2024-01-10","description":"Direct Deposit Wave PYRL PAYROLL PAYROLL","amount":"1271.81"}]
PROMPT;
	}

	/**
	 * Build user message with the statement text.
	 *
	 * @param string $text Extracted statement text.
	 * @return string
	 */
	private function get_user_prompt( $text ) {
		$max_len = 120000;
		if ( strlen( $text ) > $max_len ) {
			$text = substr( $text, 0, $max_len ) . "\n[... text truncated ...]";
		}
		return "Extract only real bank transactions from the Activity section(s). Skip Beginning Balance, Ending Balance, page numbers, addresses, and headers. Return a JSON array only.\n\n" . $text;
	}

	/**
	 * Parse LLM response (JSON array) into normalized transaction rows.
	 *
	 * @param string $response Raw response from LLM.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	private function parse_llm_response( $response ) {
		$response = preg_replace( '/^[\s\S]*?(\[[\s\S]*\])[\s\S]*$/s', '$1', trim( $response ) );
		$response = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $response );

		$decoded = json_decode( $response, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$transactions = [];
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$date = isset( $row['date'] ) ? $this->normalize_date( (string) $row['date'] ) : '';
			$desc = isset( $row['description'] ) ? trim( (string) $row['description'] ) : '';
			$amt  = isset( $row['amount'] ) ? $this->normalize_amount( (string) $row['amount'] ) : '';

			if ( $date === '' || $amt === '' ) {
				continue;
			}
			if ( $desc === '' ) {
				$desc = __( 'Transaction', 'statement-processor' );
			}

			$transactions[] = [
				'date'        => $date,
				'time'        => '00:00:00',
				'description' => $desc,
				'amount'      => $amt,
			];
		}

		return $transactions;
	}

	/**
	 * Normalize date string to Y-m-d.
	 *
	 * @param string $date Date string.
	 * @return string
	 */
	private function normalize_date( $date ) {
		$ts = strtotime( $date );
		if ( $ts === false ) {
			return '';
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Normalize amount string to two decimals, keep sign.
	 *
	 * @param string $amount Raw amount.
	 * @return string
	 */
	private function normalize_amount( $amount ) {
		$amount = str_replace( ',', '', $amount );
		$amount = preg_replace( '/\s+/', '', $amount );
		$amount = preg_replace( '/[^\d.\-]/', '', $amount );
		if ( $amount === '' || $amount === '-' ) {
			return '';
		}
		return number_format( (float) $amount, 2, '.', '' );
	}
}
