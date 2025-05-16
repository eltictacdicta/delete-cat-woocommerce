<?php

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maneja la solicitud AJAX para eliminar categorías vacías.
 */
function handle_ajax_delete_empty_product_categories_logic() {
    if (!defined('DOING_AJAX') || !DOING_AJAX) {
        wp_die('Acceso no permitido');
    }
    
    check_ajax_referer('delete_empty_cats_nonce', 'security');
    
    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No tienes permisos para realizar esta acción.'));
        return;
    }

    // Limpiar cualquier output previo
    if (ob_get_length()) ob_clean();
    
    header('Content-Type: application/json');
    
    $deleted_categories = array();
    $failed_categories = array();

    // Obtener todas las categorías de producto
    $all_categories = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false // Incluir categorías vacías
    ));

    if (empty($all_categories) || is_wp_error($all_categories)) {
        wp_send_json_success(array(
            'success' => true,
            'message' => 'No se encontraron categorías de producto para revisar.',
            'deleted_count' => 0,
            'deleted_categories' => array()
        ));
        return;
    }

    foreach ($all_categories as $category) {
        // Verificar si la categoría tiene productos asociados
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1, // Solo necesitamos saber si hay al menos uno
            'tax_query'      => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category->term_id,
                ),
            ),
        );
        $products_query = new WP_Query($args);
        $has_products = $products_query->have_posts();
        wp_reset_postdata();

        // Verificar si la categoría tiene categorías hijas
        $has_children = get_terms(array(
            'taxonomy'   => 'product_cat',
            'parent'     => $category->term_id,
            'hide_empty' => false, // Incluir hijas vacías también
            'fields'     => 'ids', // Solo necesitamos saber si hay alguna
            'number'     => 1
        ));
        $has_children = !empty($has_children) && !is_wp_error($has_children);

        // Si la categoría está vacía Y no tiene hijos, intentar eliminarla
        if (!$has_products && !$has_children) {
            $deleted = wp_delete_term($category->term_id, 'product_cat');

            if (!is_wp_error($deleted)) {
                $deleted_categories[] = array(
                    'id' => $category->term_id,
                    'name' => $category->name
                );
            } else {
                $failed_categories[] = array(
                    'id' => $category->term_id,
                    'name' => $category->name,
                    'error' => $deleted->get_error_message()
                );
            }
        }
    }

    $message = sprintf('Proceso completado. %d categorías eliminadas.', count($deleted_categories));
    
    if (!empty($failed_categories)) {
        $message .= sprintf(' %d categorías no pudieron ser eliminadas.', count($failed_categories));
        // Opcional: añadir detalles de los fallos en la respuesta si se desea
        // $response['failed_details'] = $failed_categories;
    }

    $response = array(
        'success' => true,
        'message' => $message,
        'deleted_count' => count($deleted_categories),
        'deleted_categories' => $deleted_categories
    );

    wp_send_json($response);
    
    exit;
} 