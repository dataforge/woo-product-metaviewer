<?php
/*
Plugin Name: Woo Product Meta Viewer
Plugin URI: https://github.com/dataforge/woo-product-metaviewer
Description: This is a plugin to view and compare product metadata of Woocommerce items.
Version: 1.1
Author: Dataforge
License: GPL2
GitHub Plugin URI: https://github.com/dataforge/woo-product-metaviewer
*/

class Product_Meta_Viewer {
    function __construct() {
        add_action('admin_menu', array($this, 'add_product_meta_viewer_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }

    function enqueue_admin_styles($hook) {
        if ($hook != 'woocommerce_page_product-meta-viewer') {
            return;
        }
        
        wp_enqueue_style('product-meta-viewer-styles', plugin_dir_url(__FILE__) . 'css/admin-style.css', array(), '1.0.0');
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

    function display_product_metadata_table($product, $metadata) {
        if (!$product || !is_a($product, 'WC_Product')) {
            echo '<div class="error"><p>Invalid product object.</p></div>';
            return;
        }
        
        $parent_product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        
        echo '<h3>Product: ' . esc_html($product->get_name()) . ' (ID: ' . esc_html($product->get_id()) . ') <a href="' . $this->get_product_edit_link($parent_product_id) . '" target="_blank">Edit Product</a></h3>';
        
        echo '<table class="widefat meta-viewer-table"><tbody>';
        echo '<tr><td>Product ID</td><td>' . esc_html($product->get_id()) . '</td></tr>';
        echo '<tr><td>Date Posted</td><td>' . esc_html(get_the_date('', $product->get_id())) . '</td></tr>';
        echo '<tr><td>Product Type</td><td>' . esc_html($product->get_type()) . '</td></tr>';
        
        // Get product image and attachment details
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
        
        // Add featured image row
        echo '<tr><td>Featured Image</td><td class="product-image-cell">';
        if ($attachment_id) {
            $image_src = wp_get_attachment_image_src($attachment_id, 'full');
            $image_url = $image_src ? $image_src[0] : '';
            $media_edit_link = admin_url('post.php?post=' . $attachment_id . '&action=edit');
            
            echo $image_html;
            echo '<div class="image-details">';
            echo '<a href="' . esc_url($media_edit_link) . '" target="_blank">Edit in Media Library</a>';
            echo '<br><a href="' . esc_url($image_url) . '" target="_blank">View Full Size Image</a>';
            echo '</div>';
        } else {
            echo 'No featured image';
        }
        echo '</td></tr>';
        
        // Add prices
        echo '<tr><td>Regular Price</td><td>' . wc_price($product->get_regular_price()) . '</td></tr>';
        echo '<tr><td>Sale Price</td><td>' . ($product->get_sale_price() ? wc_price($product->get_sale_price()) : 'Not on sale') . '</td></tr>';
        
        // Add stock info
        echo '<tr><td>Stock Status</td><td>' . esc_html($product->get_stock_status()) . '</td></tr>';
        if ($product->managing_stock()) {
            echo '<tr><td>Stock Quantity</td><td>' . esc_html($product->get_stock_quantity()) . '</td></tr>';
        }
        
        echo '<tr><td>Catalog Visibility</td><td>' . esc_html($product->get_catalog_visibility()) . '</td></tr>';
        echo '<tr><td>Featured Status</td><td>' . ($product->is_featured() ? 'Yes' : 'No') . '</td></tr>';
        
        // Add descriptions
        echo '<tr><td>Short Description</td><td>' . wp_kses_post($product->get_short_description()) . '</td></tr>';
        
        // Add dimensions if physical product
        if ($product->has_weight()) {
            echo '<tr><td>Weight</td><td>' . esc_html($product->get_weight()) . ' ' . get_option('woocommerce_weight_unit') . '</td></tr>';
        }
        
        if ($product->has_dimensions()) {
            echo '<tr><td>Dimensions (L×W×H)</td><td>' . 
                esc_html($product->get_length()) . ' × ' . 
                esc_html($product->get_width()) . ' × ' . 
                esc_html($product->get_height()) . ' ' . 
                get_option('woocommerce_dimension_unit') . '</td></tr>';
        }
        
        // Add permalink with visible URL
        $permalink = get_permalink($product->get_id());
        echo '<tr><td>Product URL</td><td>';
        echo '<a href="' . esc_url($permalink) . '" target="_blank">View Product</a>';
        echo '<br><span class="url-text">' . esc_url($permalink) . '</span>';
        echo '</td></tr>';
        
        if($product->is_type('variation')) {
            echo '<tr><td>Parent ID</td><td>' . esc_html($product->get_parent_id()) . '</td></tr>';
            $parent_product = wc_get_product($product->get_parent_id());
            if ($parent_product) {
                echo '<tr><td>Parent SKU</td><td>' . esc_html($parent_product->get_sku()) . '</td></tr>';
            }
            echo '<tr><td>Variation ID</td><td>' . esc_html($product->get_id()) . '</td></tr>';  // This would be same as Product ID for variations
            echo '<tr><td>Variation SKU</td><td>' . esc_html($product->get_sku()) . '</td></tr>';
        }
        
        foreach ($metadata as $key => $values) {
            if (empty($values)) continue;
            
            foreach ($values as $value) {
                echo '<tr>';
                echo '<td>' . esc_html($key) . '</td>';
                
                // Handle different metadata types appropriately
                if (is_array($value) || is_object($value)) {
                    echo '<td><pre>' . esc_html(print_r($value, true)) . '</pre></td>';
                } else {
                    echo '<td>' . esc_html($value) . '</td>';
                }
                echo '</tr>';
            }
        }
        
        echo '<tr>';
        echo '<td>Product Categories</td>';
        echo '<td>' . esc_html(implode(', ', $this->get_product_categories($parent_product_id))) . '</td>';
        echo '</tr>';
        
        // Add product tags
        $tags = get_the_terms($parent_product_id, 'product_tag');
        if ($tags && !is_wp_error($tags)) {
            echo '<tr><td>Product Tags</td><td>' . esc_html(implode(', ', wp_list_pluck($tags, 'name'))) . '</td></tr>';
        }
        
        echo '</tbody></table>';
    }

    function display_comparison_table($product1, $metadata1, $product2, $metadata2) {
        // Validate products
        if (!$product1 || !is_a($product1, 'WC_Product') || !$product2 || !is_a($product2, 'WC_Product')) {
            echo '<div class="error"><p>Invalid product objects for comparison.</p></div>';
            return;
        }
        
        $edit_link1 = $this->get_product_edit_link($product1->get_id());
        $edit_link2 = $this->get_product_edit_link($product2->get_id());

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

        echo '<table class="widefat meta-viewer-table comparison-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Meta Key</th>';
        echo '<th>' . esc_html($product1->get_name()) . '</th>';
        echo '<th>' . esc_html($product2->get_name()) . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        // Helper function to create highlighted rows
        $create_highlighted_row = function($label, $value1, $value2) {
            $highlight = ($value1 !== $value2) ? 'style="background-color: #ffcccc;"' : '';
            echo "<tr $highlight>";
            echo '<td>' . $label . '</td>';
            echo '<td>' . $value1 . '</td>';
            echo '<td>' . $value2 . '</td>';
            echo '</tr>';
        };

        // Display Product ID for both products
        $create_highlighted_row('Product ID', 
            esc_html($product1->get_id()), 
            esc_html($product2->get_id())
        );

        $create_highlighted_row('Date Posted', 
            esc_html(get_the_date('', $product1->get_id())), 
            esc_html(get_the_date('', $product2->get_id()))
        );

        $create_highlighted_row('Product Type', 
            esc_html($product1->get_type()), 
            esc_html($product2->get_type())
        );
        
        // Add prices
        $create_highlighted_row('Regular Price', 
            wc_price($product1->get_regular_price()), 
            wc_price($product2->get_regular_price())
        );

        $create_highlighted_row('Sale Price', 
            ($product1->get_sale_price() ? wc_price($product1->get_sale_price()) : 'Not on sale'), 
            ($product2->get_sale_price() ? wc_price($product2->get_sale_price()) : 'Not on sale')
        );
        
        // Add stock info
        $create_highlighted_row('Stock Status', 
            esc_html($product1->get_stock_status()), 
            esc_html($product2->get_stock_status())
        );

        $create_highlighted_row('Stock Quantity', 
            ($product1->managing_stock() ? esc_html($product1->get_stock_quantity()) : 'N/A'), 
            ($product2->managing_stock() ? esc_html($product2->get_stock_quantity()) : 'N/A')
        );

        $create_highlighted_row('Catalog Visibility', 
            esc_html($product1->get_catalog_visibility()), 
            esc_html($product2->get_catalog_visibility())
        );

        $create_highlighted_row('Featured Status', 
            ($product1->is_featured() ? 'Yes' : 'No'), 
            ($product2->is_featured() ? 'Yes' : 'No')
        );
        
        // Add dimensions
        $weight1 = $product1->has_weight() ? esc_html($product1->get_weight()) . ' ' . get_option('woocommerce_weight_unit') : 'N/A';
        $weight2 = $product2->has_weight() ? esc_html($product2->get_weight()) . ' ' . get_option('woocommerce_weight_unit') : 'N/A';
        
        $create_highlighted_row('Weight', $weight1, $weight2);
        
        $dimensions1 = $product1->has_dimensions() 
            ? esc_html($product1->get_length()) . ' × ' . esc_html($product1->get_width()) . ' × ' . esc_html($product1->get_height()) . ' ' . get_option('woocommerce_dimension_unit') 
            : 'N/A';
        
        $dimensions2 = $product2->has_dimensions() 
            ? esc_html($product2->get_length()) . ' × ' . esc_html($product2->get_width()) . ' × ' . esc_html($product2->get_height()) . ' ' . get_option('woocommerce_dimension_unit') 
            : 'N/A';
            
        $create_highlighted_row('Dimensions (L×W×H)', $dimensions1, $dimensions2);
        
        // Add Featured Image row
        echo '<tr>';
        echo '<td>Featured Image</td>';
        
        // Product 1 image
        echo '<td class="product-image-cell">';
        $image_html1 = $product1->get_image(array(150, 150));
        $attachment_id1 = $product1->get_image_id();
        
        if ((!$attachment_id1 || empty($image_html1)) && $product1->is_type('variation')) {
            $parent_product1 = wc_get_product($product1->get_parent_id());
            if ($parent_product1) {
                $image_html1 = $parent_product1->get_image(array(150, 150));
                $attachment_id1 = $parent_product1->get_image_id();
            }
        }
        
        if ($attachment_id1) {
            $image_src1 = wp_get_attachment_image_src($attachment_id1, 'full');
            $image_url1 = $image_src1 ? $image_src1[0] : '';
            $media_edit_link1 = admin_url('post.php?post=' . $attachment_id1 . '&action=edit');
            
            echo $image_html1;
            echo '<div class="image-details">';
            echo '<a href="' . esc_url($media_edit_link1) . '" target="_blank">Edit in Media Library</a>';
            echo '<br><a href="' . esc_url($image_url1) . '" target="_blank">View Full Size Image</a>';
            echo '</div>';
        } else {
            echo 'No featured image';
        }
        echo '</td>';
        
        // Product 2 image
        echo '<td class="product-image-cell">';
        $image_html2 = $product2->get_image(array(150, 150));
        $attachment_id2 = $product2->get_image_id();
        
        if ((!$attachment_id2 || empty($image_html2)) && $product2->is_type('variation')) {
            $parent_product2 = wc_get_product($product2->get_parent_id());
            if ($parent_product2) {
                $image_html2 = $parent_product2->get_image(array(150, 150));
                $attachment_id2 = $parent_product2->get_image_id();
            }
        }
        
        if ($attachment_id2) {
            $image_src2 = wp_get_attachment_image_src($attachment_id2, 'full');
            $image_url2 = $image_src2 ? $image_src2[0] : '';
            $media_edit_link2 = admin_url('post.php?post=' . $attachment_id2 . '&action=edit');
            
            echo $image_html2;
            echo '<div class="image-details">';
            echo '<a href="' . esc_url($media_edit_link2) . '" target="_blank">Edit in Media Library</a>';
            echo '<br><a href="' . esc_url($image_url2) . '" target="_blank">View Full Size Image</a>';
            echo '</div>';
        } else {
            echo 'No featured image';
        }
        echo '</td>';
        echo '</tr>';
        
        // Add product URLs with visible URLs
        $permalink1 = get_permalink($product1->get_id());
        $permalink2 = get_permalink($product2->get_id());
        
        $url1 = '<a href="' . esc_url($permalink1) . '" target="_blank">View Product</a>';
        $url1 .= '<br><span class="url-text">' . esc_url($permalink1) . '</span>';
        
        $url2 = '<a href="' . esc_url($permalink2) . '" target="_blank">View Product</a>';
        $url2 .= '<br><span class="url-text">' . esc_url($permalink2) . '</span>';
        
        $create_highlighted_row('Product URL', $url1, $url2);

        // Add product tags for comparison
        $tags1 = get_the_terms($product1->is_type('variation') ? $product1->get_parent_id() : $product1->get_id(), 'product_tag');
        $tags2 = get_the_terms($product2->is_type('variation') ? $product2->get_parent_id() : $product2->get_id(), 'product_tag');
        
        $tags1_str = ($tags1 && !is_wp_error($tags1)) ? implode(', ', wp_list_pluck($tags1, 'name')) : '';
        $tags2_str = ($tags2 && !is_wp_error($tags2)) ? implode(', ', wp_list_pluck($tags2, 'name')) : '';
        
        $create_highlighted_row('Product Tags', esc_html($tags1_str), esc_html($tags2_str));

        $all_keys = array_unique(array_merge(array_keys($metadata1), array_keys($metadata2)));

        foreach ($all_keys as $key) {
            $value1 = isset($metadata1[$key]) ? (is_array($metadata1[$key]) ? implode(", ", $metadata1[$key]) : $metadata1[$key]) : '';
            $value2 = isset($metadata2[$key]) ? (is_array($metadata2[$key]) ? implode(", ", $metadata2[$key]) : $metadata2[$key]) : '';
            $create_highlighted_row(esc_html($key), esc_html($value1), esc_html($value2));
        }

        echo '</tbody>';
        echo '</table>';
    }

    function display_product_meta_admin_page() {
        echo '<div class="wrap">';
        echo '<h1>Product Meta Viewer</h1>';
        
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
                $metadata1 = get_post_meta($product1->get_id());
                $metadata1['product_categories'] = $this->get_product_categories($product1->is_type('variation') ? $product1->get_parent_id() : $product1->get_id());
                $this->display_product_metadata_table($product1, $metadata1);
                
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

                // Fetch metadata for the actual products
                $metadata1 = get_post_meta($product1->get_id());
                $metadata2 = get_post_meta($product2->get_id());

                // Add categories to metadata for comparison
                $metadata1['product_categories'] = $this->get_product_categories($product1->is_type('variation') ? $product1->get_parent_id() : $product1->get_id());
                $metadata2['product_categories'] = $this->get_product_categories($product2->is_type('variation') ? $product2->get_parent_id() : $product2->get_id());

                $this->display_comparison_table($product1, $metadata1, $product2, $metadata2);
                
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
        echo '</div>';

        echo '<br>';
        echo '<input type="submit" name="submit" value="Get/Compare Products" class="button button-primary">';
        echo '</form>';
        
        echo '</div>'; // .wrap
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
}

// Initialize the plugin
$product_meta_viewer = new Product_Meta_Viewer();

// Register activation and deactivation hooks
register_activation_hook(__FILE__, array('Product_Meta_Viewer', 'activate'));
register_deactivation_hook(__FILE__, array('Product_Meta_Viewer', 'deactivate'));
