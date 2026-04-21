<?php

if (! defined('ABSPATH')) exit;

/**
 * The main-specific functionality of the plugin.
 *
 * @link       https://www.diwakardhl.com
 * @since      1.0.0
 *
 * @package    Anibas_File_Manager
 * @subpackage Anibas_File_Manager/main
 */

use Anibas\ArchiveAjaxHandler;
use Anibas\AuthAjaxHandler;
use Anibas\BackupAjaxHandler;
use Anibas\FileCrudAjaxHandler;
use Anibas\SettingsAjaxHandler;
use Anibas\TransferAjaxHandler;
use Anibas\TrashAjaxHandler;
use Anibas\UploadAjaxHandler;

/**
 * The main-specific functionality of the plugin.
 *
 * @package    Anibas_File_Manager
 * @subpackage Anibas_File_Manager/main
 * @author     Diwakar Dahal <thee.walker.dhl@gmail.com>
 */
class Anibas_File_Manager_Main
{

	private $plugin_name;
	private $version;

	public function __construct($plugin_name, $version)
	{
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		add_action('admin_menu', array($this, 'register_admin_settings_page'));
		add_action('admin_head', array($this, 'admin_menu_icon_styles'));
		add_action('init', array($this, 'init_ajax_handler'));
		add_action('init', array($this, 'init_editor_ajax_handler'));
	}

	public function init_ajax_handler()
	{
		new ArchiveAjaxHandler();
		new AuthAjaxHandler();
		new BackupAjaxHandler();
		new FileCrudAjaxHandler();
		new SettingsAjaxHandler();
		new TransferAjaxHandler();
		new TrashAjaxHandler();
		new UploadAjaxHandler();

	}

	public function init_editor_ajax_handler()
	{
		new \Anibas\EditorAjax();
	}

	public function register_admin_settings_page()
	{
		add_menu_page(
			esc_html__('File Manager', 'anibas-file-manager'),
			esc_html__('File Manager', 'anibas-file-manager'),
			'manage_options',
			'anibas-file-manager',
			array($this, 'display_menu_page'),
			ANIBAS_FILE_MANAGER_PLUGIN_URL . 'afm-favicon.svg',
			9
		);

		add_submenu_page(
			'anibas-file-manager',
			esc_html__('Settings', 'anibas-file-manager'),
			esc_html__('Settings', 'anibas-file-manager'),
			'manage_options',
			'anibas-file-manager-settings',
			array($this, 'display_settings_page')
		);
	}

	public function display_menu_page()
	{
		require_once ANIBAS_FILE_MANAGER_PLUGIN_DIR . 'engine' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'anibas-file-manager-admin-display.php';
	}

	public function display_settings_page()
	{
		require_once ANIBAS_FILE_MANAGER_PLUGIN_DIR . 'engine' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'anibas-file-manager-settings.php';
	}

	public function admin_menu_icon_styles()
	{
?>
		<style>
			#adminmenu .toplevel_page_anibas-file-manager .wp-menu-image img {
				box-sizing: content-box !important;
				width: 20px;
				height: 20px;
				padding: 6px 0 0 0 !important;
				object-fit: contain;
			}
		</style>
<?php
	}

	public function enqueue_styles()
	{
		$page = anibas_fm_fetch_request_variable('get', 'page', '');
		if ('anibas-file-manager' === $page) {
			wp_enqueue_style($this->plugin_name . '-file-manager-css', ANIBAS_FILE_MANAGER_PLUGIN_URL . 'dist/main.css', array(), $this->version, 'all');
			wp_enqueue_style($this->plugin_name . '-file-manager-bootstrap-css', ANIBAS_FILE_MANAGER_PLUGIN_URL . 'bootstrap/bootstrap.min.css', array(), $this->version, 'all');
		} elseif ('anibas-file-manager-settings' === $page) {
			wp_enqueue_style($this->plugin_name . '-settings-css', ANIBAS_FILE_MANAGER_PLUGIN_URL . 'dist/settings.css', array(), $this->version, 'all');
			wp_enqueue_style($this->plugin_name . '-file-manager-bootstrap-css', ANIBAS_FILE_MANAGER_PLUGIN_URL . 'bootstrap/bootstrap.min.css', array(), $this->version, 'all');
		}
	}

	public function enqueue_scripts()
	{
		$page = anibas_fm_fetch_request_variable('get', 'page', '');
		if ('anibas-file-manager' === $page) {
			wp_enqueue_script($this->plugin_name . '-file-manager-js', ANIBAS_FILE_MANAGER_PLUGIN_URL . 'dist/main.js', array('wp-i18n'), $this->version, array('strategy' => 'defer', 'in_footer' => true));
			add_filter('script_loader_tag', function ($tag, $handle) {
				if ($this->plugin_name . '-file-manager-js' === $handle) {
					return str_replace('<script ', '<script type="module" ', $tag);
				}
				return $tag;
			}, 10, 2);

			wp_localize_script(
				$this->plugin_name . '-file-manager-js',
				'AnibasFM',
				array(
					'ajaxURL'        => admin_url('admin-ajax.php'),
					'actions'        => array(
						'getFileList'       => ANIBAS_FM_GET_FILE_LIST,
						'createFolder'      => ANIBAS_FM_CREATE_FOLDER,
						'deleteFile'        => ANIBAS_FM_DELETE_FILE,
						'saveSettings'      => ANIBAS_FM_SAVE_SETTINGS,
						'verifyPassword'    => ANIBAS_FM_VERIFY_PASSWORD,
						'checkAuth'         => ANIBAS_FM_CHECK_AUTH,
						'verifyDeletePassword' => ANIBAS_FM_VERIFY_DELETE_PASSWORD,
						'transferFile'      => ANIBAS_FM_TRANSFER_FILE,
						'jobStatus'         => ANIBAS_FM_JOB_STATUS,
						'cancelJob'         => ANIBAS_FM_CANCEL_JOB,
						'checkConflict'     => ANIBAS_FM_CHECK_CONFLICT,
						'checkRunningTasks' => ANIBAS_FM_CHECK_RUNNING_TASKS,
						'requestDeleteToken' => ANIBAS_FM_REQUEST_DELETE_TOKEN,
						'getRemoteSettings' => ANIBAS_FM_GET_REMOTE_SETTINGS,
						'testRemoteConnection' => ANIBAS_FM_TEST_REMOTE_CONNECTION,
						'archiveCreate'       => ANIBAS_FM_ARCHIVE_CREATE,
						'archiveCheck'        => ANIBAS_FM_ARCHIVE_CHECK,
						'archiveRestore'      => ANIBAS_FM_ARCHIVE_RESTORE,
						'cancelArchiveJob'    => ANIBAS_FM_CANCEL_ARCHIVE_JOB,
						'verifyFmPassword'    => ANIBAS_FM_VERIFY_FM_PASSWORD,
						'checkFmAuth'         => ANIBAS_FM_CHECK_FM_AUTH,
						'renameFile'          => ANIBAS_FM_RENAME_FILE,
						'duplicateFile'       => ANIBAS_FM_DUPLICATE_FILE,
						'downloadFile'        => ANIBAS_FM_DOWNLOAD_FILE,
						'previewFile'         => ANIBAS_FM_PREVIEW_FILE,
						'getFileDetails'      => ANIBAS_FM_GET_FILE_DETAILS,
						'initEditorSession'   => ANIBAS_FM_INIT_EDITOR_SESSION,
						'getFileChunk'        => ANIBAS_FM_GET_FILE_CHUNK,
						'saveFile'            => ANIBAS_FM_SAVE_FILE,
						'emptyFolder'         => ANIBAS_FM_EMPTY_FOLDER,
						'listTrash'           => ANIBAS_FM_LIST_TRASH,
						'restoreTrash'        => ANIBAS_FM_RESTORE_TRASH,
						'emptyTrash'          => ANIBAS_FM_EMPTY_TRASH,
						'backupSingleFile'    => ANIBAS_FM_BACKUP_SINGLE_FILE,
						'backupStart'         => ANIBAS_FM_BACKUP_START,
						'backupPoll'          => ANIBAS_FM_BACKUP_POLL,
						'backupCancel'        => ANIBAS_FM_BACKUP_CANCEL,
						'backupStatus'        => ANIBAS_FM_BACKUP_STATUS,
					),
					'listNonce'      => wp_create_nonce(ANIBAS_FM_NONCE_LIST),
					'createNonce'    => wp_create_nonce(ANIBAS_FM_NONCE_CREATE),
					'deleteNonce'    => wp_create_nonce(ANIBAS_FM_NONCE_DELETE),
					'settingsNonce'  => wp_create_nonce(ANIBAS_FM_NONCE_SETTINGS),
					'fmNonce'        => wp_create_nonce(ANIBAS_FM_NONCE_FM),
					'editorNonce'         => wp_create_nonce(ANIBAS_FM_NONCE_EDITOR),
					'editorExtensions'    => ANIBAS_FM_EDITOR_EXTENSIONS,
					'editorDotfiles'      => ANIBAS_FM_EDITOR_DOTFILES,
					'hasDeletePassword'      => ! empty(anibas_fm_get_option('delete_password_hash', '')),
					'fmPasswordRequired'     => ! empty(anibas_fm_get_option('fm_password_hash', '')),
					'fmRefreshRequired'      => (bool) anibas_fm_get_option('fm_password_refresh_required', true),
					'pluginUrl'              => ANIBAS_FILE_MANAGER_PLUGIN_URL,
				)
			);

			wp_set_script_translations($this->plugin_name . '-file-manager-js', 'anibas-file-manager', ANIBAS_FILE_MANAGER_PLUGIN_DIR . 'languages');

			wp_enqueue_script($this->plugin_name . '-file-manager-bootstrap-js', ANIBAS_FILE_MANAGER_PLUGIN_URL . 'bootstrap/bootstrap.min.js', array(), $this->version, true);
		} elseif ('anibas-file-manager-settings' === $page) {
			wp_enqueue_script($this->plugin_name . '-settings-js', ANIBAS_FILE_MANAGER_PLUGIN_URL . 'dist/settings.js', array('wp-i18n'), $this->version, array('strategy' => 'defer', 'in_footer' => true));
			add_filter('script_loader_tag', function ($tag, $handle) {
				if ($this->plugin_name . '-settings-js' === $handle) {
					return str_replace('<script ', '<script type="module" ', $tag);
				}
				return $tag;
			}, 10, 2);

			wp_set_script_translations($this->plugin_name . '-settings-js', 'anibas-file-manager', ANIBAS_FILE_MANAGER_PLUGIN_DIR . 'languages');

			wp_localize_script(
				$this->plugin_name . '-settings-js',
				'AnibasFMSettings',
				array(
					'ajaxURL'        => admin_url('admin-ajax.php'),
					'actions'        => array(
						'getFileList'       => ANIBAS_FM_GET_FILE_LIST,
						'getRemoteSettings' => ANIBAS_FM_GET_REMOTE_SETTINGS,
						'saveRemoteSettings' => ANIBAS_FM_SAVE_REMOTE_SETTINGS,
						'testRemoteConnection' => ANIBAS_FM_TEST_REMOTE_CONNECTION,
						'backupStart'         => ANIBAS_FM_BACKUP_START,
						'backupPoll'          => ANIBAS_FM_BACKUP_POLL,
						'backupCancel'        => ANIBAS_FM_BACKUP_CANCEL,
						'backupStatus'        => ANIBAS_FM_BACKUP_STATUS,
						'listFileBackups'     => ANIBAS_FM_LIST_FILE_BACKUPS,
						'restoreFileBackup'   => ANIBAS_FM_RESTORE_FILE_BACKUP,
						'listSiteBackups'     => ANIBAS_FM_LIST_SITE_BACKUPS,
					),
					'nonce'          => wp_create_nonce(ANIBAS_FM_NONCE_SETTINGS),
					'listNonce'      => wp_create_nonce(ANIBAS_FM_NONCE_LIST),
					'createNonce'    => wp_create_nonce(ANIBAS_FM_NONCE_CREATE),
					'excludedPaths'  => anibas_fm_exclude_paths(),
					'chunkSize'      => intval(anibas_fm_get_option('chunk_size', ANIBAS_FM_DEFAULT_CHUNK_SIZE)),
					'hasPassword'    => ! empty(anibas_fm_get_option('settings_password_hash', '')),
					'hasDeletePassword'      => ! empty(anibas_fm_get_option('delete_password_hash', '')),
					'hasFmPassword'          => ! empty(anibas_fm_get_option('fm_password_hash', '')),
					'fmPasswordRefreshRequired' => (bool) anibas_fm_get_option('fm_password_refresh_required', true),
					'deleteToTrash'          => (bool) anibas_fm_get_option('delete_to_trash', false),
					'remoteFileBackupsEnabled' => (bool) anibas_fm_get_option('remote_file_backups_enabled', false),
					'isLocalhost'            => anibas_fm_is_development_site(),
					'debugMode'              => (bool) anibas_fm_get_option('debug_mode', false),
				)
			);
		}
	}
}
