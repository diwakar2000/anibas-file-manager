<?php
declare(strict_types=1);

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Validates an ANFM package before any restore workflow can trust it.
 *
 * This checks extension, binary header metadata, fixed EOF footer metadata,
 * package size, and the encrypted manifest hash before restore planning.
 */
class SiteBackupPackageValidator
{
    private const MAGIC = 'ANFM';
    private const VERSION = 2;
    private const HEADER_SIZE = 50;
    private const FOOTER_MAGIC = 'ANFMEND!';
    private const FOOTER_VERSION = 1;
    private const FOOTER_SIZE = 64;

    /**
     * @return array<string,int|bool|string>
     */
    public function validate(string $path): array
    {
        $path = wp_normalize_path($path);
        if ($path === '' || ! is_file($path)) {
            throw new \RuntimeException('Backup package does not exist.');
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'anfm') {
            throw new \RuntimeException('Site restore only accepts ANFM backup packages.');
        }

        $actual_size = (int) filesize($path);
        if ($actual_size < self::HEADER_SIZE + self::FOOTER_SIZE) {
            throw new \RuntimeException('Backup package is too small to contain a valid ANFM header and footer.');
        }

        $handle = @fopen($path, 'rb');
        if (! $handle) {
            throw new \RuntimeException('Failed to open backup package.');
        }

        $header = fread($handle, self::HEADER_SIZE);
        fseek($handle, -self::FOOTER_SIZE, SEEK_END);
        $footer = fread($handle, self::FOOTER_SIZE);
        fclose($handle);

        if (! is_string($header) || strlen($header) !== self::HEADER_SIZE) {
            throw new \RuntimeException('Backup package header is incomplete.');
        }
        if (! is_string($footer) || strlen($footer) !== self::FOOTER_SIZE) {
            throw new \RuntimeException('Backup package footer is incomplete.');
        }

        $magic = substr($header, 0, 4);
        if ($magic !== self::MAGIC) {
            throw new \RuntimeException('Backup package is not an ANFM file.');
        }

        $version = unpack('C', $header[4])[1];
        if ((int) $version > self::VERSION) {
            throw new \RuntimeException('Backup package version is newer than this plugin supports.');
        }

        $flags = unpack('C', $header[5])[1];
        $manifest_offset = unpack('P', substr($header, 38, 8))[1];
        $manifest_size = unpack('V', substr($header, 46, 4))[1];
        if (! is_int($manifest_offset) || ! is_int($manifest_size)) {
            throw new \RuntimeException('Backup package metadata is invalid.');
        }

        if ($manifest_offset < self::HEADER_SIZE || $manifest_size <= 0) {
            throw new \RuntimeException('Backup package manifest metadata is missing.');
        }

        $footer_data = $this->parse_footer($footer);
        if ((int) $footer_data['package_size'] !== $actual_size) {
            throw new \RuntimeException('Backup package footer size does not match the actual file size.');
        }
        if ((int) $footer_data['manifest_offset'] !== (int) $manifest_offset
            || (int) $footer_data['manifest_size'] !== (int) $manifest_size) {
            throw new \RuntimeException('Backup package header and footer metadata do not match.');
        }

        $expected_size = $manifest_offset + $manifest_size + self::FOOTER_SIZE;
        if ($expected_size !== $actual_size) {
            throw new \RuntimeException('Backup package size does not match its footer metadata.');
        }

        $manifest_hash = $this->hash_file_region($path, (int) $manifest_offset, (int) $manifest_size);
        if (! hash_equals((string) $footer_data['manifest_hash'], $manifest_hash)) {
            throw new \RuntimeException('Backup package footer hash does not match the encrypted manifest.');
        }

        return [
            'path' => $path,
            'size' => $actual_size,
            'version' => (int) $version,
            'password_protected' => (((int) $flags) & 1) === 1,
            'manifest_offset' => (int) $manifest_offset,
            'manifest_size' => (int) $manifest_size,
            'footer_version' => (int) $footer_data['footer_version'],
            'manifest_hash' => bin2hex((string) $footer_data['manifest_hash']),
        ];
    }

    /**
     * @return array{footer_version:int,archive_version:int,package_size:int,manifest_offset:int,manifest_size:int,manifest_hash:string}
     */
    private function parse_footer(string $footer): array
    {
        if (substr($footer, 0, 8) !== self::FOOTER_MAGIC) {
            throw new \RuntimeException('Backup package footer is missing.');
        }

        $footer_version = unpack('C', $footer[8])[1];
        $archive_version = unpack('C', $footer[9])[1];
        if ((int) $footer_version > self::FOOTER_VERSION || (int) $archive_version > self::VERSION) {
            throw new \RuntimeException('Backup package footer version is newer than this plugin supports.');
        }

        return [
            'footer_version' => (int) $footer_version,
            'archive_version' => (int) $archive_version,
            'package_size' => (int) unpack('P', substr($footer, 12, 8))[1],
            'manifest_offset' => (int) unpack('P', substr($footer, 20, 8))[1],
            'manifest_size' => (int) unpack('V', substr($footer, 28, 4))[1],
            'manifest_hash' => substr($footer, 32, 32),
        ];
    }

    private function hash_file_region(string $path, int $offset, int $length): string
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            throw new \RuntimeException('Failed to open backup package for manifest hash.');
        }

        fseek($handle, $offset);
        $remaining = $length;
        $context = hash_init('sha256');

        while ($remaining > 0) {
            $chunk = fread($handle, min(1048576, $remaining));
            if ($chunk === false || $chunk === '') {
                fclose($handle);
                throw new \RuntimeException('Backup package ended before the manifest hash could be verified.');
            }
            $remaining -= strlen($chunk);
            hash_update($context, $chunk);
        }

        fclose($handle);
        return hash_final($context, true);
    }
}
