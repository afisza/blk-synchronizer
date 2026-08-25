<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BlkEndpoints {

    /** @var BlkSynchronizer */
    public BlkSynchronizer $bs;

    public function __construct( BlkSynchronizer $bs ) {
        $this->bs = $bs;
        add_action( 'init', [ $this, 'register_endpoints' ] );
    }

    /**
     * Register query-string based endpoints for external triggers.
     */
    public function register_endpoints(): void {
        $action = sanitize_text_field( $_GET['action'] ?? '' );
        $method = sanitize_text_field( $_GET['method'] ?? '' );

        if ( $action !== 'blkSynchronizer' ) {
            return;
        }

        switch ( $method ) {
            case 'synchronize':
                if ( function_exists( 'ignore_user_abort' ) ) {
                    ignore_user_abort( true );
                }
                try {
                    $this->bs->startSynchronize();
                } catch ( Exception $e ) {
                    blk_error_log( 'Endpoint synchronize error: ' . $e->getMessage() );
                }
                break;

            default:
                blk_error_log( 'Unknown endpoint method called: ' . $method );
                break;
        }
    }
}
