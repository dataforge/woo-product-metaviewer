<?php
/*
Plugin Name: Woo Product Meta Viewer
Plugin URI: https://github.com/dataforge/woo-product-metaviewer
Description: This is a plugin to view and compare product metadata of Woocommerce items.
Version: 1.11
Author: Dataforge
License: GPL2
GitHub Plugin URI: https://github.com/dataforge/woo-product-metaviewer
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Product_Meta_Viewer {
    function __construct() {
        add_action('admin_menu', array($this, 'add_product_meta_viewer_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }

    function enqueue_admin_styles($hook) {
        if ($hook != 'woocommerce_page_product-meta-viewer') {
            return;
        }
        
        // Enqueue plugin styles
        wp_enqueue_style('product-meta-viewer-styles', plugin_dir_url(__FILE__) . 'css/admin-style.css', array(), '1.0.0');
        
        // Enqueue Select2 (bundled with WP, but fallback to CDN if needed)
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);

        // Enqueue custom JS for AJAX product picker
        wp_enqueue_script('product-meta-viewer-picker', plugin_dir_url(__FILE__) . 'js/product-meta-viewer-picker.js', array('jquery', 'select2'), '1.0.0', true);
        wp_localize_script('product-meta-viewer-picker', 'PMVPicker', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pmv_product_search')
        ));
    }

    function add_product_meta_viewer_menu() {
        add_submenu_page(
            'woocommerce', 
            'Woo Product Meta Viewer', 
            'Woo Product Meta Viewer', 
            'manage_options', 
            'product-meta-viewer', 
            array($this, 'display_product_meta_admin_page')
        );
        // Removed settings submenu; settings will be a tab on the main page
    }

    function get_product_categories($product_id) {
        $categories = wp_get_post_terms($product_id, 'product_cat');
        return array_map(function($category) {
            return $category->name;
        }, $categories);
    }

    function get_product_edit_link($product_id) {
        return esc_url(get_admin_url(null, "post.php?post={$product_id}&action=edit"));
    }

    /**
     * Get complete product data for unified display
     */
    function get_complete_product_data($product) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return array();
        }

        $data = array();
        $is_variation = $product->is_type('variation');
        $parent_id = $is_variation ? $product->get_parent_id() : $product->get_id();
        $parent_product = $is_variation ? wc_get_product($parent_id) : null;

        // Basic Product Information
        $data['Product ID'] = $product->get_id();
        $data['Product Name'] = $product->get_name();
        $data['Date Created'] = $product->get_date_created() ? $product->get_date_created()->date('Y-m-d H:i:s') : '';
        $data['Date Modified'] = $product->get_date_modified() ? $product->get_date_modified()->date('Y-m-d H:i:s') : '';
        $data['Product Type'] = $product->get_type();
        $data['Product Status'] = $product->get_status();

        // Parent/Variation Relationship
        if ($is_variation && $parent_product) {
            $data['Parent ID'] = $parent_id;
            $data['Parent Name'] = $parent_product->get_name();
            $data['Parent SKU'] = $parent_product->get_sku();
            $data['Variation SKU'] = $product->get_sku();
        } else {
            $data['SKU'] = $product->get_sku();
        }

        // Pricing Information
        $data['Regular Price'] = $product->get_regular_price() ? wc_price($product->get_regular_price()) : 'Not set';
        $data['Sale Price'] = $product->get_sale_price() ? wc_price($product->get_sale_price()) : 'Not on sale';
        $data['Price HTML'] = $product->get_price_html();
        $data['Tax Status'] = $product->get_tax_status();
        $data['Tax Class'] = $product->get_tax_class() ?: 'Standard';

        // Stock Management
        $data['Stock Status'] = $product->get_stock_status();
        $data['Manage Stock'] = $product->managing_stock() ? 'Yes' : 'No';
        if ($product->managing_stock()) {
            $data['Stock Quantity'] = $product->get_stock_quantity();
            $data['Low Stock Amount'] = $product->get_low_stock_amount() ?: 'Not set';
        }
        $data['Backorders'] = $product->get_backorders();
        $data['Sold Individually'] = $product->is_sold_individually() ? 'Yes' : 'No';

        // Physical Properties
        if ($product->has_weight()) {
            $data['Weight'] = $product->get_weight() . ' ' . get_option('woocommerce_weight_unit');
        }
        if ($product->has_dimensions()) {
            $data['Dimensions (L×W×H)'] = $product->get_length() . ' × ' . $product->get_width() . ' × ' . $product->get_height() . ' ' . get_option('woocommerce_dimension_unit');
        }
        $data['Virtual'] = $product->is_virtual() ? 'Yes' : 'No';
        $data['Downloadable'] = $product->is_downloadable() ? 'Yes' : 'No';

        // Visibility and Features
        $data['Catalog Visibility'] = $product->get_catalog_visibility();
        $data['Featured'] = $product->is_featured() ? 'Yes' : 'No';
        $data['Reviews Allowed'] = $product->get_reviews_allowed() ? 'Yes' : 'No';
        $data['Menu Order'] = $product->get_menu_order();

        // Content
        $data['Short Description'] = $product->get_short_description();
        $data['Description'] = $product->get_description();

        // Images
        $data['Featured Image'] = $this->get_product_image_data($product);
        $data['Product Gallery'] = $this->get_product_gallery_data($product);

        // Categories and Tags (use parent for variations)
        $cat_product_id = $is_variation ? $parent_id : $product->get_id();
        $data['Product Categories'] = implode(', ', $this->get_product_categories($cat_product_id));
        
        $tags = get_the_terms($cat_product_id, 'product_tag');
        $data['Product Tags'] = ($tags && !is_wp_error($tags)) ? implode(', ', wp_list_pluck($tags, 'name')) : '';

        // Attributes
        $data['Product Attributes'] = $this->get_product_attributes_data($product);

        // URLs
        $data['Product URL'] = get_permalink($product->get_id());
        $data['Edit Product URL'] = $this->get_product_edit_link($parent_id);

        // Shipping
        $data['Shipping Class'] = $product->get_shipping_class();
        
        // Purchase Note
        $purchase_note = $product->get_purchase_note();
        if ($purchase_note) {
            $data['Purchase Note'] = $purchase_note;
        }

        // Custom Metadata
        $metadata = get_post_meta($product->get_id());
        $filtered_metadata = $this->filter_relevant_metadata($metadata);
        $data = array_merge($data, $filtered_metadata);

        return $data;
    }

    /**
     * Get product image data with edit links
     */
    function get_product_image_data($product) {
        $image_html = $product->get_image(array(150, 150));
        $attachment_id = $product->get_image_id();
        
        // If product has no image and it's a variation, try to get parent image
        if ((!$attachment_id || empty($image_html)) && $product->is_type('variation')) {
            $parent_product = wc_get_product($product->get_parent_id());
            if ($parent_product) {
                $image_html = $parent_product->get_image(array(150, 150));
                $attachment_id = $parent_product->get_image_id();
            }
        }
        
        if ($attachment_id) {
            $image_src = wp_get_attachment_image_src($attachment_id, 'full');
            $image_url = $image_src ? $image_src[0] : '';
            $media_edit_link = admin_url('post.php?post=' . $attachment_id . '&action=edit');
            
            return array(
                'html' => $image_html,
                'edit_link' => $media_edit_link,
                'full_url' => $image_url,
                'attachment_id' => $attachment_id
            );
        }
        
        return array('html' => 'No featured image');
    }

    /**
     * Get product gallery data
     */
    function get_product_gallery_data($product) {
        $gallery_ids = $product->get_gallery_image_ids();
        if (empty($gallery_ids)) {
            return 'No gallery images';
        }
        
        $gallery_data = array();
        foreach ($gallery_ids as $attachment_id) {
            $image_html = wp_get_attachment_image($attachment_id, array(100, 100));
            $image_src = wp_get_attachment_image_src($attachment_id, 'full');
            $image_url = $image_src ? $image_src[0] : '';
            $media_edit_link = admin_url('post.php?post=' . $attachment_id . '&action=edit');
            
            $gallery_data[] = array(
                'html' => $image_html,
                'edit_link' => $media_edit_link,
                'full_url' => $image_url,
                'attachment_id' => $attachment_id
            );
        }
        
        return $gallery_data;
    }

    /**
     * Get product attributes data
     */
    function get_product_attributes_data($product) {
        $attributes = $product->get_attributes();
        if (empty($attributes)) {
            return 'No attributes';
        }
        
        $attribute_data = array();
        foreach ($attributes as $attribute) {
            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product->get_id(), $attribute->get_name());
                $values = $terms && !is_wp_error($terms) ? wp_list_pluck($terms, 'name') : array();
                $attribute_data[$attribute->get_name()] = implode(', ', $values);
            } else {
                $attribute_data[$attribute->get_name()] = implode(', ', $attribute->get_options());
            }
        }
        
        return $attribute_data;
    }

    /**
     * Filter metadata to show only relevant fields
     */
    function filter_relevant_metadata($metadata) {
        $filtered = array();
        $exclude_keys = array(
            '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date',
            '_thumbnail_id', '_product_image_gallery', '_wc_rating_count',
            '_wc_average_rating', '_wc_review_count', '_product_attributes',
            '_default_attributes', '_swatch_type', '_swatch_type_options',
            '_manage_stock', '_stock_status', '_backorders', '_sold_individually',
            '_weight', '_length', '_width', '_height', '_sku', '_regular_price',
            '_sale_price', '_price', '_featured', '_catalog_visibility',
            '_tax_status', '_tax_class', '_purchase_note', '_virtual',
            '_downloadable', '_download_limit', '_download_expiry',
            '_stock', '_low_stock_amount', '_wc_pb_edit_in_cart',
            '_wc_pb_aggregate_weight', '_wc_pb_shipped_individually'
        );
        
        foreach ($metadata as $key => $values) {
            if (in_array($key, $exclude_keys) || strpos($key, '_yoast_') === 0) {
                continue;
            }
            
            if (!empty($values)) {
                foreach ($values as $value) {
                    if (!empty($value)) {
                        $filtered[$key] = is_array($value) || is_object($value) ? 
                            print_r($value, true) : $value;
                        break; // Only take first non-empty value
                    }
                }
            }
        }
        
        return $filtered;
    }

    /**
     * Format a value for display
     */
    function format_product_value($value) {
        if (is_array($value)) {
            if (isset($value['html'])) {
                // This is image data
                $output = $value['html'];
                if (isset($value['edit_link']) && isset($value['full_url'])) {
                    $output .= '<div class="image-details">';
                    $output .= '<a href="' . esc_url($value['edit_link']) . '" target="_blank">Edit in Media Library</a>';
                    $output .= '<br><a href="' . esc_url($value['full_url']) . '" target="_blank">View Full Size</a>';
                    $output .= '</div>';
                }
                return $output;
            } elseif (is_numeric(key($value))) {
                // This is gallery data
                $output = '';
                foreach ($value as $item) {
                    if (isset($item['html'])) {
                        $output .= '<div class="gallery-item" style="display: inline-block; margin: 5px;">';
                        $output .= $item['html'];
                        if (isset($item['edit_link'])) {
                            $output .= '<br><a href="' . esc_url($item['edit_link']) . '" target="_blank" style="font-size: 11px;">Edit</a>';
                        }
                        $output .= '</div>';
                    }
                }
                return $output ?: 'No gallery images';
            } else {
                // Regular array data
                $formatted = array();
                foreach ($value as $k => $v) {
                    $formatted[] = $k . ': ' . $v;
                }
                return implode('<br>', $formatted);
            }
        } elseif (is_object($value)) {
            return '<pre>' . esc_html(print_r($value, true)) . '</pre>';
        } elseif (filter_var($value, FILTER_VALIDATE_URL)) {
            return '<a href="' . esc_url($value) . '" target="_blank">View</a><br><span class="url-text">' . esc_url($value) . '</span>';
        } else {
            return wp_kses_post($value);
        }
    }

    /**
     * Compare two values for difference highlighting
     */
    function compare_values($value1, $value2) {
        // Handle array comparisons
        if (is_array($value1) && is_array($value2)) {
            return serialize($value1) !== serialize($value2);
        }
        
        // Handle URL comparisons (ignore the links, compare actual URLs)
        if (is_string($value1) && is_string($value2)) {
            $clean1 = strip_tags($value1);
            $clean2 = strip_tags($value2);
            return $clean1 !== $clean2;
        }
        
        return $value1 !== $value2;
    }

    /**
     * Display a unified product data row
     */
    function display_product_data_row($label, $value1, $value2 = null, $highlight_differences = false) {
        $is_comparison = ($value2 !== null);
        $highlight = '';
        
        if ($is_comparison && $highlight_differences && $this->compare_values($value1, $value2)) {
            $highlight = ' style="background-color: #ffcccc;"';
        }
        
        echo '<tr' . $highlight . '>';
        echo '<td>' . esc_html($label) . '</td>';
        
        if ($is_comparison) {
            echo '<td class="product-data-cell">' . $this->format_product_value($value1) . '</td>';
            echo '<td class="product-data-cell">' . $this->format_product_value($value2) . '</td>';
        } else {
            echo '<td class="product-data-cell">' . $this->format_product_value($value1) . '</td>';
        }
        
        echo '</tr>';
    }

    function display_product_metadata_table($product, $metadata) {
        if (!$product || !is_a($product, 'WC_Product')) {
            echo '<div class="error"><p>Invalid product object.</p></div>';
            return;
        }
        
        $parent_product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        
        echo '<h3>Product: ' . esc_html($product->get_name()) . ' (ID: ' . esc_html($product->get_id()) . ') <a href="' . $this->get_product_edit_link($parent_product_id) . '" target="_blank">Edit Product</a></h3>';
        
        // Get complete product data using unified function
        $product_data = $this->get_complete_product_data($product);
        
        echo '<table class="widefat meta-viewer-table"><tbody>';
        
        // Display all product data using unified display function
        foreach ($product_data as $label => $value) {
            $this->display_product_data_row($label, $value);
        }
        
        echo '</tbody></table>';
    }

    function display_comparison_table($product1, $metadata1, $product2, $metadata2) {
        // Validate products
        if (!$product1 || !is_a($product1, 'WC_Product') || !$product2 || !is_a($product2, 'WC_Product')) {
            echo '<div class="error"><p>Invalid product objects for comparison.</p></div>';
            return;
        }
        
        $parent_id1 = $product1->is_type('variation') ? $product1->get_parent_id() : $product1->get_id();
        $parent_id2 = $product2->is_type('variation') ? $product2->get_parent_id() : $product2->get_id();
        
        $edit_link1 = $this->get_product_edit_link($parent_id1);
        $edit_link2 = $this->get_product_edit_link($parent_id2);

        // Display product images
        echo '<div class="product-comparison-images">';
        echo '<div class="product-column">';
        echo '<h3><a href="' . $edit_link1 . '" target="_blank">' . esc_html($product1->get_name()) . '</a></h3>';
        echo $product1->get_image(array(200, 200));
        echo '</div>';
        
        echo '<div class="product-column">';
        echo '<h3><a href="' . $edit_link2 . '" target="_blank">' . esc_html($product2->get_name()) . '</a></h3>';
        echo $product2->get_image(array(200, 200));
        echo '</div>';
        echo '</div>';

        // Get complete product data using unified function
        $product_data1 = $this->get_complete_product_data($product1);
        $product_data2 = $this->get_complete_product_data($product2);

        echo '<table class="widefat meta-viewer-table comparison-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Meta Key</th>';
        echo '<th>' . esc_html($product1->get_name()) . '</th>';
        echo '<th>' . esc_html($product2->get_name()) . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        // Get all unique keys from both products
        $all_keys = array_unique(array_merge(array_keys($product_data1), array_keys($product_data2)));

        // Display all product data using unified display function with comparison
        foreach ($all_keys as $label) {
            $value1 = isset($product_data1[$label]) ? $product_data1[$label] : '';
            $value2 = isset($product_data2[$label]) ? $product_data2[$label] : '';
            
            $this->display_product_data_row($label, $value1, $value2, true);
        }

        echo '</tbody>';
        echo '</table>';
    }

    function display_product_meta_admin_page() {
        // Tab logic
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'viewer';

        echo '<div class="wrap">';
        echo '<h1>Product Meta Viewer</h1>';

        // Tab navigation
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=product-meta-viewer&tab=viewer')) . '" class="nav-tab' . ($active_tab === 'viewer' ? ' nav-tab-active' : '') . '">Meta Viewer</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=product-meta-viewer&tab=settings')) . '" class="nav-tab' . ($active_tab === 'settings' ? ' nav-tab-active' : '') . '">Settings</a>';
        echo '</h2>';

        if ($active_tab === 'settings') {
            $this->display_settings_tab_content();
            echo '</div>';
            return;
        }

        // --- Meta Viewer tab content (original code) ---
        // Get product details from GET parameters
        $sku1 = isset($_GET['sku1']) ? sanitize_text_field($_GET['sku1']) : '';
        $id1 = isset($_GET['id1']) ? intval($_GET['id1']) : '';
        $sku2 = isset($_GET['sku2']) ? sanitize_text_field($_GET['sku2']) : '';
        $id2 = isset($_GET['id2']) ? intval($_GET['id2']) : '';

        // Check if form was submitted via POST and redirect to GET URL
        if (isset($_POST['submit']) && check_admin_referer('display_product_meta_action', '_wpnonce')) {
            $post_sku1 = isset($_POST['product_sku_1']) ? sanitize_text_field($_POST['product_sku_1']) : '';
            $post_id1 = isset($_POST['product_id_1']) ? intval($_POST['product_id_1']) : '';
            $post_sku2 = isset($_POST['product_sku_2']) ? sanitize_text_field($_POST['product_sku_2']) : '';
            $post_id2 = isset($_POST['product_id_2']) ? intval($_POST['product_id_2']) : '';

            // Sanitize and use product_pick_1 and product_pick_2 if set (override ID fields)
            $post_pick1 = isset($_POST['product_pick_1']) ? intval($_POST['product_pick_1']) : 0;
            $post_pick2 = isset($_POST['product_pick_2']) ? intval($_POST['product_pick_2']) : 0;
            if ($post_pick1 > 0) {
                $post_id1 = $post_pick1;
            }
            if ($post_pick2 > 0) {
                $post_id2 = $post_pick2;
            }

            // Build redirect URL
            $redirect_url = $this->get_permalink_with_params($post_sku1, $post_id1, $post_sku2, $post_id2);

            // Perform the redirect
            wp_redirect($redirect_url);
            exit;
        }

        $product_ids_1 = array();
        $product_ids_2 = array();

        // Get product 1 data
        if (!empty($sku1)) {
            $product_ids_1 = wc_get_products(array(
                'sku' => $sku1,
                'return' => 'ids',
                'limit' => -1,
                'type' => array('simple', 'variation')
            ));
        } elseif (!empty($id1)) {
            $product_ids_1 = array($id1);
        }

        // Get product 2 data
        if (!empty($sku2)) {
            $product_ids_2 = wc_get_products(array(
                'sku' => $sku2,
                'return' => 'ids',
                'limit' => -1,
                'type' => array('simple', 'variation')
            ));
        } elseif (!empty($id2)) {
            $product_ids_2 = array($id2);
        }

        // Display error messages only after trying to find products
        if (((!empty($sku1) || !empty($id1) || !empty($sku2) || !empty($id2))) && 
            empty($product_ids_1) && empty($product_ids_2)) {
            echo '<div class="error"><p>No products found with the provided information. Please check your SKU or ID.</p></div>';
        }

        // If only one product is provided, display its metadata
        if (!empty($product_ids_1) && empty($product_ids_2)) {
            $product1 = wc_get_product($product_ids_1[0]);
            if ($product1) {
                // Use unified display function (metadata parameter is no longer used)
                $this->display_product_metadata_table($product1, array());

                // Display permalink with parameters
                $view_link = $this->get_permalink_with_params($sku1, $id1);
                echo '<div class="permalink-box">';
                echo '<h3>Permalink to this product view:</h3>';
                echo '<input type="text" value="' . esc_url($view_link) . '" class="widefat" onclick="this.select();">';
                echo '</div>';
            }
        } 
        // If both products are provided, display the comparison
        elseif (!empty($product_ids_1) && !empty($product_ids_2)) {
            $product1 = wc_get_product($product_ids_1[0]);
            $product2 = wc_get_product($product_ids_2[0]);

            if ($product1 && $product2) {
                echo '<h2>Comparing Metadata</h2>';

                // Use unified display function (metadata parameters are no longer used)
                $this->display_comparison_table($product1, array(), $product2, array());

                // Display permalink with parameters for comparison
                $compare_link = $this->get_permalink_with_params($sku1, $id1, $sku2, $id2);
                echo '<div class="permalink-box">';
                echo '<h3>Permalink to this comparison:</h3>';
                echo '<input type="text" value="' . esc_url($compare_link) . '" class="widefat" onclick="this.select();">';
                echo '</div>';
            }
        } else {
            echo '<p>Please provide a valid SKU or ID for at least one product.</p>';
        }

        // Form for searching products - now using POST with redirection
        echo '<form method="post" class="meta-viewer-form">';
        wp_nonce_field('display_product_meta_action');

        echo '<div class="product-input-section">';
        echo '<h3>Enter Details for Product 1:</h3>';
        echo '<div class="input-group">';
        echo '<label for="product_sku_1">SKU:</label>';
        echo '<input type="text" id="product_sku_1" name="product_sku_1" value="' . esc_attr($sku1) . '">';
        echo '</div>';

        echo '<div class="input-group">';
        echo '<label for="product_id_1">OR ID:</label>';
        echo '<input type="number" id="product_id_1" name="product_id_1" value="' . esc_attr($id1) . '">';
        echo '</div>';

        // Add AJAX-powered Pick dropdown
        echo '<div class="input-group">';
        echo '<label for="product_pick_1">Pick:</label>';
        echo '<select id="product_pick_1" class="pmv-product-picker" data-target="#product_id_1" style="width: 100%;" name="product_pick_1"></select>';
        echo '</div>';

        echo '</div>';

        echo '<div class="product-input-section">';
        echo '<h3>Optional - Enter Details for Product 2 (for comparison):</h3>';
        echo '<div class="input-group">';
        echo '<label for="product_sku_2">SKU:</label>';
        echo '<input type="text" id="product_sku_2" name="product_sku_2" value="' . esc_attr($sku2) . '">';
        echo '</div>';

        echo '<div class="input-group">';
        echo '<label for="product_id_2">OR ID:</label>';
        echo '<input type="number" id="product_id_2" name="product_id_2" value="' . esc_attr($id2) . '">';
        echo '</div>';

        // Add AJAX-powered Pick dropdown
        echo '<div class="input-group">';
        echo '<label for="product_pick_2">Pick:</label>';
        echo '<select id="product_pick_2" class="pmv-product-picker" data-target="#product_id_2" style="width: 100%;" name="product_pick_2"></select>';
        echo '</div>';

        echo '</div>';

        echo '<br>';
        echo '<input type="submit" name="submit" value="Get/Compare Products" class="button button-primary">';
        echo '</form>';

        echo '</div>'; // .wrap
    }

    // Settings tab content
    private function display_settings_tab_content() {
        // Handle "Check for Plugin Updates" button
        if (isset($_POST['woo_inv_to_rs_check_update']) && check_admin_referer('woo_inv_to_rs_settings_nonce', 'woo_inv_to_rs_settings_nonce')) {
            // Simulate the cron event for plugin update check
            do_action('wp_update_plugins');
            if (function_exists('wp_clean_plugins_cache')) {
                wp_clean_plugins_cache(true);
            }
            // Remove the update_plugins transient to force a check
            delete_site_transient('update_plugins');
            // Call the update check directly as well
            if (function_exists('wp_update_plugins')) {
                wp_update_plugins();
            }
            // Get update info
            $plugin_file = plugin_basename(__FILE__);
            $update_plugins = get_site_transient('update_plugins');
            $update_msg = '';
            if (isset($update_plugins->response) && isset($update_plugins->response[$plugin_file])) {
                $new_version = $update_plugins->response[$plugin_file]->new_version;
                $update_msg = '<div class="updated"><p>Update available: version ' . esc_html($new_version) . '.</p></div>';
            } else {
                $update_msg = '<div class="updated"><p>No update available for this plugin.</p></div>';
            }
            echo $update_msg;
        }
        ?>
        <div class="wrap">
            <h2>Plugin Update Settings</h2>
            <form method="post" action="">
                <?php wp_nonce_field('woo_inv_to_rs_settings_nonce', 'woo_inv_to_rs_settings_nonce'); ?>
                <input type="hidden" name="woo_inv_to_rs_check_update" value="1">
                <?php submit_button('Check for Plugin Updates', 'secondary'); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Generate a permalink with product parameters
     */
    function get_permalink_with_params($sku1 = '', $id1 = '', $sku2 = '', $id2 = '') {
        $base_url = admin_url('admin.php?page=product-meta-viewer');
        $params = array();
        
        if (!empty($sku1)) {
            $params['sku1'] = urlencode($sku1);
        } elseif (!empty($id1)) {
            $params['id1'] = intval($id1);
        }
        
        if (!empty($sku2)) {
            $params['sku2'] = urlencode($sku2);
        } elseif (!empty($id2)) {
            $params['id2'] = intval($id2);
        }
        
        if (!empty($params)) {
            return add_query_arg($params, $base_url);
        }
        
        return $base_url;
    }

    // Add plugin activation/deactivation hooks
    public static function activate() {
        // Create any necessary database tables or options
    }
    
    public static function deactivate() {
        // Clean up if needed
    }
    // AJAX handler for product search (for Select2)
    public function ajax_product_search() {
        check_ajax_referer('pmv_product_search', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }

        $term = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $results = array();

        if (!class_exists('WC_Product_Query')) {
            wp_send_json(array());
        }

        // Query products and variations by name or SKU
        $args = array(
            'limit' => 20,
            'status' => 'publish',
            'type' => array('simple', 'variable', 'variation'),
            's' => $term,
            'return' => 'objects',
        );

        $products = wc_get_products($args);

        $added_ids = array();

        foreach ($products as $product) {
            // Add parent variable product (only once)
            if ($product->is_type('variable')) {
                $parent_id = $product->get_id();
                if (!in_array($parent_id, $added_ids, true)) {
                    $label = $product->get_name();
                    $sku = $product->get_sku();
                    $text = $label . ' (ID: ' . $parent_id . ')';
                    if ($sku) {
                        $text .= ' [SKU: ' . $sku . ']';
                    }
                    // Match search term against name or SKU
                    if (
                        stripos($label, $term) !== false ||
                        ($sku && stripos($sku, $term) !== false)
                    ) {
                        $results[] = array(
                            'id' => $parent_id,
                            'text' => $text,
                        );
                        $added_ids[] = $parent_id;
                    }
                }
                // Add each variation as a separate entry (with its own ID/SKU)
                $children = $product->get_children();
                foreach ($children as $child_id) {
                    if (!in_array($child_id, $added_ids, true)) {
                        $variation = wc_get_product($child_id);
                        if ($variation) {
                            $label = $variation->get_name();
                            $attributes = wc_get_formatted_variation($variation, true, false, true);
                            $sku = $variation->get_sku();
                            $text = $label . ' (ID: ' . $variation->get_id() . ')';
                            if ($sku) {
                                $text .= ' [SKU: ' . $sku . ']';
                            }
                            // Match search term against name, attributes, or SKU
                            if (
                                stripos($label, $term) !== false ||
                                stripos($attributes, $term) !== false ||
                                ($sku && stripos($sku, $term) !== false)
                            ) {
                                $results[] = array(
                                    'id' => $variation->get_id(),
                                    'text' => $text,
                                );
                                $added_ids[] = $variation->get_id();
                            }
                        }
                    }
                }
            } else {
                $id = $product->get_id();
                if (!in_array($id, $added_ids, true)) {
                    $label = $product->get_name();
                    $sku = $product->get_sku();
                    $text = $label . ' (ID: ' . $id . ')';
                    if ($sku) {
                        $text .= ' [SKU: ' . $sku . ']';
                    }
                    // Match search term against name or SKU
                    if (
                        stripos($label, $term) !== false ||
                        ($sku && stripos($sku, $term) !== false)
                    ) {
                        $results[] = array(
                            'id' => $id,
                            'text' => $text,
                        );
                        $added_ids[] = $id;
                    }
                }
            }
        }

        wp_send_json(array('results' => $results));
    }
}

$product_meta_viewer = new Product_Meta_Viewer();

// Register activation and deactivation hooks
register_activation_hook(__FILE__, array('Product_Meta_Viewer', 'activate'));
register_deactivation_hook(__FILE__, array('Product_Meta_Viewer', 'deactivate'));

// Register AJAX handler
add_action('wp_ajax_pmv_product_search', array($product_meta_viewer, 'ajax_product_search'));
