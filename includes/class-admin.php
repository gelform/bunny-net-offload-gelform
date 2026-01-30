<?php
/**
 * Admin Settings Page
 *
 * Handles the plugin's admin interface.
 *
 * @package BunnyNetOffloadGelform
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin class.
 *
 * @since 1.0.0
 */
class BNOG_Admin {

    /**
     * Admin page slug.
     *
     * @var string
     */
    const PAGE_SLUG = 'bunny-net-offload-gelform';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'handle_auth_redirect' ) );
        add_action( 'admin_notices', array( $this, 'display_notices' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_bnog_setup_cdn', array( $this, 'ajax_setup_cdn' ) );
        add_action( 'wp_ajax_bnog_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_bnog_purge_cache', array( $this, 'ajax_purge_cache' ) );
    }

    /**
     * Add admin menu page.
     */
    public function add_menu_page() {
        add_options_page(
            __( 'Bunny.net Offload', 'bunny-net-offload-gelform' ),
            __( 'Bunny.net Offload', 'bunny-net-offload-gelform' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'bnog-admin',
            BNOG_PLUGIN_URL . 'assets/admin.css',
            array(),
            BNOG_VERSION
        );

        wp_enqueue_script(
            'bnog-admin',
            BNOG_PLUGIN_URL . 'assets/admin.js',
            array( 'jquery' ),
            BNOG_VERSION,
            true
        );

        wp_localize_script(
            'bnog-admin',
            'bnogAdmin',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'bnog_admin_nonce' ),
                'authUrl'   => bunny_net_offload_gelform()->auth->get_auth_url(),
                'strings'   => array(
                    'settingUp'      => __( 'Setting up CDN...', 'bunny-net-offload-gelform' ),
                    'success'        => __( 'Success!', 'bunny-net-offload-gelform' ),
                    'error'          => __( 'Error', 'bunny-net-offload-gelform' ),
                    'saving'         => __( 'Saving...', 'bunny-net-offload-gelform' ),
                    'saved'          => __( 'Settings saved!', 'bunny-net-offload-gelform' ),
                    'syncing'        => __( 'Syncing in progress...', 'bunny-net-offload-gelform' ),
                    'syncComplete'   => __( 'Sync complete!', 'bunny-net-offload-gelform' ),
                    'syncBackground' => __( 'Syncing is running in the background. You can leave this page and come back later.', 'bunny-net-offload-gelform' ),
                    'confirmDelete'  => __( 'Are you sure you want to disconnect? This will not delete your CDN content.', 'bunny-net-offload-gelform' ),
                    'purging'        => __( 'Purging cache...', 'bunny-net-offload-gelform' ),
                    'purged'         => __( 'Cache purged!', 'bunny-net-offload-gelform' ),
                ),
            )
        );
    }

    /**
     * Handle auth redirect to clean URL (runs on admin_init before output).
     */
    public function handle_auth_redirect() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['auth'] ) ) {
            return;
        }

        // Verify user capabilities before processing auth redirect.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Store result in transient and redirect to clean URL.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( 'success' === $_GET['auth'] ) {
            set_transient( 'bnog_auth_success', true, 30 );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        } elseif ( 'failed' === $_GET['auth'] ) {
            set_transient( 'bnog_auth_failed', true, 30 );
        }

        wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    /**
     * Display admin notices.
     */
    public function display_notices() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
            return;
        }

        // Show success notice from transient.
        if ( get_transient( 'bnog_auth_success' ) ) {
            delete_transient( 'bnog_auth_success' );
            echo '<div class="notice notice-success is-dismissible"><p>';
            esc_html_e( 'Successfully connected to Bunny.net!', 'bunny-net-offload-gelform' );
            echo '</p></div>';
        }

        // Show error notice from transient.
        if ( get_transient( 'bnog_auth_failed' ) ) {
            delete_transient( 'bnog_auth_failed' );
            $error = get_transient( 'bnog_auth_error' );
            delete_transient( 'bnog_auth_error' );

            echo '<div class="notice notice-error is-dismissible"><p>';
            if ( $error ) {
                echo esc_html( $error );
            } else {
                esc_html_e( 'Failed to connect to Bunny.net. Please try again.', 'bunny-net-offload-gelform' );
            }
            echo '</p></div>';
        }

        // Debug: Show callback parameters if WP_DEBUG is on.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $debug_params = get_transient( 'bnog_debug_callback_params' );
            if ( $debug_params ) {
                delete_transient( 'bnog_debug_callback_params' );
                echo '<div class="notice notice-info is-dismissible"><p>';
                echo '<strong>Debug - Callback parameters received:</strong><br>';
                echo '<code>' . esc_html( wp_json_encode( $debug_params, JSON_PRETTY_PRINT ) ) . '</code>';
                echo '</p></div>';
            }
        }
    }

    /**
     * Render the settings page.
     */
    public function render_page() {
        $is_connected  = bunny_net_offload_gelform()->is_connected();
        $is_configured = bunny_net_offload_gelform()->is_configured();
        $config        = bunny_net_offload_gelform()->get_config();

        ?>
        <div class="wrap bnog-admin-wrap">
            <h1><?php esc_html_e( 'Bunny.net Offload by Gelform', 'bunny-net-offload-gelform' ); ?></h1>

            <div class="bnog-container">
                <?php if ( ! $is_connected ) : ?>
                    <?php $this->render_connect_screen(); ?>
                <?php elseif ( ! $is_configured ) : ?>
                    <?php $this->render_setup_screen( $config ); ?>
                <?php else : ?>
                    <?php $this->render_configured_screen( $config ); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the connect screen (not connected).
     */
    private function render_connect_screen() {
        $auth_url = bunny_net_offload_gelform()->auth->get_auth_url();
        ?>
        <div class="bnog-card bnog-card-connect">
            <div class="bnog-card-header">
                <h2><?php esc_html_e( 'Connect to Bunny.net', 'bunny-net-offload-gelform' ); ?></h2>
            </div>
            <div class="bnog-card-body">
                <p class="bnog-description">
                    <?php esc_html_e( 'Connect your Bunny.net account to automatically optimize and serve images from a global CDN.', 'bunny-net-offload-gelform' ); ?>
                </p>

                <a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary button-hero bnog-connect-btn" id="bnog-connect">
                    <span class="dashicons dashicons-cloud"></span>
                    <?php esc_html_e( 'Connect to Bunny.net', 'bunny-net-offload-gelform' ); ?>
                </a>

                <p class="bnog-signup-link">
                    <?php
                    printf(
                        /* translators: %s: Bunny.net signup URL */
                        esc_html__( "Don't have an account? %s", 'bunny-net-offload-gelform' ),
                        '<a href="https://bunny.net/?ref=bunny-net-offload-gelform" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sign up free', 'bunny-net-offload-gelform' ) . '</a>'
                    );
                    ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Render the setup screen (connected but not configured).
     *
     * @param array $config Current configuration.
     */
    private function render_setup_screen( $config ) {
        $regions = bunny_net_offload_gelform()->api->get_available_regions();
        ?>
        <div class="bnog-card bnog-card-setup">
            <div class="bnog-card-header">
                <h2>
                    <span class="bnog-status-icon bnog-status-connected"></span>
                    <?php esc_html_e( 'Connected to Bunny.net', 'bunny-net-offload-gelform' ); ?>
                </h2>
                <div class="bnog-header-actions">
                    <a href="https://dash.bunny.net/" target="_blank" rel="noopener noreferrer" class="button button-link">
                        <?php esc_html_e( 'Bunny.net', 'bunny-net-offload-gelform' ); ?>
                        <span class="dashicons dashicons-external bnog-external-icon"></span>
                    </a>
                    <button type="button" class="button button-link bnog-disconnect-btn" id="bnog-disconnect">
                        <?php esc_html_e( 'Disconnect', 'bunny-net-offload-gelform' ); ?>
                    </button>
                </div>
            </div>
            <div class="bnog-card-body">
                <p class="bnog-setup-description">
                    <?php esc_html_e( 'Select a storage region and we\'ll set up your CDN automatically. You can configure advanced settings after setup.', 'bunny-net-offload-gelform' ); ?>
                </p>

                <form id="bnog-setup-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="bnog-region"><?php esc_html_e( 'Storage Region', 'bunny-net-offload-gelform' ); ?></label>
                            </th>
                            <td>
                                <select name="region" id="bnog-region" class="regular-text">
                                    <option value="NY"><?php esc_html_e( 'New York, USA', 'bunny-net-offload-gelform' ); ?></option>
                                    <?php foreach ( $regions as $code => $region ) : ?>
                                        <?php if ( 'NY' !== $code ) : ?>
                                            <option value="<?php echo esc_attr( $code ); ?>">
                                                <?php echo esc_html( $region['name'] ); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'Choose a region closest to your primary audience.', 'bunny-net-offload-gelform' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div class="bnog-setup-action">
                        <button type="submit" class="button button-primary button-hero" id="bnog-setup-btn">
                            <span class="dashicons dashicons-admin-site-alt3"></span>
                            <?php esc_html_e( 'Set Up CDN', 'bunny-net-offload-gelform' ); ?>
                        </button>
                        <span class="spinner"></span>
                        <span class="bnog-status-message"></span>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render the configured screen (fully set up).
     *
     * @param array $config Current configuration.
     */
    private function render_configured_screen( $config ) {
        $synced_count   = bunny_net_offload_gelform()->media_handler->get_synced_count();
        $unsynced_count = bunny_net_offload_gelform()->media_handler->get_unsynced_count();
        $regions        = bunny_net_offload_gelform()->api->get_available_regions();
        $region_name    = isset( $regions[ $config['storage_region'] ] ) ? $regions[ $config['storage_region'] ]['name'] : $config['storage_region'];
        $sync_all_files = ! empty( $config['sync_all_files'] );

        // Check if sync is currently running by looking at the queue.
        $sync_status  = get_option( 'bnog_sync_status', array() );
        $sync_queue   = get_option( 'bnog_upload_queue', array() );
        $sync_running = ! empty( $sync_status['running'] ) && ! empty( $sync_queue );
        ?>
        <div class="bnog-card bnog-card-configured">
            <div class="bnog-card-header">
                <h2>
                    <span class="bnog-status-icon bnog-status-active"></span>
                    <?php esc_html_e( 'Connected & Active', 'bunny-net-offload-gelform' ); ?>
                </h2>
                <div class="bnog-header-actions">
                    <?php
                    // Build deep link to storage zone in Bunny.net dashboard.
                    // Use absint() to normalize the ID and prevent malformed URLs.
                    $storage_zone_id     = ! empty( $config['storage_zone_id'] ) ? absint( $config['storage_zone_id'] ) : 0;
                    $bunny_dashboard_url = $storage_zone_id
                        ? 'https://dash.bunny.net/storage/' . $storage_zone_id . '/file-manager'
                        : 'https://dash.bunny.net/';
                    ?>
                    <a href="<?php echo esc_url( $bunny_dashboard_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-link">
                        <?php esc_html_e( 'Bunny.net', 'bunny-net-offload-gelform' ); ?>
                        <span class="dashicons dashicons-external bnog-external-icon"></span>
                    </a>
                    <button type="button" class="button button-link bnog-disconnect-btn" id="bnog-disconnect">
                        <?php esc_html_e( 'Disconnect', 'bunny-net-offload-gelform' ); ?>
                    </button>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="bnog-tabs-nav">
                <button type="button" class="bnog-tab-btn active" data-tab="status">
                    <?php esc_html_e( 'Status', 'bunny-net-offload-gelform' ); ?>
                </button>
                <button type="button" class="bnog-tab-btn" data-tab="advanced">
                    <?php esc_html_e( 'Advanced', 'bunny-net-offload-gelform' ); ?>
                </button>
            </div>

            <!-- Status Tab -->
            <div class="bnog-tab-content active" id="bnog-tab-status">
                <div class="bnog-card-body">
                    <?php
                    $effective_cdn_url = bunny_net_offload_gelform()->url_rewriter->get_effective_cdn_url();
                    $has_custom_domain = ! empty( $config['custom_cdn_domain'] );
                    ?>
                    <div class="bnog-info-grid">
                        <div class="bnog-info-item">
                            <label><?php esc_html_e( 'CDN URL', 'bunny-net-offload-gelform' ); ?></label>
                            <code><?php echo esc_html( str_replace( 'https://', '', $effective_cdn_url ) ); ?></code>
                            <?php if ( $has_custom_domain ) : ?>
                                <span class="bnog-custom-domain-badge"><?php esc_html_e( 'Custom Domain', 'bunny-net-offload-gelform' ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="bnog-info-item">
                            <label><?php esc_html_e( 'Storage Zone', 'bunny-net-offload-gelform' ); ?></label>
                            <code><?php echo esc_html( $config['storage_zone_name'] ); ?></code>
                        </div>
                        <div class="bnog-info-item">
                            <label><?php esc_html_e( 'Region', 'bunny-net-offload-gelform' ); ?></label>
                            <span><?php echo esc_html( $region_name ); ?></span>
                        </div>
                    </div>

                    <hr class="bnog-divider">

                    <h3><?php esc_html_e( 'Sync', 'bunny-net-offload-gelform' ); ?></h3>

                    <div class="bnog-sync-section">
                        <?php if ( $sync_running ) : ?>
                            <!-- Sync in progress -->
                            <div class="bnog-sync-in-progress">
                                <div class="bnog-sync-stats">
                                    <span class="bnog-stat">
                                        <strong><?php echo esc_html( number_format( $synced_count ) ); ?></strong>
                                        <?php echo $sync_all_files ? esc_html__( 'files on CDN', 'bunny-net-offload-gelform' ) : esc_html__( 'images on CDN', 'bunny-net-offload-gelform' ); ?>
                                    </span>
                                    <span class="bnog-stat bnog-stat-pending">
                                        <strong><?php echo esc_html( number_format( count( $sync_queue ) ) ); ?></strong>
                                        <?php esc_html_e( 'remaining', 'bunny-net-offload-gelform' ); ?>
                                    </span>
                                </div>
                                <div class="bnog-sync-running-notice">
                                    <span class="spinner is-active"></span>
                                    <span class="bnog-sync-running-text">
                                        <?php esc_html_e( 'Syncing in progress...', 'bunny-net-offload-gelform' ); ?>
                                    </span>
                                    <a href="<?php echo esc_url( add_query_arg( array() ) ); ?>" class="button button-small bnog-refresh-btn">
                                        <span class="dashicons dashicons-update"></span>
                                        <?php esc_html_e( 'Refresh', 'bunny-net-offload-gelform' ); ?>
                                    </a>
                                </div>
                                <p class="bnog-sync-notice">
                                    <?php esc_html_e( 'Syncing is running in the background. You can leave this page and come back later.', 'bunny-net-offload-gelform' ); ?>
                                </p>
                            </div>
                        <?php else : ?>
                            <!-- Normal sync UI -->
                            <div class="bnog-sync-stats">
                                <span class="bnog-stat">
                                    <strong><?php echo esc_html( number_format( $synced_count ) ); ?></strong>
                                    <?php echo $sync_all_files ? esc_html__( 'files on CDN', 'bunny-net-offload-gelform' ) : esc_html__( 'images on CDN', 'bunny-net-offload-gelform' ); ?>
                                </span>
                                <?php if ( $unsynced_count > 0 ) : ?>
                                    <span class="bnog-stat bnog-stat-pending">
                                        <strong><?php echo esc_html( number_format( $unsynced_count ) ); ?></strong>
                                        <?php esc_html_e( 'waiting to sync', 'bunny-net-offload-gelform' ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ( $unsynced_count > 0 ) : ?>
                                <div class="bnog-sync-options">
                                    <label class="bnog-checkbox-label">
                                        <input type="checkbox" id="bnog-resize-before-sync" value="1">
                                        <?php esc_html_e( 'Resize and compress images before syncing', 'bunny-net-offload-gelform' ); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e( 'Apply current compression settings to existing images. Syncing happens in the background.', 'bunny-net-offload-gelform' ); ?>
                                    </p>
                                </div>
                                <div class="bnog-sync-actions">
                                    <button type="button" class="button" id="bnog-sync-btn">
                                        <?php esc_html_e( 'Start Sync of Existing Files', 'bunny-net-offload-gelform' ); ?>
                                    </button>
                                    <span class="spinner"></span>
                                    <span class="bnog-sync-progress"></span>
                                </div>
                                <p class="bnog-sync-notice" style="display: none;">
                                    <?php esc_html_e( 'You can leave this page. Syncing will continue in the background.', 'bunny-net-offload-gelform' ); ?>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Advanced Tab -->
            <div class="bnog-tab-content" id="bnog-tab-advanced">
                <div class="bnog-card-body">
                    <form id="bnog-settings-form">
                        <h3><?php esc_html_e( 'Image Dimensions', 'bunny-net-offload-gelform' ); ?></h3>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="bnog-max-width"><?php esc_html_e( 'Max Dimension', 'bunny-net-offload-gelform' ); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="max_width" id="bnog-max-width"
                                           value="<?php echo esc_attr( isset( $config['max_width'] ) ? $config['max_width'] : 2048 ); ?>"
                                           min="100" max="10000" step="1" class="small-text">
                                    <span class="description"><?php esc_html_e( 'px', 'bunny-net-offload-gelform' ); ?></span>
                                    <p class="description">
                                        <?php esc_html_e( 'Images will be resized proportionally so neither width nor height exceeds this value.', 'bunny-net-offload-gelform' ); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <hr class="bnog-divider">

                        <h3><?php esc_html_e( 'Compression Settings', 'bunny-net-offload-gelform' ); ?></h3>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="bnog-jpeg-quality"><?php esc_html_e( 'JPEG Quality', 'bunny-net-offload-gelform' ); ?></label>
                                </th>
                                <td>
                                    <input type="range" name="jpeg_quality" id="bnog-jpeg-quality"
                                           value="<?php echo esc_attr( isset( $config['jpeg_quality'] ) ? $config['jpeg_quality'] : 85 ); ?>"
                                           min="1" max="100" step="1" class="bnog-range-input">
                                    <span class="bnog-range-value" id="bnog-jpeg-quality-value">
                                        <?php echo esc_html( isset( $config['jpeg_quality'] ) ? $config['jpeg_quality'] : 85 ); ?>%
                                    </span>
                                    <p class="description">
                                        <?php esc_html_e( 'Higher values = better quality, larger files. Recommended: 80-90%.', 'bunny-net-offload-gelform' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="bnog-png-compression"><?php esc_html_e( 'PNG Compression', 'bunny-net-offload-gelform' ); ?></label>
                                </th>
                                <td>
                                    <input type="range" name="png_compression" id="bnog-png-compression"
                                           value="<?php echo esc_attr( isset( $config['png_compression'] ) ? $config['png_compression'] : 6 ); ?>"
                                           min="0" max="9" step="1" class="bnog-range-input">
                                    <span class="bnog-range-value" id="bnog-png-compression-value">
                                        <?php echo esc_html( isset( $config['png_compression'] ) ? $config['png_compression'] : 6 ); ?>
                                    </span>
                                    <p class="description">
                                        <?php esc_html_e( '0 = no compression (fastest), 9 = maximum compression (slowest). Recommended: 6.', 'bunny-net-offload-gelform' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="bnog-webp-quality"><?php esc_html_e( 'WebP Quality', 'bunny-net-offload-gelform' ); ?></label>
                                </th>
                                <td>
                                    <input type="range" name="webp_quality" id="bnog-webp-quality"
                                           value="<?php echo esc_attr( isset( $config['webp_quality'] ) ? $config['webp_quality'] : 82 ); ?>"
                                           min="1" max="100" step="1" class="bnog-range-input">
                                    <span class="bnog-range-value" id="bnog-webp-quality-value">
                                        <?php echo esc_html( isset( $config['webp_quality'] ) ? $config['webp_quality'] : 82 ); ?>%
                                    </span>
                                    <p class="description">
                                        <?php esc_html_e( 'Quality for WebP conversion. WebP typically achieves similar quality at lower values than JPEG.', 'bunny-net-offload-gelform' ); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <hr class="bnog-divider">

                        <h3><?php esc_html_e( 'CDN Domain', 'bunny-net-offload-gelform' ); ?></h3>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="bnog-custom-cdn-domain"><?php esc_html_e( 'Custom Domain', 'bunny-net-offload-gelform' ); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="custom_cdn_domain" id="bnog-custom-cdn-domain"
                                           value="<?php echo esc_attr( isset( $config['custom_cdn_domain'] ) ? $config['custom_cdn_domain'] : '' ); ?>"
                                           class="regular-text" placeholder="cdn.yourdomain.com">
                                    <p class="description">
                                        <?php esc_html_e( 'Optional: Use your own domain for CDN URLs instead of the default Bunny.net domain.', 'bunny-net-offload-gelform' ); ?>
                                        <br>
                                        <?php
                                        printf(
                                            /* translators: %s: Bunny.net documentation URL */
                                            esc_html__( 'You must configure this domain as a custom hostname in your %s first.', 'bunny-net-offload-gelform' ),
                                            '<a href="https://dash.bunny.net/cdn" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Bunny.net dashboard', 'bunny-net-offload-gelform' ) . '</a>'
                                        );
                                        ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <hr class="bnog-divider">

                        <h3><?php esc_html_e( 'Storage', 'bunny-net-offload-gelform' ); ?></h3>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="bnog-keep-local"><?php esc_html_e( 'Keep Local Files', 'bunny-net-offload-gelform' ); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="keep_local_files" id="bnog-keep-local" value="1"
                                            <?php checked( ! empty( $config['keep_local_files'] ) ); ?>>
                                        <?php esc_html_e( 'Keep original files on your server after uploading to CDN', 'bunny-net-offload-gelform' ); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e( 'Disable to save local disk space. Warning: If disabled, files cannot be recovered if removed from CDN.', 'bunny-net-offload-gelform' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="bnog-sync-all-files"><?php esc_html_e( 'Sync All File Types', 'bunny-net-offload-gelform' ); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="sync_all_files" id="bnog-sync-all-files" value="1"
                                            <?php checked( ! empty( $config['sync_all_files'] ) ); ?>>
                                        <?php esc_html_e( 'Also sync non-image files (PDFs, documents, videos, etc.) to CDN', 'bunny-net-offload-gelform' ); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e( 'When enabled, all media library files will be synced to the CDN, not just images.', 'bunny-net-offload-gelform' ); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <hr class="bnog-divider">

                        <h3><?php esc_html_e( 'Cache', 'bunny-net-offload-gelform' ); ?></h3>

                        <div class="bnog-cache-section">
                            <button type="button" class="button" id="bnog-purge-btn">
                                <?php esc_html_e( 'Purge CDN Cache', 'bunny-net-offload-gelform' ); ?>
                            </button>
                            <span class="spinner"></span>
                            <span class="bnog-status-message"></span>
                            <p class="description">
                                <?php esc_html_e( 'Clear all cached files from the CDN edge servers.', 'bunny-net-offload-gelform' ); ?>
                            </p>
                        </div>

                        <p class="submit">
                            <button type="submit" class="button button-primary" id="bnog-save-btn">
                                <?php esc_html_e( 'Save Settings', 'bunny-net-offload-gelform' ); ?>
                            </button>
                            <span class="spinner"></span>
                            <span class="bnog-status-message"></span>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for CDN setup.
     */
    public function ajax_setup_cdn() {
        // Verify nonce with proper JSON error response.
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'bnog_admin_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'bunny-net-offload-gelform' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bunny-net-offload-gelform' ) ) );
        }

        // Get settings from request.
        $region = isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : 'NY';

        // Validate region.
        $valid_regions = array_keys( bunny_net_offload_gelform()->api->get_available_regions() );
        if ( ! in_array( $region, $valid_regions, true ) ) {
            $region = 'NY';
        }

        // Setup CDN.
        $result = bunny_net_offload_gelform()->api->setup_cdn( $region );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Encrypt the storage password before storing.
        $result['storage_zone_password'] = bunny_net_offload_gelform()->auth->encrypt_value( $result['storage_zone_password'] );

        // Save configuration.
        $config = array_merge(
            $result,
            array(
                'max_width'        => 2048,
                'max_height'       => 2048,
                'jpeg_quality'     => 85,
                'png_compression'  => 6,
                'webp_quality'     => 82,
                'keep_local_files' => true,
                'sync_all_files'   => true,
            )
        );

        update_option( 'bnog_config', $config );

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %s: CDN URL */
                    __( 'Your CDN is ready! Images will be served from %s', 'bunny-net-offload-gelform' ),
                    str_replace( 'https://', '', $result['cdn_url'] )
                ),
                'cdn_url' => $result['cdn_url'],
            )
        );
    }

    /**
     * AJAX handler for saving settings.
     */
    public function ajax_save_settings() {
        // Verify nonce with proper JSON error response.
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'bnog_admin_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'bunny-net-offload-gelform' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bunny-net-offload-gelform' ) ) );
        }

        $config = bunny_net_offload_gelform()->get_config();

        // Update settings with proper validation.
        $max_width       = isset( $_POST['max_width'] ) ? absint( $_POST['max_width'] ) : 2048;
        $jpeg_quality    = isset( $_POST['jpeg_quality'] ) ? absint( $_POST['jpeg_quality'] ) : 85;
        $png_compression = isset( $_POST['png_compression'] ) ? absint( $_POST['png_compression'] ) : 6;
        $webp_quality    = isset( $_POST['webp_quality'] ) ? absint( $_POST['webp_quality'] ) : 82;

        // Validate ranges.
        $max_width       = max( 100, min( 10000, $max_width ) );
        $jpeg_quality    = max( 1, min( 100, $jpeg_quality ) );
        $png_compression = max( 0, min( 9, $png_compression ) );
        $webp_quality    = max( 1, min( 100, $webp_quality ) );

        $config['max_width']        = $max_width;
        $config['max_height']       = $max_width;
        $config['jpeg_quality']     = $jpeg_quality;
        $config['png_compression']  = $png_compression;
        $config['webp_quality']     = $webp_quality;
        $config['keep_local_files'] = ! empty( $_POST['keep_local_files'] );
        $config['sync_all_files']   = ! empty( $_POST['sync_all_files'] );

        // Sanitize and validate custom CDN domain.
        $custom_cdn_domain = isset( $_POST['custom_cdn_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_cdn_domain'] ) ) : '';

        // Remove protocol if present.
        $custom_cdn_domain = preg_replace( '#^https?://#', '', $custom_cdn_domain );

        // Remove any path, query string, or fragment.
        $custom_cdn_domain = preg_replace( '#[/?#].*$#', '', $custom_cdn_domain );

        // Remove trailing slashes and whitespace.
        $custom_cdn_domain = trim( rtrim( $custom_cdn_domain, '/' ) );

        // Validate that it looks like a valid hostname (alphanumeric, dots, hyphens only).
        // Clear the value if it doesn't match a valid hostname pattern.
        if ( ! empty( $custom_cdn_domain ) && ! preg_match( '/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $custom_cdn_domain ) ) {
            $custom_cdn_domain = '';
        }

        $config['custom_cdn_domain'] = $custom_cdn_domain;

        update_option( 'bnog_config', $config );

        // Clear URL availability cache when custom domain changes.
        bunny_net_offload_gelform()->url_rewriter->clear_availability_cache();

        wp_send_json_success( array( 'message' => __( 'Settings saved!', 'bunny-net-offload-gelform' ) ) );
    }

    /**
     * AJAX handler for purging cache.
     */
    public function ajax_purge_cache() {
        // Verify nonce with proper JSON error response.
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'bnog_admin_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'bunny-net-offload-gelform' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bunny-net-offload-gelform' ) ) );
        }

        $config = bunny_net_offload_gelform()->get_config();

        if ( empty( $config['pull_zone_id'] ) ) {
            wp_send_json_error( array( 'message' => __( 'CDN is not configured.', 'bunny-net-offload-gelform' ) ) );
        }

        $result = bunny_net_offload_gelform()->api->purge_cache( $config['pull_zone_id'] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Clear local availability cache.
        bunny_net_offload_gelform()->url_rewriter->clear_availability_cache();

        wp_send_json_success( array( 'message' => __( 'CDN cache purged successfully!', 'bunny-net-offload-gelform' ) ) );
    }
}
