<?php
/**
 * Chunked TAR archive creation engine.
 *
 * Creates a standard POSIX TAR (UStar) archive from files/folders across
 * multiple HTTP requests, respecting max_execution_time and memory limits.
 * Uses flock for concurrency control and atomic state writes for crash safety.
 *
 * The output is a universally compatible .tar file that can be opened by
 * any OS (macOS, Linux, Windows 11+, 7-Zip, WinRAR, etc.).
 *
 * TAR format (sequential):
 *   [512-byte header][file data padded to 512][512-byte header][file data]...[1024 zero bytes]
 *
 * Because TAR is sequential with no central directory, files can be written
 * one at a time and even mid-file across requests — perfect for time-budgeted
 * chunked processing.
 *
 * Usage:
 *   $engine = TarCreateEngine::get_instance( $source_path, $output_tar );
 *   $engine->build_manifest();
 *   $info = $engine->get_manifest_info();
 *   while ( $engine->run_step() ) { }
 *   $engine->cleanup();
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

use Exception, RecursiveDirectoryIterator, RecursiveIteratorIterator;

class TarCreateEngine {

    private string $source;
    private string $output;

    private string $manifest_file;
    private string $state_file;
    private string $lock_file;

    private int $time_budget;
    private int $chunk_size;

    private static $instances = [];

    /**
     * Get or create an engine instance for a given source + output pair.
     */
    public static function get_instance( string $source, string $output ): self {
        if ( ! file_exists( $source ) ) {
            throw new Exception( 'Source path does not exist' );
        }
        $key = md5( $source . '|' . $output );
        if ( empty( self::$instances[ $key ] ) ) {
            self::$instances[ $key ] = new self( $source, $output );
        }
        return self::$instances[ $key ];
    }

    private function __construct( string $source, string $output ) {
        $this->source = rtrim( $source, '/' );
        $this->output = $output;

        $output_dir = dirname( $output );
        if ( ! is_dir( $output_dir ) ) {
            throw new Exception( 'Output directory does not exist' );
        }

        $this->manifest_file = $output . '.manifest.json';
        $this->state_file    = $output . '.state.json';
        $this->lock_file     = $output . '.lock';

        // Use ~60% of available execution time, minimum 10s
        $max_time = (int) ini_get( 'max_execution_time' );
        $this->time_budget = max( 10, $max_time > 0 ? (int) ( $max_time * 0.6 ) : 20 );

        // Read chunk size for streaming file data
        $this->chunk_size = intval( anibas_fm_get_option( 'chunk_size', defined( 'ANIBAS_FM_DEFAULT_CHUNK_SIZE' ) ? ANIBAS_FM_DEFAULT_CHUNK_SIZE : 1048576 ) );
        if ( $this->chunk_size < 262144 ) $this->chunk_size = 262144;
        if ( $this->chunk_size > 10485760 ) $this->chunk_size = 10485760;
    }

    /* ------------------------------------- */
    /* LOCKING                               */
    /* ------------------------------------- */

    private function acquire_lock() {
        $lock = fopen( $this->lock_file, 'c' );
        if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
            if ( $lock ) {
                fclose( $lock );
            }
            throw new Exception( 'Another tar creation process is running' );
        }
        return $lock;
    }

    private function release_lock( $lock ) {
        if ( is_resource( $lock ) ) {
            flock( $lock, LOCK_UN );
            fclose( $lock );
        }
    }

    /* ------------------------------------- */
    /* MANIFEST BUILD                        */
    /* ------------------------------------- */

    /**
     * Build a manifest of all files and directories to be archived.
     */
    public function build_manifest() {
        if ( file_exists( $this->manifest_file ) ) {
            return;
        }

        $entries       = [];
        $max_file_size = 0;
        $max_file_name = '';

        if ( is_file( $this->source ) ) {
            $size = filesize( $this->source );
            $name = basename( $this->source );
            $entries[] = [
                'path'  => $this->source,
                'name'  => $name,
                'size'  => $size,
                'isdir' => false,
            ];
            $max_file_size = $size;
            $max_file_name = $name;
        } else {
            $base_len = strlen( $this->source ) + 1;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $this->source,
                    RecursiveDirectoryIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $item ) {
                $full_path     = $item->getPathname();
                $relative_path = substr( $full_path, $base_len );

                if ( $item->isDir() ) {
                    $entries[] = [
                        'path'  => $full_path,
                        'name'  => $relative_path,
                        'size'  => 0,
                        'isdir' => true,
                    ];
                } else {
                    $size = $item->getSize();
                    $entries[] = [
                        'path'  => $full_path,
                        'name'  => $relative_path,
                        'size'  => $size,
                        'isdir' => false,
                    ];
                    if ( $size > $max_file_size ) {
                        $max_file_size = $size;
                        $max_file_name = $relative_path;
                    }
                }
            }
        }

        $tmp = $this->manifest_file . '.tmp';
        file_put_contents( $tmp, json_encode( [
            'total'         => count( $entries ),
            'total_size'    => array_sum( array_column( $entries, 'size' ) ),
            'max_file_size' => $max_file_size,
            'max_file_name' => $max_file_name,
            'entries'       => $entries,
        ] ) );
        rename( $tmp, $this->manifest_file );
    }

    /* ------------------------------------- */
    /* MANIFEST INFO (for frontend)          */
    /* ------------------------------------- */

    public function get_manifest_info(): array {
        if ( ! file_exists( $this->manifest_file ) ) {
            throw new Exception( 'Manifest not built. Call build_manifest() first.' );
        }

        $manifest = json_decode( file_get_contents( $this->manifest_file ), true );

        return [
            'total'         => isset( $manifest['total'] ) ? (int) $manifest['total'] : 0,
            'total_size'    => isset( $manifest['total_size'] ) ? (int) $manifest['total_size'] : 0,
            'max_file_size' => isset( $manifest['max_file_size'] ) ? (int) $manifest['max_file_size'] : 0,
            'max_file_name' => isset( $manifest['max_file_name'] ) ? $manifest['max_file_name'] : '',
        ];
    }

    /* ------------------------------------- */
    /* STATE                                 */
    /* ------------------------------------- */

    private function load_state(): array {
        if ( ! file_exists( $this->state_file ) ) {
            return [
                'cursor'          => 0,
                'file_offset'     => 0,
                'archive_pos'     => 0,
                'header_written'  => false,
                'bytes_processed' => 0,
            ];
        }

        $data = json_decode( file_get_contents( $this->state_file ), true );

        return is_array( $data ) ? $data : [
            'cursor'          => 0,
            'file_offset'     => 0,
            'archive_pos'     => 0,
            'header_written'  => false,
            'bytes_processed' => 0,
        ];
    }

    private function save_state( array $state ) {
        $tmp = $this->state_file . '.tmp';
        file_put_contents( $tmp, json_encode( $state ) );
        rename( $tmp, $this->state_file );
    }

    /* ------------------------------------- */
    /* SECURITY                              */
    /* ------------------------------------- */

    private function validate_path( string $path ): bool {
        if ( is_file( $this->source ) ) {
            return realpath( $path ) === realpath( $this->source );
        }

        $base = realpath( $this->source );
        $real = realpath( $path );

        if ( $real === false || $base === false ) {
            return false;
        }

        return strpos( $real, $base ) === 0;
    }

    /* ------------------------------------- */
    /* TAR HEADER GENERATION                 */
    /* ------------------------------------- */

    /**
     * Build a 512-byte POSIX UStar header for a file or directory entry.
     *
     * @param string $name     Relative path (max 255 chars with prefix).
     * @param int    $size     File size in bytes (0 for directories).
     * @param int    $mtime    Last modified timestamp.
     * @param bool   $is_dir   Whether this entry is a directory.
     * @param int    $mode     File permissions.
     * @return string 512-byte binary header.
     */
    private function build_tar_header( string $name, int $size, int $mtime, bool $is_dir, int $mode = 0 ): string {
        // Default permissions
        if ( $mode === 0 ) {
            $mode = $is_dir ? 0755 : 0644;
        }

        // UStar allows 100-char name + 155-char prefix
        $prefix = '';
        $entry_name = $name;

        if ( $is_dir && substr( $entry_name, -1 ) !== '/' ) {
            $entry_name .= '/';
        }

        // If name is too long, split into prefix + name
        if ( strlen( $entry_name ) > 100 ) {
            $slash_pos = strrpos( substr( $entry_name, 0, 155 ), '/' );
            if ( $slash_pos !== false ) {
                $prefix     = substr( $entry_name, 0, $slash_pos );
                $entry_name = substr( $entry_name, $slash_pos + 1 );
            }
            // Truncate if still too long (shouldn't happen with reasonable paths)
            if ( strlen( $entry_name ) > 100 ) {
                $entry_name = substr( $entry_name, 0, 100 );
            }
            if ( strlen( $prefix ) > 155 ) {
                $prefix = substr( $prefix, 0, 155 );
            }
        }

        $type_flag = $is_dir ? '5' : '0';  // '5' = directory, '0' = regular file

        // Build header with placeholder checksum (8 spaces)
        $header = '';
        $header .= str_pad( $entry_name, 100, "\0" );            // 0-99:    name
        $header .= str_pad( decoct( $mode ), 7, '0', STR_PAD_LEFT ) . "\0";  // 100-107: mode
        $header .= str_pad( decoct( 0 ), 7, '0', STR_PAD_LEFT ) . "\0";     // 108-115: uid
        $header .= str_pad( decoct( 0 ), 7, '0', STR_PAD_LEFT ) . "\0";     // 116-123: gid
        $header .= str_pad( decoct( $size ), 11, '0', STR_PAD_LEFT ) . "\0"; // 124-135: size
        $header .= str_pad( decoct( $mtime ), 11, '0', STR_PAD_LEFT ) . "\0"; // 136-147: mtime
        $header .= '        ';                                     // 148-155: checksum placeholder (8 spaces)
        $header .= $type_flag;                                     // 156:     type flag
        $header .= str_pad( '', 100, "\0" );                      // 157-256: linkname
        $header .= "ustar\0";                                     // 257-262: magic
        $header .= "00";                                           // 263-264: version
        $header .= str_pad( '', 32, "\0" );                       // 265-296: uname
        $header .= str_pad( '', 32, "\0" );                       // 297-328: gname
        $header .= str_pad( '', 8, "\0" );                        // 329-336: devmajor
        $header .= str_pad( '', 8, "\0" );                        // 337-344: devminor
        $header .= str_pad( $prefix, 155, "\0" );                 // 345-499: prefix
        $header .= str_pad( '', 12, "\0" );                       // 500-511: padding

        // Calculate and insert checksum
        $checksum = 0;
        for ( $i = 0; $i < 512; $i++ ) {
            $checksum += ord( $header[ $i ] );
        }
        $checksum_str = str_pad( decoct( $checksum ), 6, '0', STR_PAD_LEFT ) . "\0 ";

        // Replace checksum placeholder at offset 148
        $header = substr( $header, 0, 148 ) . $checksum_str . substr( $header, 156 );

        return $header;
    }

    /**
     * Get the padding needed to align to a 512-byte boundary.
     */
    private function get_padding( int $size ): string {
        $remainder = $size % 512;
        if ( $remainder === 0 ) {
            return '';
        }
        return str_repeat( "\0", 512 - $remainder );
    }

    /* ------------------------------------- */
    /* MAIN WORKER                           */
    /* ------------------------------------- */

    /**
     * Write files to the tar archive in a time-bounded step.
     *
     * Because TAR is sequential (no central directory), we can:
     * - Write one file at a time (header + data + padding)
     * - Pause mid-file and resume from the same byte offset
     * - Finalize by writing 1024 zero bytes at the end
     *
     * @return bool true if more work remains, false if complete.
     */
    public function run_step(): bool {
        $lock = $this->acquire_lock();

        try {
            if ( ! file_exists( $this->manifest_file ) ) {
                throw new Exception( 'Manifest not built. Call build_manifest() first.' );
            }

            $manifest = json_decode( file_get_contents( $this->manifest_file ), true );

            if ( ! is_array( $manifest ) || ! isset( $manifest['entries'] ) ) {
                throw new Exception( 'Invalid manifest file' );
            }

            $entries = $manifest['entries'];
            $total   = count( $entries );
            $state   = $this->load_state();

            if ( $state['cursor'] >= $total ) {
                // All entries done — write end-of-archive marker if not already
                $this->write_end_marker( $state );
                $this->release_lock( $lock );
                return false;
            }

            // Open archive for appending (or create if first step)
            $tar = fopen( $this->output, $state['archive_pos'] > 0 ? 'r+b' : 'wb' );
            if ( ! $tar ) {
                throw new Exception( 'Cannot create/open tar file' );
            }

            // Seek to current position
            if ( $state['archive_pos'] > 0 ) {
                fseek( $tar, $state['archive_pos'] );
            }

            $start = microtime( true );

            while ( $state['cursor'] < $total ) {
                $entry     = $entries[ $state['cursor'] ];
                $file_path = $entry['path'];
                $tar_name  = $entry['name'];
                $file_size = (int) $entry['size'];
                $is_dir    = ! empty( $entry['isdir'] );

                // Validate the file still exists and is within source
                if ( ! file_exists( $file_path ) || ! $this->validate_path( $file_path ) ) {
                    $state['cursor']++;
                    $state['file_offset']    = 0;
                    $state['header_written'] = false;
                    $this->save_state( $state );
                    continue;
                }

                $mtime = filemtime( $file_path );

                // Step 1: Write tar header (if not already written for this entry)
                if ( ! $state['header_written'] ) {
                    $actual_size = $is_dir ? 0 : filesize( $file_path );
                    $header = $this->build_tar_header( $tar_name, $actual_size, $mtime, $is_dir );
                    fwrite( $tar, $header );
                    $state['archive_pos']    += 512;
                    $state['header_written'] = true;

                    // For directories, no data to write
                    if ( $is_dir ) {
                        $state['cursor']++;
                        $state['file_offset']    = 0;
                        $state['header_written'] = false;
                        $this->save_state( $state );

                        if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                            fclose( $tar );
                            $this->release_lock( $lock );
                            return true;
                        }
                        continue;
                    }

                    $this->save_state( $state );
                }

                // Step 2: Stream file data in chunks
                if ( ! $is_dir ) {
                    $src = fopen( $file_path, 'rb' );
                    if ( ! $src ) {
                        // Skip unreadable files
                        $state['cursor']++;
                        $state['file_offset']    = 0;
                        $state['header_written'] = false;
                        $this->save_state( $state );
                        continue;
                    }

                    // Resume from offset if partially written
                    if ( $state['file_offset'] > 0 ) {
                        fseek( $src, $state['file_offset'] );
                    }

                    while ( ! feof( $src ) ) {
                        $chunk = fread( $src, $this->chunk_size );
                        if ( $chunk === false || strlen( $chunk ) === 0 ) {
                            break;
                        }

                        fwrite( $tar, $chunk );
                        $state['file_offset']     += strlen( $chunk );
                        $state['archive_pos']     += strlen( $chunk );
                        $state['bytes_processed'] += strlen( $chunk );

                        // Check time budget after each chunk
                        if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                            fclose( $src );
                            fclose( $tar );
                            $this->save_state( $state );
                            $this->release_lock( $lock );
                            return true;
                        }
                    }

                    fclose( $src );

                    // Write padding to 512-byte boundary
                    $actual_size = $is_dir ? 0 : filesize( $file_path );
                    $padding = $this->get_padding( $actual_size );
                    if ( strlen( $padding ) > 0 ) {
                        fwrite( $tar, $padding );
                        $state['archive_pos'] += strlen( $padding );
                    }
                }

                // Entry complete — advance cursor
                $state['cursor']++;
                $state['file_offset']    = 0;
                $state['header_written'] = false;
                $this->save_state( $state );

                // Check time budget between files
                if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                    fclose( $tar );
                    $this->release_lock( $lock );
                    return true;
                }
            }

            // All entries written — add end-of-archive marker
            fwrite( $tar, str_repeat( "\0", 1024 ) );
            $state['archive_pos'] += 1024;
            fclose( $tar );
            $this->save_state( $state );
            $this->release_lock( $lock );
            return false;

        } catch ( Exception $e ) {
            $this->release_lock( $lock );
            throw $e;
        }
    }

    /**
     * Write end-of-archive marker (1024 zero bytes) if not already present.
     */
    private function write_end_marker( array &$state ) {
        $expected_end = $state['archive_pos'];
        $actual_size  = file_exists( $this->output ) ? filesize( $this->output ) : 0;

        // If the file doesn't already have the end marker, append it
        if ( $actual_size > 0 && $actual_size < $expected_end + 1024 ) {
            $tar = fopen( $this->output, 'r+b' );
            fseek( $tar, $expected_end );
            fwrite( $tar, str_repeat( "\0", 1024 ) );
            fclose( $tar );
            $state['archive_pos'] += 1024;
            $this->save_state( $state );
        }
    }

    /* ------------------------------------- */
    /* PROGRESS                              */
    /* ------------------------------------- */

    public function progress(): array {
        if ( ! file_exists( $this->manifest_file ) ) {
            return [ 'current' => 0, 'total' => 0, 'percent' => 0, 'bytes_processed' => 0, 'total_size' => 0 ];
        }

        $manifest   = json_decode( file_get_contents( $this->manifest_file ), true );
        $state      = $this->load_state();
        $total      = isset( $manifest['total'] ) ? (int) $manifest['total'] : 0;
        $total_size = isset( $manifest['total_size'] ) ? (int) $manifest['total_size'] : 0;
        $current    = isset( $state['cursor'] ) ? (int) $state['cursor'] : 0;

        return [
            'current'         => $current,
            'total'           => $total,
            'percent'         => $total > 0 ? round( ( $current / $total ) * 100, 2 ) : 0,
            'bytes_processed' => isset( $state['bytes_processed'] ) ? (int) $state['bytes_processed'] : 0,
            'total_size'      => $total_size,
        ];
    }

    /* ------------------------------------- */
    /* CLEANUP                               */
    /* ------------------------------------- */

    public function cleanup( bool $remove_output = false ) {
        $files = [
            $this->manifest_file,
            $this->state_file,
            $this->lock_file,
            $this->manifest_file . '.tmp',
            $this->state_file . '.tmp',
        ];

        if ( $remove_output ) {
            $files[] = $this->output;
        }

        foreach ( $files as $file ) {
            if ( file_exists( $file ) ) {
                wp_delete_file( $file );
            }
        }
    }

    public function is_complete(): bool {
        if ( ! file_exists( $this->manifest_file ) ) {
            return false;
        }

        $manifest = json_decode( file_get_contents( $this->manifest_file ), true );
        $state    = $this->load_state();
        $total    = isset( $manifest['total'] ) ? (int) $manifest['total'] : 0;

        return $state['cursor'] >= $total;
    }

    public function get_output_path(): string {
        return $this->output;
    }
}
