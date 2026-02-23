<?php
/**
 * Statement Processor settings page template (Settings > Statement Processor).
 *
 * @package StatementProcessor
 * @var array<string, mixed> $options
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$option_group = \StatementProcessor\Admin\SettingsPage::OPTION_GROUP;
$page_slug   = \StatementProcessor\Admin\SettingsPage::PAGE_SLUG;
?>
<div class="wrap statement-processor-settings">
	<h1><?php esc_html_e( 'Statement Processor Settings', 'statement-processor' ); ?></h1>

	<form method="post" action="options.php" id="statement-processor-settings-form">
		<?php
		settings_fields( $option_group );
		do_settings_sections( $page_slug );
		submit_button( __( 'Save settings', 'statement-processor' ) );
		?>
	</form>
</div>
