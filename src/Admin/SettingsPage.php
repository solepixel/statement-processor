<?php
/**
 * Settings page for Statement Processor (Settings > Statement Processor).
 *
 * @package StatementProcessor
 */

namespace StatementProcessor\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Settings > Statement Processor page and AI/LLM options.
 */
class SettingsPage {

	const OPTION_GROUP  = 'statement_processor_settings';
	const OPTION_NAME   = 'statement_processor_ai';
	const PAGE_SLUG     = 'statement-processor';
	const SETTINGS_CAP  = 'manage_options';

	/**
	 * Default option values.
	 *
	 * @var array<string, mixed>
	 */
	private static $defaults = [
		'enabled'  => false,
		'provider' => 'openai',
		'api_key'  => '',
		'model'    => 'gpt-4o-mini',
	];

	/**
	 * Constructor; registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ], 20 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'add_sections_and_fields' ] );
	}

	/**
	 * Add the submenu page under Settings.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Statement Processor', 'statement-processor' ),
			__( 'Statement Processor', 'statement-processor' ),
			self::SETTINGS_CAP,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register the option and sanitize callback.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_options' ],
			]
		);
	}

	/**
	 * Add settings sections and fields.
	 */
	public function add_sections_and_fields() {
		$section = 'statement_processor_ai_section';
		add_settings_section(
			$section,
			__( 'AI / LLM for PDF parsing', 'statement-processor' ),
			function () {
				echo '<p>' . esc_html__( 'Use an AI model to extract transactions from Discover (and other) bank statement PDFs. When enabled, the plugin will send extracted PDF text to the configured provider to get a structured list of transactions.', 'statement-processor' ) . '</p>';
			},
			self::PAGE_SLUG,
			[
				'before_section' => '<div class="statement-processor-settings-wrap">',
				'after_section'  => '</div>',
			]
		);

		add_settings_field(
			'sp_ai_enabled',
			__( 'Enable AI parsing', 'statement-processor' ),
			[ $this, 'render_enabled_field' ],
			self::PAGE_SLUG,
			$section,
			[ 'label_for' => 'sp_ai_enabled' ]
		);

		add_settings_field(
			'sp_ai_provider',
			__( 'Provider', 'statement-processor' ),
			[ $this, 'render_provider_field' ],
			self::PAGE_SLUG,
			$section,
			[ 'label_for' => 'sp_ai_provider' ]
		);

		add_settings_field(
			'sp_ai_api_key',
			__( 'API key', 'statement-processor' ),
			[ $this, 'render_api_key_field' ],
			self::PAGE_SLUG,
			$section,
			[ 'label_for' => 'sp_ai_api_key' ]
		);

		add_settings_field(
			'sp_ai_model',
			__( 'Model', 'statement-processor' ),
			[ $this, 'render_model_field' ],
			self::PAGE_SLUG,
			$section,
			[ 'label_for' => 'sp_ai_model' ]
		);
	}

	/**
	 * Sanitize and validate saved options.
	 *
	 * @param array<string, mixed>|mixed $input Raw POST data.
	 * @return array<string, mixed>
	 */
	public function sanitize_options( $input ) {
		if ( ! is_array( $input ) ) {
			return $this->get_options();
		}

		$out = $this->get_options();

		$out['enabled'] = ! empty( $input['enabled'] );

		$allowed_providers = [ 'openai' ];
		if ( isset( $input['provider'] ) && in_array( $input['provider'], $allowed_providers, true ) ) {
			$out['provider'] = $input['provider'];
		}

		if ( isset( $input['api_key'] ) && is_string( $input['api_key'] ) ) {
			$out['api_key'] = trim( $input['api_key'] );
		}

		if ( isset( $input['model'] ) && is_string( $input['model'] ) ) {
			$out['model'] = sanitize_text_field( trim( $input['model'] ) );
			if ( $out['model'] === '' ) {
				$out['model'] = self::$defaults['model'];
			}
		}

		return $out;
	}

	/**
	 * Get current options (merged with defaults).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_options() {
		$saved = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return array_merge( self::$defaults, $saved );
	}

	/**
	 * Whether AI parsing is enabled and configured (has API key).
	 *
	 * @return bool
	 */
	public static function is_ai_configured() {
		$opts = self::get_options();
		return ! empty( $opts['enabled'] ) && $opts['api_key'] !== '';
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'statement-processor' ) );
		}

		$options = self::get_options();
		include STATEMENT_PROCESSOR_PLUGIN_DIR . 'src/Admin/views/settings.php';
	}

	/**
	 * Render Enable AI checkbox.
	 */
	public function render_enabled_field() {
		$opts = self::get_options();
		$val  = ! empty( $opts['enabled'] );
		?>
		<label for="sp_ai_enabled">
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled]" id="sp_ai_enabled" value="1" <?php checked( $val ); ?> />
			<?php esc_html_e( 'Use AI to parse Discover (and compatible) statement PDFs', 'statement-processor' ); ?>
		</label>
		<?php
	}

	/**
	 * Render Provider dropdown.
	 */
	public function render_provider_field() {
		$opts   = self::get_options();
		$val    = isset( $opts['provider'] ) ? $opts['provider'] : 'openai';
		$id     = 'sp_ai_provider';
		$name   = self::OPTION_NAME . '[provider]';
		?>
		<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>">
			<option value="openai" <?php selected( $val, 'openai' ); ?>><?php esc_html_e( 'OpenAI (GPT)', 'statement-processor' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Additional providers may be added in future versions.', 'statement-processor' ); ?></p>
		<?php
	}

	/**
	 * Render API key field.
	 */
	public function render_api_key_field() {
		$opts = self::get_options();
		$val  = isset( $opts['api_key'] ) ? $opts['api_key'] : '';
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_key]" id="sp_ai_api_key" value="<?php echo esc_attr( $val ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Required for OpenAI. Stored in the database; keep your site secure.', 'statement-processor' ); ?></p>
		<?php
	}

	/**
	 * Render Model field.
	 */
	public function render_model_field() {
		$opts = self::get_options();
		$val  = isset( $opts['model'] ) ? $opts['model'] : self::$defaults['model'];
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[model]" id="sp_ai_model" value="<?php echo esc_attr( $val ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'e.g. gpt-4o-mini, gpt-4o. Leave default for cost-effective parsing.', 'statement-processor' ); ?></p>
		<?php
	}
}
