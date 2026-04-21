<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'anibas_fm_fetch_request_variable' ) ) {
    function anibas_fm_fetch_request_variable( $from = 'request', $key = false, $default = null ) {
        if ( 'get' === $from ) {
            $input = $_GET;
        } elseif ( 'post' === $from ) {
            $input = $_POST;
        } else {
            $input = $_REQUEST;
        }

        if ( empty( $input ) ) {
            $raw = file_get_contents('php://input');
            $input = json_decode( $raw, true );
            if ( ! is_array( $input ) ) {
                $input = array();
            }
        }

        if ( false === $key ) {
            return $input;
        }

        if ( isset( $input[ $key ] ) ) {
            $value = $input[ $key ];
            // Sanitize based on type
            if ( is_string( $value ) ) {
                return sanitize_text_field( wp_unslash( $value ) );
            }
            return $value;
        }

        return $default;
    }
}

if ( ! function_exists( 'anibas_fm_is_development_site' ) ) {
    /**
     * Check if the current environment is a local or sandbox development site.
     *
     * @return bool
     */
    function anibas_fm_is_development_site() {
        $server_name = isset( $_SERVER['SERVER_NAME'] ) ? strtolower( $_SERVER['SERVER_NAME'] ) : '';
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';

        $local_hosts = array( 'localhost', '127.0.0.1', '::1' );
        $dev_tlds = [
            // Standard Local TLDs (RFC 2606)
            '.test',
            '.localhost',
            '.invalid',
            '.example',

            // Popular Local Dev Docker Environments
            '.local',       // LocalWP (Common)
            '.ddev.site',   // DDEV
            '.lndo.site',   // Lando
            '.nitro',       // Nitro

            // Temporary Tunnels (Often used to share local dev)
            '.ngrok.io',
            '.ngrok-free.app',
            '.loca.lt',     // LocalTunnel

            // Instant Sandbox Platforms
            '.instawp.com',
            '.instawp.site',
            '.instawp.xyz',
            '.s3-tastewp.com',
            '.wpsandbox.pro',

            // Common Managed Hosting Staging Domains (Optional, but useful)
            '.wpengine.com',
            '.pantheonsite.io',
            '.mykinsta.com',
            '.kinsta.cloud',
            '.flywheelstaging.com',
            '.cloudwaysapps.com'
        ];

        if ( in_array( $server_name, $local_hosts, true ) || in_array( $remote_addr, $local_hosts, true ) ) {
            return true;
        }

        foreach ( $dev_tlds as $tld ) {
            if ( substr( $server_name, -strlen( $tld ) ) === $tld || $server_name === trim( $tld, '.' ) ) {
                return true;
            }
        }

        // Check for private IP ranges (192.168.x.x, 10.x.x.x, 172.16-31.x.x)
        if ( ! empty( $remote_addr ) && ! filter_var( $remote_addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return true;
        }

        return false;
    }
}

if ( ! function_exists( 'anibas_fm_get_blocked_paths' ) ) {
    /**
     * Get immutable hardcoded blocked paths.
     *
     * @return array
     */
    function anibas_fm_get_blocked_paths() {
        $paths = array(
            // WordPress Core
            'wp-admin',
            'wp-includes',
            'wp-config.php',
            
            // Server Configuration
            '.htaccess',
            'nginx.conf',
            '.user.ini',
            'php.ini',
            'web.config',
            
            // Version Control
            '.git',
            '.svn',
            '.hg',
            '.bzr',
            
            // Environment & Secrets
            '.env',
            '.env.local',
            '.env.production',
            
            // This Plugin
            untrailingslashit(substr(ANIBAS_FILE_MANAGER_PLUGIN_DIR, strlen(ABSPATH))),
            
            // Database & Backups
            'wp-content/backup-db',
            'wp-content/backups',
            
            // Logs
            'error_log',
            'debug.log',
            'wp-content/debug.log',
        );

        if ( ! anibas_fm_is_development_site() || ! (bool) anibas_fm_get_option( 'debug_mode', false ) ) {
            $log_dir = get_option( 'anibas_file_manager_log_dir' );
            if ( ! empty( $log_dir ) ) {
                $paths[] = trim( anibas_fm_convert_to_relative_path( $log_dir ), '/' );
            }
        }

        return $paths;
    }
}

if ( ! function_exists( 'anibas_fm_exclude_paths' ) ) {
    /**
     * Get user excluded paths.
     *
     * @return array
     */
    function anibas_fm_exclude_paths() {

        $paths = anibas_fm_get_option( 'excluded_paths', array() );

        if ( ! is_array( $paths ) ) {
            return array();
        }

        return array_values(
            array_map(
                'untrailingslashit',
                array_filter( $paths )
            )
        );
    }
}



if ( ! function_exists( 'anibas_fm_get_option' ) ) {
    /**
     * Get option value.
     *
     * @param string|null $key     Option key
     * @param mixed       $default Default value
     *
     * @return mixed
     */
    function anibas_fm_get_option( $key = null, $default = false ) {
        $options = get_option( 'AnibasFileManagerOptions', array() );

        if ( ! is_array( $options ) ) {
            $options = array();
        }

        if ( $key === null ) {
            return $options;
        }

        return array_key_exists( $key, $options ) 
            ? $options[ $key ] 
            : $default;
    }
}

if ( ! function_exists( 'anibas_fm_update_option' ) ) {
    function anibas_fm_update_option( $key, $value = null ) {
        $options = get_option( 'AnibasFileManagerOptions', array() );

        if ( ! is_array( $options ) ) {
            $options = array();
        }

        if ( is_array( $key ) ) {
            // Merge multiple options
            $options = array_merge( $options, $key );
        } else {
            $options[ $key ] = $value;
        }

        return update_option( 'AnibasFileManagerOptions', $options );
    }
}

if ( ! function_exists( 'anibas_fm_is_a_file_zip') ) {
    /**
     * Check if a file is a ZIP archive.
     *
     * @param string $file Path to the file
     * @return bool True if the file is a ZIP archive, false otherwise
     */
    function anibas_fm_is_a_file_zip( $file ) : bool {
        if ( ! is_file( $file ) ) { return false; }

        $fh = fopen($file, 'rb');
        if ( ! $fh ) {
            return false;
        }

        $bytes = fread( $fh, 4 );
        fclose( $fh );

        $signatures = [
            "\x50\x4B\x03\x04",
            "\x50\x4B\x05\x06",
            "\x50\x4B\x07\x08"
        ];

        if (in_array($bytes, $signatures, true) ) {
            $zip = new ZipArchive();
            if ( $zip->open($file) === TRUE ) {
                $zip->close();
                return true;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'anibas_fm_zip_integrity_test') ) {
    /**
     * Check the integrity of a ZIP file by reading every file in the archive.
     *
     * This function opens a ZIP file and checks every file in the archive by reading its contents.
     * If any file is unreadable, the function returns false. Otherwise, it returns true.
     *
     * @param string $file Path to the ZIP file
     * @return bool True if the ZIP file is intact, false otherwise
     */
    function anibas_fm_zip_integrity_test( string $file ): bool {
        $zip = new ZipArchive();

        if ( $zip->open( $file ) !== TRUE ) {
            return false;
        }

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {

            $stat = $zip->statIndex( $i );

            if ( substr( $stat['name'], -1 ) === '/' ) {
                continue;
            }

            $stream = $zip->getStream( $stat['name'] );

            if ( ! $stream ) {
                $zip->close();
                return false;
            }

            while ( ! feof( $stream ) ) {
                fread( $stream, 8192 );
            }

            fclose( $stream );
        }

        $zip->close();

        return true;
    }
}

if ( ! function_exists( 'anibas_fm_create_log_file_path' ) ) {
    function anibas_fm_create_log_file_path(): string {
        $log_dir = get_option( 'anibas_file_manager_log_dir' );
        if ( ! $log_dir ) {
            // Create log directory with protection files
            $log_dir = WP_CONTENT_DIR . '/anibas-logs-' . random_int( 1000000000000000, 9999999999999999 );
            
            if ( ! file_exists( $log_dir ) ) {
                wp_mkdir_p( $log_dir );
            }

            update_option( 'anibas_file_manager_log_dir', $log_dir );
        }
		
		// Protect directory from direct access
		if ( ! file_exists( $log_dir . '/.htaccess' ) ) {
			file_put_contents( $log_dir . '/.htaccess', 'Deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- called before WP_Filesystem is available
		}

		if ( ! file_exists( $log_dir . '/index.php' ) ) {
			file_put_contents( $log_dir . '/index.php', "<?php\n// Silence is golden\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- called before WP_Filesystem is available
		}
        return $log_dir;
    }
}

if ( ! function_exists( 'anibas_fm_get_log_file_path' ) ) {
    function anibas_fm_get_log_file_path(): string {
        $log_dir = get_option( 'anibas_file_manager_log_dir' );

        if ( ! $log_dir ) {
            $log_dir = anibas_fm_create_log_file_path();
        }

        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            // Protect directory from direct access
            file_put_contents( $log_dir . '/.htaccess', 'Deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- called before WP_Filesystem is available
            file_put_contents( $log_dir . '/index.php', "<?php\n// Silence is golden\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- called before WP_Filesystem is available
        }

        return $log_dir;
    }
}

if ( ! function_exists( 'anibas_fm_convert_to_relative_path' ) ) {
    /**
     * Convert absolute path to relative path from WordPress root.
     *
     * @param string $absolute_path The absolute file system path
     * @return string The relative path from WordPress root
     */
    function anibas_fm_convert_to_relative_path( $absolute_path ) {
        if ( empty( $absolute_path ) ) {
            return '';
        }
        
        // Get WordPress root path
        $wp_root = realpath( ABSPATH );
        $abs_path = realpath( $absolute_path );
        
        // If paths don't exist or can't be resolved, return as-is
        if ( ! $wp_root || ! $abs_path ) {
            return $absolute_path;
        }
        
        // Convert to forward slashes for consistency
        $wp_root = str_replace( '\\', '/', $wp_root );
        $abs_path = str_replace( '\\', '/', $abs_path );
        
        // Remove trailing slash from root for comparison
        $wp_root = rtrim( $wp_root, '/' );
        
        // If the path is within WordPress root, make it relative
        if ( strpos( $abs_path, $wp_root ) === 0 ) {
            $relative = ltrim( substr( $abs_path, strlen( $wp_root ) ), '/' );
            return $relative === '' ? '/' : '/' . $relative;
        }
        
        // If path is outside WordPress root, return as-is (shouldn't happen with proper validation)
        return $absolute_path;
    }
}

if ( ! function_exists( 'anibas_fm_convert_paths_in_job_data' ) ) {
    /**
     * Convert absolute paths to relative paths in job data arrays.
     *
     * @param array $job_data The job data array
     * @return array The job data with converted paths
     */
    function anibas_fm_convert_paths_in_job_data( $job_data ) {
        if ( ! is_array( $job_data ) ) {
            return $job_data;
        }
        
        // Convert source path if present
        if ( isset( $job_data['source'] ) ) {
            $job_data['source'] = anibas_fm_convert_to_relative_path( $job_data['source'] );
        }
        
        // Convert destination path if present
        if ( isset( $job_data['destination'] ) ) {
            $job_data['destination'] = anibas_fm_convert_to_relative_path( $job_data['destination'] );
        }
        
        // Convert source_root if present
        if ( isset( $job_data['source_root'] ) ) {
            $job_data['source_root'] = anibas_fm_convert_to_relative_path( $job_data['source_root'] );
        }
        
        // Convert dest_root if present
        if ( isset( $job_data['dest_root'] ) ) {
            $job_data['dest_root'] = anibas_fm_convert_to_relative_path( $job_data['dest_root'] );
        }
        
        return $job_data;
    }
}

if ( ! function_exists( 'anibas_fm_get_trash_dir' ) ) {
    /**
     * Get the absolute path to the .trash directory inside wp-content/uploads.
     * Creates it if it does not exist.
     *
     * @return string Absolute path to the trash directory.
     */
    function anibas_fm_get_trash_dir(): string {
        $upload_dir = wp_upload_dir();
        $trash_dir  = $upload_dir['basedir'] . '/' . ANIBAS_FM_TRASH_DIR_NAME;

        if ( ! is_dir( $trash_dir ) ) {
            wp_mkdir_p( $trash_dir );
            // Prevent direct web access
            @file_put_contents( $trash_dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
            @file_put_contents( $trash_dir . '/index.php', "<?php\n// Silence is golden\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
        }

        return $trash_dir;
    }
}

if ( ! function_exists( 'anibas_fm_trash_enabled' ) ) {
    /**
     * Whether deleted files should be moved to trash instead of permanent deletion.
     *
     * @return bool
     */
    function anibas_fm_trash_enabled(): bool {
        return (bool) anibas_fm_get_option( 'delete_to_trash', false );
    }
}

if ( ! function_exists( 'anibas_fm_purge_trash' ) ) {
    /**
     * Delete trash items older than ANIBAS_FM_TRASH_MAX_AGE.
     * Runs on the daily cron regardless of the trash setting — if the .trash
     * directory exists we clean it.
     */
    function anibas_fm_purge_trash(): void {
        $upload_dir = wp_upload_dir();
        $trash_dir  = $upload_dir['basedir'] . '/' . ANIBAS_FM_TRASH_DIR_NAME;

        if ( ! is_dir( $trash_dir ) ) {
            return;
        }

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;

        // Load the index ledger
        $index_file = $trash_dir . '/index.json';
        $index = [];
        if ( file_exists( $index_file ) ) {
            $raw = file_get_contents( $index_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ( $raw ) {
                $index = json_decode( $raw, true ) ?: [];
            }
        }

        $now      = time();
        $modified = false;
        $iterator = new \DirectoryIterator( $trash_dir );

        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) continue;
            $name = $item->getFilename();

            // Skip protection and ledger files
            if ( $name === '.htaccess' || $name === 'index.php' || $name === 'index.json' ) continue;

            // Prefer trashed_at from ledger; fall back to mtime
            if ( isset( $index[ $name ]['trashed_at'] ) ) {
                $timestamp = intval( $index[ $name ]['trashed_at'] );
            } else {
                $parts     = explode( '_', $name, 2 );
                $timestamp = isset( $parts[0] ) && is_numeric( $parts[0] ) ? intval( $parts[0] ) : $item->getMTime();
            }

            if ( ( $now - $timestamp ) >= ANIBAS_FM_TRASH_MAX_AGE ) {
                $wp_filesystem->delete( $item->getPathname(), true );
                if ( isset( $index[ $name ] ) ) {
                    unset( $index[ $name ] );
                    $modified = true;
                }
            }
        }

        // Persist the updated index if we removed entries
        if ( $modified ) {
            file_put_contents( $index_file, wp_json_encode( $index ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
        }
    }

    function anibas_fm_purge_temp(): void {
        $upload_dir = wp_upload_dir();
        $temp_dir   = $upload_dir['basedir'] . '/anibas_fm_temp';

        if ( ! is_dir( $temp_dir ) ) {
            return;
        }

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;

        $now = time();
        $iterator = new \DirectoryIterator( $temp_dir );

        $has_active_items = false;

        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) continue;

            if ( ( $now - $item->getMTime() ) >= ANIBAS_FM_TEMP_MAX_AGE ) {
                $wp_filesystem->delete( $item->getPathname(), true );
            } else {
                $has_active_items = true;
            }
        }

        if ( ! $has_active_items ) {
            $wp_filesystem->delete( $temp_dir, true );
        }
    }
}

// Register the cron callback (always, so it fires if scheduled)
add_action( ANIBAS_FM_TRASH_CRON_HOOK, 'anibas_fm_purge_trash' );
add_action( ANIBAS_FM_TEMP_CRON_HOOK, 'anibas_fm_purge_temp' );
add_action( ANIBAS_FM_BACKUP_CRON_HOOK, 'anibas_fm_purge_old_backups' );

/* =========================================================
   BACKUP HELPERS
========================================================= */

if ( ! function_exists( 'anibas_fm_get_backup_dir' ) ) {
    /**
     * Get the absolute path to the secure backup directory inside WP_CONTENT_DIR.
     * Uses a hidden random-suffix directory name (like .anibas-backups-{random}).
     * Creates it if it does not exist.
     *
     * @return string Absolute path to the backup directory.
     */
    function anibas_fm_get_backup_dir() {
        $backup_dir = get_option( 'anibas_file_manager_backup_dir' );

        if ( ! $backup_dir ) {
            // Create backup directory with hidden random-suffix name
            $backup_dir = WP_CONTENT_DIR . '/.anibas-backups-' . random_int( 1000000000000000, 9999999999999999 );
            update_option( 'anibas_file_manager_backup_dir', $backup_dir );
        }

        if ( ! is_dir( $backup_dir ) ) {
            wp_mkdir_p( $backup_dir );
        }

        // Protect directory from direct web access
        if ( ! file_exists( $backup_dir . '/.htaccess' ) ) {
            @file_put_contents( $backup_dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
        }
        if ( ! file_exists( $backup_dir . '/index.php' ) ) {
            @file_put_contents( $backup_dir . '/index.php', "<?php\n// Silence is golden\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
        }

        return $backup_dir;
    }
}

if ( ! function_exists( 'anibas_fm_is_backup_running' ) ) {
    /**
     * Check whether a backup operation is currently in progress.
     *
     * @return bool
     */
    function anibas_fm_is_backup_running() {
        $lock = get_transient( ANIBAS_FM_BACKUP_LOCK_KEY );
        return ! empty( $lock );
    }
}

if ( ! function_exists( 'anibas_fm_get_backup_lock' ) ) {
    /**
     * Get the current backup lock data.
     *
     * @return array|false Lock data array or false if no backup running.
     */
    function anibas_fm_get_backup_lock() {
        $lock = get_transient( ANIBAS_FM_BACKUP_LOCK_KEY );
        return is_array( $lock ) ? $lock : false;
    }
}

if ( ! function_exists( 'anibas_fm_set_backup_lock' ) ) {
    /**
     * Set the backup lock transient.
     *
     * @param string $job_id   Backup job ID.
     * @param string $format   Archive format (tar or anfm).
     * @param string $output   Output filename.
     */
    function anibas_fm_set_backup_lock( $job_id, $format, $output ) {
        set_transient( ANIBAS_FM_BACKUP_LOCK_KEY, array(
            'job_id'     => $job_id,
            'format'     => $format,
            'output'     => $output,
            'started_at' => time(),
        ), 2 * HOUR_IN_SECONDS ); // Auto-expire after 2 hours (safety net)
    }
}

if ( ! function_exists( 'anibas_fm_clear_backup_lock' ) ) {
    /**
     * Clear the backup lock transient.
     */
    function anibas_fm_clear_backup_lock() {
        delete_transient( ANIBAS_FM_BACKUP_LOCK_KEY );
    }
}

if ( ! function_exists( 'anibas_fm_get_backup_scope' ) ) {
    /**
     * Get the list of absolute paths to include in a site backup.
     *
     * @return array Array of absolute file/directory paths.
     */
    function anibas_fm_get_backup_scope() {
        $root  = untrailingslashit( ABSPATH );
        $paths = array();

        // Always include wp-content
        $wp_content = realpath( WP_CONTENT_DIR );
        if ( $wp_content ) {
            $paths[] = $wp_content;
        }

        // Include select root-level files if they exist and are readable
        $root_files = array(
            '.htaccess',
            'index.php',
            'wp-cron.php',
            'robots.txt',
        );

        foreach ( $root_files as $file ) {
            $full = $root . '/' . $file;
            if ( file_exists( $full ) && is_readable( $full ) ) {
                $paths[] = $full;
            }
        }

        return $paths;
    }
}

if ( ! function_exists( 'anibas_fm_get_file_backups_dir' ) ) {
    /**
     * Get the per-file edit backup subdirectory (under the main backup dir).
     * Creates it if it does not exist.
     *
     * @return string
     */
    function anibas_fm_get_file_backups_dir() {
        $dir = anibas_fm_get_backup_dir() . '/file-backups';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }
}

if ( ! function_exists( 'anibas_fm_prepare_file_backup_target' ) ) {
    /**
     * Resolve (and create) the target file for a per-source backup. Each source
     * file gets a deterministic subfolder so the rolling-window cleanup can
     * keep the N newest versions without path collisions. Trims older versions
     * to leave room for the new one.
     *
     * @param string $storage      Storage id (e.g. 'local', 's3').
     * @param string $source_path  Absolute source path.
     * @return string|null         Absolute destination path, or null on failure.
     */
    function anibas_fm_prepare_file_backup_target( $storage, $source_path ) {
        $key     = anibas_fm_file_backup_key( $storage, $source_path );
        $src_dir = anibas_fm_get_file_backups_dir() . '/' . $key;
        if ( ! is_dir( $src_dir ) ) {
            wp_mkdir_p( $src_dir );
        }
        $marker = $src_dir . '/.source';
        if ( ! file_exists( $marker ) ) {
            @file_put_contents( $marker, $storage . '|' . $source_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
        }

        // Leave room for the version we are about to write — trim to keep-1.
        $keep = defined( 'ANIBAS_FM_FILE_BACKUP_KEEP' ) ? (int) ANIBAS_FM_FILE_BACKUP_KEEP : 5;
        $keep = max( 1, $keep ) - 1;

        $versions = array();
        foreach ( new DirectoryIterator( $src_dir ) as $item ) {
            if ( $item->isDot() || ! $item->isFile() ) continue;
            if ( $item->getFilename() === '.source' ) continue;
            $versions[] = array( 'path' => $item->getPathname(), 'mtime' => $item->getMTime() );
        }
        if ( count( $versions ) > $keep ) {
            usort( $versions, function ( $a, $b ) { return $b['mtime'] - $a['mtime']; } );
            foreach ( array_slice( $versions, $keep ) as $v ) {
                @unlink( $v['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }

        return $src_dir . '/' . gmdate( 'Y-m-d_His' ) . '__' . basename( $source_path );
    }
}

if ( ! function_exists( 'anibas_fm_secret_key' ) ) {
    /**
     * Derive a symmetric 32-byte key from WordPress salts for encrypting
     * sensitive plugin-stored values (e.g. remote storage credentials).
     *
     * The key is stable for the lifetime of the site's AUTH_KEY/SECURE_AUTH_SALT,
     * so rotating those WP salts will invalidate previously encrypted blobs —
     * which is the intended behaviour (credentials get re-entered).
     */
    function anibas_fm_secret_key() {
        return hash( 'sha256', wp_salt( 'auth' ) . '|anibas-fm-creds', true );
    }
}

if ( ! function_exists( 'anibas_fm_encrypt_value' ) ) {
    /**
     * Encrypt a short string with AES-256-GCM. Returns a base64 blob with
     * an "afm1:" prefix so decrypt can detect already-encrypted values.
     * Returns the input unchanged on failure (non-blocking best effort).
     *
     * @param string $plaintext
     * @return string
     */
    function anibas_fm_encrypt_value( $plaintext ) {
        if ( ! is_string( $plaintext ) || $plaintext === '' ) {
            return $plaintext;
        }
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return $plaintext;
        }
        $iv  = random_bytes( 12 );
        $tag = '';
        $ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', anibas_fm_secret_key(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( $ct === false ) {
            return $plaintext;
        }
        return 'afm1:' . base64_encode( $iv . $tag . $ct );
    }
}

if ( ! function_exists( 'anibas_fm_decrypt_value' ) ) {
    /**
     * Decrypt a value produced by anibas_fm_encrypt_value(). Pass-through if
     * the value does not carry the "afm1:" prefix (legacy/plaintext values
     * written before encryption was introduced stay readable).
     *
     * @param mixed $value
     * @return mixed
     */
    function anibas_fm_decrypt_value( $value ) {
        if ( ! is_string( $value ) || strpos( $value, 'afm1:' ) !== 0 ) {
            return $value;
        }
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return $value;
        }
        $raw = base64_decode( substr( $value, 5 ), true );
        if ( $raw === false || strlen( $raw ) < 28 ) {
            return $value;
        }
        $iv  = substr( $raw, 0, 12 );
        $tag = substr( $raw, 12, 16 );
        $ct  = substr( $raw, 28 );
        $pt  = openssl_decrypt( $ct, 'aes-256-gcm', anibas_fm_secret_key(), OPENSSL_RAW_DATA, $iv, $tag );
        return $pt === false ? $value : $pt;
    }
}

if ( ! function_exists( 'anibas_fm_remote_secret_fields' ) ) {
    /**
     * Fields within a remote-storage connection that should be encrypted at rest.
     */
    function anibas_fm_remote_secret_fields() {
        return array( 'password', 'secret_key', 'private_key' );
    }
}

if ( ! function_exists( 'anibas_fm_encrypt_remote_settings' ) ) {
    /**
     * Encrypt secret fields inside the remote_connections option structure.
     *
     * @param array $settings
     * @return array
     */
    function anibas_fm_encrypt_remote_settings( $settings ) {
        if ( ! is_array( $settings ) ) {
            return array();
        }
        $secret_fields = anibas_fm_remote_secret_fields();
        foreach ( $settings as $storage => $conn ) {
            if ( ! is_array( $conn ) ) continue;
            foreach ( $secret_fields as $f ) {
                if ( isset( $conn[ $f ] ) && is_string( $conn[ $f ] ) && $conn[ $f ] !== '' ) {
                    $settings[ $storage ][ $f ] = anibas_fm_encrypt_value( $conn[ $f ] );
                }
            }
        }
        return $settings;
    }
}

if ( ! function_exists( 'anibas_fm_decrypt_remote_settings' ) ) {
    /**
     * Decrypt secret fields inside the remote_connections option structure.
     *
     * @param array $settings
     * @return array
     */
    function anibas_fm_decrypt_remote_settings( $settings ) {
        if ( ! is_array( $settings ) ) {
            return array();
        }
        $secret_fields = anibas_fm_remote_secret_fields();
        foreach ( $settings as $storage => $conn ) {
            if ( ! is_array( $conn ) ) continue;
            foreach ( $secret_fields as $f ) {
                if ( isset( $conn[ $f ] ) && is_string( $conn[ $f ] ) ) {
                    $settings[ $storage ][ $f ] = anibas_fm_decrypt_value( $conn[ $f ] );
                }
            }
        }
        return $settings;
    }
}

if ( ! function_exists( 'anibas_fm_get_remote_settings' ) ) {
    /**
     * Load & decrypt the saved remote-storage connection settings.
     */
    function anibas_fm_get_remote_settings() {
        $raw = get_option( 'anibas_fm_remote_connections', array() );
        return anibas_fm_decrypt_remote_settings( $raw );
    }
}

if ( ! function_exists( 'anibas_fm_sanitize_remote_settings' ) ) {
    /**
     * Whitelist-validate the remote-connection settings before persisting.
     * Strips unknown keys and coerces known ones to strict types.
     * Preserves existing encrypted secrets when the client sends an empty
     * string for a secret field (so the UI can omit untouched fields).
     *
     * @param mixed $input Decoded JSON from the client.
     * @return array
     */
    function anibas_fm_sanitize_remote_settings( $input ) {
        if ( ! is_array( $input ) ) {
            return array();
        }

        $existing = get_option( 'anibas_fm_remote_connections', array() );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        $allowed = array(
            'ftp'           => array( 'enabled', 'host', 'port', 'username', 'password', 'base_path', 'use_ssl', 'insecure_ssl', 'is_passive' ),
            'sftp'          => array( 'enabled', 'host', 'port', 'username', 'password', 'private_key', 'base_path' ),
            's3'            => array( 'enabled', 'access_key', 'secret_key', 'region', 'bucket', 'prefix', 'chunk_size' ),
            's3_compatible' => array( 'enabled', 'access_key', 'secret_key', 'region', 'endpoint', 'bucket', 'prefix', 'path_style', 'chunk_size' ),
        );
        $bool_keys = array( 'enabled', 'use_ssl', 'insecure_ssl', 'path_style', 'is_passive' );
        $int_keys  = array( 'port', 'chunk_size' );

        $out = array();
        foreach ( $allowed as $storage => $keys ) {
            if ( ! isset( $input[ $storage ] ) || ! is_array( $input[ $storage ] ) ) {
                continue;
            }
            $row = array();
            foreach ( $keys as $k ) {
                if ( ! array_key_exists( $k, $input[ $storage ] ) ) continue;
                $v = $input[ $storage ][ $k ];
                if ( in_array( $k, $bool_keys, true ) ) {
                    $row[ $k ] = (bool) $v;
                } elseif ( in_array( $k, $int_keys, true ) ) {
                    $row[ $k ] = (int) $v;
                } else {
                    $row[ $k ] = is_scalar( $v ) ? (string) $v : '';
                }
            }

            // Preserve previously stored secrets when the client sends empty.
            foreach ( anibas_fm_remote_secret_fields() as $sf ) {
                if ( in_array( $sf, $keys, true )
                    && ( ! isset( $row[ $sf ] ) || $row[ $sf ] === '' )
                    && isset( $existing[ $storage ][ $sf ] ) ) {
                    $row[ $sf ] = $existing[ $storage ][ $sf ];
                }
            }

            $out[ $storage ] = $row;
        }

        return anibas_fm_encrypt_remote_settings( $out );
    }
}

if ( ! function_exists( 'anibas_fm_has_recent_file_backup' ) ) {
    /**
     * Whether a version file for (storage, source_path) exists younger than
     * $max_age_seconds. Used to de-duplicate the "Backup before edit" click
     * and the save-time auto-backup — they otherwise capture the same content
     * within the same session and produce identical snapshots.
     *
     * @param string $storage         Storage id.
     * @param string $source_path     Absolute source path.
     * @param int    $max_age_seconds Versions younger than this count as recent.
     * @return bool
     */
    function anibas_fm_has_recent_file_backup( $storage, $source_path, $max_age_seconds = 30 ) {
        $key     = anibas_fm_file_backup_key( $storage, $source_path );
        $src_dir = anibas_fm_get_file_backups_dir() . '/' . $key;
        if ( ! is_dir( $src_dir ) ) {
            return false;
        }
        $now = time();
        foreach ( new DirectoryIterator( $src_dir ) as $item ) {
            if ( $item->isDot() || ! $item->isFile() ) continue;
            if ( $item->getFilename() === '.source' ) continue;
            if ( $now - $item->getMTime() < $max_age_seconds ) {
                return true;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'anibas_fm_file_backup_key' ) ) {
    /**
     * Stable, filesystem-safe folder name identifying a (storage,path) pair.
     * Each source file has its own subfolder holding up to N timestamped versions.
     *
     * @param string $storage  Storage id (e.g. 'local', 's3', 'ftp').
     * @param string $path     The source file path.
     * @return string          32-char hex identifier.
     */
    function anibas_fm_file_backup_key( $storage, $path ) {
        return md5( $storage . '|' . $path );
    }
}

if ( ! function_exists( 'anibas_fm_purge_old_backups' ) ) {
    /**
     * Cleanup backups on the daily cron hook.
     *
     * - Whole-site archives at the backup dir root: age-based purge.
     * - Per-file edit backups under file-backups/<key>/: keep last
     *   ANIBAS_FM_FILE_BACKUP_KEEP versions per source path.
     */
    function anibas_fm_purge_old_backups() {
        $backup_dir = anibas_fm_get_backup_dir();

        if ( ! is_dir( $backup_dir ) ) {
            return;
        }

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;

        $now      = time();
        $iterator = new DirectoryIterator( $backup_dir );

        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) continue;

            $name = $item->getFilename();
            if ( $name === '.htaccess' || $name === 'index.php' ) continue;
            if ( $name === 'file-backups' ) continue;

            $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, array( 'tar', 'anfm' ), true ) ) continue;

            if ( ( $now - $item->getMTime() ) >= ANIBAS_FM_BACKUP_MAX_AGE ) {
                $wp_filesystem->delete( $item->getPathname(), false );
            }
        }

        $file_backup_dir = $backup_dir . '/file-backups';
        if ( is_dir( $file_backup_dir ) ) {
            $keep = defined( 'ANIBAS_FM_FILE_BACKUP_KEEP' ) ? (int) ANIBAS_FM_FILE_BACKUP_KEEP : 5;
            $per_source = new DirectoryIterator( $file_backup_dir );
            foreach ( $per_source as $src_dir ) {
                if ( $src_dir->isDot() || ! $src_dir->isDir() ) continue;

                $src_path = $src_dir->getPathname();
                $versions = array();
                foreach ( new DirectoryIterator( $src_path ) as $ver ) {
                    if ( $ver->isDot() || ! $ver->isFile() ) continue;
                    if ( $ver->getFilename() === '.source' ) continue;
                    $versions[] = array( 'path' => $ver->getPathname(), 'mtime' => $ver->getMTime() );
                }

                usort( $versions, function ( $a, $b ) { return $b['mtime'] - $a['mtime']; } );
                $to_remove = array_slice( $versions, $keep );
                foreach ( $to_remove as $v ) {
                    $wp_filesystem->delete( $v['path'], false );
                }

                $remaining = glob( $src_path . '/*' );
                if ( is_array( $remaining ) && count( $remaining ) === 0 ) {
                    $wp_filesystem->rmdir( $src_path );
                } elseif ( is_array( $remaining ) && count( $remaining ) === 1 && basename( $remaining[0] ) === '.source' ) {
                    $wp_filesystem->delete( $remaining[0], false );
                    $wp_filesystem->rmdir( $src_path );
                }
            }
        }
    }
}
