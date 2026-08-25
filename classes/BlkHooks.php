<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BlkHooks {

    /** @var BlkSynchronizer */
    public $bs;

    public function __construct( BlkSynchronizer $bs ) {
        $this->bs = $bs;
        add_action( 'init', [ $this, 'initialize_hooks' ] );
    }

    /**
     * Register WordPress hooks.
     */
    public function initialize_hooks() {
        add_filter( 'cron_schedules', [ $this, 'add_custom_cron_intervals' ] );
        add_action( 'blkSynchronizer', [ $this, 'start_synchronize' ] );
        add_action( 'update_option_blk_settings', [ $this, 'change_cron_interval' ], 10, 2 );

        // AJAX handler with nonce + capability check
        add_action( 'wp_ajax_blk_start_import', [ $this, 'handle_manual_import' ] );

        // Self-healing: the sync event used to be scheduled only when the
        // interval setting changed, so it was silently lost on plugin
        // reinstall/deactivation and never came back ("Not scheduled").
        // Re-create it whenever it is missing.
        $this->maybe_schedule_sync();
    }

    /**
     * Ensure the recurring sync event exists. Safe to call on every request.
     */
    public function maybe_schedule_sync() {
        if ( ! wp_next_scheduled( 'blkSynchronizer' ) ) {
            wp_schedule_event( time(), 'blk_custom_interval', 'blkSynchronizer' );
            if ( function_exists( 'blk_debug_log' ) ) {
                blk_debug_log( 'Cron event blkSynchronizer was missing - re-scheduled automatically (self-heal).' );
            }
        }
    }

    /**
     * Handle manual import AJAX request.
     */
    public function handle_manual_import() {
        check_ajax_referer( BLK_NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        blk_debug_log( 'Manual sync triggered via AJAX.' );
        $this->start_synchronize();
        wp_send_json_success( 'Synchronization started.' );
    }

    /**
     * Add custom cron interval based on plugin settings.
     */
    public function add_custom_cron_intervals( $schedules ) {
        $blk_settings     = get_option( 'blk_settings' );
        $interval_minutes = intval( $blk_settings['blk_cron_interval'] ?? 60 );

        $schedules['blk_custom_interval'] = [
            'interval' => $interval_minutes * 60,
            'display'  => sprintf( 'Every %d minutes', $interval_minutes ),
        ];

        return $schedules;
    }

    /**
     * Run synchronization with log cleanup.
     */
    public function start_synchronize() {
        $this->delete_old_logs();
        $this->bs->startSynchronize();
    }

    /**
     * Reschedule cron when interval setting changes.
     */
    public function change_cron_interval( $old_value, $new_value ) {
        // Compare loosely typed values; the old option may not have the key at
        // all (first save), in which case we still want to (re)schedule.
        $old_interval = intval( is_array( $old_value ) ? ( $old_value['blk_cron_interval'] ?? 0 ) : 0 );
        $new_interval = intval( is_array( $new_value ) ? ( $new_value['blk_cron_interval'] ?? 0 ) : 0 );

        if ( $new_interval > 0 && $old_interval !== $new_interval ) {
            wp_clear_scheduled_hook( 'blkSynchronizer' );
            wp_schedule_event( time(), 'blk_custom_interval', 'blkSynchronizer' );
        }
    }

    /**
     * Delete old log files, keeping only BLK_LOG_MAX_FILES most recent per type.
     */
    public function delete_old_logs() {
        if ( ! defined( 'BLK_SYNCHRONIZER_LOGS_PATH' ) ) {
            return;
        }

        $dir = trailingslashit( BLK_SYNCHRONIZER_LOGS_PATH );
        $max = defined( 'BLK_LOG_MAX_FILES' ) ? BLK_LOG_MAX_FILES : 10;

        $prefixes = [ 'debug-', 'synchronizer-', 'errors-' ];
        $deleted  = [];

        foreach ( $prefixes as $prefix ) {
            $files = glob( $dir . $prefix . '*.log' );
            if ( empty( $files ) ) {
                continue;
            }

            // Sort newest first by modification time
            usort( $files, function( $a, $b ) {
                return filemtime( $b ) - filemtime( $a );
            });

            // Delete everything beyond the limit
            $to_delete = array_slice( $files, $max );
            foreach ( $to_delete as $file ) {
                if ( unlink( $file ) ) {
                    $deleted[] = basename( $file );
                }
            }
        }

        if ( ! empty( $deleted ) ) {
            blk_debug_log( 'Cleaned up ' . count( $deleted ) . ' old log files.' );
        }
    }
}
