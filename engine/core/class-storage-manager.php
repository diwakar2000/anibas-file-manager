<?php

/**
 * Central registry that resolves storage ids to configured filesystem
 * adapter instances (local and remote/cloud), lazily instantiating them
 * from stored connection settings.
 *
 * @package Anibas_File_Manager
 */

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Singleton responsible for holding adapter configuration and lazily
 * creating/caching FileSystemAdapter instances for each configured storage
 * id (local or a remote connection).
 */
class StorageManager {
    private static $instance = null;
    private $adapters = [];
    private $adapter_configs = [];
    private $current_storage = 'local';

    private function __construct() {
        $this->register_adapter_configs();
    }

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Store adapter configurations without instantiating them.
     * Adapters are created lazily on first access via get_adapter().
     */
    private function register_adapter_configs() {
        // Local is always available
        $this->adapter_configs['local'] = true;

        $settings = anibas_fm_get_remote_settings();

        foreach ( anibas_fm_remote_storage_providers() as $storage => $provider ) {
            $factory = $provider['adapter_factory'] ?? null;
            if ( ! empty( $settings[ $storage ]['enabled'] ) && $factory && is_callable( $factory ) ) {
                $this->adapter_configs[ $storage ] = $settings[ $storage ];
            }
        }
    }

    /**
     * Create an adapter instance from its stored config.
     */
    private function create_adapter( $storage ) {
        if ( ! isset( $this->adapter_configs[ $storage ] ) ) {
            return null;
        }

        $c = $this->adapter_configs[ $storage ];

        if ( $storage === 'local' ) {
            return new LocalFileSystemAdapter();
        }

        $providers = anibas_fm_remote_storage_providers();
        $factory = $providers[ $storage ]['adapter_factory'] ?? null;
        if ( ! $factory || ! is_callable( $factory ) ) {
            return null;
        }

        return call_user_func( $factory, $c );
    }

    public static function create_ftp_adapter( array $c ) {
        $is_passive = array_key_exists( 'is_passive', $c ) ? (bool) $c['is_passive'] : true;
        return new FTPFileSystemAdapter(
            $c['host'],
            $c['username'],
            $c['password'],
            $c['base_path'] ?? '/',
            $c['use_ssl'] ?? false,
            $c['port'] ?? 21,
            $is_passive
        );
    }

    public static function create_sftp_adapter( array $c ) {
        return new SFTPFileSystemAdapter(
            $c['host'],
            $c['username'],
            $c['password'] ?? null,
            $c['private_key'] ?? null,
            $c['base_path'] ?? '/',
            $c['port'] ?? 22
        );
    }

    public static function create_s3_adapter( array $c ) {
        $s3_client = new AnibasS3Client(
            $c['access_key'],
            $c['secret_key'],
            $c['region']   ?? 'us-east-1',
            $c['endpoint'] ?? null,
            ! empty( $c['path_style'] )
        );
        $chunk_size = isset( $c['chunk_size'] ) ? (int) $c['chunk_size'] : 5242880;
        return new S3FileSystemAdapter(
            $s3_client,
            $c['bucket'],
            $c['prefix'] ?? '',
            $chunk_size
        );
    }

    public static function create_s3_compatible_adapter( array $c ) {
        $s3_client = new AnibasS3Client(
            $c['access_key'],
            $c['secret_key'],
            $c['region']   ?? 'us-east-1',
            $c['endpoint'],
            true
        );
        $chunk_size = isset( $c['chunk_size'] ) ? (int) $c['chunk_size'] : 5242880;
        return new S3FileSystemAdapter(
            $s3_client,
            $c['bucket'],
            $c['prefix'] ?? '',
            $chunk_size
        );
    }

    public static function create_gdrive_adapter( array $c ) {
        $chunk_size = isset( $c['chunk_size'] ) ? (int) $c['chunk_size'] : ANIBAS_FM_DEFAULT_CHUNK_SIZE;
        return new GoogleDriveFileSystemAdapter(
            new AnibasGoogleDriveClient( $c ),
            $c['root_folder_id'] ?? 'root',
            $chunk_size
        );
    }

    public static function create_onedrive_adapter( array $c ) {
        $chunk_size = isset( $c['chunk_size'] ) ? (int) $c['chunk_size'] : ANIBAS_FM_DEFAULT_CHUNK_SIZE;
        return new OneDriveFileSystemAdapter(
            new AnibasOneDriveClient( $c ),
            $c['drive_id'] ?? '',
            $c['root_path'] ?? '/',
            $chunk_size
        );
    }

    public static function create_dropbox_adapter( array $c ) {
        $chunk_size = isset( $c['chunk_size'] ) ? (int) $c['chunk_size'] : ANIBAS_FM_DEFAULT_CHUNK_SIZE;
        return new DropboxFileSystemAdapter(
            new AnibasDropboxClient( $c ),
            $c['root_path'] ?? '/',
            $chunk_size
        );
    }

    public function get_adapter( $storage = null ) {
        if ( $storage === null ) {
            $storage = $this->current_storage;
        }

        // Lazy-load: create adapter on first access
        if ( ! isset( $this->adapters[ $storage ] ) ) {
            try {
                $adapter = $this->create_adapter( $storage );
            } catch ( \Throwable $e ) {
                throw new \RuntimeException(
                    sprintf( 'Failed to connect to "%s" storage: %s', esc_html( $storage ), esc_html( $e->getMessage() ) ),
                    0,
                    $e
                );
            }
            if ( $adapter === null ) {
                return null;
            }
            $adapter->set_storage_id( $storage );
            $this->adapters[ $storage ] = $adapter;
        }

        return $this->adapters[ $storage ];
    }

    public function has_adapter( $storage ) {
        return isset( $this->adapter_configs[ $storage ] );
    }

    public function set_current_storage( $storage ) {
        if ( isset( $this->adapter_configs[ $storage ] ) ) {
            $this->current_storage = $storage;
        }
    }

    /**
     * Validate that a cross-storage transfer is allowed.
     * At least one side must be local storage. Remote-to-remote is blocked.
     *
     * @return true|\WP_Error
     */
    public function validate_cross_storage_transfer( string $source_storage, string $dest_storage ) {
        if ( $source_storage === $dest_storage ) {
            return true;
        }

        $source_adapter = $this->get_adapter( $source_storage );
        $dest_adapter   = $this->get_adapter( $dest_storage );

        if ( ! $source_adapter || ! $dest_adapter ) {
            return new \WP_Error( 'invalid_storage', 'Invalid storage adapter.' );
        }

        if ( ! $source_adapter->is_local_storage() && ! $dest_adapter->is_local_storage() ) {
            return new \WP_Error(
                'remote_to_remote',
                'Direct transfers between remote storages are not supported. Transfer to local storage first, then to the target storage.'
            );
        }

        // The remote adapter must support resumable chunked transfers
        $remote_adapter = $source_adapter->is_local_storage() ? $dest_adapter : $source_adapter;
        if ( ! $remote_adapter->supports_chunked_transfer() ) {
            return new \WP_Error(
                'chunked_transfer_unsupported',
                'This storage does not support resumable chunked transfers.'
            );
        }

        return true;
    }

    /**
     * Get (and create) a temp directory for cross-storage staging files.
     */
    public function get_cross_storage_temp_dir(): string {
        $dir = wp_upload_dir()['basedir'] . '/anibas-fm-temp';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            anibas_fm_protect_dir( $dir );
        }
        return $dir;
    }
}
