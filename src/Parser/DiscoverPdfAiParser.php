<?php
/**
 * Discover statement PDF parser using an LLM to extract transactions.
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
 * Extracts text from a Discover (or similar) statement PDF and uses an LLM to return
 * a structured list of transactions (date, description, amount).
 */
class DiscoverPdfAiParser {

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
	 * Check if the extracted text looks like a Discover account activity statement.
	 *
	 * @param string $text Extracted PDF text.
	 * @return bool
	 */
	public static function is_discover_statement( $text ) {
		return preg_match( '/ACCOUNT\s+ACTIVITY/i', $text ) !== 0
			&& ( preg_match( '/Eff\.\s*Date|Description\s+Amount/i', $text ) !== 0 || preg_match( '/ATM\s+and\s+Debit/i', $text ) !== 0 );
	}

	/**
	 * Parse a Discover (or compatible) statement PDF via LLM and return normalized transactions.
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
		if ( strlen( $text ) < self::MIN_TEXT_LENGTH || ! self::is_discover_statement( $text ) ) {
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
	 * System prompt instructing the model how to output transactions.
	 *
	 * @return string
	 */
	private function get_system_prompt() {
		return <<<PROMPT
You are a precise assistant that extracts bank transactions from statement text.

You will receive raw text from a Discover (or similar) bank statement PDF. It may include:
- Section headers: "Deposits and Credits", "ATM and Debit Card Withdrawals", "Electronic Withdrawals", "Service Charges"
- Columns: Eff. Date, Syst. Date, Description, Amount
- Transaction lines with dates (e.g. "Oct 31", "Nov 01"), descriptions (e.g. "Debit Purchase 1031 0693 SHELL SERVICE S ALABASTER AL US", "ACH Withdrawal Evernest WEB PMTS"), and amounts (e.g. 65.31, 1841.94)

RULES:

1) ROW ALIGNMENT (CRITICAL): Each transaction's amount must be the amount that appears on the SAME line as that transaction in the statement. Do NOT assign an amount from a different row. Match each description to its amount by their line position only. If a line has "Description A" and "100.00", then that transaction is description A with amount 100.00—never use the amount from the next or previous line.

2) DEBIT vs CREDIT (sign of amount): Use ONLY the leading transaction type (the first phrase in the description) to decide. Ignore words in the merchant name.
   - DEBIT (use NEGATIVE amount): "ACH Withdrawal", "Debit Purchase", "POS w/ Cash", "ATM W/D", "Electronic Withdrawal", "Check", or any withdrawal type—even if the merchant name contains "PAYMENTS", "CREDIT", etc.
   - CREDIT (use POSITIVE amount): "POS Credit", "ACH Deposit", "Check Deposit", "Early Pay", or any deposit/credit type.
   - Example: "ACH Withdrawal VERIZON WIRELESS PAYMENTS" → DEBIT (negative), because the first phrase is "ACH Withdrawal". Do not treat it as a credit because of the word "PAYMENTS" in the merchant name.

3) SECTIONS AND SIGN (if transaction type is unclear, use section):
   - "Deposits and Credits" section: EVERY transaction in this section is a deposit or credit. Use POSITIVE amounts only.
   - "ATM and Debit Card Withdrawals", "Electronic Withdrawals", "Service Charges" sections: Every transaction in these sections is a withdrawal. Use NEGATIVE amounts. Determine which section each transaction is in by its position in the text (between the section header and the next section or "TOTAL" line).
   - When both transaction type and section are present, the transaction type (rule 2) takes priority over the section.

4) DESCRIPTION: Use the FULL description as it appears on the statement. Include the transaction type prefix (e.g. "POS Credit 1030 0693", "ACH Withdrawal", "Debit Purchase 1031 0693") and the full merchant/location text (e.g. "THOMPSON HIGH S ALABASTER AL US", "Evernest WEB PMTS") so descriptions are not cut off. Do not truncate to only the merchant name—preserve the complete description field.

5) Output ONLY a valid JSON array of objects, no markdown or explanation. Each object: "date", "description", "amount".
   - "date": YYYY-MM-DD (infer year from statement period or context if only "Mon DD" is given).
   - "description": string (full description as on statement).
   - "amount": string with two decimal places, signed (positive for Deposits and Credits, negative for withdrawal sections).

Example output:
[{"date":"2024-10-31","description":"POS Credit 1030 0693 THOMPSON HIGH S ALABASTER AL US","amount":"150.00"},{"date":"2024-11-01","description":"ACH Withdrawal Evernest WEB PMTS","amount":"-1841.94"}]
PROMPT;
	}

	/**
	 * Build user message with the statement text.
	 *
	 * @param string $text Extracted statement text.
	 * @return string
	 */
	private function get_user_prompt( $text ) {
		// Truncate if extremely long to stay within context limits (e.g. 100k chars).
		$max_len = 120000;
		if ( strlen( $text ) > $max_len ) {
			$text = substr( $text, 0, $max_len ) . "\n[... text truncated ...]";
		}
		return "Extract all transactions from this statement text and return a JSON array only.\n\n" . $text;
	}

	/**
	 * Parse LLM response (JSON array) into normalized transaction rows.
	 *
	 * @param string $response Raw response from LLM.
	 * @return array<int, array{date: string, time: string, description: string, amount: string}>
	 */
	private function parse_llm_response( $response ) {
		// Strip possible markdown code fence.
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
	 * @param string $date Date string (e.g. YYYY-MM-DD or "Oct 31, 2024").
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
	 * @param string $amount Raw amount (e.g. "-65.31", "100.00").
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
