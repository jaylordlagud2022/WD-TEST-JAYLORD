<?php
/**
 * Plugin Name: WooCommerce Banner Manager
 * Description: A plugin to manage rotating banners from the admin dashboard.
 * Version: 1.0
 * Author: Jaylord Lagud
 */

 if (!defined('ABSPATH')) {
     exit; // Exit if accessed directly
 }

 // Define constants
 define('WC_BANNER_MANAGER_PATH', plugin_dir_path(__FILE__));
 define('WC_BANNER_MANAGER_URL', plugin_dir_url(__FILE__));

 // Include required files
 require_once WC_BANNER_MANAGER_PATH . 'includes/admin-banner-manager.php';
 require_once WC_BANNER_MANAGER_PATH . 'includes/shortcode-display.php';

 // Enqueue scripts and styles
 function wc_banner_manager_enqueue_assets() {
     wp_enqueue_style('wc-banner-manager-style', WC_BANNER_MANAGER_URL . 'assets/css/style.css');
     wp_enqueue_script('wc-banner-manager-script', WC_BANNER_MANAGER_URL . 'assets/js/script.js', ['jquery'], null, true);
 }
 add_action('admin_enqueue_scripts', 'wc_banner_manager_enqueue_assets');
 add_action('wp_enqueue_scripts', 'wc_banner_manager_enqueue_assets');