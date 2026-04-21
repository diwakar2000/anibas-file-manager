<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fired during plugin activation
 *
 * @link       https://www.diwakardhl.com
 * @since      1.0.0
 *
 * @package    Anibas_File_Manager
 * @subpackage Anibas_File_Manager/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Anibas_File_Manager
 * @subpackage Anibas_File_Manager/includes
 * @author     Diwakar Dahal <thee.walker.dhl@gmail.com>
 */
class Anibas_File_Manager_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		anibas_fm_create_log_file_path();

		// Schedule daily trash cleanup
		if ( ! wp_next_scheduled( ANIBAS_FM_TRASH_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', ANIBAS_FM_TRASH_CRON_HOOK );
		}

		// Schedule daily temp cleanup
		if ( ! wp_next_scheduled( ANIBAS_FM_TEMP_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', ANIBAS_FM_TEMP_CRON_HOOK );
		}

		// Schedule daily backup cleanup
		if ( ! wp_next_scheduled( ANIBAS_FM_BACKUP_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', ANIBAS_FM_BACKUP_CRON_HOOK );
		}
	}

}
