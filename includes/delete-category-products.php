<?php
/**
 * Funciones para eliminar productos de una categoría específica
 * siguiendo estas reglas:
 * 1. Si el producto pertenece solo a esta categoría, eliminarlo completamente
 * 2. Si el producto pertenece a otras categorías, desvincularlo de esta categoría
 * 3. Si esta categoría es la principal, cambiarla por otra de las categorías a las que pertenece
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Elimina productos de una categoría específica aplicando las reglas
 * @param int $category_id ID de la categoría de la que se eliminarán productos
 * @return array Resultado con estadísticas y mensajes
 */
function dcw_delete_category_products($category_id) {
    if (!term_exists($category_id, 'product_cat')) {
        return [
            'success' => false,
            'message' => 'La categoría no existe',
            'stats' => []
        ];
    }

    $args = array(
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'tax_query' => array(
            array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $category_id,
                'include_children' => false,
            )
        )
    );
    
    $query = new WP_Query($args);
    $product_ids = $query->posts;
    
    if (empty($product_ids)) {
        return [
            'success' => true,
            'message' => 'No se encontraron productos en esta categoría',
            'stats' => [
                'total' => 0,
                'deleted' => 0,
                'unlinked' => 0,
                'changed_primary' => 0,
                'errors' => 0
            ]
        ];
    }
    
    $stats = [
        'total' => count($product_ids),
        'deleted' => 0,
        'unlinked' => 0,
        'changed_primary' => 0,
        'errors' => 0
    ];
    
    $log = [];
    
    foreach ($product_ids as $product_id) {
        $result = dcw_process_single_product($product_id, $category_id);
        
        if (!$result['success']) {
            $stats['errors']++;
            $log[] = "Error al procesar producto ID $product_id: " . $result['message'];
            continue;
        }
        
        switch ($result['action']) {
            case 'deleted':
                $stats['deleted']++;
                $log[] = "Producto ID $product_id eliminado completamente";
                break;
            case 'unlinked':
                $stats['unlinked']++;
                $log[] = "Producto ID $product_id desvinculado de la categoría";
                break;
            case 'changed_primary':
                $stats['changed_primary']++;
                $log[] = "Producto ID $product_id: cambiada categoría principal";
                break;
        }
    }
    
    return [
        'success' => true,
        'message' => "Procesamiento completado. {$stats['total']} productos procesados.",
        'stats' => $stats,
        'log' => $log
    ];
}

/**
 * Procesa un producto individual aplicando las reglas de eliminación
 * @param int $product_id ID del producto a procesar
 * @param int $category_id ID de la categoría que se está eliminando
 * @return array Resultado de la operación
 */
function dcw_process_single_product($product_id, $category_id) {
    // Obtener todas las categorías asignadas al producto
    $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
    
    if (is_wp_error($categories)) {
        return [
            'success' => false, 
            'message' => $categories->get_error_message()
        ];
    }
    
    // Si el producto solo pertenece a esta categoría, eliminarlo
    if (count($categories) === 1 && $categories[0] == $category_id) {
        $result = wp_delete_post($product_id, true);
        if (!$result) {
            return [
                'success' => false,
                'message' => "No se pudo eliminar el producto ID $product_id"
            ];
        }
        return [
            'success' => true,
            'action' => 'deleted',
            'message' => "Producto ID $product_id eliminado"
        ];
    }
    
    // El producto pertenece a otras categorías
    // Verificar si la categoría actual es la principal
    $product = wc_get_product($product_id);
    if (!$product) {
        return [
            'success' => false,
            'message' => "No se pudo obtener el producto ID $product_id"
        ];
    }
    
    // Obtener la categoría principal (primera categoría)
    $product_categories = $product->get_category_ids();
    $is_primary = false;
    
    if (!empty($product_categories) && $product_categories[0] == $category_id) {
        $is_primary = true;
        
        // Eliminar la categoría actual de la lista
        $other_categories = array_diff($categories, [$category_id]);
        
        if (!empty($other_categories)) {
            // Reordenar las categorías para que otra sea la principal
            $new_categories = array_values($other_categories);
            
            // Guardar las nuevas categorías
            $result = wp_set_post_terms($product_id, $new_categories, 'product_cat');
            
            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'message' => $result->get_error_message()
                ];
            }
            
            return [
                'success' => true,
                'action' => 'changed_primary',
                'message' => "Categoría principal cambiada para el producto ID $product_id"
            ];
        }
    } else {
        // La categoría no es la principal, simplemente desvincularla
        $other_categories = array_diff($categories, [$category_id]);
        $result = wp_set_post_terms($product_id, $other_categories, 'product_cat');
        
        if (is_wp_error($result)) {
            return [
                'success' => false,
                'message' => $result->get_error_message()
            ];
        }
        
        return [
            'success' => true,
            'action' => 'unlinked',
            'message' => "Producto ID $product_id desvinculado de la categoría"
        ];
    }
    
    // Si llegamos aquí, algo salió mal
    return [
        'success' => false,
        'message' => "Error desconocido al procesar el producto ID $product_id"
    ];
}

/**
 * Manejador AJAX para eliminar productos de una categoría
 */
function handle_ajax_delete_category_products() {
    check_ajax_referer('delete_category_products_nonce', 'security');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permisos insuficientes']);
    }
    
    $category_url = isset($_POST['category_url']) ? sanitize_text_field($_POST['category_url']) : '';
    
    if (empty($category_url)) {
        wp_send_json_error(['message' => 'URL de categoría vacía']);
    }
    
    // Obtener ID de la categoría desde la URL
    $category_id = get_category_id_from_url($category_url);
    
    if (!$category_id) {
        wp_send_json_error(['message' => 'No se pudo obtener el ID de categoría. Verifica la URL.']);
    }
    
    // Procesar la eliminación
    $result = dcw_delete_category_products($category_id);
    
    if (!$result['success']) {
        wp_send_json_error([
            'message' => $result['message']
        ]);
    }
    
    wp_send_json_success([
        'message' => $result['message'],
        'stats' => $result['stats'],
        'log' => $result['log']
    ]);
}

// Registrar el manejador AJAX
add_action('wp_ajax_delete_category_products', 'handle_ajax_delete_category_products'); 