<?php

/**
 * Plugin Name: Woo Bulk Product Importer
 * Plugin URI: https://condixia.com
 * Author: Mahim Zaman (Condixia)
 * Author URI: https://mahimzaman.com
 * Description: Imports product from a json data. 
 * Text Domain: wbpi
 */

defined('ABSPATH') || exit;

define('WBPI_PATH', trailingslashit(plugin_dir_path(__FILE__)));
define('WBPI_URL', trailingslashit(plugin_dir_url(__FILE__)));

class WBPI_Importer
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action(
            'wbpi_process_import_batch',
            [$this, 'process_import_batch'],
            10,
            1
        );
        $this->dependencies();
        $this->instance();
    }

    public function process_import_batch($batch_id)
    {

        $products = get_option(
            'wbpi_import_queue_' . $batch_id,
            []
        );

        $stats = get_option(
            'wbpi_import_stats_' . $batch_id,
            []
        );

        if (empty($products)) {
            return;
        }

        foreach ($products as $row) {

            try {

                $product_id = $this->get_or_create_parent_product($row);

                $this->delete_all_variations($product_id);

                $this->sync_parent_taxonomies($product_id, $row);

                $this->sync_parent_attributes($product_id, $row);

                $this->sync_parent_meta($product_id, $row);

                $this->sync_parent_images($product_id, $row);

                $this->create_product_variation(
                    $product_id,
                    $row
                );
            } catch (Exception $e) {

                $stats['failed']++;

                $this->log_error(
                    'FATAL: ' . $e->getMessage(),
                    $row
                );
            }

            $stats['processed']++;

            update_option(
                'wbpi_import_stats_' . $batch_id,
                $stats,
                false
            );
        }

        $stats['completed'] = true;

        $stats['completed_at'] = time();

        $total_time = (
            (int) $stats['completed_at'] -
            (int) $stats['started_at']
        );

        $stats['final_time_taken'] = gmdate(
            'i:s',
            $total_time
        );

        update_option(
            'wbpi_import_stats_' . $batch_id,
            $stats,
            false
        );
    }

    private function dependencies()
    {
        require_once WBPI_PATH . 'includes/class-admin.php';
    }

    private function instance()
    {
        new WBPI_Admin();
    }

    public function register_routes()
    {

        register_rest_route(
            'wbpi/v1',
            '/import',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_import_request'],
                'permission_callback' => [$this, 'validate_wc_auth'],
            ]
        );
    }

    public function validate_wc_auth($request)
    {

        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';

        $localhost_ips = [
            '127.0.0.1',
            '::1',
        ];

        if (in_array($remote_addr, $localhost_ips, true)) {
            return true;
        }

        if (! class_exists('WooCommerce')) {
            return new WP_Error(
                'woocommerce_missing',
                'WooCommerce is not active.',
                ['status' => 500]
            );
        }

        if (! function_exists('wc_rest_check_manager_permissions')) {
            return new WP_Error(
                'wc_auth_failed',
                'WooCommerce REST authentication unavailable.',
                ['status' => 401]
            );
        }

        return true;
    }

    public function get_product_id_by_slug($slug)
    {

        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "
            SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type = 'product'
            AND post_name = %s
            LIMIT 1
            ",
                $slug
            )
        );
    }

    public function handle_import_request(WP_REST_Request $request)
    {

        $products = $request->get_json_params();

        if (empty($products) || ! is_array($products)) {

            return new WP_Error(
                'invalid_data',
                'Invalid JSON payload.',
                ['status' => 400]
            );
        }

        $batch_id = 'wbpi_' . time();

        update_option('wbpi_import_queue_' . $batch_id, $products, false);

        update_option('wbpi_import_stats_' . $batch_id, [
            'total'      => count($products),
            'processed'  => 0,
            'failed'     => 0,
            'started_at' => time(),
            'completed'  => false,
        ], false);

        wp_schedule_single_event(
            time() + 5,
            'wbpi_process_import_batch',
            [$batch_id]
        );

        $estimated_seconds = count($products) * 4;

        return rest_ensure_response([
            'success' => true,
            'batch_id' => $batch_id,
            'estimated_finish_time' => date(
                'Y-m-d H:i:s',
                time() + $estimated_seconds
            ),
            'estimated_seconds' => $estimated_seconds,
        ]);
    }

    public function get_or_create_parent_product($row)
    {

        $sku = sanitize_text_field(
            $row['sku'] ?? ''
        );

        if (empty($sku)) {
            throw new Exception('Missing parent SKU.');
        }

        $existing_id = wc_get_product_id_by_sku($sku);

        /*
    |--------------------------------------------------------------------------
    | Delete old parent product
    |--------------------------------------------------------------------------
    */

        if ($existing_id) {

            wp_delete_post($existing_id, true);

            $this->log_error(
                'INFO: Existing parent deleted before recreation: ' . $sku,
                $row
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Create parent product
    |--------------------------------------------------------------------------
    */

        $product = new WC_Product_Variable();

        $product->set_name(
            sanitize_text_field(
                $row['product_name'] ?? ''
            )
        );

        $product->set_slug(
            sanitize_title(
                $row['slug'] ?? ''
            )
        );

        $product->set_sku($sku);

        $product->set_status('publish');

        return $product->save();
    }

    public function delete_all_variations($product_id)
    {

        $variations = get_posts([
            'post_type'      => 'product_variation',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'private'],
            'post_parent'    => $product_id,
            'fields'         => 'ids',
        ]);

        foreach ($variations as $variation_id) {
            wp_delete_post($variation_id, true);
        }
    }

    public function sync_parent_taxonomies($product_id, $row)
    {

        $taxonomies = [
            'product_cat'   => $row['categories'] ?? '',
            'product_tag'   => $row['tags'] ?? '',
            'product_brand' => $row['brand'] ?? '',
        ];

        foreach ($taxonomies as $taxonomy => $values) {

            if (empty($values)) {
                wp_set_object_terms($product_id, [], $taxonomy);
                continue;
            }

            $term_ids = [];

            $items = array_filter(array_map('trim', explode(',', $values)));

            foreach ($items as $item) {

                $term = term_exists($item, $taxonomy);

                if (! $term) {
                    $term = wp_insert_term($item, $taxonomy);
                }

                if (! is_wp_error($term)) {
                    $term_ids[] = (int) $term['term_id'];
                }
            }

            wp_set_object_terms($product_id, $term_ids, $taxonomy, false);
        }
    }

    public function sync_parent_attributes($product_id, $row)
    {

        $attributes_map = [
            'pa_color' => $row['pa_color'] ?? '',
            'pa_size'  => $row['pa_size'] ?? '',
        ];

        $product_attributes = [];

        foreach ($attributes_map as $taxonomy => $raw_values) {

            if (empty($raw_values)) {
                continue;
            }

            $label = str_replace('pa_', '', $taxonomy);

            if (! taxonomy_exists($taxonomy)) {

                wc_create_attribute([
                    'name'         => ucfirst($label),
                    'slug'         => $label,
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ]);

                register_taxonomy(
                    $taxonomy,
                    'product',
                    [
                        'hierarchical' => false,
                        'label'        => ucfirst($label),
                        'query_var'    => true,
                        'rewrite'      => false,
                    ]
                );
            }

            $term_ids = [];

            $values = array_filter(array_map('trim', explode(',', $raw_values)));

            foreach ($values as $value) {

                $term = term_exists($value, $taxonomy);

                if (! $term) {
                    $term = wp_insert_term($value, $taxonomy);
                }

                if (! is_wp_error($term)) {
                    $term_ids[] = (int) $term['term_id'];
                }
            }

            wp_set_object_terms($product_id, $term_ids, $taxonomy, false);

            $attribute = new WC_Product_Attribute();

            $attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
            $attribute->set_name($taxonomy);
            $attribute->set_options($term_ids);
            $attribute->set_visible(true);
            $attribute->set_variation(true);

            $product_attributes[] = $attribute;
        }

        $product = wc_get_product($product_id);

        $product->set_attributes($product_attributes);

        $product->save();
    }

    public function sync_parent_meta($product_id, $row)
    {

        update_post_meta(
            $product_id,
            '_short_description',
            wp_kses_post($row['short_description'] ?? '')
        );

        wp_update_post([
            'ID'           => $product_id,
            'post_content' => wp_kses_post($row['long_description'] ?? ''),
            'post_excerpt' => wp_kses_post($row['short_description'] ?? ''),
        ]);

        update_post_meta(
            $product_id,
            'long_description',
            wp_kses_post($row['long_description'] ?? '')
        );

        update_post_meta(
            $product_id,
            'whats_included',
            wp_kses_post($row['what_included'] ?? '')
        );

        update_post_meta(
            $product_id,
            'technical_specs',
            wp_kses_post($row['technical_specs'] ?? '')
        );
    }

    public function sync_parent_images($product_id, $row)
    {

        if (empty($row['images'])) {
            return;
        }

        $urls = array_filter(array_map('trim', explode(',', $row['images'])));

        $gallery_ids = [];

        foreach ($urls as $index => $url) {

            if (! $this->is_valid_image_url($url)) {
                continue;
            }

            $attachment_id = $this->upload_image_from_url($url);

            if (! $attachment_id) {
                continue;
            }

            if ($index === 0) {
                set_post_thumbnail($product_id, $attachment_id);
            } else {
                $gallery_ids[] = $attachment_id;
            }
        }

        update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
    }

    public function is_valid_image_url($url)
    {

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        $path = parse_url($url, PHP_URL_PATH);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, $allowed, true);
    }

    public function upload_image_from_url($url)
    {

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url($url);

        if (is_wp_error($tmp)) {
            return false;
        }

        $file = [
            'name'     => basename($url),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file, 0);

        if (is_wp_error($attachment_id)) {

            @unlink($tmp);

            return false;
        }

        return $attachment_id;
    }

    public function create_product_variation(
        $product_id,
        $row
    ) {

        $variation = new WC_Product_Variation();

        $variation->set_parent_id(
            $product_id
        );

        $variation->set_regular_price(
            (string) ($row['price'] ?? 0)
        );

        /*
    |--------------------------------------------------------------------------
    | Attributes
    |--------------------------------------------------------------------------
    */

        $attributes = [];

        if (! empty($row['pa_color'])) {

            $attributes['pa_color'] = sanitize_title(
                trim(
                    explode(
                        ',',
                        $row['pa_color']
                    )[0]
                )
            );
        }

        if (! empty($row['pa_size'])) {

            $attributes['pa_size'] = sanitize_title(
                trim(
                    explode(
                        ',',
                        $row['pa_size']
                    )[0]
                )
            );
        }

        $variation->set_attributes(
            $attributes
        );

        if (
            isset($row['stock']) &&
            $row['stock'] !== '' &&
            $row['stock'] !== null
        ) {
            $variation->set_manage_stock(true);

            $stock_qty = (int) $row['stock'];

            $variation->set_stock_quantity(
                $stock_qty
            );

            $variation->set_stock_status(
                $stock_qty > 0
                    ? 'instock'
                    : 'outofstock'
            );
        } else {

            /*
    |--------------------------------------------------------------------------
    | Keep untouched fallback
    |--------------------------------------------------------------------------
    */

            $variation->set_stock_status(
                'instock'
            );
        }

        $variation->set_status(
            'publish'
        );

        return $variation->save();
    }

    public function log_error($message, $row = [])
    {

        if (! is_dir(WBPI_PATH . 'logs')) {
            wp_mkdir_p(WBPI_PATH . 'logs');
        }

        $file = WBPI_PATH . 'logs/import-errors.log';

        $log = sprintf(
            "[%s] %s | DATA: %s\n",
            current_time('mysql'),
            $message,
            wp_json_encode($row)
        );

        file_put_contents($file, $log, FILE_APPEND);
    }
}

new WBPI_Importer();
