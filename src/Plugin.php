<?php
/**
 * Main plugin bootstrap.
 *
 * @package StatementProcessor
 */

namespace StatementProcessor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin entry point; wires hooks and instantiates components.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Post type registrar.
	 *
	 * @var PostType\TransactionPostType|null
	 */
	private $transaction_post_type;

	/**
	 * Taxonomy registrar (sources).
	 *
	 * @var Taxonomy\TransactionSource|null
	 */
	private $transaction_source;

	/**
	 * Taxonomy registrar (categories).
	 *
	 * @var Taxonomy\TransactionCategory|null
	 */
	private $transaction_category;

	/**
	 * Admin page.
	 *
	 * @var Admin\AdminPage|null
	 */
	private $admin_page;

	/**
	 * Upload handler.
	 *
	 * @var Admin\UploadHandler|null
	 */
	private $upload_handler;

	/**
	 * Export handler.
	 *
	 * @var Admin\ExportHandler|null
	 */
	private $export_handler;

	/**
	 * Transaction list/edit UI (column + meta box).
	 *
	 * @var Admin\TransactionAdminUI|null
	 */
	private $transaction_admin_ui;

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the plugin (register hooks and components).
	 */
	public function init() {
		$this->transaction_post_type  = new PostType\TransactionPostType();
		$this->transaction_source     = new Taxonomy\TransactionSource();
		$this->transaction_category   = new Taxonomy\TransactionCategory();

		add_action( 'init', [ $this, 'register_cpt_and_taxonomy' ], 0 );

		// UploadHandler registers REST import-batch route on all requests; admin hooks only when is_admin().
		$this->upload_handler = new Admin\UploadHandler();

		if ( is_admin() ) {
			$this->admin_page           = new Admin\AdminPage();
			new Admin\ExportPage();
			$this->export_handler       = new Admin\ExportHandler();
			$this->transaction_admin_ui = new Admin\TransactionAdminUI();
			new Admin\SettingsPage();
		}
	}

	/**
	 * Register custom post type and taxonomy on init.
	 */
	public function register_cpt_and_taxonomy() {
		$this->transaction_post_type->register();
		$this->transaction_source->register();
		$this->transaction_category->register();
	}

	/**
	 * Get the transaction post type key.
	 *
	 * @return string
	 */
	public static function post_type() {
		return 'sp-transaction';
	}

	/**
	 * Get the source taxonomy key.
	 *
	 * @return string
	 */
	public static function taxonomy_source() {
		return 'sp-source';
	}

	/**
	 * Get the category taxonomy key.
	 *
	 * @return string
	 */
	public static function taxonomy_category() {
		return 'sp-category';
	}
}
