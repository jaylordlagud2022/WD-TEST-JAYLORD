<?php

// Create a custom post type for banners
function wc_banner_manager_register_post_type() {
    register_post_type('wc_banners', [
        'labels' => [
            'name' => __('Banners', 'wc-banner-manager'),
            'singular_name' => __('Banner', 'wc-banner-manager'),
        ],
        'public' => false,
        'show_ui' => true,
        'menu_icon' => 'dashicons-images-alt',
        'supports' => ['title', 'thumbnail'],
    ]);
}
add_action('init', 'wc_banner_manager_register_post_type');

// Add meta box for banner settings
function wc_banner_manager_add_meta_box() {
    add_meta_box(
        'wc_banner_settings',
        __('Banner Settings', 'wc-banner-manager'),
        'wc_banner_manager_meta_box_callback',
        'wc_banners',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'wc_banner_manager_add_meta_box');

function wc_banner_manager_meta_box_callback($post) {
    $url = get_post_meta($post->ID, '_wc_banner_url', true);
    ?>
    <p>
        <label for="wc_banner_url"><?php _e('Redirect URL', 'wc-banner-manager'); ?></label>
        <input type="url" id="wc_banner_url" name="wc_banner_url" value="<?php echo esc_url($url); ?>" style="width: 100%;">
    </p>
    <?php
}

// Save banner meta data
function wc_banner_manager_save_post($post_id) {
    if (isset($_POST['wc_banner_url'])) {
        update_post_meta($post_id, '_wc_banner_url', esc_url_raw($_POST['wc_banner_url']));
    }
}
add_action('save_post', 'wc_banner_manager_save_post');
