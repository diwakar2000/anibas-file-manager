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
			$ch = curl_init();
			$protocol = $use_ssl ? 'ftps' : 'ftp';
			$url = "{$protocol}://{$config['host']}:{$port}/";

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_USERPWD, "{$config['username']}:{$config['password']}");
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
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
				$insecure = ! empty($config['insecure_ssl']);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, ! $insecure);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $insecure ? 0 : 2);
			}

			curl_exec($ch);
			$error = curl_error($ch);
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			if ($error) {
				return ['success' => false, 'message' => $error];
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
			$ch = curl_init();
			$url = "sftp://{$config['host']}:{$port}/";

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_setopt($ch, CURLOPT_DIRLISTONLY, true);

			if (! empty($config['private_key']) && file_exists($config['private_key'])) {
				curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $config['private_key']);
			} else {
				curl_setopt($ch, CURLOPT_USERPWD, "{$config['username']}:{$config['password']}");
			}

			curl_exec($ch);
			$error = curl_error($ch);

			if ($error) {
				return ['success' => false, 'message' => $error];
			}

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
			$s3 = new AnibasS3Client(
				$config['access_key'],
				$config['secret_key'],
				$config['region'] ?? 'us-east-1'
			);

			// Test connection by listing objects (minimal impact)
			$s3->listObjectsV2([
				'Bucket' => $config['bucket'],
				'MaxKeys' => 1
			]);

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
			$s3 = new AnibasS3Client(
				$config['access_key'],
				$config['secret_key'],
				$config['region'] ?? 'us-east-1',
				$config['endpoint'],
				true // Always path-style for S3-compatible
			);

			// Test connection by listing objects (minimal impact)
			$s3->listObjectsV2([
				'Bucket' => $config['bucket'],
				'MaxKeys' => 1
			]);

			return ['success' => true, 'message' => 'Connected successfully'];
		} catch (\Exception $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}
}
