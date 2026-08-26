<?php
/**
 * @package       BaseLinker Synchronizer
 * @author        Alex Shram
 *
 * @wordpress-plugin
 * Plugin Name:   BaseLinker Synchronizer Extended
 * Plugin URI:    https://github.com/afisza/blk-synchronizer
 * Description:   Synchronization of BaseLinker and WooCommerce
 * Version:       3.1.1
 * Author:        Alex Shram
 * Author URI:    https://afisza.com/
 * Text Domain:   blk-synchronizer-main
 * Domain Path:   /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'BLK_SYNCHRONIZER_PATH', plugin_dir_path( __FILE__ ) );
define( 'BLK_SYNCHRONIZER_DIR_URL', plugin_dir_url( __FILE__ ) );
define( 'BLK_PLUGIN_VERSION', '3.1.1' );
define( 'BLK_SYNCHRONIZER_LOGS_PATH', WP_CONTENT_DIR . '/uploads/blk-logs/' );
define( 'BLK_SYNCHRONIZER_LOGS_URL', content_url( '/uploads/blk-logs/' ) );
define( 'BLK_LOCK_MAX_AGE', 600 ); // 10 minutes in seconds
define( 'BLK_API_DELAY_MICROSECONDS', 500000 ); // 500ms between API calls
define( 'BLK_API_CHUNK_SIZE', 1000 ); // Products per API chunk
define( 'BLK_LOG_MAX_FILES', 10 ); // Max log files to keep per type (debug, sync, errors)
define( 'BLK_PRODUCT_BATCH_SIZE', 100 ); // Products per DB batch query
define( 'BLK_NONCE_ACTION', 'blk_admin_nonce' );

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

require_once BLK_SYNCHRONIZER_PATH . 'classes/BlkSynchronizer.php';
require_once BLK_SYNCHRONIZER_PATH . 'classes/BaseLinkerHelper.php';
require_once BLK_SYNCHRONIZER_PATH . 'classes/BlkHooks.php';
require_once BLK_SYNCHRONIZER_PATH . 'classes/BlkEndpoints.php';
require_once BLK_SYNCHRONIZER_PATH . 'helper.php';
require_once BLK_SYNCHRONIZER_PATH . 'admin.php';

add_action( 'init', function() {
    load_plugin_textdomain( 'blk-synchronizer-main', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
});

if ( class_exists( BlkSynchronizer::class ) ) {
    $bs        = new BlkSynchronizer();
    $endpoints = new BlkEndpoints( $bs );
    $hooks     = new BlkHooks( $bs );
}

/**
 * Schedule the recurring sync on activation. The activation request runs
 * after `init`, so BlkHooks::initialize_hooks() (and with it the
 * `cron_schedules` filter) has not run - register the custom interval
 * inline before scheduling.
 */
register_activation_hook( __FILE__, function() {
    add_filter( 'cron_schedules', function( $schedules ) {
        $blk_settings     = get_option( 'blk_settings' );
        $interval_minutes = intval( $blk_settings['blk_cron_interval'] ?? 60 );
        $schedules['blk_custom_interval'] = [
            'interval' => $interval_minutes * 60,
            'display'  => sprintf( 'Every %d minutes', $interval_minutes ),
        ];
        return $schedules;
    });

    if ( ! wp_next_scheduled( 'blkSynchronizer' ) ) {
        wp_schedule_event( time(), 'blk_custom_interval', 'blkSynchronizer' );
    }
});

/**
 * Clean up the sync event on deactivation so it is not left orphaned.
 */
register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'blkSynchronizer' );
});

/**
 * Check if import is currently locked (running).
 */
function blk_is_import_locked() {
    $lock_file = BLK_SYNCHRONIZER_PATH . '.import-lock';

    if ( blk_is_stop_import() ) {
        blk_debug_log( 'Import lock ignored due to stop request.' );
        return false;
    }

    if ( file_exists( $lock_file ) ) {
        $file_time = filemtime( $lock_file );
        if ( ( time() - $file_time ) > BLK_LOCK_MAX_AGE ) {
            unlink( $lock_file );
            blk_debug_log( 'Old import lock file removed (expired).' );
            return false;
        }
        return true;
    }

    return false;
}

/**
 * Create the import lock file.
 */
function blk_create_import_lock() {
    file_put_contents( BLK_SYNCHRONIZER_PATH . '.import-lock', date_i18n( 'Y-m-d H:i:s' ) );
    blk_debug_log( 'Import lock file created.' );
}

/**
 * Remove the import lock file.
 */
function blk_remove_import_lock() {
    $lock_file = BLK_SYNCHRONIZER_PATH . '.import-lock';
    if ( file_exists( $lock_file ) ) {
        unlink( $lock_file );
        blk_debug_log( 'Import lock file removed.' );
    }
}

/**
 * Check if stop signal is active.
 */
function blk_is_stop_import() {
    $stop_file = BLK_SYNCHRONIZER_PATH . '.import-stop';

    if ( file_exists( $stop_file ) ) {
        if ( ( time() - filemtime( $stop_file ) ) > BLK_LOCK_MAX_AGE ) {
            unlink( $stop_file );
            return false;
        }
        return true;
    }

    return false;
}

/**
 * Create the import stop file.
 */
function blk_create_import_stop() {
    file_put_contents( BLK_SYNCHRONIZER_PATH . '.import-stop', date_i18n( 'Y-m-d H:i:s' ) );
    blk_debug_log( 'Import stop file created.' );
}

/**
 * Remove the import stop file.
 */
function blk_remove_import_stop() {
    $stop_file = BLK_SYNCHRONIZER_PATH . '.import-stop';
    if ( file_exists( $stop_file ) ) {
        unlink( $stop_file );
        blk_debug_log( 'Import stop file removed.' );
    }
}

// AJAX: Test API connection
add_action( 'wp_ajax_blk_test_connection', function() {
    check_ajax_referer( BLK_NONCE_ACTION, 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    try {
        $blk         = new BaseLinkerHelper();
        $inventories = $blk->getInventories();

        if ( empty( $inventories ) ) {
            wp_send_json_error( 'Connected but no inventories found.' );
        }

        $data = [];
        foreach ( $inventories as $inv ) {
            $data[] = [
                'inventory_id'        => $inv['inventory_id'],
                'name'                => $inv['name'],
                'is_default'          => $inv['is_default'] ?? false,
                'default_price_group' => $inv['default_price_group'] ?? 0,
                'default_warehouse'   => $inv['default_warehouse'] ?? '',
                'default_language'    => $inv['default_language'] ?? '',
                'languages'           => $inv['languages'] ?? [],
                'price_groups'        => $inv['price_groups'] ?? [],
                'warehouses'          => $inv['warehouses'] ?? [],
            ];
        }

        wp_send_json_success( [
            'message'     => 'Connection successful! Found ' . count( $inventories ) . ' inventory catalog(s).',
            'inventories' => $data,
        ]);
    } catch ( Exception $e ) {
        wp_send_json_error( 'Connection failed: ' . $e->getMessage() );
    }
});

// AJAX: Get sync progress
add_action( 'wp_ajax_blk_sync_progress', function() {
    check_ajax_referer( BLK_NONCE_ACTION, 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $progress_file = BLK_SYNCHRONIZER_PATH . '.sync-progress.json';
    if ( file_exists( $progress_file ) ) {
        $data = json_decode( file_get_contents( $progress_file ), true );
        wp_send_json_success( $data );
    } else {
        wp_send_json_success( [ 'step' => '', 'percent' => 0, 'message' => '' ] );
    }
});

// AJAX: Stop import (with nonce + capability check)
add_action( 'wp_ajax_blk_stop_import', function() {
    check_ajax_referer( BLK_NONCE_ACTION, 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    blk_debug_log( 'AJAX request received: blk_stop_import' );
    blk_create_import_stop();
    wp_send_json_success( 'Stop import file created.' );
});

// AJAX: Sync status (with nonce + capability check)
add_action( 'wp_ajax_blk_sync_status', function() {
    check_ajax_referer( BLK_NONCE_ACTION, 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $status    = blk_is_import_locked() ? 'in_progress' : 'completed';
    $next_sync = wp_next_scheduled( 'blkSynchronizer' );

    wp_send_json_success( [
        'status'            => $status,
        'next_sync'         => $next_sync ? intval( $next_sync ) : 0,
        'next_sync_display' => $next_sync ? date_i18n( 'H:i:s', $next_sync + ( get_option( 'gmt_offset' ) * 3600 ) ) : '',
    ] );
});

// Plugin Update Checker
require_once BLK_SYNCHRONIZER_PATH . 'vendor/autoload.php';
$UpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/afisza/blk-synchronizer',
    __FILE__,
    'blk-synchronizer'
);
$UpdateChecker->setBranch( 'main' );
