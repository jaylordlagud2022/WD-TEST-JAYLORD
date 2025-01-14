<?php

// Shortcode to display banners
function wc_banner_manager_shortcode() {
    $args = [
        'post_type' => 'wc_banners',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    ];
    $banners = new WP_Query($args);

    if ($banners->have_posts()) {
        ob_start();
        ?>
        <div class="wc-banner-slider">
            <?php while ($banners->have_posts()): $banners->the_post(); ?>
                <div class="wc-banner-slide">
                    <a href="<?php echo esc_url(get_post_meta(get_the_ID(), '_wc_banner_url', true)); ?>">
                        <?php the_post_thumbnail('full'); ?>
                    </a>
                </div>
            <?php endwhile; ?>
        </div>
        <?php
        wp_reset_postdata();
        return ob_get_clean();
    }

    return __('No banners found.', 'wc-banner-manager');
}
add_shortcode('wc_banner_slider', 'wc_banner_manager_shortcode');
