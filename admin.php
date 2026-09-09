<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BlkSettingsPage {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'init_settings' ] );
    }

    public function add_admin_menu() {
        $page = add_menu_page(
            'BaseLinker Sync',
            'BL Synk',
            'manage_options',
            'bs-settings-page',
            [ $this, 'settings_page' ],
            'dashicons-update',
            56
        );

        add_action( 'admin_print_styles-' . $page, [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets() {
        wp_enqueue_style( 'dashicons' );
        wp_enqueue_script( 'blk-prism', BLK_SYNCHRONIZER_DIR_URL . '/assets/prism.js', [], BLK_PLUGIN_VERSION, true );
        wp_enqueue_script( 'blk-admin-page', BLK_SYNCHRONIZER_DIR_URL . '/assets/admin-page.js', [ 'jquery' ], BLK_PLUGIN_VERSION, true );
        wp_enqueue_style( 'blk-prism', BLK_SYNCHRONIZER_DIR_URL . '/assets/prism.css', [], BLK_PLUGIN_VERSION );
        wp_enqueue_style( 'blk-admin-page', BLK_SYNCHRONIZER_DIR_URL . '/assets/admin-page.css', [], BLK_PLUGIN_VERSION );

        wp_localize_script( 'blk-admin-page', 'blkAdminPageData', [
            'logFilePath' => BLK_SYNCHRONIZER_LOGS_URL,
            'nonce'       => wp_create_nonce( BLK_NONCE_ACTION ),
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        ]);
    }

    public function init_settings() {
        register_setting( 'Blk', 'blk_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ]);
    }

    /**
     * Sanitize all settings on save.
     */
    public function sanitize_settings( $input ) {
        $sanitized = [];

        $sanitized['blk_api_token']            = sanitize_text_field( $input['blk_api_token'] ?? '' );
        $sanitized['blk_inventory_id']         = absint( $input['blk_inventory_id'] ?? 0 );
        $sanitized['blk_price_group_id']       = absint( $input['blk_price_group_id'] ?? 0 );
        $sanitized['blk_price_group_id_before_promo'] = absint( $input['blk_price_group_id_before_promo'] ?? 0 );
        $sanitized['blk_promo_category_id']    = absint( $input['blk_promo_category_id'] ?? 0 );
        $sanitized['blk_warehouse_id']         = sanitize_text_field( $input['blk_warehouse_id'] ?? '' );
        $sanitized['blk_default_language']     = sanitize_text_field( $input['blk_default_language'] ?? 'pl' );
        $sanitized['blk_categories_to_ignore'] = sanitize_text_field( $input['blk_categories_to_ignore'] ?? '' );
        $sanitized['blk_skus_to_ignore']       = sanitize_text_field( $input['blk_skus_to_ignore'] ?? '' );
        $sanitized['blk_lock_file_max_lifetime']= absint( $input['blk_lock_file_max_lifetime'] ?? 10 );
        $sanitized['blk_cron_interval']        = absint( $input['blk_cron_interval'] ?? 60 );
        $sanitized['blk_query_type']           = in_array( $input['blk_query_type'] ?? 'all', [ 'all', 'chunks' ], true )
            ? $input['blk_query_type']
            : 'all';

        return $sanitized;
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized access.' );
        }

        $active_tab   = sanitize_text_field( $_GET['tab'] ?? 'dashboard' );
        $blk_settings = get_option( 'blk_settings', [] );
        $is_syncing   = blk_is_import_locked();
        ?>
        <div class="blk-wrap">

            <!-- Header -->
            <div class="blk-header">
                <h1>
                    <span class="blk-logo">BL</span>
                    BaseLinker Synchronizer
                    <span class="blk-version">v<?php echo esc_html( BLK_PLUGIN_VERSION ); ?></span>
                </h1>
            </div>

            <!-- Navigation Tabs -->
            <div class="blk-tabs">
                <a href="?page=bs-settings-page&tab=dashboard"
                   class="blk-tab <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-dashboard"></span> Dashboard
                </a>
                <a href="?page=bs-settings-page&tab=log"
                   class="blk-tab <?php echo $active_tab === 'log' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-media-text"></span> Logs
                </a>
                <a href="?page=bs-settings-page&tab=settings"
                   class="blk-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic"></span> Settings
                </a>
            </div>

            <?php
            switch ( $active_tab ) {
                case 'log':
                    $this->render_log_tab();
                    break;
                case 'settings':
                    $this->render_settings_tab( $blk_settings );
                    break;
                default:
                    $this->render_dashboard_tab( $blk_settings, $is_syncing );
                    break;
            }
            ?>

            <!-- Footer -->
            <div class="blk-footer">
                BaseLinker Synchronizer Extended v<?php echo esc_html( BLK_PLUGIN_VERSION ); ?> &mdash;
                <a href="https://afisza.com/" target="_blank">Alex Shram</a>
            </div>

        </div>
        <?php
    }


    /**
     * Dashboard tab with stats and sync controls.
     */
    private function render_dashboard_tab( $blk_settings, $is_syncing ) {
        // Gather stats
        $product_count = 0;
        if ( function_exists( 'wc_get_products' ) ) {
            $product_count = count( wc_get_products( [ 'limit' => -1, 'return' => 'ids', 'status' => 'publish' ] ) );
        }

        $next_sync = wp_next_scheduled( 'blkSynchronizer' );
        $cron_interval = intval( $blk_settings['blk_cron_interval'] ?? 60 );
        $has_token = ! empty( $blk_settings['blk_api_token'] );

        // WPML status
        $wpml_active = function_exists( 'icl_object_id' );
        $wpml_languages = [];
        if ( $wpml_active && function_exists( 'icl_get_languages' ) ) {
            $wpml_languages = icl_get_languages( 'skip_missing=0' );
        }
        ?>

        <!-- Stat Cards -->
        <div class="blk-grid blk-grid-3" style="margin-bottom: 24px;">
            <div class="blk-stat">
                <div class="blk-stat-icon blue">
                    <span class="dashicons dashicons-products"></span>
                </div>
                <div class="blk-stat-content">
                    <h4>WooCommerce Products</h4>
                    <div class="blk-stat-value"><?php echo number_format_i18n( $product_count ); ?></div>
                    <div class="blk-stat-sub">Published products</div>
                </div>
            </div>

            <div class="blk-stat">
                <div class="blk-stat-icon <?php echo $has_token ? 'green' : 'red'; ?>">
                    <span class="dashicons dashicons-admin-network"></span>
                </div>
                <div class="blk-stat-content">
                    <h4>API Connection</h4>
                    <div class="blk-stat-value">
                        <span class="blk-badge <?php echo $has_token ? 'on' : 'off'; ?>">
                            <?php echo $has_token ? 'Connected' : 'Not configured'; ?>
                        </span>
                    </div>
                    <div class="blk-stat-sub">BaseLinker API</div>
                </div>
            </div>

            <div class="blk-stat">
                <div class="blk-stat-icon purple">
                    <span class="dashicons dashicons-translation"></span>
                </div>
                <div class="blk-stat-content">
                    <h4>WPML Integration</h4>
                    <div class="blk-stat-value">
                        <span class="blk-badge <?php echo $wpml_active ? 'on' : 'neutral'; ?>">
                            <?php echo $wpml_active ? 'Active (' . count( $wpml_languages ) . ' languages)' : 'Not detected'; ?>
                        </span>
                    </div>
                    <div class="blk-stat-sub">Multilingual sync</div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="blk-grid blk-grid-2">

            <!-- Sync Controls Card -->
            <div class="blk-card">
                <div class="blk-card-header">
                    <h3><span class="dashicons dashicons-update"></span> Synchronization</h3>
                    <?php if ( $is_syncing ) : ?>
                        <span class="blk-badge warn">In progress</span>
                    <?php else : ?>
                        <span class="blk-badge on">Ready</span>
                    <?php endif; ?>
                </div>
                <div class="blk-card-body">
                    <p style="color: var(--blk-text-muted); font-size: 14px; margin-top: 0;">
                        Manually trigger a full synchronization between BaseLinker and WooCommerce, or stop a running sync.
                    </p>
                    <div class="blk-actions-row">
                        <button id="blk-start-import" class="blk-btn blk-btn-primary <?php echo $is_syncing ? 'disabled' : ''; ?>"
                                <?php echo $is_syncing ? 'disabled' : ''; ?>>
                            <span class="dashicons dashicons-controls-play"></span> Start Sync
                        </button>
                        <button id="blk-stop-import" class="blk-btn blk-btn-danger">
                            <span class="dashicons dashicons-controls-pause"></span> Stop Sync
                        </button>
                    </div>

                    <?php
                    $last_progress_file = BLK_SYNCHRONIZER_PATH . '.sync-progress.json';
                    $last_progress      = file_exists( $last_progress_file ) ? json_decode( file_get_contents( $last_progress_file ), true ) : null;
                    $last_stats         = $last_progress['stats'] ?? null;
                    ?>
                    <div id="blk-last-sync-summary" class="blk-last-sync-summary" <?php echo empty( $last_progress['message'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="blk-last-sync-title">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <strong>Last synchronization<span id="blk-last-sync-time"><?php echo ! empty( $last_progress['time'] ) ? ' (' . esc_html( $last_progress['time'] ) . ')' : ''; ?></span>:</strong>
                        </div>
                        <?php if ( $last_stats ) : ?>
                            <table class="blk-last-sync-table" id="blk-last-sync-table">
                                <thead>
                                    <tr>
                                        <th>Duration</th>
                                        <th>Added</th>
                                        <th>Updated</th>
                                        <th>Removed</th>
                                        <th>Ignored</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?php echo esc_html( $last_stats['duration'] ?? '—' ); ?></td>
                                        <td><?php echo esc_html( $last_stats['added'] ?? 0 ); ?></td>
                                        <td><?php echo esc_html( $last_stats['updated'] ?? 0 ); ?></td>
                                        <td><?php echo esc_html( $last_stats['removed'] ?? 0 ); ?></td>
                                        <td><?php echo esc_html( $last_stats['ignored'] ?? 0 ); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        <?php else : ?>
                            <span id="blk-last-sync-message"><?php echo esc_html( $last_progress['message'] ?? '' ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Status Card -->
            <div class="blk-card">
                <div class="blk-card-header">
                    <h3><span class="dashicons dashicons-info-outline"></span> System Status</h3>
                </div>
                <div class="blk-card-body">
                    <ul class="blk-status-list">
                        <li class="blk-status-item">
                            <span class="label">
                                <span class="dashicons dashicons-clock" style="color: var(--blk-primary);"></span>
                                Cron Interval
                            </span>
                            <span class="blk-badge info"><?php echo esc_html( $cron_interval ); ?> min</span>
                        </li>
                        <li class="blk-status-item">
                            <span class="label">
                                <span class="dashicons dashicons-calendar-alt" style="color: var(--blk-primary);"></span>
                                Next Scheduled Sync
                            </span>
                            <span>
                                <?php if ( $next_sync ) : ?>
                                    <span class="blk-countdown" id="blk-countdown"
                                          data-timestamp="<?php echo esc_attr( $next_sync ); ?>">
                                        --
                                    </span>
                                    <span class="blk-badge neutral" style="margin-left: 6px;">
                                        <?php echo esc_html( date_i18n( 'H:i:s', $next_sync + ( get_option( 'gmt_offset' ) * 3600 ) ) ); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="blk-badge neutral">Not scheduled</span>
                                <?php endif; ?>
                            </span>
                        </li>
                        <li class="blk-status-item">
                            <span class="label">
                                <span class="dashicons dashicons-lock" style="color: var(--blk-primary);"></span>
                                Import Lock
                            </span>
                            <span class="blk-badge <?php echo $is_syncing ? 'warn' : 'on'; ?>">
                                <?php echo $is_syncing ? 'Locked' : 'Unlocked'; ?>
                            </span>
                        </li>
                        <li class="blk-status-item">
                            <span class="label">
                                <span class="dashicons dashicons-database" style="color: var(--blk-primary);"></span>
                                Query Type
                            </span>
                            <span class="blk-badge neutral">
                                <?php echo esc_html( ucfirst( $blk_settings['blk_query_type'] ?? 'all' ) ); ?>
                            </span>
                        </li>
                        <?php if ( $wpml_active && ! empty( $wpml_languages ) ) : ?>
                        <li class="blk-status-item">
                            <span class="label">
                                <span class="dashicons dashicons-translation" style="color: var(--blk-primary);"></span>
                                WPML Languages
                            </span>
                            <span>
                                <?php foreach ( $wpml_languages as $lang ) : ?>
                                    <span class="blk-badge info" style="margin-left: 2px;">
                                        <?php echo esc_html( strtoupper( $lang['language_code'] ) ); ?>
                                    </span>
                                <?php endforeach; ?>
                            </span>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }


    /**
     * Logs tab with file viewer.
     */
    private function render_log_tab() {
        $log_directory = BLK_SYNCHRONIZER_LOGS_PATH;
        $sorted_files  = $this->get_sorted_log_files( $log_directory );
        ?>

        <div class="blk-card blk-card-full">
            <div class="blk-card-header">
                <h3><span class="dashicons dashicons-media-text"></span> Log Viewer</h3>
                <div class="blk-actions-row">
                    <button id="blk-start-import" class="blk-btn blk-btn-primary blk-btn-sm">
                        <span class="dashicons dashicons-controls-play"></span> Start Sync
                    </button>
                    <button id="blk-stop-import" class="blk-btn blk-btn-danger blk-btn-sm">
                        <span class="dashicons dashicons-controls-pause"></span> Stop
                    </button>
                </div>
            </div>
            <div class="blk-card-body">
                <div class="blk-log-toolbar">
                    <select id="blk-log-file-select" class="blk-input" style="width: auto; min-width: 280px;">
                        <?php foreach ( $sorted_files as $file ) : ?>
                            <option value="<?php echo esc_attr( $file ); ?>"><?php echo esc_html( $file ); ?></option>
                        <?php endforeach; ?>
                        <?php if ( empty( $sorted_files ) ) : ?>
                            <option value="">No log files found</option>
                        <?php endif; ?>
                    </select>
                    <button id="blk-log-reload" class="blk-btn blk-btn-outline blk-btn-sm">
                        <span class="dashicons dashicons-image-rotate"></span> Reload
                    </button>
                </div>

                <div class="blk-log-container" id="blk-log">
                    <div class="blk-log-empty">Select a log file to view</div>
                </div>
            </div>
        </div>
        <?php
    }


    /**
     * Settings tab with custom form layout.
     */
    private function render_settings_tab( $blk_settings ) {
        ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'Blk' ); ?>

            <div class="blk-grid blk-grid-2">

                <!-- API Configuration -->
                <div class="blk-card">
                    <div class="blk-card-header">
                        <h3><span class="dashicons dashicons-admin-network"></span> API Configuration</h3>
                    </div>
                    <div class="blk-card-body">
                        <div class="blk-input-group">
                            <label for="blk_api_token">BaseLinker API Token</label>
                            <input type="password" id="blk_api_token"
                                   name="blk_settings[blk_api_token]"
                                   class="blk-input"
                                   value="<?php echo esc_attr( $blk_settings['blk_api_token'] ?? '' ); ?>"
                                   autocomplete="off">
                            <div class="help">Your API token from BaseLinker panel &rarr; My account &rarr; API.</div>
                            <button type="button" id="blk-test-connection" class="blk-btn blk-btn-outline blk-btn-sm" style="margin-top: 8px;">
                                <span class="dashicons dashicons-admin-plugins"></span> Test Connection
                            </button>
                            <div id="blk-connection-result" class="blk-connection-result"></div>
                        </div>

                        <div class="blk-input-group">
                            <label for="blk_query_type">Query Type</label>
                            <select id="blk_query_type" name="blk_settings[blk_query_type]" class="blk-input" style="width: auto; min-width: 200px;">
                                <option value="all" <?php selected( $blk_settings['blk_query_type'] ?? 'all', 'all' ); ?>>All (faster)</option>
                                <option value="chunks" <?php selected( $blk_settings['blk_query_type'] ?? 'all', 'chunks' ); ?>>Chunks (for slow servers)</option>
                            </select>
                            <div class="help">Use "Chunks" if your server has limited resources.</div>
                        </div>

                        <hr style="border: none; border-top: 1px solid var(--blk-border); margin: 20px 0;">
                        <h4 style="margin: 0 0 16px; font-size: 14px; color: var(--blk-text-muted);">
                            <span class="dashicons dashicons-database" style="font-size: 16px; vertical-align: text-bottom;"></span>
                            Inventory Configuration
                        </h4>
                        <p style="font-size: 12px; color: var(--blk-text-muted); margin: 0 0 16px;">
                            Leave at 0 / empty to auto-detect from your default BaseLinker inventory.
                        </p>

                        <div class="blk-input-group">
                            <label for="blk_inventory_id">Inventory ID</label>
                            <input type="number" id="blk_inventory_id"
                                   name="blk_settings[blk_inventory_id]"
                                   class="blk-input" style="width: 160px;"
                                   value="<?php echo esc_attr( $blk_settings['blk_inventory_id'] ?? 0 ); ?>"
                                   min="0">
                            <div class="help">BaseLinker catalog ID. Set 0 to use default inventory.</div>
                        </div>

                        <div class="blk-input-group">
                            <label for="blk_price_group_id">Price Group ID</label>
                            <input type="number" id="blk_price_group_id"
                                   name="blk_settings[blk_price_group_id]"
                                   class="blk-input" style="width: 160px;"
                                   value="<?php echo esc_attr( $blk_settings['blk_price_group_id'] ?? 0 ); ?>"
                                   min="0">
                            <div class="help">Which price group to use for WooCommerce prices (gross). Set 0 for default.</div>
                        </div>

                        <div class="blk-input-group">
                            <label for="blk_price_group_id_before_promo">Price Group ID (przed promocją)</label>
                            <input type="number" id="blk_price_group_id_before_promo"
                                   name="blk_settings[blk_price_group_id_before_promo]"
                                   class="blk-input" style="width: 160px;"
                                   value="<?php echo esc_attr( $blk_settings['blk_price_group_id_before_promo'] ?? 0 ); ?>"
                                   min="0">
                            <div class="help">ID grupy cenowej "przed promocją" w BaseLinkerze. Gdy cena z tej grupy jest wyższa niż cena domyślna, produkt dostaje cenę promocyjną w WooCommerce (regular_price = ta grupa, sale_price = cena domyślna). Zostaw 0, aby wyłączyć obsługę cen promocyjnych.</div>
                        </div>

                        <div class="blk-input-group">
                            <label for="blk_promo_category_id">Promo Category ID</label>
                            <input type="number" id="blk_promo_category_id"
                                   name="blk_settings[blk_promo_category_id]"
                                   class="blk-input" style="width: 160px;"
                                   value="<?php echo esc_attr( $blk_settings['blk_promo_category_id'] ?? 0 ); ?>"
                                   min="0">
                            <div class="help">ID kategorii WooCommerce (product_cat) "Promocje" (np. 231 = promocje-bestmarket). Produkt z aktywną ceną promocyjną dostaje tę kategorię dodatkowo (bez usuwania innych), a traci ją, gdy promocja się kończy. Zostaw 0, aby wyłączyć.</div>
                        </div>

                        <div class="blk-input-group">
                            <label for="blk_warehouse_id">Warehouse ID</label>
                            <input type="text" id="blk_warehouse_id"
                                   name="blk_settings[blk_warehouse_id]"
                                   class="blk-input" style="width: 200px;"
                                   value="<?php echo esc_attr( $blk_settings['blk_warehouse_id'] ?? '' ); ?>"
                                   placeholder="e.g. bl_206">
                            <div class="help">Warehouse ID for stock (e.g. "bl_206"). Leave empty to sum all warehouses.</div>
                        </div>

                        <div class="blk-input-group">
                            <label for="blk_default_language">Default Language</label>
                            <input type="text" id="blk_default_language"
                                   name="blk_settings[blk_default_language]"
                                   class="blk-input" style="width: 100px;"
                                   value="<?php echo esc_attr( $blk_settings['blk_default_language'] ?? 'pl' ); ?>"
                                   placeholder="pl">
                            <div class="help">Language code for product names/descriptions (e.g. "pl", "en", "de").</div>
                        </div>
                    </div>
                </div>

                <!-- Sync Settings -->
                <div class="blk-card">
                    <div class="blk-card-header">
                        <h3><span class="dashicons dashicons-clock"></span> Sync Settings</h3>
                    </div>
                    <div class="blk-card-body">
                        <div class="blk-input-group">
                            <label for="blk_cron_interval">Cron Interval (minutes)</label>
                            <input type="number" id="blk_cron_interval"
                                   name="blk_settings[blk_cron_interval]"
                                   class="blk-input" style="width: 120px;"
                                   value="<?php echo esc_attr( $blk_settings['blk_cron_interval'] ?? 60 ); ?>"
                                   min="5" max="1440">
                            <div class="help">How often to auto-sync. Recommended: 20&ndash;60 minutes.</div>
                        </div>

                        <div class="blk-input-group">
                            <label for="blk_lock_file_max_lifetime">Lock File Max Lifetime (minutes)</label>
                            <input type="number" id="blk_lock_file_max_lifetime"
                                   name="blk_settings[blk_lock_file_max_lifetime]"
                                   class="blk-input" style="width: 120px;"
                                   value="<?php echo esc_attr( $blk_settings['blk_lock_file_max_lifetime'] ?? 10 ); ?>"
                                   min="5" max="120">
                            <div class="help">Auto-release lock after this time. Recommended: 10&ndash;30 minutes.</div>
                        </div>
                    </div>
                </div>

                <!-- Ignore Lists -->
                <div class="blk-card blk-card-full">
                    <div class="blk-card-header">
                        <h3><span class="dashicons dashicons-hidden"></span> Ignore Rules</h3>
                    </div>
                    <div class="blk-card-body">
                        <div class="blk-grid blk-grid-2">
                            <div class="blk-input-group">
                                <label for="blk_categories_to_ignore">Categories to Ignore</label>
                                <textarea id="blk_categories_to_ignore"
                                          name="blk_settings[blk_categories_to_ignore]"
                                          class="blk-input"
                                          rows="5"
                                          placeholder="e.g. 123, 456, 789"><?php echo esc_textarea( $blk_settings['blk_categories_to_ignore'] ?? '' ); ?></textarea>
                                <div class="help">Comma-separated BaseLinker category IDs to exclude from sync.</div>
                            </div>
                            <div class="blk-input-group">
                                <label for="blk_skus_to_ignore">SKUs to Ignore</label>
                                <textarea id="blk_skus_to_ignore"
                                          name="blk_settings[blk_skus_to_ignore]"
                                          class="blk-input"
                                          rows="5"
                                          placeholder="e.g. SKU-001, SKU-002"><?php echo esc_textarea( $blk_settings['blk_skus_to_ignore'] ?? '' ); ?></textarea>
                                <div class="help">Comma-separated SKUs to exclude from sync.</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div style="margin-top: 24px;">
                <button type="submit" class="blk-btn blk-btn-success">
                    <span class="dashicons dashicons-saved"></span> Save Settings
                </button>
            </div>
        </form>
        <?php
    }


    /**
     * Get sorted log files: debug first, then sync logs, newest first.
     */
    private function get_sorted_log_files( $directory ) {
        if ( ! is_dir( $directory ) ) {
            return [];
        }

        $debug_files = [];
        $sync_files  = [];
        $error_files = [];

        $handle = opendir( $directory );
        if ( ! $handle ) {
            return [];
        }

        while ( false !== ( $entry = readdir( $handle ) ) ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            if ( strpos( $entry, 'debug-' ) === 0 ) {
                $debug_files[] = $entry;
            } elseif ( strpos( $entry, 'synchronizer-' ) === 0 ) {
                $sync_files[] = $entry;
            } elseif ( strpos( $entry, 'errors-' ) === 0 ) {
                $error_files[] = $entry;
            }
        }
        closedir( $handle );

        rsort( $debug_files );
        rsort( $sync_files );
        rsort( $error_files );

        return array_merge( $debug_files, $error_files, $sync_files );
    }
}

new BlkSettingsPage();
