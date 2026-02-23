<?php
/**
 * LLM client for Statement Processor (OpenAI via HTTP).
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\AI;

use StatementProcessor\Admin\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends prompts to a configured LLM provider and returns generated text.
 * Currently supports OpenAI via wp_remote_post.
 */
class LlmClient {

	const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';
	const DEFAULT_MAX_TOKENS = 16000;

	/**
	 * Call the configured provider with a system and user message; return the assistant text.
	 *
	 * @param string $system_message System / instruction message.
	 * @param string $user_message   User content (e.g. extracted PDF text).
	 * @param array  $options        Optional. Keys: max_tokens (int).
	 * @return string|null Assistant reply text, or null on failure.
	 */
	public function generate_text( $system_message, $user_message, $options = [] ) {
		$opts = SettingsPage::get_options();
		if ( empty( $opts['enabled'] ) || empty( $opts['api_key'] ) ) {
			return null;
		}

		$provider = isset( $opts['provider'] ) ? $opts['provider'] : 'openai';
		if ( $provider === 'openai' ) {
			return $this->call_openai( $opts, $system_message, $user_message, $options );
		}

		return null;
	}

	/**
	 * Call OpenAI chat completions API.
	 *
	 * @param array  $opts           Settings (api_key, model).
	 * @param string $system_message System message.
	 * @param string $user_message   User message.
	 * @param array  $options        Optional. max_tokens.
	 * @return string|null
	 */
	private function call_openai( $opts, $system_message, $user_message, $options = [] ) {
		$api_key    = $opts['api_key'];
		$model      = isset( $opts['model'] ) && $opts['model'] !== '' ? $opts['model'] : 'gpt-4o-mini';
		$max_tokens = isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : self::DEFAULT_MAX_TOKENS;

		$body = array(
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'temperature' => 0.1,
			'messages'    => array(
				array( 'role' => 'system', 'content' => $system_message ),
				array( 'role' => 'user', 'content' => $user_message ),
			),
		);

		$response = wp_remote_post(
			self::OPENAI_URL,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 120,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['choices'][0]['message']['content'] ) ) {
			return null;
		}

		return trim( (string) $data['choices'][0]['message']['content'] );
	}
}
