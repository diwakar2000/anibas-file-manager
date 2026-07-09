<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

class RemoteStorageTester
{

	public static function test_ftp($config)
	{
		if (empty($config['host']) || empty($config['username'])) {
			return ['success' => false, 'message' => 'Missing required fields'];
		}

		$port = $config['port'] ?? 21;
		$use_ssl = $config['use_ssl'] ?? false;
		$is_passive = array_key_exists('is_passive', $config) ? (bool) $config['is_passive'] : true;

		try {
			$resolved = anibas_fm_normalize_remote_path('/', (string) ($config['base_path'] ?? '/'));
			if ($resolved === false) {
				return ['success' => false, 'message' => 'Invalid base path'];
			}

			$ch = curl_init();
			if (! $ch) {
				return ['success' => false, 'message' => 'FTP extension is not available'];
			}
			$protocol = $use_ssl ? 'ftps' : 'ftp';
			$list_path = rtrim($resolved, '/') . '/';
			$encoded_path = implode('/', array_map('rawurlencode', explode('/', $list_path)));
			$url = "{$protocol}://{$config['host']}:{$port}{$encoded_path}";

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_USERPWD, "{$config['username']}:" . (string) ($config['password'] ?? ''));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
			curl_setopt($ch, CURLOPT_FTPLISTONLY, true);

			if ($is_passive) {
				curl_setopt($ch, CURLOPT_FTP_USE_EPSV, true);
				curl_setopt($ch, CURLOPT_FTP_USE_EPRT, false);
			} else {
				curl_setopt($ch, CURLOPT_FTP_USE_EPSV, false);
				curl_setopt($ch, CURLOPT_FTP_USE_EPRT, true);
				curl_setopt($ch, CURLOPT_FTPPORT, '-');
			}

			if ($use_ssl) {
				curl_setopt($ch, CURLOPT_USE_SSL, CURLFTPSSL_ALL);
				curl_setopt($ch, CURLOPT_FTPSSLAUTH, CURLFTPAUTH_TLS);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			}

			$result = curl_exec($ch);
			$error = curl_error($ch);
			curl_close($ch);

			if ($error || $result === false) {
				return ['success' => false, 'message' => $error ?: 'FTP connection failed'];
			}

			return ['success' => true, 'message' => 'Connected successfully'];
		} catch (\Exception $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	public static function test_sftp($config)
	{
		if (empty($config['host']) || empty($config['username'])) {
			return ['success' => false, 'message' => 'Missing required fields'];
		}

		$port = $config['port'] ?? 22;

		try {
			$adapter = StorageManager::create_sftp_adapter(array_merge($config, array('port' => $port)));
			self::assert_adapter_root_listable($adapter);

			return ['success' => true, 'message' => 'Connected successfully'];
		} catch (\Exception $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	public static function test_s3($config)
	{
		if (empty($config['access_key']) || empty($config['secret_key']) || empty($config['bucket'])) {
			return ['success' => false, 'message' => 'Missing required fields'];
		}

		try {
			$adapter = StorageManager::create_s3_adapter($config);
			self::assert_adapter_root_listable($adapter);

			return ['success' => true, 'message' => 'Connected successfully'];
		} catch (\Exception $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	public static function test_s3_compatible($config)
	{
		if (empty($config['endpoint']) || empty($config['access_key']) || empty($config['secret_key']) || empty($config['bucket'])) {
			return ['success' => false, 'message' => 'Missing required fields'];
		}

		try {
			$adapter = StorageManager::create_s3_compatible_adapter($config);
			self::assert_adapter_root_listable($adapter);

			return ['success' => true, 'message' => 'Connected successfully'];
		} catch (\Exception $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	public static function test_gdrive($config)
	{
		if (empty($config['refresh_token']) && empty($config['access_token'])) {
			return ['success' => false, 'message' => 'No saved OAuth token'];
		}

		try {
			$adapter = StorageManager::create_gdrive_adapter($config);
			self::assert_adapter_root_listable($adapter);
			return ['success' => true, 'message' => 'Connected successfully'];
		} catch (\Exception $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	public static function test_onedrive($config)
	{
		if (empty($config['refresh_token']) && empty($config['access_token'])) {
			return ['success' => false, 'message' => 'No saved OAuth token'];
		}

		try {
			$adapter = StorageManager::create_onedrive_adapter($config);
			if (! $adapter->is_dir('/')) {
				return ['success' => false, 'message' => 'Root path is not accessible'];
			}
			self::assert_adapter_root_listable($adapter);
			return ['success' => true, 'message' => 'Connected successfully'];
		} catch (\Exception $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	public static function test_dropbox($config)
	{
		if (empty($config['refresh_token']) && empty($config['access_token'])) {
			return ['success' => false, 'message' => 'No saved OAuth token'];
		}

		try {
			$client = new AnibasDropboxClient($config);
			$root = self::normalize_dropbox_test_path((string) ($config['root_path'] ?? '/'));
			$client->rpc('/files/list_folder', [
				'path'             => $root,
				'recursive'        => false,
				'include_deleted'  => false,
				'include_mounted_folders' => true,
				'limit'            => 1,
			]);
			return ['success' => true, 'message' => 'Connected successfully'];
		} catch (\Exception $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	private static function normalize_dropbox_test_path(string $path): string
	{
		$path = str_replace(["\0", '\\'], ['', '/'], $path);
		$segments = [];
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				if (empty($segments)) {
					return "\0";
				}
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}
		return $segments ? '/' . implode('/', $segments) : '';
	}

	private static function assert_adapter_root_listable($adapter): void
	{
		$page = $adapter->iterateDirectory('/', null, 1);
		if (! is_array($page) || ! array_key_exists('entries', $page)) {
			throw new \RuntimeException('Root path is not accessible');
		}
	}
}
