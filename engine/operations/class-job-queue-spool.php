<?php

/**
 * Append-only JSONL spool storing background-job work queues on disk so the
 * wp_options-stored job row stays small regardless of how many files a job
 * touches.
 *
 * @package Anibas_File_Manager
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Append-only JSONL spool for background-job work queues.
 *
 * Each job has one file per "stream" (e.g. 'files', 'folders'). The list phase
 * appends entries, and consumer phases (transfer/delete) walk the file via a
 * byte-offset cursor stored on the small per-job work_queue option row. This
 * keeps the wp_options-stored work_queue tiny no matter how many files the
 * job touches — the heavy data lives on disk.
 *
 * Layout:
 *   {uploads}/anibas-fm-temp/queues/{job_id}/{stream}.jsonl
 *
 * Format:
 *   One JSON-encoded item per line, terminated by "\n". Lines that fail to
 *   decode are reported as null items by peek() but the cursor advances past
 *   them so a single corrupt line cannot wedge a job.
 */
class JobQueueSpool
{
    private static ?string $base_dir = null;

    private static function base_dir(): string
    {
        if ( self::$base_dir === null ) {
            self::$base_dir = wp_upload_dir()['basedir'] . '/anibas-fm-temp/queues';
        }
        return self::$base_dir;
    }

    private static function job_dir( string $job_id ): string
    {
        // Sanitize: job IDs in this plugin are wp_generate_password output (alnum),
        // but defend in depth against path traversal regardless.
        $clean = preg_replace( '/[^A-Za-z0-9_\-]/', '', $job_id );
        return self::base_dir() . '/' . $clean;
    }

    private static function path( string $job_id, string $stream ): string
    {
        $clean_stream = preg_replace( '/[^A-Za-z0-9_\-]/', '', $stream );
        return self::job_dir( $job_id ) . '/' . $clean_stream . '.jsonl';
    }

    /**
     * Ensure the per-job spool directory exists. Returns the directory path
     * on success or false on failure.
     */
    private static function ensure_dir( string $job_id )
    {
        $dir = self::job_dir( $job_id );
        if ( ! is_dir( $dir ) ) {
            if ( ! wp_mkdir_p( $dir ) ) {
                return false;
            }
            // Protect the parent queues/ directory once, lazily.
            if ( function_exists( 'anibas_fm_protect_dir' ) ) {
                anibas_fm_protect_dir( self::base_dir() );
            }
        }
        return $dir;
    }

    /**
     * Append items to the named stream. Each item is JSON-encoded on its own line.
     * Returns true on success, false if the spool can't be opened/written.
     */
    public static function append( string $job_id, string $stream, array $items ): bool
    {
        if ( empty( $items ) ) {
            return true;
        }
        if ( ! self::ensure_dir( $job_id ) ) {
            return false;
        }
        $path = self::path( $job_id, $stream );

        $payload = '';
        foreach ( $items as $item ) {
            $line = wp_json_encode( $item );
            if ( $line === false ) {
                continue;
            }
            $payload .= $line . "\n";
        }
        if ( $payload === '' ) {
            return true;
        }

        $fp = @fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( $fp === false ) {
            return false;
        }
        try {
            // LOCK_EX serializes the rare case where two workers race on the same job —
            // the dispatcher already serializes via wp_options lock, so this is belt-and-suspenders.
            @flock( $fp, LOCK_EX );
            $bytes = fwrite( $fp, $payload );
            fflush( $fp );
            @flock( $fp, LOCK_UN );
            return $bytes !== false && $bytes === strlen( $payload );
        } finally {
            fclose( $fp );
        }
    }

    /**
     * Read the next item starting at byte offset $cursor. Does NOT advance any
     * persistent cursor — the caller decides when to commit by storing
     * $result['next_cursor']. Returns:
     *   ['item' => array|null, 'next_cursor' => int, 'eof' => bool]
     *
     * - When eof is true, item is null and next_cursor === filesize.
     * - When the line is malformed, item is null but next_cursor is past the bad line.
     */
    public static function peek( string $job_id, string $stream, int $cursor ): array
    {
        $path = self::path( $job_id, $stream );
        if ( ! file_exists( $path ) ) {
            return [ 'item' => null, 'next_cursor' => $cursor, 'eof' => true ];
        }
        $size = @filesize( $path );
        if ( $size === false || $cursor >= $size ) {
            return [ 'item' => null, 'next_cursor' => $size === false ? $cursor : $size, 'eof' => true ];
        }
        $fp = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( $fp === false ) {
            return [ 'item' => null, 'next_cursor' => $cursor, 'eof' => true ];
        }
        try {
            if ( fseek( $fp, $cursor ) !== 0 ) {
                return [ 'item' => null, 'next_cursor' => $cursor, 'eof' => true ];
            }
            $line = fgets( $fp );
            if ( $line === false ) {
                return [ 'item' => null, 'next_cursor' => $size, 'eof' => true ];
            }
            $next_cursor = $cursor + strlen( $line );
            $trimmed     = rtrim( $line, "\r\n" );
            if ( $trimmed === '' ) {
                return [ 'item' => null, 'next_cursor' => $next_cursor, 'eof' => $next_cursor >= $size ];
            }
            $decoded = json_decode( $trimmed, true );
            if ( ! is_array( $decoded ) ) {
                return [ 'item' => null, 'next_cursor' => $next_cursor, 'eof' => $next_cursor >= $size ];
            }
            return [ 'item' => $decoded, 'next_cursor' => $next_cursor, 'eof' => $next_cursor >= $size ];
        } finally {
            fclose( $fp );
        }
    }

    /**
     * Read up to $max consecutive items starting at $cursor. Returns:
     *   ['items' => array, 'next_cursor' => int, 'eof' => bool]
     *
     * Skips malformed lines silently (logged at the caller's discretion).
     */
    public static function read_batch( string $job_id, string $stream, int $cursor, int $max = 100 ): array
    {
        $items = [];
        $eof   = false;
        for ( $i = 0; $i < $max; $i++ ) {
            $peek   = self::peek( $job_id, $stream, $cursor );
            $cursor = $peek['next_cursor'];
            if ( $peek['item'] !== null ) {
                $items[] = $peek['item'];
            }
            if ( $peek['eof'] ) {
                $eof = true;
                break;
            }
        }
        return [ 'items' => $items, 'next_cursor' => $cursor, 'eof' => $eof ];
    }

    public static function is_eof( string $job_id, string $stream, int $cursor ): bool
    {
        $path = self::path( $job_id, $stream );
        if ( ! file_exists( $path ) ) {
            return true;
        }
        $size = @filesize( $path );
        return $size === false || $cursor >= $size;
    }

    public static function size( string $job_id, string $stream ): int
    {
        $path = self::path( $job_id, $stream );
        if ( ! file_exists( $path ) ) {
            return 0;
        }
        $size = @filesize( $path );
        return $size === false ? 0 : (int) $size;
    }

    /**
     * Read the previous JSONL item ending before byte offset $cursor.
     * Used for deepest-first folder deletion without loading the full folder
     * stream into memory.
     */
    public static function previous( string $job_id, string $stream, int $cursor ): array
    {
        $path = self::path( $job_id, $stream );
        if ( ! file_exists( $path ) ) {
            return [ 'item' => null, 'previous_cursor' => 0, 'eof' => true ];
        }

        $size = @filesize( $path );
        if ( $size === false || $size <= 0 ) {
            return [ 'item' => null, 'previous_cursor' => 0, 'eof' => true ];
        }

        $end = min( max( 0, $cursor ), (int) $size );
        if ( $end <= 0 ) {
            return [ 'item' => null, 'previous_cursor' => 0, 'eof' => true ];
        }

        $fp = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( $fp === false ) {
            return [ 'item' => null, 'previous_cursor' => 0, 'eof' => true ];
        }

        try {
            while ( $end > 0 ) {
                fseek( $fp, $end - 1 );
                $char = fgetc( $fp );
                if ( $char !== "\n" && $char !== "\r" ) {
                    break;
                }
                $end--;
            }

            if ( $end <= 0 ) {
                return [ 'item' => null, 'previous_cursor' => 0, 'eof' => true ];
            }

            $start = $end - 1;
            while ( $start > 0 ) {
                fseek( $fp, $start - 1 );
                if ( fgetc( $fp ) === "\n" ) {
                    break;
                }
                $start--;
            }

            fseek( $fp, $start );
            $line = fread( $fp, $end - $start );
            if ( $line === false ) {
                return [ 'item' => null, 'previous_cursor' => $start, 'eof' => $start <= 0 ];
            }

            $decoded = json_decode( rtrim( $line, "\r\n" ), true );
            return [
                'item'            => is_array( $decoded ) ? $decoded : null,
                'previous_cursor' => $start,
                'eof'             => $start <= 0,
            ];
        } finally {
            fclose( $fp );
        }
    }

    public static function exists( string $job_id, string $stream ): bool
    {
        return file_exists( self::path( $job_id, $stream ) );
    }

    /**
     * Count newline-terminated lines in the stream. Used for total_files. Lazy
     * and not cached — callers should snapshot the result on the job once.
     */
    public static function count_lines( string $job_id, string $stream ): int
    {
        $path = self::path( $job_id, $stream );
        if ( ! file_exists( $path ) ) {
            return 0;
        }
        $fp = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( $fp === false ) {
            return 0;
        }
        $count = 0;
        try {
            while ( ( $chunk = fread( $fp, 1 << 16 ) ) !== false && $chunk !== '' ) {
                $count += substr_count( $chunk, "\n" );
            }
        } finally {
            fclose( $fp );
        }
        return $count;
    }

    /**
     * Delete the spool directory for a job. Best-effort; missing files are not
     * an error. Called from BackgroundProcessor on job completion/failure/cancel.
     */
    public static function cleanup( string $job_id ): void
    {
        $dir = self::job_dir( $job_id );
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $entries = @scandir( $dir );
        if ( is_array( $entries ) ) {
            foreach ( $entries as $entry ) {
                if ( $entry === '.' || $entry === '..' ) {
                    continue;
                }
                @unlink( $dir . '/' . $entry );
            }
        }
        @rmdir( $dir );
    }
}
