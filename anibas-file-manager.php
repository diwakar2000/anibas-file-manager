<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://diwakar2000.com.np/
 * @since             1.0.0
 * @package           Anibas_File_Manager
 *
 * @wordpress-plugin
 * Plugin Name:       Anibas File Manager
 * Plugin URI:        https://diwakar2000.com.np/anibas-file-manager/
 * Description:       Advanced File Manager plugin for WordPress. Create, read, update and delete files, manage folders directly from your admin dashboard.
 * Version:           0.4.0
 * Author:            Diwakar Dahal
 * Author URI:        https://diwakar2000.com.np/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       anibas-file-manager
 * Domain Path:       /languages
 * Requires PHP:      8.0
 * Requires at least: 6.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'ANIBAS_FILE_MANAGER_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-anibas-file-manager-activator.php
 */
function anibas_fm_activate_anibas_file_manager() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-anibas-file-manager-activator.php';
	Anibas_File_Manager_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-anibas-file-manager-deactivator.php
 */
function anibas_fm_deactivate_anibas_file_manager() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-anibas-file-manager-deactivator.php';
	Anibas_File_Manager_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'anibas_fm_activate_anibas_file_manager' );
register_deactivation_hook( __FILE__, 'anibas_fm_deactivate_anibas_file_manager' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-anibas-file-manager.php';

define( 'ANIBAS_FILE_MANAGER_PLUGIN_DIR', trailingslashit( __DIR__ ) );

define( 'ANIBAS_FILE_MANAGER_PLUGIN_URL', str_replace( WP_CONTENT_DIR, WP_CONTENT_URL, ANIBAS_FILE_MANAGER_PLUGIN_DIR ) );

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_anibas_file_manager() {

	$plugin = new Anibas_File_Manager();
	$plugin->run();
}
run_anibas_file_manager();
