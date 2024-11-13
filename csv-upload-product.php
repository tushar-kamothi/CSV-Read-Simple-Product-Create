<?php 
/*
* Plugin Name: CSV Upload Products
* Description: This is custom plugin for creation products.
* Author: Tushar K.
* Version: 1.0
* Plugin URI: https://example.com
* Author URI: https://example.com
* Requires at least: 6.0
* Tested up to: 6.3        // Latest WordPress version tested
* Requires PHP: 7.4        // PHP version required
* WC requires at least: 5.0 // WooCommerce version required
* WC tested up to: 8.0 
*/

// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Check if WooCommerce is active
function my_custom_plugin_check_woocommerce() {
    // Check if the WooCommerce plugin is active
    if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
        // Display an admin notice
        add_action( 'admin_notices', 'my_custom_plugin_woocommerce_notice' );

        // Deactivate this plugin
        deactivate_plugins( plugin_basename( __FILE__ ) );
    }
}
add_action( 'admin_init', 'my_custom_plugin_check_woocommerce' );

// Admin notice if WooCommerce is not active
function my_custom_plugin_woocommerce_notice() {
    ?>
    <div class="error notice">
        <p><?php _e( 'My Custom Plugin requires WooCommerce to be installed and active.', 'my-custom-plugin' ); ?></p>
    </div>
    <?php
}

function get_attachment_id_by_url($image_url) {
    global $wpdb;

    $image_url = esc_url_raw($image_url);

    $attachment_id = $wpdb->get_var($wpdb->prepare("
        SELECT post_id FROM $wpdb->postmeta
        WHERE meta_key = '_original_image_url' AND meta_value = %s
        LIMIT 1
    ", $image_url));

    if ($attachment_id) {
        return $attachment_id;
    }

    return false;
}

require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

// Function to download and handle image
function upload_image_from_url($image_url) {
    if (empty($image_url)) {
        error_log('Image URL is empty');
        return false;
    }

    // Check if the image already exists
    $existing_attachment_id = get_attachment_id_by_url($image_url);
    if ($existing_attachment_id) {
        return $existing_attachment_id;
    }

    // Proceed to download the image
    $response = wp_remote_get($image_url);
    if (is_wp_error($response)) {
        error_log('Failed to fetch image: ' . $response->get_error_message());
        return false;
    }

    // Check if the response code is 200
    if (wp_remote_retrieve_response_code($response) !== 200) {
        error_log('Image URL returned an error: ' . wp_remote_retrieve_response_code($response));
        return false;
    }

    // Check the Content-Type to confirm it is an image
    $content_type = wp_remote_retrieve_header($response, 'content-type');
    if (strpos($content_type, 'image/') === false) {
        error_log('URL does not return an image: ' . $image_url . ' (Content-Type: ' . $content_type . ')');
        return false;
    }

    $image_data = wp_remote_retrieve_body($response);
    if (empty($image_data)) {
        error_log('Empty image data retrieved from URL: ' . $image_url);
        return false;
    }

    // Use basename to get the filename
    $filename = basename($image_url);
    $upload = wp_upload_bits($filename, null, $image_data);
    if ($upload['error']) {
        error_log('Failed to upload image: ' . $upload['error']);
        return false;
    }

    $wp_filetype = wp_check_filetype($filename, null);
    if (!$wp_filetype['type']) {
        error_log('File type not allowed: ' . $filename);
        return false; // Abort if the file type is not allowed
    }

    $attachment = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_title'     => sanitize_file_name($filename),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'guid'           => $upload['url']
    );

    $attachment_id = wp_insert_attachment($attachment, $upload['file']);
    if (is_wp_error($attachment_id)) {
        error_log('Failed to insert image into media library: ' . $attachment_id->get_error_message());
        @unlink($upload['file']);
        return false;
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
    if (!wp_update_attachment_metadata($attachment_id, $attach_data)) {
        error_log('Failed to update attachment metadata for ID: ' . $attachment_id);
    }

    // Save the original image URL in post meta
    update_post_meta($attachment_id, '_original_image_url', esc_url_raw($image_url));

    error_log('Image upload successful, attachment ID: ' . $attachment_id);
    return $attachment_id;
}

function get_image_from_iframe($url) {
    // Step 1: Fetch the page content
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        error_log('Failed to fetch page content: ' . $response->get_error_message());
        return false;
    }

    $html = wp_remote_retrieve_body($response);    

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    
    $iframes = $dom->getElementsByTagName('iframe');
    if ($iframes->length === 0) {
        error_log('No iframe found on the page.');
        return false;
    }

    $iframe_src = $iframes->item(0)->getAttribute('src');
    
    return $iframe_src;
}

function create_product_from_csv($product_data) {
    if (!isset($product_data)) {
        error_log('Missing product data: ' . print_r($product_data, true));
        return false;
    }

    $parts = [];

    // Add Carat if it's not empty
    if (!empty($product_data['Carat'])) $parts[] = $product_data['Carat'] . ' Carat';
    if (!empty($product_data['Color'])) $parts[] = $product_data['Color'];
    if (!empty($product_data['FancyColorIntensity'])) $parts[] = $product_data['FancyColorIntensity'];
    if (!empty($product_data['FancyColor'])) $parts[] = $product_data['FancyColor'];
    if (!empty($product_data['Clarity'])) $parts[] = $product_data['Clarity'];
    if (!empty($product_data['Shape'])) $parts[] = 'Cut - ' . $product_data['Shape'];

    // Join the parts with ' - ' and assign to $pro_name
    $pro_name = implode(' - ', $parts);

    if (empty($product_data['Stock #'])) {
        error_log('Stock # is blank. Product creation aborted.');
        return false; 
    }

    $existing_product_id = wc_get_product_id_by_sku($product_data['Stock #']);
    

    $product = $existing_product_id ? wc_get_product($existing_product_id) : new WC_Product_Simple();


    $product->set_name($pro_name);
    if (!$existing_product_id) $product->set_sku($product_data['Stock #']);

    $product->set_regular_price($product_data['Total Amount']);

     // Only process image if no featured image is already set
    if (!$product->get_image_id() && isset($product_data['DiamondImage'])) {
        $image_url_new = get_image_from_iframe($product_data['DiamondImage']);
        $image_id = upload_image_from_url($image_url_new);
        if ($image_id) $product->set_image_id($image_id);
    }

     // Create or get the parent category "Diamond"
    $parent_cat_id = term_exists('Diamond', 'product_cat')['term_id'] ?? wp_insert_term('Diamond', 'product_cat')['term_id'];

    $shape_cat_id = term_exists($product_data['Shape'], 'product_cat')['term_id'] ?? wp_insert_term(ucfirst(strtolower($product_data['Shape'])), 'product_cat', ['parent' => $parent_cat_id])['term_id'];

    $product->set_category_ids([$parent_cat_id, $shape_cat_id]);    

    // Attributes processing
    $new_attributes = [];
    $attributes = [
        'Color' => 'pa_color',
        'Clarity' => 'pa_clarity',
        'FancyColor' => 'pa_fancycolor'
    ];

    // Handle Color attribute
    foreach ($attributes as $key => $taxonomy) {
        if (!empty($product_data[$key])) {
            $term_value = sanitize_text_field($product_data[$key]);
            if (!taxonomy_exists($taxonomy)) wc_create_attribute(['name' => $key, 'slug' => $taxonomy, 'type' => 'select']);
            if (!term_exists($term_value, $taxonomy)) wp_insert_term($term_value, $taxonomy);
            wp_set_object_terms($product->get_id(), $term_value, $taxonomy, true);
            $new_attributes[$taxonomy] = [
                'name' => $taxonomy,
                'value' => $term_value,
                'position' => count($new_attributes),
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 1
            ];
        }
    }

    if (!empty($new_attributes)) $product->set_attributes($new_attributes);

     $custom_meta_keys = ['Stock #', 'Availability', 'Lab', 'Report #', 'Treatment', 'Total Amount', 'FancyColor', 'FancyColorIntensity', 'Shape', 'Carat', 'Color', 'Clarity', 'Cut', 'Pol', 'Sym', 'Measurement', 'Table', 'Depth', 'CrownHeight', 'Crown Angle', 'PavilionDepth', 'Pavilion Angle', 'KeyToSymbols', 'GirdleThin', 'GirdleThick', 'Girdle Condition', 'CuletSize', 'Girdle Percent', 'Cert comment', 'Certificate Url', 'Laser Inscription'];
    foreach ($custom_meta_keys as $key) {
        if (isset($product_data[$key])) $product->update_meta_data($key, sanitize_text_field($product_data[$key]));
    }
    

    if (isset($product_data['Video Link'])) {
        $product->update_meta_data('video_link', esc_url($product_data['Video Link']));
    }     

    $dummy_description = 'Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry’s standard dummy.Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry’s standard dummy.Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry’s standard dummy.';

    if (empty($product->get_description())) {
        $product->set_description($dummy_description);
    }

    $product->set_status('publish');
    $product_id = $product->save();

    return $product_id;
}

function add_video_to_product_gallery() {
    global $product;

    // Get the video URL from the product meta
    $video_url = get_post_meta($product->get_id(), 'video_link', true);

    // If the video URL exists, create an iframe and add it to the gallery
    if ( ! empty( $video_url ) ) {
        $video_url = str_replace('http://', 'https://', $video_url); // Ensure it's using HTTPS
        $video_html = '<div class="woocommerce-product-video" style="width:100%; height:470px;">';
        $video_html .= '<iframe width="100%" scrolling="no" height="100%" src="' . esc_url( $video_url ) . '" frameborder="0" allowfullscreen></iframe>';
        $video_html .= '</div>';

        // $html .= $video_html;
        echo $video_html;
    }

    // return $html;
}
// add_filter( 'woocommerce_single_product_image_thumbnail_html', 'add_video_to_product_gallery', 10, 2 );
add_action('woocommerce_before_single_product_summary', 'add_video_to_product_gallery', 20);

function display_custom_meta_after_product_title() {
    global $product;

    // Define the meta keys you want to display
    $custom_meta_data_dvi_1 = [
        'Stone No' => get_post_meta($product->get_id(), 'Stock #', true),
        'Lab' => get_post_meta($product->get_id(), 'Lab', true),
        'Certificate No' => get_post_meta($product->get_id(), 'Report #', true),
        'Certificate Url' => get_post_meta($product->get_id(), 'Certificate Url', true),
        'Price' => get_post_meta($product->get_id(), 'Total Amount', true),
    ];
     if (!empty(array_filter($custom_meta_data_dvi_1))) {
        echo '<div class="stone_detail_wrapper">';
        echo '<h4>Stone Details</h4>';
        echo '<div class="additional_detail_box">';
        echo '<table><tbody>';
        foreach ($custom_meta_data_dvi_1 as $key => $value) {

            if ($key === 'Certificate Url') {
                continue;
            }

            if ($value) {

                echo '<tr><td>' . esc_html($key) . '</td><td>';
            
                // Check if the key is "Certificate No" and add a link if true
                if ($key === 'Certificate No') {

                    $certificate_url = esc_url($custom_meta_data_dvi_1['Certificate Url']);

                    echo '<a href="' . esc_url($certificate_url) . '" target="_blank" rel="noopener noreferrer" class="pdf_open">' . esc_html($value) . '</a>';

                } else {
                    echo esc_html($value);
                }
                
                echo '</td></tr>';
                
            }
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }

    $custom_meta_data = [
        'Shape' => get_post_meta($product->get_id(), 'Shape', true),
        'Carat' => get_post_meta($product->get_id(), 'Carat', true),
        'Color' => get_post_meta($product->get_id(), 'Color', true),
        'Fancy Color' => get_post_meta($product->get_id(), 'FancyColor', true),
        'Fancy Color Intensity' => get_post_meta($product->get_id(), 'FancyColorIntensity', true),
        'Clarity' => get_post_meta($product->get_id(), 'Clarity', true),
        'Cut' => get_post_meta($product->get_id(), 'Cut', true),
        'Polish' => get_post_meta($product->get_id(), 'Pol', true),
        'Symmetry' => get_post_meta($product->get_id(), 'Sym', true),
        
    ];

     if (!empty(array_filter($custom_meta_data))) {
        // Split the data into two columns
        $first_column = [];
        $second_column = [];

        foreach ($custom_meta_data as $key => $value) {
            if ($key === 'Cut' || $key === 'Polish' || $key === 'Symmetry' || $key === 'Fluorescence') {
                $second_column[$key] = $value;
            } else {
                $first_column[$key] = $value;
            }
        }

        // Start the custom HTML output
        echo '<div class="stone_detail_wrapper">';
        echo '<h4>Grading Details</h4>';
        echo '<div class="stone_detail_wrapper_inner">';
        echo '<div class="g-2 row">';

        // First column
        echo '<div class="col-md-6">';
        echo '<div class="additional_detail_box"><table><tbody>';
        foreach ($first_column as $key => $value) {
            if ($value) {
                echo '<tr><td>' . esc_html($key) . '</td><td>' . esc_html($value) . '</td></tr>';
            }
        }
        echo '</tbody></table></div></div>'; // Close first column

        // Second column
        echo '<div class="col-md-6">';
        echo '<div class="additional_detail_box"><table><tbody>';
        foreach ($second_column as $key => $value) {
            if ($value) {
                echo '<tr><td>' . esc_html($key) . '</td><td>' . esc_html($value) . '</td></tr>';
            }
        }
        echo '</tbody></table></div></div>'; // Close second column

        echo '</div>'; // Close row and inner wrapper
        echo '</div>'; // Close stone_detail_wrapper
    }

    $custom_meta_data_3 = [
        'Measurement' => get_post_meta($product->get_id(), 'Measurement', true),
        'Table' => get_post_meta($product->get_id(), 'Table', true),
        'Depth' => get_post_meta($product->get_id(), 'Depth', true),
        'CrownHeight' => get_post_meta($product->get_id(), 'CrownHeight', true),
        'Crown Angle' => get_post_meta($product->get_id(), 'Crown Angle', true),
        'PavilionDepth' => get_post_meta($product->get_id(), 'PavilionDepth', true),
        'Pavilion Angle' => get_post_meta($product->get_id(), 'Pavilion Angle', true),
        'KeyToSymbols' => get_post_meta($product->get_id(), 'KeyToSymbols', true),
        'GirdleThin' => get_post_meta($product->get_id(), 'GirdleThin', true),
        'GirdleThick' => get_post_meta($product->get_id(), 'GirdleThick', true),
        'Girdle Condition' => get_post_meta($product->get_id(), 'Girdle Condition', true),
        'CuletSize' => get_post_meta($product->get_id(), 'CuletSize', true),
        'Girdle Percent' => get_post_meta($product->get_id(), 'Girdle Percent', true),
        'Cert comment' => get_post_meta($product->get_id(), 'Cert comment', true),
    ];

    if (!empty(array_filter($custom_meta_data_3))) {                
        // Start the custom HTML output
        echo '<div class="stone_detail_wrapper">';
        echo '<h4>Measurement Mapping</h4>';
        echo '<div class="stone_detail_wrapper_inner">';
        echo '<div class="g-2 row">';

        // First column
        echo '<div class="col-md-6">';
        echo '<div class="additional_detail_box"><table><tbody>'; 

        // Measurement
        echo '<tr><td>Measurement</td><td>' . (!empty($custom_meta_data_3['Measurement']) ? htmlspecialchars($custom_meta_data_3['Measurement']) : '-') . '</td></tr>';

        echo '<tr><td>Table %</td><td>' . (!empty($custom_meta_data_3['Table']) ? htmlspecialchars($custom_meta_data_3['Table']).'%' : '-') . '</td></tr>';

        echo '<tr><td>Depth  %</td><td>' . (!empty($custom_meta_data_3['Depth']) ? htmlspecialchars($custom_meta_data_3['Depth']).'%' : '-') . '</td></tr>';

        // CA-CH
        echo '<tr><td>CA-CH</td><td>' . (isset($custom_meta_data_3['CrownHeight']) && isset($custom_meta_data_3['Crown Angle']) 
            ? htmlspecialchars($custom_meta_data_3['CrownHeight']) . '°-' . htmlspecialchars($custom_meta_data_3['Crown Angle']) . '°' 
            : '-') . '</td></tr>';

        // PA-PH
        echo '<tr><td>PA-PH</td><td>' . (isset($custom_meta_data_3['Pavilion Angle']) && isset($custom_meta_data_3['PavilionDepth']) 
            ? htmlspecialchars($custom_meta_data_3['Pavilion Angle']) . '°-' . htmlspecialchars($custom_meta_data_3['PavilionDepth']) . '°' 
            : '-') . '</td></tr>';

        // Key to Symbols
        echo '<tr><td>Key To Symbols</td><td>' . (!empty($custom_meta_data_3['KeyToSymbols']) ? htmlspecialchars($custom_meta_data_3['KeyToSymbols']) : '-') . '</td></tr>';        

        echo '</tbody></table></div></div>'; // Close first column

        // Second column
        echo '<div class="col-md-6">';
        echo '<div class="additional_detail_box"><table><tbody>';

       // Girdle
        echo '<tr><td>Girdle</td><td>';
        if (!empty($custom_meta_data_3['GirdleThick']) && !empty($custom_meta_data_3['GirdleThin'])) {
            echo htmlspecialchars($custom_meta_data_3['GirdleThick']) . ' to ' . htmlspecialchars($custom_meta_data_3['GirdleThin']);
        } elseif (!empty($custom_meta_data_3['GirdleThick'])) {
            echo htmlspecialchars($custom_meta_data_3['GirdleThick']);
        } elseif (!empty($custom_meta_data_3['GirdleThin'])) {
            echo htmlspecialchars($custom_meta_data_3['GirdleThin']);
        } else {
            echo '-';
        }
        echo '</td></tr>';

        // Girdle Percent
        echo '<tr><td>Girdle %</td><td>' . (!empty($custom_meta_data_3['Girdle Percent']) ? htmlspecialchars($custom_meta_data_3['Girdle Percent']) . '%' : '-') . '</td></tr>';

        // Girdle Condition
        echo '<tr><td>Girdle Condition</td><td>' . (!empty($custom_meta_data_3['Girdle Condition']) ? htmlspecialchars($custom_meta_data_3['Girdle Condition']) : '-') . '</td></tr>';

        // Culet Size
        echo '<tr><td>Culet Size</td><td>' . (!empty($custom_meta_data_3['CuletSize']) ? htmlspecialchars($custom_meta_data_3['CuletSize']) : '-') . '</td></tr>';

        // Cert comment
        // echo '<tr><td>Cert Comment</td><td>' . (!empty($custom_meta_data_3['Cert comment']) ? htmlspecialchars($custom_meta_data_3['Cert comment']) : '-') . '</td></tr>';

        echo '</tbody></table></div></div>'; // Close second column

        echo '</div>'; // Close row
        echo '</div>'; // Close stone_detail_wrapper 
        echo '</div>'; // Close row
        echo '</div>'; // Close stone_detail_wrapper
    }

}
// Hook to display the custom meta after the product title
add_action('woocommerce_single_product_summary', 'display_custom_meta_after_product_title', 15);

function remove_quantity_field_for_diamond_category() {
    if ( is_product() ) {
        global $post;
        $product = wc_get_product( $post->ID );
        
        if ( $product && has_term( 'Diamond', 'product_cat', $product->get_id() ) ) {
            // This removes the quantity input and forces the quantity to 1
            remove_action( 'woocommerce_before_add_to_cart_quantity', 'woocommerce_quantity_input' );
            ?>
            <style>
                form.cart .quantity {
                    display: none !important;
                }
            </style>
            <?php
        }
    }
}
add_action( 'wp', 'remove_quantity_field_for_diamond_category' );

add_action('admin_menu', 'add_product_creation_page');
function add_product_creation_page() {
    add_menu_page(
        'Create Products from CSV',     // Page title
        'Create Products from CSV',     // Menu title
        'manage_options',               // Capability
        'create-products-csv',          // Menu slug
        'create_products_from_csv_page' // Function to display content
    );
}

function create_products_from_csv_page() {
    ?>
    <div class="wrap" style="max-width: 800px; margin: 0 auto; padding: 20px; background-color: #f9f9f9; border: 1px solid #e1e1e1; border-radius: 5px;">
        <h1 style="font-size: 24px; margin-bottom: 20px;">Import WooCommerce Products from CSV</h1>
        <div id="import-status" style="padding: 10px; background-color: #fff; border: 1px solid #ccc; border-radius: 4px; margin-bottom: 20px; min-height: 50px;">Click on Start import button</div>
        <button id="start-import" class="button button-primary" style="padding: 10px 20px; font-size: 16px; background-color: #007cba; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Start Import</button>
    </div>
        
    <script>
        jQuery(document).ready(function($) {
            var batchSize = 100; // Fixed batch size of 100

            $('#start-import').on('click', function() {
                $(this).prop('disabled', true);
                $('#import-status').html('Counting Products... Please wait...');
                
                var startRow = 0; // Start from the first row

                function startImport() {
                    $.post(ajaxurl, {
                        action: 'start_import',
                        start_row: startRow,
                        batch_size: batchSize // Send the fixed batch size
                    }, function(response) {
                        if (response.success) {
                            var imported = response.data.imported; // Total imported so far
                            var totalProducts = response.data.total; // Total products to import
                            startRow = response.data.next_row; // Get next row to process

                            // Update the UI with the correct number of imported products
                            $('#import-status').html('Imported ' + imported + ' of ' + totalProducts + ' products.');

                            // Continue import if there are still products left to process
                            if (startRow < totalProducts) {
                                setTimeout(startImport, 2000); // Delay the next batch by 2 seconds
                            } else {
                                $('#import-status').html('All products imported successfully! Total imported: ' + totalProducts);
                                $('#start-import').prop('disabled', false); // Re-enable the button after completion
                            }
                        } else {
                            $('#import-status').html('<strong>Error:</strong> ' + response.data);
                            $('#start-import').prop('disabled', false);
                        }
                    });
                }

                startImport(); // Start the first batch import
            });
        });
    </script>


    <?php
}

function import_products_from_csv_in_batches($csv_file_path, $batch_size = 100, $start_row = 0) {
    if (!file_exists($csv_file_path)) {
        return false;
    }

    // Get total products if not already counted
    $total_products = get_transient('total_products_count');
    if (!$total_products) {
        $total_products = 0;

        // Count total products once and store in transient
        if (($handle = fopen($csv_file_path, 'r')) !== false) {
            fgetcsv($handle); // Skip header
            while (($row = fgetcsv($handle)) !== false) {
                if (array_filter($row)) {
                    $total_products++;
                }
            }
            fclose($handle);
        }

        set_transient('total_products_count', $total_products, 60 * 60); // Store for 1 hour
    }

    $imported_count = 0; // Count the number of products imported in the current batch

    if (($handle = fopen($csv_file_path, 'r')) !== false) {
        // Read and store the header row, which should contain column names
        $header = fgetcsv($handle); // Capture the header row
        
        if ($header === false || empty($header)) {
            error_log('Failed to read CSV header or header is empty.');
            fclose($handle);
            return; // Exit if the header is not valid
        }

        // Skip rows until the starting row
        for ($i = 0; $i < $start_row; $i++) {
            fgetcsv($handle);
        }

        // Import products in batches of $batch_size
        $row_count = 0;
        while (($row = fgetcsv($handle)) !== false && $row_count < $batch_size) {
            if (array_filter($row)) {
                // Make sure the header and row have the same number of elements
                if (count($header) === count($row)) {
                    $product_data = array_combine($header, $row); // Combine header and row to create product data array
                    create_product_from_csv($product_data); // Function to create a product from CSV row
                    $row_count++;
                    $imported_count++;
                } else {
                    error_log('Row and header column count mismatch. Skipping row.');
                }
            }
        }
        
        fclose($handle);
    }


    // Get the current import progress
    $current_progress = get_transient('csv_import_progress');
    $total_imported = $current_progress['imported'] ?? 0;
    $total_imported += $imported_count;

    // Store progress
    set_transient('csv_import_progress', array('imported' => $total_imported, 'total' => $total_products), 60 * 60);

    // Clear transients after import is finished
    if ($total_imported >= $total_products) {
        delete_transient('csv_import_progress');
        delete_transient('total_products_count');
    }

    // Return the result for the current batch
    return array('imported_count' => $imported_count, 'total_count' => $total_products);
}

add_action('wp_ajax_start_import', 'ajax_start_import');
function ajax_start_import() {
    $csv_file_path = ABSPATH . 'csv/VG.csv';
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 100; // Fixed batch size of 100
    $start_row = isset($_POST['start_row']) ? intval($_POST['start_row']) : 0;

    $result = import_products_from_csv_in_batches($csv_file_path, $batch_size, $start_row);

    $total_count = $result['total_count'] ?? 0;
    $imported_count = $result['imported_count'] ?? 0;

    // Get the current progress
    $current_progress = get_transient('csv_import_progress');
    $total_imported = $current_progress['imported'] ?? 0;

    $next_row = $start_row + $imported_count;

    // Check if the import has finished
    if ($total_imported >= $total_count) {
        wp_send_json_success(array('imported' => $total_imported, 'total' => $total_count, 'next_row' => $total_count));
    } else {
        wp_send_json_success(array('imported' => $total_imported, 'total' => $total_count, 'next_row' => $next_row));
    }
}

add_action('wp_ajax_check_import_progress', 'ajax_check_import_progress');
function ajax_check_import_progress() {
    $progress = get_transient('csv_import_progress') ?: array('imported' => 0, 'total' => 0);
    wp_send_json_success($progress);
}


?>