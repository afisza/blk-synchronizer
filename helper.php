<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function blk_synk_log( $value, $date = '' ) {
    if ( ! file_exists( BLK_SYNCHRONIZER_LOGS_PATH ) ) {
        if (!mkdir( BLK_SYNCHRONIZER_LOGS_PATH, 0755, true )) {
            error_log('Failed to create log directory: ' . BLK_SYNCHRONIZER_LOGS_PATH);
            return;
        }
    }

    $log_file = 'synchronizer-' . ( empty( $date ) ? date_i18n('Y-m-d-H-i-s') : $date ) . '.log';
    $value = is_scalar($value) ? $value : print_r($value, true);

    if (false === file_put_contents(BLK_SYNCHRONIZER_LOGS_PATH . $log_file, date_i18n('Y-m-d H:i:s') . ' ' . $value . "\n", FILE_APPEND)) {
        error_log('Failed to write to synchronizer log file: ' . $log_file);
    }
}

function blk_debug_log( $value ) {
    if ( ! file_exists( BLK_SYNCHRONIZER_LOGS_PATH ) ) {
        if (!mkdir( BLK_SYNCHRONIZER_LOGS_PATH, 0755, true )) {
            error_log('Failed to create log directory: ' . BLK_SYNCHRONIZER_LOGS_PATH);
            return;
        }
    }

    $log_file = 'debug-' . date_i18n('Y-m-d') . '.log';
    $value = is_scalar($value) ? $value : print_r($value, true);

    if (false === file_put_contents(BLK_SYNCHRONIZER_LOGS_PATH . $log_file, date_i18n('Y-m-d H:i:s') . ' ' . $value . "\n", FILE_APPEND)) {
        error_log('Failed to write to debug log file: ' . $log_file);
    }
}

function blk_error_log( $value ) {
    if ( ! file_exists( BLK_SYNCHRONIZER_LOGS_PATH ) ) {
        if (!mkdir( BLK_SYNCHRONIZER_LOGS_PATH, 0755, true )) {
            error_log('Failed to create log directory: ' . BLK_SYNCHRONIZER_LOGS_PATH);
            return;
        }
    }

    $log_file = 'errors-' . date_i18n('Y-m-d') . '.log';
    $value = is_scalar($value) ? $value : print_r($value, true);

    if (false === file_put_contents(BLK_SYNCHRONIZER_LOGS_PATH . $log_file, date_i18n('Y-m-d H:i:s') . ' ' . $value . "\n", FILE_APPEND)) {
        error_log('Failed to write to errors log file: ' . $log_file);
    }
}
