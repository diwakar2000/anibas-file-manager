<?php
/**
 * Incremental archive manifest storage.
 *
 * Keeps large scan results out of PHP memory by writing entries as JSONL and
 * storing only summary counters in the manifest JSON.
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Writes and reads archive scan results as a JSONL sidecar file plus a
 * small summary manifest, so building the file list for large archives
 * never has to hold every entry in PHP memory at once.
 */
class ArchiveManifestStore {

    private const WRITE_BUFFER_LINE_LIMIT = 250;

    public static function build_step(
        array $source_paths,
        string $base_path,
        string $manifest_file,
        bool $include_dirs = false,
        array $excluded_dirs = array(),
        ?int $time_budget = null
    ): array {
        if ( file_exists( $manifest_file ) ) {
            $info = self::read_info( $manifest_file );
            $info['complete'] = true;
            return $info;
        }

        $time_budget = $time_budget ?? self::default_time_budget();
        $started     = microtime( true );
        $state_file  = self::build_state_file( $manifest_file );
        $entries_file = self::entries_file( $manifest_file );
        $state       = self::load_build_state( $state_file );

        if ( empty( $state ) ) {
            $state = self::initial_build_state( $source_paths, $base_path, $excluded_dirs );
            $dir = dirname( $manifest_file );
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            if ( file_exists( $entries_file ) ) {
                wp_delete_file( $entries_file );
            }
        }

        $lines = array();

        if ( ! empty( $state['pending_entries'] ) && is_array( $state['pending_entries'] ) ) {
            foreach ( $state['pending_entries'] as $entry ) {
                if ( is_array( $entry ) ) {
                    $lines[] = wp_json_encode( $entry ) . "\n";
                    if ( count( $lines ) >= self::WRITE_BUFFER_LINE_LIMIT ) {
                        self::append_entries( $entries_file, $lines );
                        $lines = array();
                    }
                }
            }
            unset( $state['pending_entries'] );
        }

        while ( ! empty( $state['stack'] ) ) {
            $idx = count( $state['stack'] ) - 1;
            $dir = $state['stack'][ $idx ];
            $path = (string) ( $dir['path'] ?? '' );

            if ( $path === '' || self::is_excluded_path( $path, $state['excluded_dirs'] ) ) {
                array_pop( $state['stack'] );
                continue;
            }

            try {
                $iterator = new \DirectoryIterator( $path );
                if ( ! empty( $dir['position'] ) ) {
                    $iterator->seek( (int) $dir['position'] );
                }
            } catch ( \Throwable $e ) {
                array_pop( $state['stack'] );
                continue;
            }

            $done = true;

            for ( ; $iterator->valid(); $iterator->next() ) {
                if ( $iterator->isDot() ) {
                    $state['stack'][ $idx ]['position'] = (int) $iterator->key() + 1;
                    if ( $time_budget > 0 && microtime( true ) - $started >= $time_budget ) {
                        $done = false;
                        break;
                    }
                    continue;
                }

                $full = wp_normalize_path( $iterator->getPathname() );
                $state['stack'][ $idx ]['position'] = (int) $iterator->key() + 1;

                if ( is_dir( $full ) && ! is_link( $full ) ) {
                    if ( self::is_excluded_path( $full, $state['excluded_dirs'] ) ) {
                        continue;
                    }
                    if ( $include_dirs ) {
                        self::queue_entry( $lines, $state, $full, $state['base_path'], 0, true );
                    }
                    $state['stack'][] = array( 'path' => $full, 'position' => 0 );
                    $done = false;
                    break;
                }

                if ( is_file( $full ) ) {
                    if ( self::is_excluded_path( $full, $state['excluded_dirs'] ) ) {
                        continue;
                    }
                    $size = (int) @filesize( $full );
                    self::queue_entry( $lines, $state, $full, $state['base_path'], $size, false );
                }

                if ( count( $lines ) >= self::WRITE_BUFFER_LINE_LIMIT ) {
                    self::append_entries( $entries_file, $lines );
                    $lines = array();
                }

                if ( $time_budget > 0 && microtime( true ) - $started >= $time_budget ) {
                    $done = false;
                    break;
                }
            }

            if ( $done ) {
                array_pop( $state['stack'] );
            }

            if ( $time_budget > 0 && microtime( true ) - $started >= $time_budget ) {
                break;
            }
        }

        self::append_entries( $entries_file, $lines );

        if ( ! empty( $state['stack'] ) ) {
            self::save_json( $state_file, $state );
            return self::build_response( $state, false );
        }

        $manifest = array(
            'total'          => (int) $state['total'],
            'total_size'     => (int) $state['total_size'],
            'max_file_size'  => (int) $state['max_file_size'],
            'max_file_name'  => (string) $state['max_file_name'],
            'entries_file'   => $entries_file,
            'entries_format' => 'jsonl',
        );
        self::save_json( $manifest_file, $manifest );
        if ( file_exists( $state_file ) ) {
            wp_delete_file( $state_file );
        }

        return self::build_response( $state, true );
    }

    public static function read_manifest( string $manifest_file ): array {
        if ( ! is_file( $manifest_file ) ) {
            return array();
        }
        $manifest = anibas_fm_read_small_json_file( $manifest_file );
        return is_array( $manifest ) ? $manifest : array();
    }

    public static function read_info( string $manifest_file ): array {
        $manifest = self::read_manifest( $manifest_file );
        return array(
            'total'         => (int) ( $manifest['total'] ?? 0 ),
            'total_size'    => (int) ( $manifest['total_size'] ?? 0 ),
            'max_file_size' => (int) ( $manifest['max_file_size'] ?? 0 ),
            'max_file_name' => (string) ( $manifest['max_file_name'] ?? '' ),
        );
    }

    public static function is_valid_manifest( array $manifest ): bool {
        return isset( $manifest['entries'] ) || ! empty( $manifest['entries_file'] );
    }

    public static function current_entry( array $manifest, array &$state ): ?array {
        $cursor = (int) ( $state['cursor'] ?? 0 );

        if ( isset( $manifest['entries'] ) && is_array( $manifest['entries'] ) ) {
            return isset( $manifest['entries'][ $cursor ] ) && is_array( $manifest['entries'][ $cursor ] )
                ? $manifest['entries'][ $cursor ]
                : null;
        }

        if ( isset( $state['current_entry_cursor'], $state['current_entry'] )
            && (int) $state['current_entry_cursor'] === $cursor
            && is_array( $state['current_entry'] ) ) {
            return $state['current_entry'];
        }

        $entries_file = (string) ( $manifest['entries_file'] ?? '' );
        if ( $entries_file === '' || ! is_file( $entries_file ) ) {
            return null;
        }

        $offset = max( 0, (int) ( $state['entry_offset'] ?? 0 ) );
        $fh = @fopen( $entries_file, 'rb' );
        if ( ! $fh ) {
            return null;
        }

        if ( $offset > 0 ) {
            fseek( $fh, $offset );
        }
        $line = fgets( $fh );
        $next = ftell( $fh );
        fclose( $fh );

        if ( $line === false || $line === '' ) {
            return null;
        }

        $entry = json_decode( trim( $line ), true );
        if ( ! is_array( $entry ) ) {
            return null;
        }

        $state['current_entry']        = $entry;
        $state['current_entry_cursor'] = $cursor;
        $state['next_entry_offset']    = $next !== false ? (int) $next : $offset;

        return $entry;
    }

    public static function advance_entry( array $manifest, array &$state ): void {
        $state['cursor'] = (int) ( $state['cursor'] ?? 0 ) + 1;
        if ( ! isset( $manifest['entries'] ) ) {
            $state['entry_offset'] = (int) ( $state['next_entry_offset'] ?? $state['entry_offset'] ?? 0 );
        }
        unset( $state['current_entry'], $state['current_entry_cursor'], $state['next_entry_offset'] );
    }

    public static function cleanup( string $manifest_file ): void {
        $manifest = self::read_manifest( $manifest_file );
        $files = array(
            $manifest_file,
            $manifest_file . '.tmp',
            self::entries_file( $manifest_file ),
            self::entries_file( $manifest_file ) . '.tmp',
            self::build_state_file( $manifest_file ),
            self::build_state_file( $manifest_file ) . '.tmp',
        );
        if ( ! empty( $manifest['entries_file'] ) ) {
            $files[] = (string) $manifest['entries_file'];
            $files[] = (string) $manifest['entries_file'] . '.tmp';
        }
        foreach ( array_unique( $files ) as $file ) {
            if ( is_string( $file ) && $file !== '' && file_exists( $file ) ) {
                wp_delete_file( $file );
            }
        }
    }

    private static function default_time_budget(): int {
        return function_exists( 'anibas_fm_safe_time_budget' )
            ? anibas_fm_safe_time_budget( 20, 0.6 )
            : 20;
    }

    private static function initial_build_state( array $source_paths, string $base_path, array $excluded_dirs ): array {
        $base_path = untrailingslashit( wp_normalize_path( $base_path ) );
        $state = array(
            'base_path'      => $base_path,
            'excluded_dirs'  => self::normalize_excluded_dirs( $excluded_dirs ),
            'stack'          => array(),
            'total'          => 0,
            'total_size'     => 0,
            'max_file_size'  => 0,
            'max_file_name'  => '',
        );

        foreach ( $source_paths as $path ) {
            $path = wp_normalize_path( (string) $path );
            if ( self::is_excluded_path( $path, $state['excluded_dirs'] ) ) {
                continue;
            }
            if ( is_file( $path ) ) {
                $size = (int) @filesize( $path );
                $rel  = self::relative_name( $path, $base_path );
                $entry = array( 'path' => $path, 'name' => $rel, 'size' => $size, 'isdir' => false );
                $state['pending_entries'][] = $entry;
                $state['total']++;
                $state['total_size'] += $size;
                if ( $size > $state['max_file_size'] ) {
                    $state['max_file_size'] = $size;
                    $state['max_file_name'] = $rel;
                }
            } elseif ( is_dir( $path ) ) {
                $state['stack'][] = array( 'path' => $path, 'position' => 0 );
            }
        }

        return $state;
    }

    private static function load_build_state( string $state_file ): array {
        if ( ! file_exists( $state_file ) ) {
            return array();
        }
        $state = anibas_fm_read_small_json_file( $state_file );
        if ( ! is_array( $state ) ) {
            return array();
        }
        if ( ! empty( $state['pending_entries'] ) && is_array( $state['pending_entries'] ) ) {
            return $state;
        }
        return $state;
    }

    private static function queue_entry( array &$lines, array &$state, string $path, string $base_path, int $size, bool $is_dir ): void {
        $rel = self::relative_name( $path, $base_path );
        $entry = array(
            'path'  => $path,
            'name'  => $rel,
            'size'  => $is_dir ? 0 : $size,
            'isdir' => $is_dir,
        );
        $lines[] = wp_json_encode( $entry ) . "\n";
        $state['total']++;
        $state['total_size'] += $is_dir ? 0 : $size;
        if ( ! $is_dir && $size > (int) $state['max_file_size'] ) {
            $state['max_file_size'] = $size;
            $state['max_file_name'] = $rel;
        }
    }

    private static function append_entries( string $entries_file, array $lines ): void {
        if ( empty( $lines ) ) {
            return;
        }
        $dir = dirname( $entries_file );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        file_put_contents( $entries_file, implode( '', $lines ), FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
    }

    private static function build_response( array $state, bool $complete ): array {
        return array(
            'complete'      => $complete,
            'total'         => (int) ( $state['total'] ?? 0 ),
            'total_size'    => (int) ( $state['total_size'] ?? 0 ),
            'max_file_size' => (int) ( $state['max_file_size'] ?? 0 ),
            'max_file_name' => (string) ( $state['max_file_name'] ?? '' ),
        );
    }

    private static function relative_name( string $path, string $base_path ): string {
        $path = wp_normalize_path( $path );
        $base = trailingslashit( untrailingslashit( wp_normalize_path( $base_path ) ) );
        if ( str_starts_with( $path, $base ) ) {
            return ltrim( substr( $path, strlen( $base ) ), '/' );
        }
        return basename( $path );
    }

    private static function normalize_excluded_dirs( array $dirs ): array {
        $out = array();
        foreach ( $dirs as $dir ) {
            $real = realpath( (string) $dir );
            if ( $real ) {
                $out[] = untrailingslashit( wp_normalize_path( $real ) );
            }
        }
        return array_values( array_unique( $out ) );
    }

    private static function is_excluded_path( string $path, array $excluded_dirs ): bool {
        if ( empty( $excluded_dirs ) ) {
            return false;
        }
        $real = realpath( $path );
        if ( ! $real ) {
            return false;
        }
        $real = untrailingslashit( wp_normalize_path( $real ) );
        foreach ( $excluded_dirs as $excluded ) {
            if ( $real === $excluded || str_starts_with( $real . '/', trailingslashit( $excluded ) ) ) {
                return true;
            }
        }
        return false;
    }

    private static function save_json( string $file, array $data ): void {
        $dir = dirname( $file );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $tmp = $file . '.tmp';
        file_put_contents( $tmp, wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
        rename( $tmp, $file );
    }

    private static function entries_file( string $manifest_file ): string {
        return $manifest_file . '.entries.jsonl';
    }

    private static function build_state_file( string $manifest_file ): string {
        return $manifest_file . '.build.json';
    }
}
