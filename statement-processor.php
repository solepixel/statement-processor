<?php
/**
 * Plugin Name: Statement Processor
 * Plugin URI: https://b7s.co
 * Description: Upload financial transaction files (PDF/CSV) from bank and credit card statements, organize by month/year and source, and export to CSV.
 * Version: 1.0.0
 * Author: Briantics, Inc.
 * Author URI: https://b7s.co
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: statement-processor
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package StatementProcessor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STATEMENT_PROCESSOR_VERSION', '1.0.0' );
define( 'STATEMENT_PROCESSOR_PLUGIN_FILE', __FILE__ );
define( 'STATEMENT_PROCESSOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STATEMENT_PROCESSOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$statement_processor_autoload = STATEMENT_PROCESSOR_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $statement_processor_autoload ) ) {
	require_once $statement_processor_autoload;
}

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'StatementProcessor\Plugin' ) ) {
			return;
		}
		StatementProcessor\Plugin::instance()->init();
	},
	0
);
