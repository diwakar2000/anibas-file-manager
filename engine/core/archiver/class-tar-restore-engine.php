<?php
/**
 * Chunked TAR archive extraction engine.
 *
 * Extracts a standard POSIX TAR (UStar) archive across multiple HTTP requests,
 * respecting max_execution_time and memory limits. Uses flock for concurrency
 * control and atomic state writes for crash safety.
 *
 * TAR is sequential — we read headers one at a time and extract each file's
 * data in chunks. No random access or central directory is needed.
 *
 * Usage:
 *   $engine = TarRestoreEngine::get_instance( $archive, $dest );
 *   $engine->build_manifest();
 *   while ( $engine->run_step() ) { }
 *   $engine->cleanup();
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

use Exception;

class TarRestoreEngine {

    private string $archive;
    private string $dest;

    private string $manifest_file;
    private string $state_file;
    private string $lock_file;

    private int $time_budget;
    private int $chunk_size;

    private static $instances = [];

    /**
     * Get or create an engine instance.
     */
    public static function get_instance( string $archive, string $dest ): self {
        if ( ! file_exists( $archive ) ) {
            throw new Exception( 'TAR file does not exist' );
        }
        if ( ! is_dir( $dest ) ) {
            throw new Exception( 'Destination directory does not exist' );
        }
        $key = md5( $archive . '|' . $dest );
        if ( empty( self::$instances[ $key ] ) ) {
            self::$instances[ $key ] = new self( $archive, $dest );
        }
        return self::$instances[ $key ];
    }

    private function __construct( string $archive, string $dest ) {
        $this->archive = $archive;
        $this->dest    = rtrim( $dest, '/' );

        $this->manifest_file = $this->dest . '/.tar_manifest.json';
        $this->state_file    = $this->dest . '/.tar_state.json';
        $this->lock_file     = $this->dest . '/.tar_lock';

        $max_time = (int) ini_get( 'max_execution_time' );
        $this->time_budget = max( 10, $max_time > 0 ? (int) ( $max_time * 0.6 ) : 20 );

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
            throw new Exception( 'Another restore process is running' );
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
    /* TAR HEADER PARSING                    */
    /* ------------------------------------- */

    /**
     * Parse a 512-byte TAR header block.
     *
     * @param string $block 512-byte raw header.
     * @return array|null Parsed entry or null if end-of-archive.
     */
    private function parse_header( string $block ): ?array {
        // End-of-archive marker: 512 bytes of zeros
        if ( trim( $block, "\0" ) === '' ) {
            return null;
        }

        // Validate UStar magic (optional — some tars don't have it)
        $magic = substr( $block, 257, 5 );

        $name   = rtrim( substr( $block, 0, 100 ), "\0" );
        $prefix = rtrim( substr( $block, 345, 155 ), "\0" );

        // Combine prefix + name for long paths
        if ( $prefix !== '' ) {
            $name = $prefix . '/' . $name;
        }

        $size_octal = trim( substr( $block, 124, 12 ), "\0 " );
        $size       = octdec( $size_octal );

        $type_flag = $block[156];

        // Type: '5' = directory, '0' or "\0" = regular file, 'L' = GNU long name
        $is_dir = ( $type_flag === '5' );

        // Verify header checksum
        $stored_checksum = octdec( trim( substr( $block, 148, 8 ), "\0 " ) );
        $check_block = substr( $block, 0, 148 ) . '        ' . substr( $block, 156 );
        $computed = 0;
        for ( $i = 0; $i < 512; $i++ ) {
            $computed += ord( $check_block[ $i ] );
        }
        if ( $computed !== $stored_checksum ) {
            throw new Exception( 'Corrupt TAR header: checksum mismatch for entry "' . esc_html( $name ) . '"' );
        }

        return [
            'name'      => $name,
            'size'      => (int) $size,
            'is_dir'    => $is_dir,
            'type_flag' => $type_flag,
        ];
    }

    /* ------------------------------------- */
    /* MANIFEST BUILD                        */
    /* ------------------------------------- */

    /**
     * Scan the TAR archive and build a manifest of all entries.
     *
     * Records each entry's name, size, type, and byte offset within the
     * archive for efficient extraction.
     */
    public function build_manifest() {
        if ( file_exists( $this->manifest_file ) ) {
            return;
        }

        $fh      = fopen( $this->archive, 'rb' );
        $entries = [];
        $total_size = 0;

        while ( true ) {
            $header_block = fread( $fh, 512 );
            if ( strlen( $header_block ) < 512 ) {
                break; // Unexpected end
            }

            $entry = $this->parse_header( $header_block );
            if ( $entry === null ) {
                break; // End-of-archive marker
            }

            // Handle GNU long name extension (type 'L')
            if ( $entry['type_flag'] === 'L' ) {
                $long_name_data = fread( $fh, $entry['size'] );
                $long_name = rtrim( $long_name_data, "\0" );
                // Skip padding
                $remainder = $entry['size'] % 512;
                if ( $remainder > 0 ) {
                    fread( $fh, 512 - $remainder );
                }
                // Next header is the actual file with this long name
                $header_block = fread( $fh, 512 );
                if ( strlen( $header_block ) < 512 ) {
                    break;
                }
                $entry = $this->parse_header( $header_block );
                if ( $entry === null ) {
                    break;
                }
                $entry['name'] = $long_name;
            }

            $data_offset = ftell( $fh );

            $entries[] = [
                'n'     => $entry['name'],
                's'     => $entry['size'],
                'd'     => $entry['is_dir'],
                'o'     => $data_offset,
            ];

            if ( ! $entry['is_dir'] ) {
                $total_size += $entry['size'];
            }

            // Skip past file data + padding
            if ( $entry['size'] > 0 ) {
                $data_blocks = (int) ceil( $entry['size'] / 512 ) * 512;
                fseek( $fh, $data_offset + $data_blocks );
            }
        }

        fclose( $fh );

        $tmp = $this->manifest_file . '.tmp';
        file_put_contents( $tmp, json_encode( [
            'total'      => count( $entries ),
            'total_size' => $total_size,
            'entries'    => $entries,
        ] ) );
        rename( $tmp, $this->manifest_file );
    }

    /* ------------------------------------- */
    /* STATE                                 */
    /* ------------------------------------- */

    private function load_state(): array {
        if ( ! file_exists( $this->state_file ) ) {
            return [
                'cursor'      => 0,
                'file_offset' => 0,
            ];
        }
        $data = json_decode( file_get_contents( $this->state_file ), true );
        return is_array( $data ) ? $data : [
            'cursor'      => 0,
            'file_offset' => 0,
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

    /**
     * Validate target path is within destination. Create directories if safe.
     */
    private function safe_path( string $name ): string {
        if ( strpos( $name, '..' ) !== false ) {
            throw new Exception( 'Path traversal attempt: ' . esc_html( $name ) );
        }

        $base   = realpath( $this->dest );
        $target = $this->dest . '/' . $name;
        $dir    = dirname( $target );

        // Walk up to find an existing ancestor for realpath check
        $check_dir = $dir;
        while ( ! is_dir( $check_dir ) && $check_dir !== $this->dest ) {
            $check_dir = dirname( $check_dir );
        }

        $real_ancestor = realpath( $check_dir );
        if ( $real_ancestor === false || strpos( $real_ancestor, $base ) !== 0 ) {
            throw new Exception( 'Path traversal attempt: ' . esc_html( $name ) );
        }

        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }

        return $target;
    }

    /* ------------------------------------- */
    /* MAIN WORKER                           */
    /* ------------------------------------- */

    /**
     * Extract files from the TAR archive in a time-bounded step.
     *
     * Reads file data directly from the archive at the recorded offsets.
     * Supports resuming mid-file across requests.
     *
     * @return bool true if more work remains, false if extraction is complete.
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
                $this->release_lock( $lock );
                return false;
            }

            $fh    = fopen( $this->archive, 'rb' );
            $start = microtime( true );

            while ( $state['cursor'] < $total ) {
                $entry      = $entries[ $state['cursor'] ];
                $name       = $entry['n'];
                $size       = (int) $entry['s'];
                $is_dir     = ! empty( $entry['d'] );
                $data_start = (int) $entry['o'];

                // Directories: just create them
                if ( $is_dir ) {
                    $target = $this->safe_path( $name );
                    if ( ! is_dir( $target ) ) {
                        mkdir( $target, 0755, true );
                    }
                    $state['cursor']++;
                    $state['file_offset'] = 0;
                    $this->save_state( $state );

                    if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                        fclose( $fh );
                        $this->release_lock( $lock );
                        return true;
                    }
                    continue;
                }

                // Regular file: extract data
                $target = $this->safe_path( $name );

                $is_resume = ( $state['file_offset'] > 0 );
                $out = fopen( $target, $is_resume ? 'ab' : 'wb' );

                // Seek archive to correct position (data_start + already-written offset)
                fseek( $fh, $data_start + $state['file_offset'] );

                $remaining = $size - $state['file_offset'];

                while ( $remaining > 0 ) {
                    $to_read = min( $remaining, $this->chunk_size );
                    $chunk   = fread( $fh, $to_read );

                    if ( $chunk === false || strlen( $chunk ) === 0 ) {
                        break;
                    }

                    fwrite( $out, $chunk );
                    $bytes_read = strlen( $chunk );
                    $state['file_offset'] += $bytes_read;
                    $remaining            -= $bytes_read;

                    // Check time budget after each chunk
                    if ( ( microtime( true ) - $start ) > $this->time_budget ) {
                        fclose( $out );
                        fclose( $fh );
                        $this->save_state( $state );
                        $this->release_lock( $lock );
                        return true;
                    }
                }

                fclose( $out );

                // File complete — advance cursor
                $state['cursor']++;
                $state['file_offset'] = 0;
                $this->save_state( $state );
            }

            fclose( $fh );
            $this->release_lock( $lock );
            return false;

        } catch ( Exception $e ) {
            $this->release_lock( $lock );
            throw $e;
        }
    }

    /* ------------------------------------- */
    /* PROGRESS                              */
    /* ------------------------------------- */

    public function progress(): array {
        if ( ! file_exists( $this->manifest_file ) ) {
            return [ 'current' => 0, 'total' => 0, 'percent' => 0 ];
        }

        $manifest = json_decode( file_get_contents( $this->manifest_file ), true );
        $state    = $this->load_state();
        $total    = isset( $manifest['total'] ) ? (int) $manifest['total'] : 0;
        $current  = isset( $state['cursor'] ) ? (int) $state['cursor'] : 0;

        return [
            'current' => $current,
            'total'   => $total,
            'percent' => $total > 0 ? round( ( $current / $total ) * 100, 2 ) : 0,
        ];
    }

    /* ------------------------------------- */
    /* CLEANUP                               */
    /* ------------------------------------- */

    public function cleanup() {
        $files = [
            $this->manifest_file,
            $this->state_file,
            $this->lock_file,
            $this->manifest_file . '.tmp',
            $this->state_file . '.tmp',
        ];
        foreach ( $files as $f ) {
            if ( file_exists( $f ) ) {
                wp_delete_file( $f );
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
}
