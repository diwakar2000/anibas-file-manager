<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://www.diwakardhl.com
 * @since      1.0.0
 *
 * @package    Anibas_File_Manager
 * @subpackage Anibas_File_Manager/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Anibas_File_Manager
 * @subpackage Anibas_File_Manager/includes
 * @author     Diwakar Dahal <thee.walker.dhl@gmail.com>
 */
class Anibas_File_Manager_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'anibas-file-manager',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
