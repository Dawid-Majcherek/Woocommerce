<?php

/**
 * Plugin Name: WooCommerce Tag, Sorting & Filtering
 * Description: Adds custom sorting (tags, price, rating) and filtering (tags, min/max price, min rating) to WooCommerce product archives.
 * Version:     1.3
 * Author:      Dawid Majcherek
 * Text Domain: woo-tag-sorting
 */

if (! defined('ABSPATH')) {
    exit; // Prevent direct access
}
/**
 * Enqueue plugin styles.
 *
 * @return void
 */
function wts_enqueue_styles() {
    // Zarejestruj i załaduj CSS tylko na stronach WooCommerce
    if ( is_shop() || is_product_category() || is_product_tag() || is_product() ) {
        wp_enqueue_style(
            'woo-tag-sorting-styles',
            plugin_dir_url( __FILE__ ) . 'assets/css/styl.css',
            array(),
            '1.0.0'
        );
    }
}
add_action( 'wp_enqueue_scripts', 'wts_enqueue_styles' );


/**
 * Add custom sorting options to WooCommerce dropdown.
 */
add_filter('woocommerce_default_catalog_orderby_options', 'wts_add_sorting_options');
add_filter('woocommerce_catalog_orderby', 'wts_add_sorting_options');

function wts_add_sorting_options($sortby)
{
    // Sorting by product tags
    $sortby['tag_asc']     = __('Sort by tag (A–Z)', 'woo-tag-sorting');
    $sortby['tag_desc']    = __('Sort by tag (Z–A)', 'woo-tag-sorting');

    // Sorting by price
    $sortby['price_low']   = __('Price: low to high', 'woo-tag-sorting');
    $sortby['price_high']  = __('Price: high to low', 'woo-tag-sorting');

    // Sorting by rating
    $sortby['rating_low']  = __('Rating: low to high', 'woo-tag-sorting');
    $sortby['rating_high'] = __('Rating: high to low', 'woo-tag-sorting');

    return $sortby;
}

/**
 * Custom SQL clauses for sorting by product tags (A–Z / Z–A).
 */
add_filter('posts_clauses', 'wts_posts_clauses', 10, 2);
function wts_posts_clauses($clauses, $query)
{
    if (is_admin() || ! $query->is_main_query()) {
        return $clauses;
    }

    // Apply only on WooCommerce archive/shop pages
    $is_shop_area = (function_exists('is_shop') && is_shop())
        || (function_exists('is_product_category') && is_product_category())
        || (function_exists('is_product_tag') && is_product_tag())
        || (function_exists('is_woocommerce') && is_woocommerce())
        || (is_post_type_archive('product'));

    if (! $is_shop_area) {
        return $clauses;
    }

    $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : get_option('woocommerce_default_catalog_orderby');

    global $wpdb;

    if ('tag_asc' === $orderby || 'tag_desc' === $orderby) {
        // Join product tags
        $clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS tr ON ({$wpdb->posts}.ID = tr.object_id)
                             LEFT JOIN {$wpdb->term_taxonomy} AS tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_tag')
                             LEFT JOIN {$wpdb->terms} AS t ON (tt.term_id = t.term_id) ";

        // Ensure GROUP BY product ID
        if (empty($clauses['groupby'])) {
            $clauses['groupby'] = "{$wpdb->posts}.ID";
        } elseif (strpos($clauses['groupby'], "{$wpdb->posts}.ID") === false) {
            $clauses['groupby'] .= ", {$wpdb->posts}.ID";
        }

        // Sorting logic
        if ('tag_asc' === $orderby) {
            $clauses['orderby'] = "(CASE WHEN MIN(t.name) IS NULL THEN 1 ELSE 0 END) ASC, MIN(t.name) ASC, {$wpdb->posts}.post_date DESC";
        } else {
            $clauses['orderby'] = "(CASE WHEN MAX(t.name) IS NULL THEN 1 ELSE 0 END) ASC, MAX(t.name) DESC, {$wpdb->posts}.post_date DESC";
        }
    }

    return $clauses;
}

/**
 * Handle price & rating sorting (ASC/DESC).
 */
add_filter('woocommerce_get_catalog_ordering_args', 'wts_custom_ordering_args');
function wts_custom_ordering_args($args)
{
    $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : get_option('woocommerce_default_catalog_orderby');

    switch ($orderby) {
        case 'price_low': // Price ascending
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'asc';
            $args['meta_key'] = '_price';
            break;

        case 'price_high': // Price descending
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'desc';
            $args['meta_key'] = '_price';
            break;

        case 'rating_low': // Rating ascending
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'asc';
            $args['meta_key'] = '_wc_average_rating';
            break;

        case 'rating_high': // Rating descending
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'desc';
            $args['meta_key'] = '_wc_average_rating';
            break;
    }

    return $args;
}

/**
 * Add filtering support (price range, rating, tags).
 */
add_action('pre_get_posts', 'wts_filter_products');
function wts_filter_products($query)
{
    if (is_admin() || ! $query->is_main_query()) {
        return;
    }

    if (! (is_shop() || is_product_category() || is_product_tag() || is_post_type_archive('product'))) {
        return;
    }

    // Filter by min price
    if (isset($_GET['min_price']) && is_numeric($_GET['min_price'])) {
        $query->set('meta_query', array(
            array(
                'key'     => '_price',
                'value'   => floatval($_GET['min_price']),
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ),
        ));
    }

    // Filter by max price
    if (isset($_GET['max_price']) && is_numeric($_GET['max_price'])) {
        $meta_query   = $query->get('meta_query', array());
        $meta_query[] = array(
            'key'     => '_price',
            'value'   => floatval($_GET['max_price']),
            'compare' => '<=',
            'type'    => 'NUMERIC',
        );
        $query->set('meta_query', $meta_query);
    }

    // Filter by min rating
    if (isset($_GET['min_rating']) && is_numeric($_GET['min_rating'])) {
        $meta_query   = $query->get('meta_query', array());
        $meta_query[] = array(
            'key'     => '_wc_average_rating',
            'value'   => floatval($_GET['min_rating']),
            'compare' => '>=',
            'type'    => 'NUMERIC',
        );
        $query->set('meta_query', $meta_query);
    }

    // Filter by specific tag
    if (isset($_GET['tag']) && ! empty($_GET['tag'])) {
        $query->set('tax_query', array(
            array(
                'taxonomy' => 'product_tag',
                'field'    => 'slug',
                'terms'    => sanitize_text_field($_GET['tag']),
            ),
        ));
    }
}
/**
 * Render a filter form (price, rating, tags).
 *
 * Usage: [woo_product_filter]
 *
 * @return string
 */
function wts_filter_form_shortcode() {
    ob_start();
    ?>
    <form method="get" action="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="woo-filter-form">

        <!-- Max price -->
        <p>
            <label for="max_price"><?php esc_html_e( 'Maximum price', 'woo-tag-sorting' ); ?>:</label>
            <input type="number" name="max_price" id="max_price"
                value="<?php echo isset( $_GET['max_price'] ) ? esc_attr( $_GET['max_price'] ) : ''; ?>" />
        </p>

        <!-- Min rating -->
        <p>
            <label for="min_rating"><?php esc_html_e( 'Minimum rating', 'woo-tag-sorting' ); ?>:</label>
            <select name="min_rating" id="min_rating">
                <option value=""><?php esc_html_e( 'Any rating', 'woo-tag-sorting' ); ?></option>
                <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                    <option value="<?php echo $i; ?>" <?php selected( isset( $_GET['min_rating'] ) ? intval( $_GET['min_rating'] ) : '', $i ); ?>>
                        <?php echo $i; ?>+
                    </option>
                <?php endfor; ?>
            </select>
        </p>

        <!-- Tags -->
        <p>
            <label for="tag"><?php esc_html_e( 'Tag', 'woo-tag-sorting' ); ?>:</label>
            <select name="tag" id="tag">
                <option value=""><?php esc_html_e( 'Any tag', 'woo-tag-sorting' ); ?></option>
                <?php
                $tags = get_terms( array(
                    'taxonomy'   => 'product_tag',
                    'hide_empty' => true,
                ) );
                if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) :
                    foreach ( $tags as $tag ) :
                        ?>
                        <option value="<?php echo esc_attr( $tag->slug ); ?>"
                            <?php selected( isset( $_GET['tag'] ) ? sanitize_text_field( $_GET['tag'] ) : '', $tag->slug ); ?>>
                            <?php echo esc_html( $tag->name ); ?>
                        </option>
                        <?php
                    endforeach;
                endif;
                ?>
            </select>
        </p>

        <p>
            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'woo-tag-sorting' ); ?></button>
        </p>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode( 'woo_product_filter', 'wts_filter_form_shortcode' );
