<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * @link       https://www.diwakardhl.com
 * @since      1.0.0
 *
 * @package    Anibas_File_Manager
 */

// If uninstall not called from WordPress, then exit.
if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Verify this is our plugin being uninstalled
if (WP_UNINSTALL_PLUGIN !== 'anibas-file-manager/anibas-file-manager.php') {
	exit;
}

// Check if multisite
if (is_multisite()) {
	// Delete options for all sites in network
	global $wpdb;
	$anibas_fm_blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");

	foreach ($anibas_fm_blog_ids as $blog_id) {
		switch_to_blog($blog_id);
		anibas_fm_delete_plugin_data();
		restore_current_blog();
	}
} else {
	// Single site cleanup
	anibas_fm_delete_plugin_data();
}

/**
 * Delete all plugin data
 */
function anibas_fm_delete_plugin_data()
{
	global $wpdb;

	// Delete plugin options
	delete_option('AnibasFileManagerOptions');
	delete_option('anibas_fm_job_queue_v2');
	delete_option('anibas_s3_transfer_log');
	
	// Delete log directory and option before resetting option
	$log_dir = get_option('anibas_file_manager_log_dir');
	delete_option('anibas_file_manager_log_dir');

	// Delete work queue options. esc_like() escapes the literal underscores in the prefix so
	// they are not interpreted as LIKE single-char wildcards.
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('anibas_fm_work_queue_') . '%'));

	// Delete transients (locks, tokens, etc.)
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_anibas_fm_') . '%'));
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_timeout_anibas_fm_') . '%'));

	// Delete temporary uploads directory
	$upload_dir = wp_upload_dir();
	$temp_dir = $upload_dir['basedir'] . '/anibas_fm_temp';
	$trash_dir = $upload_dir['basedir'] . '/.trash';
	
	if (! function_exists('WP_Filesystem')) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	
	if ($wp_filesystem) {
		if ($wp_filesystem->is_dir($temp_dir)) {
			$wp_filesystem->delete($temp_dir, true);
		}
		
		if ($wp_filesystem->is_dir($trash_dir)) {
			$wp_filesystem->delete($trash_dir, true);
		}
		
		if (! empty($log_dir) && $wp_filesystem->is_dir($log_dir)) {
			$wp_filesystem->delete($log_dir, true);
		}
	}
}
