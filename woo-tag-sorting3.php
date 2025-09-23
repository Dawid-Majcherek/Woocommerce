<?php
/**
 * Plugin Name: WooCommerce Tag & Extra Sorting
 * Description: Dodaje możliwość sortowania produktów wg tagów (A–Z i Z–A), ceny (rosnąco/malejąco) i opinii (rosnąco/malejąco).
 * Version:     1.2
 * Author:      Dawid Majcherek
 * Text Domain: woo-tag-sorting
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// === 1. Dodajemy nowe opcje do selecta sortowania WooCommerce ===
add_filter( 'woocommerce_default_catalog_orderby_options', 'wts_add_sorting_options' );
add_filter( 'woocommerce_catalog_orderby', 'wts_add_sorting_options' );

function wts_add_sorting_options( $sortby ) {
    // tagi
    $sortby['tag_asc']     = __( 'Sortuj wg tagu (A–Z)', 'woo-tag-sorting' );
    $sortby['tag_desc']    = __( 'Sortuj wg tagu (Z–A)', 'woo-tag-sorting' );

    // cena
    $sortby['price_low']   = __( 'Cena: od najniższej', 'woo-tag-sorting' );
    $sortby['price_high']  = __( 'Cena: od najwyższej', 'woo-tag-sorting' );

    // ocena
    $sortby['rating_low']  = __( 'Ocena: od najniższej', 'woo-tag-sorting' );
    $sortby['rating_high'] = __( 'Ocena: od najwyższej', 'woo-tag-sorting' );

    return $sortby;
}

// === 2. Obsługa SQL dla sortowania tagami ===
add_filter( 'posts_clauses', 'wts_posts_clauses', 10, 2 );
function wts_posts_clauses( $clauses, $query ) {
    if ( is_admin() ) return $clauses;
    if ( ! $query->is_main_query() ) return $clauses;

    // tylko na stronach WooCommerce
    $is_shop_area = ( function_exists('is_shop') && is_shop() )
                    || ( function_exists('is_product_category') && is_product_category() )
                    || ( function_exists('is_product_tag') && is_product_tag() )
                    || ( function_exists('is_woocommerce') && is_woocommerce() )
                    || ( is_post_type_archive( 'product' ) );

    if ( ! $is_shop_area ) return $clauses;

    $orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : get_option( 'woocommerce_default_catalog_orderby' );

    global $wpdb;

    // === Obsługa tagów ===
    if ( 'tag_asc' === $orderby || 'tag_desc' === $orderby ) {
        $clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS tr ON ({$wpdb->posts}.ID = tr.object_id)
                             LEFT JOIN {$wpdb->term_taxonomy} AS tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_tag')
                             LEFT JOIN {$wpdb->terms} AS t ON (tt.term_id = t.term_id) ";

        if ( empty( $clauses['groupby'] ) ) {
            $clauses['groupby'] = "{$wpdb->posts}.ID";
        } elseif ( strpos( $clauses['groupby'], "{$wpdb->posts}.ID" ) === false ) {
            $clauses['groupby'] .= ", {$wpdb->posts}.ID";
        }

        if ( 'tag_asc' === $orderby ) {
            $clauses['orderby'] = "(CASE WHEN MIN(t.name) IS NULL THEN 1 ELSE 0 END) ASC, MIN(t.name) ASC, {$wpdb->posts}.post_date DESC";
        } else {
            $clauses['orderby'] = "(CASE WHEN MAX(t.name) IS NULL THEN 1 ELSE 0 END) ASC, MAX(t.name) DESC, {$wpdb->posts}.post_date DESC";
        }
    }

    return $clauses;
}

// === 3. Obsługa SQL dla ceny i oceny ===
add_filter( 'woocommerce_get_catalog_ordering_args', 'wts_custom_ordering_args' );
function wts_custom_ordering_args( $args ) {
    $orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : get_option( 'woocommerce_default_catalog_orderby' );

    switch ( $orderby ) {
        case 'price_low':
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'asc';
            $args['meta_key'] = '_price';
            break;

        case 'price_high':
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'desc';
            $args['meta_key'] = '_price';
            break;

        case 'rating_low':
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'asc';
            $args['meta_key'] = '_wc_average_rating';
            break;

        case 'rating_high':
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'desc';
            $args['meta_key'] = '_wc_average_rating';
            break;
    }

    return $args;
}
