<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BaseLinkerHelper {

    private string $token = '';
    private int $max_retries = 3;

    /**
     * Execute a BaseLinker API call with retry logic.
     *
     * @param array $apiParams API parameters (method + parameters)
     * @param int   $attempt   Current attempt number
     * @return array Full decoded response (caller picks the key they need)
     * @throws Exception
     */
    public function exec( array $apiParams, int $attempt = 1 ): array {
        if ( empty( $this->token ) ) {
            $blk_settings = get_option( 'blk_settings' );
            $this->token  = $blk_settings['blk_api_token'] ?? '';
        }

        if ( empty( $this->token ) ) {
            throw new Exception( 'BaseLinker API token is not configured.' );
        }

        $curl = curl_init( 'https://api.baselinker.com/connector.php' );
        curl_setopt_array( $curl, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "X-BLToken: {$this->token}",
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => http_build_query( $apiParams ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $response  = curl_exec( $curl );
        $httpCode  = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
        $curlError = curl_error( $curl );
        curl_close( $curl );

        blk_debug_log( "API call: {$apiParams['method']} - HTTP {$httpCode}" );

        // Retry on network errors or 429/5xx
        if ( ! $response || $httpCode >= 429 ) {
            if ( $attempt < $this->max_retries ) {
                $delay = $attempt * 2;
                blk_debug_log( "API error (attempt {$attempt}/{$this->max_retries}). Retrying in {$delay}s..." );
                sleep( $delay );
                return $this->exec( $apiParams, $attempt + 1 );
            }

            $error_msg = $curlError ?: "HTTP {$httpCode} - Empty response";
            blk_error_log( "API request failed after {$this->max_retries} attempts: {$error_msg}" );
            throw new Exception( "API request failed: {$error_msg}" );
        }

        $decoded = json_decode( $response, true );

        if ( empty( $decoded['status'] ) || $decoded['status'] === 'ERROR' ) {
            $err = $decoded['error_message'] ?? json_encode( $decoded );
            blk_error_log( "API error: {$err}" );
            throw new Exception( "BaseLinker API error: {$err}" );
        }

        usleep( BLK_API_DELAY_MICROSECONDS );

        return $decoded;
    }

    /**
     * Get the inventory_id from settings or auto-detect the default one.
     */
    private function get_inventory_id(): int {
        $settings     = get_option( 'blk_settings' );
        $inventory_id = intval( $settings['blk_inventory_id'] ?? 0 );

        if ( $inventory_id > 0 ) {
            return $inventory_id;
        }

        // Auto-detect: fetch inventories and use the default one
        $inventories = $this->getInventories();
        foreach ( $inventories as $inv ) {
            if ( ! empty( $inv['is_default'] ) ) {
                return intval( $inv['inventory_id'] );
            }
        }

        // Fall back to the first inventory
        if ( ! empty( $inventories ) ) {
            return intval( $inventories[0]['inventory_id'] );
        }

        throw new Exception( 'No BaseLinker inventory found. Please configure inventory_id in settings.' );
    }

    /**
     * Fetch available inventories (catalogs).
     */
    public function getInventories(): array {
        $response = $this->exec( [
            'method'     => 'getInventories',
            'parameters' => '{}',
        ]);

        return $response['inventories'] ?? [];
    }

    /**
     * Fetch paginated product list using the CURRENT API method.
     * Uses getInventoryProductsList (replaces obsolete getProductsList).
     *
     * Returns array of products with keys: id, ean, sku, name, prices (object), stock (object)
     */
    public function getProductsList(): array {
        $inventory_id = $this->get_inventory_id();
        $page         = 1;
        $all_products = [];

        do {
            $params = [
                'inventory_id' => $inventory_id,
                'page'         => $page,
            ];

            blk_debug_log( "getInventoryProductsList page {$page} (inventory: {$inventory_id})..." );

            $response = $this->exec( [
                'method'     => 'getInventoryProductsList',
                'parameters' => wp_json_encode( $params ),
            ]);

            $products = $response['products'] ?? [];

            if ( empty( $products ) ) {
                break;
            }

            // Products come as assoc array keyed by product_id
            $product_list = [];
            foreach ( $products as $pid => $product ) {
                $product['product_id'] = intval( $pid );
                $product_list[]        = $product;
            }

            blk_debug_log( 'Fetched ' . count( $product_list ) . " products from page {$page}" );
            $all_products = array_merge( $all_products, $product_list );
            $page++;
        } while ( count( $products ) >= 1000 );

        blk_debug_log( 'Total products from getInventoryProductsList: ' . count( $all_products ) );
        return $all_products;
    }

    /**
     * Fetch full product data by IDs using the CURRENT API method.
     * Uses getInventoryProductsData (replaces obsolete getProductsData).
     *
     * Returns assoc array keyed by product_id with full product details.
     */
    public function getProducts( array $productIds ): array {
        $productIds = array_filter( $productIds, 'is_numeric' );

        if ( empty( $productIds ) ) {
            blk_error_log( 'No valid product IDs provided to getProducts.' );
            return [];
        }

        $inventory_id = $this->get_inventory_id();
        $chunks       = array_chunk( $productIds, BLK_API_CHUNK_SIZE );
        $all_products = [];

        foreach ( $chunks as $index => $chunkIds ) {
            $params = [
                'inventory_id' => $inventory_id,
                'products'     => array_map( 'intval', array_values( $chunkIds ) ),
            ];

            $response = $this->exec( [
                'method'     => 'getInventoryProductsData',
                'parameters' => wp_json_encode( $params ),
            ]);

            $products = $response['products'] ?? [];

            if ( empty( $products ) ) {
                blk_error_log( 'Empty result for product chunk ' . ( $index + 1 ) );
                continue;
            }

            blk_debug_log( 'Fetched ' . count( $products ) . ' products for chunk ' . ( $index + 1 ) );

            foreach ( $products as $pid => $product ) {
                $product['product_id'] = intval( $pid );
                $all_products[ $pid ]  = $product;
            }
        }

        blk_debug_log( 'Total products from getInventoryProductsData: ' . count( $all_products ) );
        return $all_products;
    }

    /**
     * Fetch inventory categories.
     */
    public function getCategories(): array {
        $response = $this->exec( [
            'method'     => 'getInventoryCategories',
            'parameters' => '{}',
        ]);

        return $response['categories'] ?? [];
    }
}
