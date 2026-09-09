<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BlkSynchronizer {

    private $synk_log_file_date;
    private $ignored_products   = 0;
    private $deleted_ignored    = 0;
    private $price_group_id     = 0;
    private $price_group_id_before_promo = 0;
    private $promo_category_id  = 0;
    private $warehouse_id       = '';
    private $default_language    = 'pl';

    /**
     * Write sync progress for the frontend progress bar.
     */
    private function update_progress( $step, $percent, $message = '', $stats = null ) {
        $data = [
            'step'    => $step,
            'percent' => min( 100, max( 0, $percent ) ),
            'message' => $message,
            'time'    => date_i18n( 'H:i:s' ),
            'stats'   => $stats,
        ];
        file_put_contents( BLK_SYNCHRONIZER_PATH . '.sync-progress.json', wp_json_encode( $data ) );
    }

    /**
     * Clean up progress file.
     */
    private function clear_progress() {
        $file = BLK_SYNCHRONIZER_PATH . '.sync-progress.json';
        if ( file_exists( $file ) ) {
            unlink( $file );
        }
    }

    /**
     * Start the full synchronization process.
     */
    public function startSynchronize() {
        blk_debug_log( 'Synchronization started.' );
        define( 'BLK_IS_SYNC_IN_PROGRESS', true );

        blk_remove_import_stop();

        if ( blk_is_import_locked() ) {
            blk_debug_log( 'Synchronization already in process. Exiting.' );
            return;
        }

        blk_create_import_lock();
        $this->load_inventory_config();

        $start_time                = microtime( true );
        $this->synk_log_file_date = date_i18n( 'Y-m-d-H-i-s' );

        // Step 1: Download products from BaseLinker
        $this->update_progress( 'download', 5, 'Downloading products from BaseLinker...' );
        $this->downloadProducts();

        // Step 2: Filter products by ignore settings
        $this->update_progress( 'filter', 20, 'Filtering products...' );
        $new_products = $this->filterNewProducts();

        // Step 3: Fetch current WooCommerce products
        $this->update_progress( 'fetch_wc', 30, 'Fetching WooCommerce products...' );
        $old_products = $this->prepareOldProducts();

        if ( empty( $old_products ) ) {
            blk_error_log( 'Old products data is empty or failed to load.' );
        }
        if ( empty( $new_products ) ) {
            blk_error_log( 'New products data is empty or failed to load.' );
        }

        // Step 4: Analyze changes
        $this->update_progress( 'analyze', 40, 'Analyzing changes...' );
        $products_to_add    = $this->find_added_products( $old_products, $new_products );
        $products_to_delete = $this->find_removed_products( $old_products, $new_products );
        $products_to_update = $this->find_updated_products( $old_products, $new_products );

        $total_ops = count( $products_to_add ) + count( $products_to_delete ) + count( $products_to_update );
        blk_debug_log( "To add: " . count( $products_to_add ) . ", remove: " . count( $products_to_delete ) . ", update: " . count( $products_to_update ) );

        // Step 5: Execute operations (45%–95%)
        $this->update_progress( 'remove', 45, 'Removing ' . count( $products_to_delete ) . ' products...' );
        $this->remove_products( $products_to_delete );

        $this->update_progress( 'update', 60, 'Updating ' . count( $products_to_update ) . ' products...' );
        $this->update_products( $products_to_update );

        $this->update_progress( 'add', 80, 'Adding ' . count( $products_to_add ) . ' new products...' );
        $this->add_new_products( $products_to_add );

        // Done
        $elapsed = number_format( microtime( true ) - $start_time, 2 );
        $this->update_progress( 'done', 100, "Completed in {$elapsed}s. Added: " . count( $products_to_add ) . ", Updated: " . count( $products_to_update ) . ", Removed: " . count( $products_to_delete ), [
            'duration' => $elapsed . 's',
            'added'    => count( $products_to_add ),
            'updated'  => count( $products_to_update ),
            'removed'  => count( $products_to_delete ),
            'ignored'  => $this->ignored_products,
        ] );
        blk_debug_log( "Synchronization completed in {$elapsed} seconds." );
        blk_debug_log( 'Ignored: ' . $this->ignored_products . ', Deleted ignored: ' . $this->deleted_ignored );

        blk_remove_import_lock();

        // Keep progress visible for 10 seconds, then clean up
        // (frontend will read it before it's gone)
    }

    /**
     * Load inventory configuration (price group, warehouse, language) from settings
     * or auto-detect from the API.
     */
    private function load_inventory_config() {
        $settings = get_option( 'blk_settings' );

        $this->price_group_id  = intval( $settings['blk_price_group_id'] ?? 0 );
        $this->price_group_id_before_promo = intval( $settings['blk_price_group_id_before_promo'] ?? 0 );
        $this->promo_category_id = intval( $settings['blk_promo_category_id'] ?? 0 );
        $this->warehouse_id    = $settings['blk_warehouse_id'] ?? '';
        $this->default_language = $settings['blk_default_language'] ?? 'pl';

        // Auto-detect from inventory if not configured
        if ( $this->price_group_id <= 0 || empty( $this->warehouse_id ) ) {
            try {
                $blk         = new BaseLinkerHelper();
                $inventories = $blk->getInventories();
                $inventory_id = intval( $settings['blk_inventory_id'] ?? 0 );

                foreach ( $inventories as $inv ) {
                    $match = ( $inventory_id > 0 && intval( $inv['inventory_id'] ) === $inventory_id )
                             || ( ! empty( $inv['is_default'] ) );

                    if ( $match ) {
                        if ( $this->price_group_id <= 0 && ! empty( $inv['default_price_group'] ) ) {
                            $this->price_group_id = intval( $inv['default_price_group'] );
                            blk_debug_log( "Auto-detected price_group_id: {$this->price_group_id}" );
                        }
                        if ( empty( $this->warehouse_id ) && ! empty( $inv['default_warehouse'] ) ) {
                            $this->warehouse_id = $inv['default_warehouse'];
                            blk_debug_log( "Auto-detected warehouse_id: {$this->warehouse_id}" );
                        }
                        if ( ! empty( $inv['default_language'] ) ) {
                            $this->default_language = $inv['default_language'];
                        }
                        break;
                    }
                }
            } catch ( Exception $e ) {
                blk_error_log( 'Could not auto-detect inventory config: ' . $e->getMessage() );
            }
        }

        blk_debug_log( "Inventory config: price_group={$this->price_group_id}, warehouse={$this->warehouse_id}, lang={$this->default_language}" );
    }

    /**
     * Extract price_brutto from the new API prices object.
     * prices = { "price_group_id": gross_price, ... }
     */
    private function extract_price( $prices ) {
        if ( ! is_array( $prices ) || empty( $prices ) ) {
            return 0;
        }

        // Use configured price group
        if ( $this->price_group_id > 0 && isset( $prices[ $this->price_group_id ] ) ) {
            return round( (float) $prices[ $this->price_group_id ], 2 );
        }

        // Fallback: use the first available price
        return round( (float) reset( $prices ), 2 );
    }

    /**
     * Resolve regular/sale price from the "domyślna" and "przed promocją" price
     * groups. Promotion is active only when the "przed promocją" price is
     * configured and strictly higher than the default price; otherwise the
     * sale price is cleared (no promotion).
     */
    private function resolve_pricing( $prices ) {
        $default = $this->extract_price( $prices );

        if ( $this->price_group_id_before_promo <= 0 || ! is_array( $prices ) || ! isset( $prices[ $this->price_group_id_before_promo ] ) ) {
            return [ 'regular' => $default, 'sale' => null ];
        }

        $before_promo = round( (float) $prices[ $this->price_group_id_before_promo ], 2 );

        if ( $before_promo > $default ) {
            return [ 'regular' => $before_promo, 'sale' => $default ];
        }

        return [ 'regular' => $default, 'sale' => null ];
    }

    /**
     * Extract stock quantity from the new API stock object.
     * stock = { "warehouse_id": quantity, ... }
     */
    private function extract_stock( $stock ) {
        if ( ! is_array( $stock ) || empty( $stock ) ) {
            return 0;
        }

        // Use configured warehouse
        if ( ! empty( $this->warehouse_id ) && isset( $stock[ $this->warehouse_id ] ) ) {
            return intval( $stock[ $this->warehouse_id ] );
        }

        // Fallback: sum all warehouses
        return intval( array_sum( $stock ) );
    }

    /**
     * Extract product name from text_fields.
     * text_fields = { "name": "...", "name|de": "...", "description": "...", ... }
     */
    private function extract_name( $text_fields ) {
        if ( ! is_array( $text_fields ) ) {
            return '';
        }

        // Try language-specific name first, then default "name" key
        if ( isset( $text_fields[ 'name|' . $this->default_language ] ) ) {
            return $text_fields[ 'name|' . $this->default_language ];
        }

        return $text_fields['name'] ?? '';
    }

    /**
     * Extract product description from text_fields.
     */
    private function extract_description( $text_fields ) {
        if ( ! is_array( $text_fields ) ) {
            return '';
        }

        if ( isset( $text_fields[ 'description|' . $this->default_language ] ) ) {
            return $text_fields[ 'description|' . $this->default_language ];
        }

        return $text_fields['description'] ?? '';
    }

    /**
     * Extract image URLs from images object.
     * images = { "1": "url", "3": "url", ... } (position => url)
     */
    private function extract_images( $images ) {
        if ( ! is_array( $images ) || empty( $images ) ) {
            return [];
        }

        // Sort by position key and return just URLs
        ksort( $images );
        return array_values( array_filter( $images, 'strlen' ) );
    }

    /**
     * Normalize a product from the new API format to our internal format.
     */
    private function normalize_product( $product ) {
        $text_fields = $product['text_fields'] ?? [];
        $name        = $this->extract_name( $text_fields );
        $description = $this->clean_description( $this->extract_description( $text_fields ) );
        $pricing     = $this->resolve_pricing( $product['prices'] ?? [] );

        return [
            'product_id'        => $product['product_id'] ?? 0,
            'sku'               => $product['sku'] ?? '',
            'ean'               => $product['ean'] ?? '',
            'name'              => $name,
            'description'       => $description,
            'price_brutto'      => $pricing['regular'],
            'price_sale_brutto' => $pricing['sale'],
            'quantity'     => $this->extract_stock( $product['stock'] ?? [] ),
            'weight'       => (string) ( $product['weight'] ?? '0' ),
            'tax_rate'     => $product['tax_rate'] ?? 0,
            'category_id'  => (string) ( $product['category_id'] ?? '' ),
            'images'       => $this->extract_images( $product['images'] ?? [] ),
            'text_fields'  => $text_fields, // Keep original for WPML
        ];
    }

    /**
     * Download products from BaseLinker and save to JSON.
     */
    public function downloadProducts() {
        $blk = new BaseLinkerHelper();

        try {
            $products = $blk->getProductsList();
        } catch ( Exception $e ) {
            blk_error_log( 'Error fetching product list: ' . $e->getMessage() );
            return;
        }

        if ( empty( $products ) ) {
            blk_error_log( 'No products found in BaseLinker.' );
            return;
        }

        blk_debug_log( 'Fetched ' . count( $products ) . ' products from BaseLinker.' );

        // Extract product IDs — from the list response, ID is in 'product_id' or 'id'
        $productIds = [];
        foreach ( $products as $p ) {
            $id = $p['product_id'] ?? $p['id'] ?? 0;
            if ( is_numeric( $id ) && $id > 0 ) {
                $productIds[] = intval( $id );
            }
        }

        if ( empty( $productIds ) ) {
            blk_error_log( 'No valid product IDs found.' );
            return;
        }

        blk_debug_log( 'Valid product IDs: ' . count( $productIds ) );

        try {
            $raw_products = $blk->getProducts( $productIds );
        } catch ( Exception $e ) {
            blk_error_log( 'Error fetching product data: ' . $e->getMessage() );
            return;
        }

        if ( empty( $raw_products ) ) {
            blk_error_log( 'No product data returned from BaseLinker.' );
            return;
        }

        blk_debug_log( 'Fetched details for ' . count( $raw_products ) . ' products.' );

        // Normalize from new API format to internal format
        $normalized = [];
        foreach ( $raw_products as $pid => $product ) {
            $product['product_id'] = intval( $pid );

            // Skip variants (parent_id > 0)
            if ( ! empty( $product['parent_id'] ) && intval( $product['parent_id'] ) > 0 ) {
                continue;
            }

            $normalized[] = $this->normalize_product( $product );
        }

        blk_debug_log( 'Normalized ' . count( $normalized ) . ' main products (variants excluded).' );
        file_put_contents( BLK_SYNCHRONIZER_PATH . 'new-products.json', wp_json_encode( $normalized ) );
    }

    /**
     * Filter products based on ignore settings.
     */
    private function filterNewProducts() {
        $file = BLK_SYNCHRONIZER_PATH . 'new-products.json';
        if ( ! file_exists( $file ) ) {
            blk_error_log( 'new-products.json does not exist.' );
            return [];
        }

        $new_products = json_decode( file_get_contents( $file ), true );
        $options      = get_option( 'blk_settings' );

        $categories_to_skip = array_filter( array_map( 'trim', explode( ',', $options['blk_categories_to_ignore'] ?? '' ) ), 'strlen' );
        $skus_to_skip       = array_filter( array_map( 'trim', explode( ',', $options['blk_skus_to_ignore'] ?? '' ) ), 'strlen' );

        $filtered = array_filter( $new_products, function( $product ) use ( $categories_to_skip, $skus_to_skip ) {
            if ( isset( $product['category_id'] ) && in_array( $product['category_id'], $categories_to_skip ) ) {
                return false;
            }
            if ( isset( $product['sku'] ) && in_array( $product['sku'], $skus_to_skip ) ) {
                return false;
            }
            return true;
        });

        blk_debug_log( 'Filtered products: ' . count( $filtered ) . ' (after ignore settings).' );
        return $filtered;
    }

    /**
     * Check if a product should be skipped based on ignore settings.
     */
    private function check_for_skip( $blk_product ) {
        if ( empty( $blk_product['category_id'] ) || empty( $blk_product['sku'] ) ) {
            blk_error_log( 'Skipping product: Missing category_id or SKU.' );
            return true;
        }

        $options            = get_option( 'blk_settings' );
        $categories_to_skip = array_filter( array_map( 'trim', explode( ',', $options['blk_categories_to_ignore'] ?? '' ) ), 'strlen' );
        $skus_to_skip       = array_filter( array_map( 'trim', explode( ',', $options['blk_skus_to_ignore'] ?? '' ) ), 'strlen' );

        if ( in_array( $blk_product['category_id'], $categories_to_skip, true ) ) {
            blk_synk_log( 'Product "' . $blk_product['name'] . '" ignored by category (' . $blk_product['category_id'] . ')', $this->synk_log_file_date );
            return true;
        }

        if ( in_array( $blk_product['sku'], $skus_to_skip, true ) ) {
            blk_synk_log( 'Product "' . $blk_product['name'] . '" ignored by SKU (' . $blk_product['sku'] . ')', $this->synk_log_file_date );
            return true;
        }

        return false;
    }

    /**
     * Fetch WooCommerce products in batches.
     */
    private function prepareOldProducts() {
        blk_debug_log( 'Fetching WooCommerce products in batches...' );

        $formatted_products = [];
        $page               = 1;
        $batch_size         = BLK_PRODUCT_BATCH_SIZE;

        do {
            $query    = new WC_Product_Query( [
                'limit'  => $batch_size,
                'page'   => $page,
                'status' => 'publish',
                'return' => 'objects',
            ]);
            $products = $query->get_products();

            if ( empty( $products ) ) {
                break;
            }

            foreach ( $products as $product ) {
                $regular = $product->get_regular_price();
                $sale    = $product->get_sale_price();

                $formatted_products[] = [
                    'sku'          => $product->get_sku(),
                    'ean'          => $product->get_meta( 'ean', true ),
                    'name'         => $product->get_name(),
                    'quantity'     => $product->get_stock_quantity(),
                    'price_netto'  => wc_get_price_excluding_tax( $product ),
                    'price_brutto' => round( (float) $regular, 2 ),
                    'price_sale_brutto' => $sale !== '' ? round( (float) $sale, 2 ) : null,
                    'weight'       => $product->get_weight(),
                    'category_id'  => $product->get_meta( 'blk_category_id', true ),
                    'images'       => $product->get_meta( 'blk_images', true ) ?: [],
                    'description'  => $product->get_description(),
                ];

                // Record today's actually-charged price for every product on
                // every sync run (not just add/update), so a promotion that
                // starts after a long flat-price streak still has real
                // pre-reduction history to compare against (Omnibus).
                if ( $regular !== '' ) {
                    $this->record_omnibus_history( $product->get_id(), [
                        'price_brutto'      => round( (float) $regular, 2 ),
                        'price_sale_brutto' => $sale !== '' ? round( (float) $sale, 2 ) : null,
                    ]);
                }

                // Reconcile the "Promocje" category against actual current
                // state on every sync run too (not just add/update), so
                // products whose promo was already active before this
                // feature existed get categorized retroactively.
                $this->sync_promo_category( $product->get_id(), $sale !== '' );
            }

            blk_debug_log( "Batch {$page}: fetched " . count( $products ) . ' products.' );
            $page++;
        } while ( count( $products ) === $batch_size );

        blk_debug_log( 'Total WooCommerce products: ' . count( $formatted_products ) );
        file_put_contents( BLK_SYNCHRONIZER_PATH . 'old-products.json', wp_json_encode( $formatted_products ) );

        return $formatted_products;
    }

    /**
     * Find products in BaseLinker but not in WooCommerce.
     */
    private function find_added_products( $old_products, $new_products ) {
        $old_skus_map = array_flip( array_filter( array_column( $old_products, 'sku' ), 'is_string' ) );

        return array_filter( $new_products, function( $p ) use ( $old_skus_map ) {
            return isset( $p['sku'] ) && ! isset( $old_skus_map[ $p['sku'] ] );
        });
    }

    /**
     * Find products in WooCommerce but not in BaseLinker.
     */
    private function find_removed_products( $old_products, $new_products ) {
        $new_skus_map = array_flip( array_filter( array_column( $new_products, 'sku' ), 'is_string' ) );

        return array_filter( $old_products, function( $p ) use ( $new_skus_map ) {
            return isset( $p['sku'] ) && ! isset( $new_skus_map[ $p['sku'] ] );
        });
    }

    /**
     * Find products in both but with changed data.
     */
    private function find_updated_products( $old_products, $new_products ) {
        $old_indexed = [];
        foreach ( $old_products as $p ) {
            if ( isset( $p['sku'] ) ) {
                $old_indexed[ $p['sku'] ] = $p;
            }
        }

        $updated = [];
        foreach ( $new_products as $new_product ) {
            $sku = $new_product['sku'] ?? null;
            if ( ! $sku || ! isset( $old_indexed[ $sku ] ) ) {
                continue;
            }
            if ( $this->has_product_changed( $old_indexed[ $sku ], $new_product ) ) {
                $updated[] = $new_product;
            }
        }

        blk_debug_log( 'Found ' . count( $updated ) . ' updated products.' );
        return $updated;
    }

    /**
     * Check if product data has changed.
     */
    private function has_product_changed( $old, $new ) {
        $keys = [ 'name', 'price_brutto', 'price_sale_brutto', 'weight', 'quantity', 'description', 'images' ];

        foreach ( $keys as $key ) {
            // price_sale_brutto can legitimately be null (no promotion) - a
            // transition to/from null is itself a change worth syncing.
            if ( array_key_exists( $key, $old ) && array_key_exists( $key, $new ) && ( $old[ $key ] === null || $new[ $key ] === null ) ) {
                if ( $old[ $key ] !== $new[ $key ] ) {
                    return true;
                }
                continue;
            }

            if ( ! isset( $old[ $key ], $new[ $key ] ) ) {
                continue;
            }

            if ( is_numeric( $old[ $key ] ) && is_numeric( $new[ $key ] ) ) {
                if ( round( (float) $old[ $key ], 2 ) !== round( (float) $new[ $key ], 2 ) ) {
                    return true;
                }
            } elseif ( $old[ $key ] !== $new[ $key ] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add new products to WooCommerce.
     */
    private function add_new_products( $added_products ) {
        if ( ! is_array( $added_products ) ) {
            return;
        }

        foreach ( $added_products as $blk_product ) {
            if ( blk_is_stop_import() ) {
                blk_debug_log( 'Import canceled by user.' );
                blk_remove_import_stop();
                blk_remove_import_lock();
                return;
            }

            if ( $this->check_for_skip( $blk_product ) ) {
                $this->ignored_products++;
                continue;
            }

            try {
                $this->createProduct( $blk_product );
                blk_synk_log( 'Added product: ' . ( $blk_product['sku'] ?? 'unknown' ), $this->synk_log_file_date );
            } catch ( Exception $e ) {
                blk_error_log( 'Error adding product ' . ( $blk_product['sku'] ?? 'unknown' ) . ': ' . $e->getMessage() );
            }
        }
    }

    /**
     * Remove products from WooCommerce.
     */
    private function remove_products( $removed_products ) {
        if ( ! is_array( $removed_products ) ) {
            return;
        }

        foreach ( $removed_products as $blk_product ) {
            if ( blk_is_stop_import() ) {
                blk_debug_log( 'Import canceled by user.' );
                blk_remove_import_stop();
                blk_remove_import_lock();
                return;
            }

            if ( empty( $blk_product['sku'] ) ) {
                continue;
            }

            $product_id = wc_get_product_id_by_sku( $blk_product['sku'] );
            if ( ! $product_id ) {
                continue;
            }

            $wcProduct = wc_get_product( $product_id );
            if ( ! $wcProduct ) {
                continue;
            }

            blk_synk_log( 'Deleting product: ' . $wcProduct->get_sku() . ' - ' . $wcProduct->get_name(), $this->synk_log_file_date );

            if ( wp_delete_post( $product_id, true ) ) {
                $this->deleted_ignored++;
            } else {
                blk_error_log( 'Failed to delete product: ' . $wcProduct->get_sku() );
            }
        }
    }

    /**
     * Update existing products in WooCommerce.
     */
    private function update_products( $changed_products ) {
        if ( ! is_array( $changed_products ) ) {
            return;
        }

        foreach ( $changed_products as $blk_product ) {
            if ( blk_is_stop_import() ) {
                blk_debug_log( 'Import canceled by user.' );
                blk_remove_import_stop();
                blk_remove_import_lock();
                return;
            }

            $sku = $blk_product['sku'] ?? '';
            if ( empty( $sku ) ) {
                continue;
            }

            $wcProductId = wc_get_product_id_by_sku( $sku );
            $wcProduct   = wc_get_product( $wcProductId );

            if ( ! $wcProduct ) {
                blk_error_log( "Product with SKU {$sku} not found in WooCommerce." );
                continue;
            }

            if ( $this->check_for_skip( $blk_product ) ) {
                wp_delete_post( $wcProduct->get_id(), true );
                $this->deleted_ignored++;
                continue;
            }

            try {
                // Update EAN
                if ( ! empty( $blk_product['ean'] ) && is_string( $blk_product['ean'] ) ) {
                    $current_ean = get_post_meta( $wcProduct->get_id(), '_global_unique_id', true );
                    if ( $current_ean !== $blk_product['ean'] ) {
                        update_post_meta( $wcProduct->get_id(), '_global_unique_id', $blk_product['ean'] );
                    }
                }

                $wcProduct->set_regular_price( round( (float) $blk_product['price_brutto'], 2 ) );
                $wcProduct->set_sale_price( $blk_product['price_sale_brutto'] !== null ? round( (float) $blk_product['price_sale_brutto'], 2 ) : '' );
                $wcProduct->set_weight( $blk_product['weight'] );
                $wcProduct->set_name( $blk_product['name'] );
                $wcProduct->set_manage_stock( true );
                $wcProduct->set_stock_quantity( $blk_product['quantity'] );
                $wcProduct->set_description( $blk_product['description'] );

                $this->set_tax_data( $wcProduct, $blk_product );

                $wc_id = $wcProduct->get_id();
                update_post_meta( $wc_id, 'blk_category_id', $blk_product['category_id'] );

                $wcProduct->save();

                update_post_meta( $wc_id, 'blk_images', $blk_product['images'] ?? '' );
                update_post_meta( $wc_id, 'blk_product_id', $blk_product['product_id'] ?? '' );
                update_post_meta( $wc_id, 'jet_product_title', $blk_product['name'] );

                if ( ! empty( $blk_product['images'] ) && function_exists( 'fifu_dev_set_image_list' ) ) {
                    fifu_dev_set_image_list( $wc_id, $this->array_to_string_with_delimiter( $blk_product['images'] ) );
                }

                $this->record_omnibus_history( $wc_id, $blk_product );
                $this->sync_promo_category( $wc_id, $blk_product['price_sale_brutto'] !== null );

                // WPML: Sync to all translations
                $this->sync_wpml_translations( $wc_id, $blk_product );

            } catch ( Exception $e ) {
                blk_error_log( "Error updating product {$sku}: " . $e->getMessage() );
                continue;
            }

            blk_synk_log( sprintf(
                'Update: %s - %s - Price: %.2f - Qty: %d - Weight: %s - EAN: %s',
                $sku, $blk_product['name'], $blk_product['price_brutto'],
                $blk_product['quantity'], $blk_product['weight'], $blk_product['ean'] ?? 'N/A'
            ), $this->synk_log_file_date );
        }
    }

    /**
     * Create or update a WooCommerce product.
     */
    private function createProduct( $blk_product ) {
        if ( empty( $blk_product['sku'] ) || empty( $blk_product['name'] ) ) {
            blk_error_log( 'Missing required product data: SKU or Name.' );
            return;
        }

        $existing_id = wc_get_product_id_by_sku( $blk_product['sku'] );
        $wcProduct   = $existing_id ? wc_get_product( $existing_id ) : null;

        if ( ! $wcProduct ) {
            $wcProduct = new WC_Product_Simple();
        }

        $wcProduct->set_sku( $blk_product['sku'] );
        $wcProduct->set_name( $blk_product['name'] );
        $wcProduct->set_regular_price( $blk_product['price_brutto'] ?? '0' );
        $wcProduct->set_sale_price( isset( $blk_product['price_sale_brutto'] ) && $blk_product['price_sale_brutto'] !== null ? round( (float) $blk_product['price_sale_brutto'], 2 ) : '' );
        $wcProduct->set_weight( $blk_product['weight'] ?? '0' );
        $wcProduct->set_manage_stock( true );
        $wcProduct->set_stock_quantity( $blk_product['quantity'] ?? 0 );
        $wcProduct->set_description( $blk_product['description'] ?? '' );

        $this->set_tax_data( $wcProduct, $blk_product );
        $wcProduct->save();

        if ( ! $wcProduct->get_id() ) {
            blk_error_log( 'Failed to save product: ' . $blk_product['sku'] );
            return;
        }

        $wc_id = $wcProduct->get_id();

        update_post_meta( $wc_id, 'blk_images', $blk_product['images'] ?? '' );
        update_post_meta( $wc_id, 'blk_product_id', $blk_product['product_id'] ?? '' );
        update_post_meta( $wc_id, 'jet_product_title', $blk_product['name'] );
        update_post_meta( $wc_id, 'blk_category_id', $blk_product['category_id'] ?? '' );

        if ( ! empty( $blk_product['ean'] ) && is_string( $blk_product['ean'] ) ) {
            update_post_meta( $wc_id, '_global_unique_id', $blk_product['ean'] );
        }

        if ( ! empty( $blk_product['images'] ) && function_exists( 'fifu_dev_set_image_list' ) ) {
            fifu_dev_set_image_list( $wc_id, $this->array_to_string_with_delimiter( $blk_product['images'] ) );
        }

        $this->record_omnibus_history( $wc_id, $blk_product );
        $this->sync_promo_category( $wc_id, $blk_product['price_sale_brutto'] !== null );

        // WPML: Sync to all translations
        $this->sync_wpml_translations( $wc_id, $blk_product );

        $action = $existing_id ? 'Updated' : 'Created';
        blk_synk_log( "{$action} product: {$wcProduct->get_sku()} - {$wcProduct->get_name()}", $this->synk_log_file_date );
    }

    /**
     * Set tax status and class on a WC product.
     */
    private function set_tax_data( $wcProduct, $blk_product ) {
        $tax_rate = $blk_product['tax_rate'] ?? null;

        if ( $tax_rate === null || $tax_rate < 0 ) {
            return;
        }

        $tax_rate = (int) $tax_rate;
        $valid_rates = [ 23, 8, 5 ];

        if ( in_array( $tax_rate, $valid_rates, true ) ) {
            $wcProduct->set_tax_status( 'taxable' );
            $wcProduct->set_tax_class( (string) $tax_rate );
        }
    }

    /**
     * Sync product data to all WPML translation versions.
     */
    private function sync_wpml_translations( $product_id, $blk_product ) {
        if ( ! function_exists( 'icl_object_id' ) ) {
            return;
        }

        global $sitepress;
        if ( ! $sitepress || ! method_exists( $sitepress, 'get_element_trid' ) ) {
            return;
        }

        $trid = $sitepress->get_element_trid( $product_id, 'post_product' );
        if ( ! $trid ) {
            return;
        }

        $translations = $sitepress->get_element_translations( $trid, 'post_product' );
        if ( empty( $translations ) ) {
            return;
        }

        $text_fields = $blk_product['text_fields'] ?? [];

        foreach ( $translations as $lang_code => $translation ) {
            $translated_id = (int) $translation->element_id;

            if ( $translated_id === $product_id ) {
                continue;
            }

            $translated_product = wc_get_product( $translated_id );
            if ( ! $translated_product ) {
                continue;
            }

            try {
                // Sync price
                $translated_product->set_regular_price( round( (float) $blk_product['price_brutto'], 2 ) );
                $translated_product->set_sale_price( isset( $blk_product['price_sale_brutto'] ) && $blk_product['price_sale_brutto'] !== null ? round( (float) $blk_product['price_sale_brutto'], 2 ) : '' );

                // Sync stock
                $translated_product->set_manage_stock( true );
                $translated_product->set_stock_quantity( $blk_product['quantity'] ?? 0 );

                // Sync weight
                if ( isset( $blk_product['weight'] ) ) {
                    $translated_product->set_weight( $blk_product['weight'] );
                }

                // Sync translated name/description if available in text_fields
                if ( ! empty( $text_fields ) ) {
                    $lang_name = $text_fields[ "name|{$lang_code}" ] ?? '';
                    $lang_desc = $text_fields[ "description|{$lang_code}" ] ?? '';

                    if ( ! empty( $lang_name ) ) {
                        $translated_product->set_name( $lang_name );
                        update_post_meta( $translated_id, 'jet_product_title', $lang_name );
                    }
                    if ( ! empty( $lang_desc ) ) {
                        $translated_product->set_description( $this->clean_description( $lang_desc ) );
                    }
                }

                $this->set_tax_data( $translated_product, $blk_product );
                $translated_product->save();

                // Sync EAN
                if ( ! empty( $blk_product['ean'] ) && is_string( $blk_product['ean'] ) ) {
                    update_post_meta( $translated_id, '_global_unique_id', $blk_product['ean'] );
                }

                // Omnibus history is per-post-ID - each translation is a
                // separate WC product, so it needs its own tracked history.
                $this->record_omnibus_history( $translated_id, $blk_product );
                $this->sync_promo_category( $translated_id, $blk_product['price_sale_brutto'] !== null );

                blk_debug_log( "WPML: Synced {$blk_product['sku']} to [{$lang_code}] (ID: {$translated_id})" );
            } catch ( Exception $e ) {
                blk_error_log( "WPML sync error for {$blk_product['sku']} [{$lang_code}]: " . $e->getMessage() );
            }
        }
    }

    /**
     * Record today's actually-charged price (sale price if active, otherwise
     * regular price) into a 30-day rolling history, and cache the lowest
     * price found in that history (excluding today) for Omnibus-directive
     * display. Idempotent per day - repeated syncs on the same day just
     * overwrite today's entry.
     */
    private function record_omnibus_history( $wc_id, $blk_product ) {
        $today          = date_i18n( 'Y-m-d' );
        $charged_price  = $blk_product['price_sale_brutto'] ?? $blk_product['price_brutto'] ?? null;

        if ( $charged_price === null ) {
            return;
        }

        $history         = get_post_meta( $wc_id, '_blk_omnibus_price_history', true );
        $history         = is_array( $history ) ? $history : [];
        $history[ $today ] = round( (float) $charged_price, 2 );

        $cutoff = date_i18n( 'Y-m-d', strtotime( '-30 days' ) );
        foreach ( $history as $date => $price ) {
            if ( $date < $cutoff ) {
                unset( $history[ $date ] );
            }
        }

        $history_before_today = $history;
        unset( $history_before_today[ $today ] );

        // Fallback: no history yet (new product, or first time we've tracked
        // it) - default to today's regular price rather than showing nothing.
        // This can never overstate the discount (regular >= sale by design).
        $lowest_30d = ! empty( $history_before_today )
            ? min( $history_before_today )
            : round( (float) ( $blk_product['price_brutto'] ?? $charged_price ), 2 );

        update_post_meta( $wc_id, '_blk_omnibus_price_history', $history );
        update_post_meta( $wc_id, '_blk_omnibus_lowest_30d', $lowest_30d );
    }

    /**
     * Append/remove the "Promocje" product category based on whether a
     * promotional (sale) price is currently active. Mirrors the behaviour
     * of the old "Best Market — Aktualizacja Cen (Google Sheet)" plugin:
     * always appends (never replaces existing categories), and only ever
     * touches this one term when removing.
     */
    private function sync_promo_category( $wc_id, $has_promo ) {
        if ( $this->promo_category_id <= 0 ) {
            return;
        }

        $in_cat = has_term( $this->promo_category_id, 'product_cat', $wc_id );

        if ( $has_promo && ! $in_cat ) {
            wp_set_object_terms( $wc_id, [ $this->promo_category_id ], 'product_cat', true );
        } elseif ( ! $has_promo && $in_cat ) {
            wp_remove_object_terms( $wc_id, $this->promo_category_id, 'product_cat' );
        }
    }

    /**
     * Clean HTML description content.
     */
    private function clean_description( $content ) {
        if ( $content === null ) {
            return '';
        }

        $content = preg_replace( '/<\/?o:p>/', '', $content );
        $content = preg_replace( '/\s+/', ' ', $content );

        return trim( $content );
    }

    /**
     * Convert array of URLs to pipe-delimited string.
     */
    private function array_to_string_with_delimiter( $input ): string {
        if ( is_array( $input ) ) {
            return implode( '|', array_filter( $input, 'strlen' ) );
        }

        if ( is_string( $input ) ) {
            return trim( $input );
        }

        return '';
    }
}
