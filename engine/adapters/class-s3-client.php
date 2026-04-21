<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Lightweight S3 client with AWS Signature V4
 * Supports AWS S3 and S3-compatible services (MinIO, DigitalOcean Spaces, Wasabi, etc.)
 * No external dependencies — uses PHP cURL and SimpleXML.
 */
class S3Exception extends \RuntimeException
{
	private string $aws_error_code;

	public function __construct(string $message, string $aws_error_code = '')
	{
		parent::__construct($message);
		$this->aws_error_code = $aws_error_code;
	}

	public function getAwsErrorCode(): string
	{
		return $this->aws_error_code;
	}
}

class AnibasS3Client
{
	private string $access_key;
	private string $secret_key;
	private string $region;
	private ?string $endpoint; // custom endpoint (path-style for S3-compatible services)
	private bool $path_style;
	private int $timeout = 300;

	/**
	 * @param string      $access_key  AWS access key ID
	 * @param string      $secret_key  AWS secret access key
	 * @param string      $region      AWS region (e.g. 'us-east-1')
	 * @param string|null $endpoint    Custom endpoint URL for S3-compatible services
	 * @param bool        $path_style  Force path-style URLs (auto-enabled when endpoint is set)
	 */
	public function __construct(
		string $access_key,
		string $secret_key,
		string $region = 'us-east-1',
		?string $endpoint = null,
		bool $path_style = false
	) {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->region     = $region;
		$this->endpoint   = $endpoint ? rtrim($endpoint, '/') : null;
		$this->path_style = $path_style || ($endpoint !== null);
	}

	// ─── URL and signing helpers ────────────────────────────────────────────────

	/**
	 * Build URL components for a request.
	 * Returns [url, host, path, query_string].
	 */
	private function build_url(string $bucket, string $key = '', array $query_params = []): array
	{
		$key = ltrim($key, '/');

		// URL-encode each path segment individually
		$encoded_key = implode(
			'/',
			array_map('rawurlencode', explode('/', $key))
		);

		if ($this->path_style && $this->endpoint) {
			// Path-style with custom endpoint: endpoint/bucket/key
			$parsed = parse_url($this->endpoint);
			$scheme = $parsed['scheme'] ?? 'https';
			$host   = $parsed['host'];
			if (isset($parsed['port'])) {
				$host .= ':' . $parsed['port'];
			}
			$base_path = rtrim($parsed['path'] ?? '', '/');
			$path      = $base_path . '/' . $bucket . ($encoded_key !== '' ? '/' . $encoded_key : '');
		} elseif ($this->path_style) {
			// Path-style on AWS: s3.region.amazonaws.com/bucket/key
			$scheme = 'https';
			$host   = "s3.{$this->region}.amazonaws.com";
			$path   = '/' . $bucket . ($encoded_key !== '' ? '/' . $encoded_key : '');
		} else {
			// Virtual-hosted-style (AWS default): bucket.s3.region.amazonaws.com/key
			$scheme = 'https';
			$host   = "{$bucket}.s3.{$this->region}.amazonaws.com";
			$path   = '/' . $encoded_key;
		}

		// Build canonical query string (sorted)
		$query_string = '';
		if (! empty($query_params)) {
			ksort($query_params);
			$pairs = [];
			foreach ($query_params as $k => $v) {
				$pairs[] = rawurlencode($k) . '=' . rawurlencode((string) $v);
			}
			$query_string = implode('&', $pairs);
		}

		$url = $scheme . '://' . $host . $path;
		if ($query_string !== '') {
			$url .= '?' . $query_string;
		}

		return [
			'url'          => $url,
			'host'         => $host,
			'path'         => $path,
			'query_string' => $query_string,
		];
	}

	/**
	 * Generate AWS Signature V4 Authorization header and signed headers array.
	 */
	private function sign(
		string $method,
		string $path,
		string $query_string,
		string $host,
		array $headers,
		string $payload_hash,
		string $datetime
	): array {
		$date = substr($datetime, 0, 8);

		// Canonical headers — sorted by lowercase name
		$headers_lower = [];
		foreach ($headers as $name => $value) {
			$headers_lower[strtolower($name)] = trim((string) $value);
		}
		ksort($headers_lower);

		$canonical_headers = '';
		foreach ($headers_lower as $name => $value) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}
		$signed_headers = implode(';', array_keys($headers_lower));

		// Canonical request
		$canonical_request = implode("\n", [
			$method,
			$path,
			$query_string,
			$canonical_headers,
			$signed_headers,
			$payload_hash,
		]);

		// Credential scope
		$credential_scope = "{$date}/{$this->region}/s3/aws4_request";

		// String to sign
		$string_to_sign = implode("\n", [
			'AWS4-HMAC-SHA256',
			$datetime,
			$credential_scope,
			hash('sha256', $canonical_request),
		]);

		// Signing key (HMAC chain)
		$k_date    = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
		$k_region  = hash_hmac('sha256', $this->region, $k_date, true);
		$k_service = hash_hmac('sha256', 's3', $k_region, true);
		$k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);

		$signature     = hash_hmac('sha256', $string_to_sign, $k_signing);
		$authorization = "AWS4-HMAC-SHA256 Credential={$this->access_key}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

		// Return all headers that must be sent (merge back with original casing + add auth)
		$result_headers = $headers;
		$result_headers['Authorization'] = $authorization;
		$result_headers['x-amz-date']    = $datetime;

		return $result_headers;
	}

	// ─── Low-level request executor ─────────────────────────────────────────────

	/**
	 * Execute a signed S3 request via cURL.
	 *
	 * @param string      $method
	 * @param string      $bucket
	 * @param string      $key
	 * @param array       $query_params
	 * @param array       $extra_headers   Additional HTTP headers (name => value)
	 * @param string|null $body            Request body string (or null)
	 * @param resource|null $body_stream   Seekable stream for streaming PUT (mutually exclusive with $body)
	 * @param int|null    $body_size       Size of $body_stream in bytes (required when $body_stream is set)
	 * @param string|null $sink_file       Write response body to this file path
	 * @return array ['status' => int, 'body' => string, 'headers' => array]
	 * @throws S3Exception
	 */
	private function request(
		string $method,
		string $bucket,
		string $key = '',
		array $query_params = [],
		array $extra_headers = [],
		?string $body = null,
		$body_stream = null,
		?int $body_size = null,
		?string $sink_file = null
	): array {
		$req      = $this->build_url($bucket, $key, $query_params);
		$datetime = gmdate('Ymd\THis\Z');

		// Compute payload hash
		if ($body !== null) {
			$payload_hash = hash('sha256', $body);
		} elseif ($body_stream !== null) {
			// For streaming uploads, use UNSIGNED-PAYLOAD to avoid reading entire stream for hashing
			$payload_hash = 'UNSIGNED-PAYLOAD';
		} else {
			$payload_hash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'; // SHA256('')
		}

		// Assemble headers for signing
		$headers = array_merge([
			'Host'                 => $req['host'],
			'x-amz-content-sha256' => $payload_hash,
		], $extra_headers);

		if ($body !== null) {
			$headers['Content-Length'] = strlen($body);
		} elseif ($body_stream !== null && $body_size !== null) {
			$headers['Content-Length'] = $body_size;
		}

		$signed = $this->sign($method, $req['path'], $req['query_string'], $req['host'], $headers, $payload_hash, $datetime);

		// Build cURL header list
		$curl_headers = [];
		foreach ($signed as $name => $value) {
			$curl_headers[] = $name . ': ' . $value;
		}

		$ch = curl_init($req['url']);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
		// HEAD: tell cURL not to expect a response body, otherwise it waits for
		// Content-Length bytes that never arrive and errors with "end of response".
		if ($method === 'HEAD') {
			curl_setopt($ch, CURLOPT_NOBODY, true);
		}
		// Follow redirects only for GET — some S3-compatible providers redirect
		// path-style object requests to virtual-hosted-style URLs (e.g. DigitalOcean Spaces).
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $method === 'GET');
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

		// Body
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		} elseif ($body_stream !== null) {
			curl_setopt($ch, CURLOPT_UPLOAD, true);
			curl_setopt($ch, CURLOPT_INFILE, $body_stream);
			if ($body_size !== null) {
				curl_setopt($ch, CURLOPT_INFILESIZE, $body_size);
			}
		}

		// Capture response headers
		$resp_headers = [];
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$resp_headers) {
			$len  = strlen($header);
			$parts = explode(':', $header, 2);
			if (count($parts) === 2) {
				$resp_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
			}
			return $len;
		});

		// Response body
		$sink_fp = null;
		if ($sink_file !== null) {
			$sink_fp = fopen($sink_file, 'wb');
			curl_setopt($ch, CURLOPT_FILE, $sink_fp);
		} else {
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		}

		$response   = curl_exec($ch);
		$http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);

		if ($sink_fp) {
			fclose($sink_fp);
		}

		if ($curl_error) {
			throw new S3Exception('cURL error: ' . esc_html( $curl_error ));
		}

		$body_str = ($sink_file !== null) ? '' : (string) $response;

		// Parse error responses — return raw result for caller to handle 4xx if needed
		if ($http_code >= 400) {
			$this->throw_s3_error($body_str, $http_code);
		}

		return [
			'status'  => $http_code,
			'body'    => $body_str,
			'headers' => $resp_headers,
		];
	}

	/**
	 * Parse S3 XML error response and throw S3Exception.
	 * Never exposes the raw response body or endpoint URL to callers.
	 */
	private function throw_s3_error(string $body, int $status): void
	{
		$code    = '';
		$message = "S3 request failed (HTTP {$status})";

		if ($body && strpos($body, '<Error>') !== false) {
			try {
				$xml  = new \SimpleXMLElement($this->strip_xml_namespaces($body));
				$code = (string) ($xml->Code ?? '');
				$msg  = (string) ($xml->Message ?? '');
				if ($msg !== '') {
					$message = $msg;
				}
			} catch (\Exception $e) {
				// XML parse failure — log internally, keep generic message
				ActivityLogger::get_instance()->log_message('AnibasS3: failed to parse error XML (HTTP ' . $status . '): ' . substr($body, 0, 500));
			}
		}

		throw new S3Exception(esc_html( $message ), esc_html( $code ));
	}

	/**
	 * Build the x-amz-copy-source header value from a "bucket/key" or "/bucket/key" string.
	 * The key portion is URL-encoded segment-by-segment; the bucket is left as-is.
	 */
	private function copy_source(string $copy_source_param): string
	{
		// Strip leading slash
		$copy_source_param = ltrim($copy_source_param, '/');

		// Split off the bucket (everything before the first '/')
		$slash_pos = strpos($copy_source_param, '/');
		if ($slash_pos === false) {
			return rawurlencode($copy_source_param);
		}

		$bucket = substr($copy_source_param, 0, $slash_pos);
		$key    = substr($copy_source_param, $slash_pos + 1);

		$encoded_key = implode('/', array_map('rawurlencode', explode('/', $key)));
		return $bucket . '/' . $encoded_key;
	}

	// ─── Public API (mirrors aws/aws-sdk-php interface used by the adapter) ─────

	/**
	 * Check if an object exists (HEAD request).
	 */
	public function doesObjectExist(string $bucket, string $key): bool
	{
		try {
			$this->request('HEAD', $bucket, $key);
			return true;
		} catch (S3Exception $e) {
			// 404 → object does not exist; any other error → treat as not found
			return false;
		}
	}

	/**
	 * List objects in a bucket (ListObjectsV2).
	 * Returns array with 'Contents' and 'CommonPrefixes' keys.
	 */
	public function listObjectsV2(array $params): array
	{
		$bucket = $params['Bucket'];
		$query  = ['list-type' => '2'];

		if (isset($params['Prefix']))    $query['prefix']    = $params['Prefix'];
		if (isset($params['Delimiter'])) $query['delimiter'] = $params['Delimiter'];
		if (isset($params['MaxKeys']))   $query['max-keys']  = $params['MaxKeys'];
		if (isset($params['ContinuationToken'])) $query['continuation-token'] = $params['ContinuationToken'];

		$resp = $this->request('GET', $bucket, '', $query);
		return $this->parse_list_response($resp['body']);
	}

	/**
	 * Strip XML namespace declarations so SimpleXML can access elements without
	 * namespace qualifiers. S3-compatible providers include default namespaces
	 * (e.g. xmlns="http://s3.amazonaws.com/doc/2006-03-01/") that make direct
	 * child access like $xml->UploadId return empty string.
	 */
	private function strip_xml_namespaces(string $xml): string
	{
		return preg_replace('/\s+xmlns[^=]*="[^"]*"/', '', $xml);
	}

	private function parse_list_response(string $xml_body): array
	{
		$result = ['Contents' => [], 'CommonPrefixes' => []];

		try {
			$xml = new \SimpleXMLElement($this->strip_xml_namespaces($xml_body));
		} catch (\Exception $e) {
			return $result;
		}

		foreach ($xml->Contents as $obj) {
			$result['Contents'][] = [
				'Key'          => (string) $obj->Key,
				'LastModified' => (string) $obj->LastModified,
				'Size'         => (int) (string) $obj->Size,
				'ETag'         => trim((string) $obj->ETag, '"'),
			];
		}

		foreach ($xml->CommonPrefixes as $prefix) {
			$result['CommonPrefixes'][] = [
				'Prefix' => (string) $prefix->Prefix,
			];
		}

		return $result;
	}

	/**
	 * Upload an object (PUT).
	 * $params['Body'] can be a string or a resource (stream).
	 */
	public function putObject(array $params): array
	{
		$bucket  = $params['Bucket'];
		$key     = $params['Key'];
		$body    = $params['Body'] ?? '';
		$headers = [];

		if (isset($params['ContentType'])) {
			$headers['Content-Type'] = $params['ContentType'];
		}

		if (is_resource($body)) {
			// Stream upload — get size via fstat
			$stat      = fstat($body);
			$body_size = $stat ? $stat['size'] : null;

			$resp = $this->request('PUT', $bucket, $key, [], $headers, null, $body, $body_size);
		} else {
			$body = (string) $body;
			$resp = $this->request('PUT', $bucket, $key, [], $headers, $body);
		}

		return ['ETag' => $resp['headers']['etag'] ?? ''];
	}

	/**
	 * Retrieve object metadata (HEAD).
	 * Returns array with 'ContentLength', 'ContentType', 'LastModified'.
	 */
	public function headObject(array $params): array
	{
		$resp = $this->request('HEAD', $params['Bucket'], $params['Key']);

		return [
			'ContentLength' => (int) ($resp['headers']['content-length'] ?? 0),
			'ContentType'   => $resp['headers']['content-type'] ?? '',
			'LastModified'  => $resp['headers']['last-modified'] ?? '',
		];
	}

	/**
	 * Copy an object within S3.
	 */
	public function copyObject(array $params): array
	{
		$headers = [
			'x-amz-copy-source' => $this->copy_source($params['CopySource']),
		];

		if (isset($params['MetadataDirective'])) {
			$headers['x-amz-metadata-directive'] = $params['MetadataDirective'];
		}

		$resp = $this->request('PUT', $params['Bucket'], $params['Key'], [], $headers, '');

		return ['CopyObjectResult' => []];
	}

	/**
	 * Delete an object.
	 */
	public function deleteObject(array $params): array
	{
		$this->request('DELETE', $params['Bucket'], $params['Key']);
		return [];
	}

	/**
	 * Download an object.
	 * If $params['@http']['sink'] is set, streams the response to that file.
	 */
	public function getObject(array $params): array
	{
		$sink_file = $params['@http']['sink'] ?? null;

		$extra_headers = [];
		if (isset($params['Range'])) {
			$extra_headers['Range'] = $params['Range'];
		}

		$resp = $this->request('GET', $params['Bucket'], $params['Key'], [], $extra_headers, null, null, null, $sink_file);

		return [
			'Body'          => $sink_file !== null ? null : $resp['body'],
			'ContentLength' => (int) ($resp['headers']['content-length'] ?? 0),
			'ContentType'   => $resp['headers']['content-type'] ?? '',
		];
	}

	/**
	 * Generate a pre-signed URL for an object (GET).
	 */
	public function getPresignedUrl(string $bucket, string $key, int $expires_in = 3600): string
	{
		$req = $this->build_url($bucket, $key, []);
		$datetime = gmdate('Ymd\THis\Z');
		$date = substr($datetime, 0, 8);

		$credential_scope = "{$date}/{$this->region}/s3/aws4_request";

		$query_params = [
			'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential' => "{$this->access_key}/{$credential_scope}",
			'X-Amz-Date' => $datetime,
			'X-Amz-Expires' => (string) $expires_in,
			'X-Amz-SignedHeaders' => 'host',
		];

		ksort($query_params);
		$pairs = [];
		foreach ($query_params as $k => $v) {
			$pairs[] = rawurlencode($k) . '=' . rawurlencode((string) $v);
		}
		$canonical_query_string = implode('&', $pairs);

		$req_path = $req['path'];
		$canonical_headers = "host:{$req['host']}\n";
		$signed_headers = 'host';
		$payload_hash = 'UNSIGNED-PAYLOAD';

		$canonical_request = implode("\n", [
			'GET',
			$req_path,
			$canonical_query_string,
			$canonical_headers,
			$signed_headers,
			$payload_hash,
		]);

		$string_to_sign = implode("\n", [
			'AWS4-HMAC-SHA256',
			$datetime,
			$credential_scope,
			hash('sha256', $canonical_request),
		]);

		$k_date    = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
		$k_region  = hash_hmac('sha256', $this->region, $k_date, true);
		$k_service = hash_hmac('sha256', 's3', $k_region, true);
		$k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);

		$signature = hash_hmac('sha256', $string_to_sign, $k_signing);

		$final_query = $canonical_query_string . '&X-Amz-Signature=' . rawurlencode($signature);

		$parsed = parse_url($req['url']);
		$url = $parsed['scheme'] . '://' . $parsed['host'];
		if (isset($parsed['port'])) {
			$url .= ':' . $parsed['port'];
		}
		$url .= $req_path . '?' . $final_query;

		return $url;
	}

	// ─── Multipart upload ────────────────────────────────────────────────────────

	/**
	 * Initiate a multipart upload.
	 * Returns ['UploadId' => '...'].
	 */
	public function createMultipartUpload(array $params): array
	{
		$resp = $this->request('POST', $params['Bucket'], $params['Key'], ['uploads' => ''], [], '');

		try {
			$xml      = new \SimpleXMLElement($this->strip_xml_namespaces($resp['body']));
			$uploadId = (string) $xml->UploadId;
			if ($uploadId === '') {
				throw new S3Exception('Empty UploadId in createMultipartUpload response. Body: ' . substr($resp['body'], 0, 500));
			}
			return ['UploadId' => $uploadId];
		} catch (S3Exception $e) {
			throw $e;
		} catch (\Exception $e) {
			throw new S3Exception('Failed to parse createMultipartUpload response: ' . esc_html( $e->getMessage() ));
		}
	}

	/**
	 * Upload a part by copying a byte range from another object.
	 * Returns ['CopyPartResult' => ['ETag' => '...']].
	 */
	public function uploadPartCopy(array $params): array
	{
		$query   = [
			'partNumber' => $params['PartNumber'],
			'uploadId'   => $params['UploadId'],
		];
		$headers = [
			'x-amz-copy-source'       => $this->copy_source($params['CopySource']),
			'x-amz-copy-source-range' => $params['CopySourceRange'],
		];

		$resp = $this->request('PUT', $params['Bucket'], $params['Key'], $query, $headers, '');

		try {
			$xml  = new \SimpleXMLElement($this->strip_xml_namespaces($resp['body']));
			$etag = (string) $xml->ETag;
		} catch (\Exception $e) {
			$etag = $resp['headers']['etag'] ?? '';
		}

		return [
			'CopyPartResult' => [
				'ETag'         => $etag,
				'LastModified' => '',
			],
		];
	}

	/**
	 * Upload a part of a multipart upload.
	 * Returns ['ETag' => '...'].
	 */
	public function uploadPart(array $params): array
	{
		$query = [
			'partNumber' => $params['PartNumber'],
			'uploadId'   => $params['UploadId'],
		];
		$body = $params['Body'] ?? '';

		$resp = $this->request('PUT', $params['Bucket'], $params['Key'], $query, [], is_string($body) ? $body : stream_get_contents($body));

		return ['ETag' => $resp['headers']['etag'] ?? ''];
	}

	/**
	 * Complete a multipart upload.
	 */
	public function completeMultipartUpload(array $params): array
	{
		$query = ['uploadId' => $params['UploadId']];

		// Build XML body
		$xml = '<CompleteMultipartUpload>';
		foreach ($params['MultipartUpload']['Parts'] as $part) {
			$etag = htmlspecialchars($part['ETag'], ENT_XML1);
			$xml .= "<Part><PartNumber>{$part['PartNumber']}</PartNumber><ETag>{$etag}</ETag></Part>";
		}
		$xml .= '</CompleteMultipartUpload>';

		$resp = $this->request('POST', $params['Bucket'], $params['Key'], $query, ['Content-Type' => 'application/xml'], $xml);

		return [];
	}

	/**
	 * Abort a multipart upload and discard all uploaded parts.
	 */
	public function abortMultipartUpload(array $params): array
	{
		$this->request('DELETE', $params['Bucket'], $params['Key'], ['uploadId' => $params['UploadId']]);
		return [];
	}
}
