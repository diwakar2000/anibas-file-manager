<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://www.diwakardhl.com
 * @since      1.0.0
 *
 * @package    Anibas_File_Manager
 * @subpackage Anibas_File_Manager/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Anibas_File_Manager
 * @subpackage Anibas_File_Manager/includes
 * @author     Diwakar Dahal <thee.walker.dhl@gmail.com>
 */
class Anibas_File_Manager {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Anibas_File_Manager_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'ANIBAS_FILE_MANAGER_VERSION' ) ) {
			$this->version = ANIBAS_FILE_MANAGER_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'anibas-file-manager';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Anibas_File_Manager_Loader. Orchestrates the hooks of the plugin.
	 * - Anibas_File_Manager_i18n. Defines internationalization functionality.
	 * - Anibas_File_Manager_Main. Defines all hooks for the admin area.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-anibas-file-manager-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-anibas-file-manager-i18n.php';

		/**
		 * Core classes.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/class-anibas-file-manager-main.php';

		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/class-ajax-handler.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/ajax/class-auth-ajax-handler.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/ajax/class-settings-ajax-handler.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/ajax/class-file-crud-ajax-handler.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/ajax/class-trash-ajax-handler.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/ajax/class-transfer-ajax-handler.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/ajax/class-upload-ajax-handler.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/ajax/class-archive-ajax-handler.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/ajax/class-backup-ajax-handler.php';

		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/class-editor-page.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/class-editor-ajax.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/class-storage-manager.php';

		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/archiver/class-archive-create-engine.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/archiver/class-archive-restore-engine.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/archiver/class-zip-create-engine.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/archiver/class-zip-restore-engine.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/archiver/class-tar-create-engine.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/archiver/class-tar-restore-engine.php';

		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/core/class-backup-engine.php';

		/**
		 * Handlers.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/handlers/class-background-processor.php';

		/**
		 * Utilities.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/utilities/class-activity-logger.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/utilities/class-remote-storage-tester.php';

		/**
		 * Filesystem adapters.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/adapters/interface-filesystem-adapter.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/adapters/class-local-filesystem-adapter.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/adapters/class-ftp-filesystem-adapter.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/adapters/class-sftp-filesystem-adapter.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/adapters/class-s3-client.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/adapters/class-s3-filesystem-adapter.php';

		/**
		 * Operations.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/operations/interface-operation-phases.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/operations/class-phase-executor.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/operations/phases/class-assembly-phase.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/operations/phases/class-finalize-assembly-phase.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/operations/phases/class-initialize-phase.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/operations/phases/class-list-phase.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/operations/phases/class-transfer-phase.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/operations/phases/class-cross-storage-transfer-phase.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/operations/phases/class-wrapup-phase.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'engine/operations/phases/class-delete-phase.php';

		/**
		 * Contains constants.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/constants.php';

		/**
		 * Contains functions.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/functions.php';

		$this->loader = new Anibas_File_Manager_Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Anibas_File_Manager_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {
		$plugin_i18n = new Anibas_File_Manager_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Anibas_File_Manager_Main( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Anibas_File_Manager_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
