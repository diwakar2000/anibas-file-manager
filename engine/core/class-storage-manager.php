<?php

namespace Anibas;

if ( ! defined( 'ABSPATH' ) ) exit;

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

        if ( ! empty( $settings['ftp']['enabled'] ) ) {
            $this->adapter_configs['ftp'] = $settings['ftp'];
        }

        if ( ! empty( $settings['sftp']['enabled'] ) ) {
            $this->adapter_configs['sftp'] = $settings['sftp'];
        }

        if ( ! empty( $settings['s3']['enabled'] ) ) {
            $this->adapter_configs['s3'] = $settings['s3'];
        }

        if ( ! empty( $settings['s3_compatible']['enabled'] ) ) {
            $this->adapter_configs['s3_compatible'] = $settings['s3_compatible'];
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

        switch ( $storage ) {
            case 'local':
                return new LocalFileSystemAdapter();

            case 'ftp':
                // is_passive defaults to true: existing connections that pre-date
                // this setting still get passive mode (the historical hardcoded value).
                $is_passive = array_key_exists( 'is_passive', $c ) ? (bool) $c['is_passive'] : true;
                return new FTPFileSystemAdapter(
                    $c['host'],
                    $c['username'],
                    $c['password'],
                    $c['base_path'] ?? '/',
                    $c['use_ssl'] ?? false,
                    $c['port'] ?? 21,
                    $is_passive,
                    ! empty( $c['insecure_ssl'] )
                );

            case 'sftp':
                return new SFTPFileSystemAdapter(
                    $c['host'],
                    $c['username'],
                    $c['password'] ?? null,
                    $c['private_key'] ?? null,
                    $c['base_path'] ?? '/',
                    $c['port'] ?? 22
                );

            case 's3':
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

            case 's3_compatible':
                $s3_client = new AnibasS3Client(
                    $c['access_key'],
                    $c['secret_key'],
                    $c['region']   ?? 'us-east-1',
                    $c['endpoint'],           // endpoint is required for S3-compatible
                    true                      // always path-style for S3-compatible services
                );
                $chunk_size = isset( $c['chunk_size'] ) ? (int) $c['chunk_size'] : 5242880;
                return new S3FileSystemAdapter(
                    $s3_client,
                    $c['bucket'],
                    $c['prefix'] ?? '',
                    $chunk_size
                );

            default:
                return null;
        }
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
            @file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
            @file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
        }
        return $dir;
    }
}
