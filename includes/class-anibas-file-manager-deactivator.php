<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fired during plugin deactivation
 *
 * @link       https://www.diwakardhl.com
 * @since      1.0.0
 *
 * @package    Anibas_File_Manager
 * @subpackage Anibas_File_Manager/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Anibas_File_Manager
 * @subpackage Anibas_File_Manager/includes
 * @author     Diwakar Dahal <thee.walker.dhl@gmail.com>
 */
class Anibas_File_Manager_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( ANIBAS_FM_TRASH_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, ANIBAS_FM_TRASH_CRON_HOOK );
		}

		$temp_timestamp = wp_next_scheduled( ANIBAS_FM_TEMP_CRON_HOOK );
		if ( $temp_timestamp ) {
			wp_unschedule_event( $temp_timestamp, ANIBAS_FM_TEMP_CRON_HOOK );
		}

		$backup_timestamp = wp_next_scheduled( ANIBAS_FM_BACKUP_CRON_HOOK );
		if ( $backup_timestamp ) {
			wp_unschedule_event( $backup_timestamp, ANIBAS_FM_BACKUP_CRON_HOOK );
		}

		// Clear any stale backup lock
		anibas_fm_clear_backup_lock();
	}
}
