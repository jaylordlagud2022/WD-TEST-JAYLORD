<?php
/**
 * Plugin Name: Weather Custom plugin
 * Description: Test plugin for Displaying weather and related product
 * Version: 1.0
 * Author: Jaylord
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

function display_weather_and_suggest_product($atts) {
    // Extract shortcode attributes
    $atts = shortcode_atts(
        [
            'product_id' => '',
        ],
        $atts,
        'custom_weather_suggestion'
    );

    // Check if we're on a WooCommerce product page and fetch the current product ID
    if (is_product()) {
        $product_id = get_the_ID();  // WooCommerce product ID
    } else {
        // If the shortcode is used on another page, use the provided product_id attribute or fallback
        $product_id = intval($atts['product_id']);
    }

    if (empty($product_id)) {
        return '<p>Please provide a valid product ID.</p>';
    }

    // Get the logged-in user's latitude and longitude from user meta (custom fields)
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $latitude = get_user_meta($user_id, 'latitude', true);
        $longitude = get_user_meta($user_id, 'longitude', true);
    }

    if (empty($latitude) || empty($longitude)) {
        // If no location data is found, fallback to a default location
        $latitude = '51.5074'; // Default to London
        $longitude = '-0.1278';
    }

    // Open-Meteo API URL
    $api_url = "https://api.open-meteo.com/v1/forecast?latitude={$latitude}&longitude={$longitude}&current_weather=true";

    // Fetch weather data from Open-Meteo
    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        return '<p>Unable to fetch weather data at the moment. Please try again later.</p>';
    }

    $weather_data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($weather_data['current_weather'])) {
        return '<p>Invalid weather data received. Please check the latitude and longitude.</p>';
    }

    // Extract weather details
    $temperature = $weather_data['current_weather']['temperature'];
    $weather_condition = $weather_data['current_weather']['weathercode']; // Can be mapped to a description if needed.

    // Get product details
    $product = wc_get_product($product_id);
    if (!$product) {
        return '<p>Invalid product ID provided.</p>';
    }

    $product_name = $product->get_name();
    $product_url = $product->get_permalink();

    // Get related products
    $related_products = $product->get_related();
    if (empty($related_products)) {
        $related_products_html = '<p>No related products available.</p>';
    } else {
        $related_products_html = '<ul class="related-products">';
        foreach ($related_products as $related_product_id) {
            $related_product = wc_get_product($related_product_id);
            if ($related_product) {
                $related_products_html .= '<li><a href="' . esc_url($related_product->get_permalink()) . '">' . esc_html($related_product->get_name()) . '</a></li>';
            }
        }
        $related_products_html .= '</ul>';
    }

    // Display weather and product suggestion
    ob_start();
    ?>
    <div class="weather-suggestion">
        <h3>Your Current Weather</h3>
        <p>🌤️ <strong>Current Temperature:</strong> <?php echo esc_html($temperature); ?>°C at your location.</p>
        <h4>Related Products You Might Like:</h4>
        <?php echo $related_products_html; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('custom_weather_suggestion', 'display_weather_and_suggest_product');

