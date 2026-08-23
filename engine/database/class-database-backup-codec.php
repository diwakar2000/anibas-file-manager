<?php
declare(strict_types=1);

namespace Anibas;

if (! defined('ABSPATH')) exit;

/**
 * Encodes database rows into line-delimited JSON without assuming UTF-8 text.
 *
 * Every non-null value is base64 encoded, so binary data and serialized PHP
 * payloads survive backup/restore without JSON mangling.
 */
class DatabaseBackupCodec
{
    public const FORMAT = 'anibas-db-jsonl-base64-row-v1';

    /**
     * @param array<string,mixed> $row
     */
    public static function encode_row(array $row): string
    {
        $encoded = [];
        foreach ($row as $column => $value) {
            $column = (string) $column;
            if ($value === null) {
                $encoded[$column] = ['null' => true];
                continue;
            }

            if (! is_scalar($value)) {
                throw new \InvalidArgumentException('Database backup row contains a non-scalar value.');
            }

            // base64_encode() here makes arbitrary binary column values (BLOBs, binary
            // strings) safe to embed in a JSONL backup line; not logic obfuscation.
            $encoded[$column] = ['b64' => base64_encode((string) $value)]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        }

        $line = wp_json_encode(['values' => $encoded], JSON_UNESCAPED_SLASHES);
        if (! is_string($line) || $line === '') {
            throw new \RuntimeException('Failed to encode database backup row.');
        }

        return $line . "\n";
    }

    /**
     * @return array<string,string|null>
     */
    public static function decode_row(string $line): array
    {
        $decoded = json_decode(trim($line), true);
        if (! is_array($decoded) || ! isset($decoded['values']) || ! is_array($decoded['values'])) {
            throw new \RuntimeException('Invalid database backup row payload.');
        }

        $row = [];
        foreach ($decoded['values'] as $column => $payload) {
            if (! is_string($column) || ! is_array($payload)) {
                throw new \RuntimeException('Invalid database backup column payload.');
            }

            if (! empty($payload['null'])) {
                $row[$column] = null;
                continue;
            }

            if (! isset($payload['b64']) || ! is_string($payload['b64'])) {
                throw new \RuntimeException('Invalid database backup value payload.');
            }

            $value = base64_decode($payload['b64'], true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes a backup row value encoded by base64_encode() above, not obfuscated code.
            if ($value === false) {
                throw new \RuntimeException('Invalid base64 database backup value.');
            }

            $row[$column] = $value;
        }

        return $row;
    }
}
