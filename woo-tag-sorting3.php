<?php
/**
 * Plugin Name: WooCommerce Tag Sorting
 * Description: Dodaje możliwość sortowania produktów wg tagów (A–Z i Z–A). Obsługuje produkty z wieloma tagami (używa MIN dla A–Z i MAX dla Z–A).
 * Version:     1.0
 * Author:      Dawid Majcherek
 * Text Domain: woo-tag-sorting
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // zabezpieczenie przed bezpośrednim dostępem
}

// Dodajemy opcje do selecta sortowania WooCommerce
add_filter( 'woocommerce_default_catalog_orderby_options', 'wts_add_sorting_options' );
add_filter( 'woocommerce_catalog_orderby', 'wts_add_sorting_options' );

function wts_add_sorting_options( $sortby ) {
    $sortby['tag_asc']  = __( 'Sortuj wg tagu (A–Z)', 'woo-tag-sorting' );
    $sortby['tag_desc'] = __( 'Sortuj wg tagu (Z–A)', 'woo-tag-sorting' );
    return $sortby;
}

/**
 * Modyfikujemy SQL klauzule (posts_clauses), aby:
 *  - połączyć tabele taksonomii product_tag (LEFT JOIN — żeby zachować produkty bez tagów)
 *  - zgrupować po ID posta, żeby nie powielać wyników
 *  - posortować po agregowanej nazwie taga:
 *      - A->Z: MIN(t.name) ASC
 *      - Z->A: MAX(t.name) DESC
 *  - produkty bez tagów będą zawsze na końcu listy
 */
add_filter( 'posts_clauses', 'wts_posts_clauses', 10, 2 );
function wts_posts_clauses( $clauses, $query ) {
    if ( is_admin() ) return $clauses;
    if ( ! $query->is_main_query() ) return $clauses;

    // ograniczamy do stron sklepu/archiwów produktów
    $is_shop_area = ( function_exists('is_shop') && is_shop() )
                    || ( function_exists('is_product_category') && is_product_category() )
                    || ( function_exists('is_product_tag') && is_product_tag() )
                    || ( function_exists('is_woocommerce') && is_woocommerce() )
                    || ( is_post_type_archive( 'product' ) );

    if ( ! $is_shop_area ) return $clauses;

    $orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : get_option( 'woocommerce_default_catalog_orderby' );

    if ( 'tag_asc' !== $orderby && 'tag_desc' !== $orderby ) {
        return $clauses;
    }

    global $wpdb;

    // Dołączamy tabele taksonomii — LEFT JOIN żeby nie wykluczać produktów bez tagów
    $clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS tr ON ({$wpdb->posts}.ID = tr.object_id)
                         LEFT JOIN {$wpdb->term_taxonomy} AS tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_tag')
                         LEFT JOIN {$wpdb->terms} AS t ON (tt.term_id = t.term_id) ";

    // Zapewniamy group by po ID posta
    if ( empty( $clauses['groupby'] ) ) {
        $clauses['groupby'] = "{$wpdb->posts}.ID";
    } elseif ( strpos( $clauses['groupby'], "{$wpdb->posts}.ID" ) === false ) {
        $clauses['groupby'] .= ", {$wpdb->posts}.ID";
    }

    // Dwa elementy sortowania:
    // 1) produkty z tagami (0) przed produktami bez tagów (1)
    // 2) następnie po agregowanej nazwie taga
    if ( 'tag_asc' === $orderby ) {
        // A-Z: używamy MIN(t.name) żeby dla produktów z wieloma tagami wziąć najmniejszą nazwę alfabetycznie
        $clauses['orderby'] = "(CASE WHEN MIN(t.name) IS NULL THEN 1 ELSE 0 END) ASC, MIN(t.name) ASC, {$wpdb->posts}.post_date DESC";
    } else {
        // Z-A: używamy MAX(t.name) żeby wziąć największą nazwę alfabetycznie
        $clauses['orderby'] = "(CASE WHEN MAX(t.name) IS NULL THEN 1 ELSE 0 END) ASC, MAX(t.name) DESC, {$wpdb->posts}.post_date DESC";
    }

    return $clauses;
}
