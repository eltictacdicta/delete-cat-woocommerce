<?php
/**
 * Funciones de lógica para el plugin Delete Categories for WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtiene el ID de una categoría de WooCommerce a partir de su URL.
 *
 * @param string $category_url La URL de la categoría.
 * @return int|false El ID de la categoría si se encuentra, o false si no.
 */
function get_category_id_from_url($category_url) {
    // Extraer el slug de la categoría de la URL
    $url_parts = explode('/', rtrim($category_url, '/'));
    $category_slug = end($url_parts);

    // Verificar si el slug no está vacío
    if (empty($category_slug)) {
        return false;
    }

    // Obtener el término de la categoría usando el slug
    $category = get_term_by('slug', $category_slug, 'product_cat');

    // Devolver el ID si la categoría existe
    return ($category) ? $category->term_id : false;
}

/**
 * Obtiene un array de productos de una categoría de WooCommerce a partir de su URL.
 *
 * @param string $category_url La URL de la categoría.
 * @return array|false Array de productos si se encuentra la categoría, o false si no.
 */
function get_products_by_category_id($category_id) {
    $output = []; // Almacenar mensajes aquí
    
    if (!$category_id) {
        $output[] = '<div class="notice notice-error"><p>ID de categoría inválido</p></div>';
        return false;
    }

    // Limpiar caché
    clean_term_cache(array($category_id), 'product_cat');
    wp_cache_flush();

    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $category_id,
                'include_children' => false,
            )
        )
    );
    
    $products_query = new WP_Query($args);
    
    if (!$products_query->have_posts()) {
        $output[] = '<div class="notice notice-warning"><p>No se encontraron productos en la categoría ID: ' . esc_html($category_id) . '</p></div>';
        return ['products' => false, 'output' => $output];
    }

    $products = array();
    while ($products_query->have_posts()) {
        $products_query->the_post();
        $product_id = get_the_ID();
        
        $actual_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        
        if (in_array($category_id, $actual_categories)) {
            $products[] = array(
                'id' => $product_id,
                'title' => get_the_title(),
            );
        }
    }
    wp_reset_postdata();
    
    return ['products' => $products, 'output' => $output];
}

/**
 * Asigna un producto a una categoría específica en WooCommerce.
 *
 * @param int $product_id El ID del producto.
 * @param int $category_id El ID de la categoría.
 * @return bool True si la asignación fue exitosa, false si hubo un error.
 */
function assign_product_to_category($product_id, $category_id) {
    // Verificar que el producto y la categoría existan
    if (!get_post($product_id) || !term_exists($category_id, 'product_cat')) {
        return false;
    }

    // Obtener las categorías actuales del producto
    $current_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));

    // Añadir la nueva categoría si no está ya asignada
    if (!in_array($category_id, $current_categories)) {
        $current_categories[] = $category_id;
    }

    // Asignar las categorías actualizadas al producto
    $result = wp_set_post_terms($product_id, $current_categories, 'product_cat');
    if(is_wp_error($result)) {
        echo '<div class="notice notice-error"><p>Error al asignar la categoría: ' . $result->get_error_message() . '</p></div>';
        return false;
    }
    return true;
}

/**
 * Desvincula un producto de una categoría específica en WooCommerce.
 *
 * @param int $product_id El ID del producto.
 * @param int $category_id El ID de la categoría a desvincular.
 * @return bool True si la desvinculación fue exitosa, false si hubo un error.
 */
function unlink_product_from_category($product_id, $category_id) {
    // Verificar que el producto y la categoría existan
    if (!get_post($product_id) || !term_exists($category_id, 'product_cat')) {
        return false;
    }

    // Obtener las categorías actuales del producto
    $current_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));

    // Verificar si la categoría existe en las asignaciones actuales
    if (!in_array($category_id, $current_categories)) {
        return true; // La categoría ya no está asignada, así que consideramos éxito
    }

    // Eliminar la categoría de la lista
    $updated_categories = array_diff($current_categories, array($category_id));

    // Verificar que al menos quede una categoría
    if (empty($updated_categories)) {
        return false; // No se puede dejar un producto sin categorías
    }

    // Asignar las categorías actualizadas al producto
    $result = wp_set_post_terms($product_id, $updated_categories, 'product_cat');
    if(is_wp_error($result)) {
        return false;
    }
    return true;
}

/**
 * Obtiene los IDs de las categorías asignadas a un producto.
 *
 * @param int $product_id El ID del producto.
 * @return array Array con los IDs de las categorías asignadas al producto.
 */
function get_product_category_ids($product_id) {
    return wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
}


/**
 * Reemplaza la categoría de un producto, asignándolo a la categoría destino y desvinculándolo de la categoría origen.
 * 
 * @param int $product_id El ID del producto.
 * @param int $category_to_unlink El ID de la categoría a desvincular (solo para este producto).
 * @param int $category_to_assign El ID de la categoría a asignar.
 * @return bool True si la operación fue exitosa, false si hubo un error.
 */
function replace_product_category($product_id, $category_to_unlink, $category_to_assign) {
    $messages = array();
    
    // Verificar que el producto y las categorías existan
    if (!get_post($product_id) || !term_exists($category_to_unlink, 'product_cat') || !term_exists($category_to_assign, 'product_cat')) {
        $messages[] = 'El producto o las categorías no existen.';
        return array('success' => false, 'messages' => $messages);
    }

    // Limpiar caché antes de obtener categorías
    clean_term_cache(array($category_to_unlink, $category_to_assign), 'product_cat');
    clean_post_cache($product_id);

    $current_categories = get_product_category_ids($product_id);
    $messages[] = 'Categorías actuales: ' . implode(', ', $current_categories);

    // Verificar si la categoría destino ya está asignada
    $is_assign_category_present = in_array($category_to_assign, $current_categories);
    
    // Verificar que el producto tenga la categoría a desvincular
    $is_unlink_category_present = in_array($category_to_unlink, $current_categories);
    
    if (!$is_unlink_category_present) {
        $messages[] = 'Error: El producto con ID ' . $product_id . ' no pertenece a la categoría origen con ID ' . $category_to_unlink . '.';
        
        if ($is_assign_category_present) {
            $messages[] = 'El producto ya está asignado a la categoría destino. Operación parcialmente completada.';
            return array('success' => true, 'messages' => $messages);
        }
    }

    // 1. Asignamos la nueva categoría si no está ya asignada
    if (!$is_assign_category_present) {
        $assign_result = assign_product_to_category($product_id, $category_to_assign);
        if (!$assign_result) {
            $messages[] = 'Error al asignar la categoría destino.';
            return array('success' => false, 'messages' => $messages);
        }
        else {
            $messages[] = 'Categoría destino asignada exitosamente.';
        }
        
        $current_categories = get_product_category_ids($product_id);
    } else {
        $messages[] = 'El producto ya está asignado a la categoría destino.';
    }

    // 2. Desvincular la categoría origen si está presente
    if ($is_unlink_category_present) {
        if (count($current_categories) < 2) {
            $messages[] = 'El producto solo tiene una categoría y no se puede desvincular.';
            return array('success' => false, 'messages' => $messages);
        }
        
        $unlink_result = unlink_product_from_category($product_id, $category_to_unlink);
        
        if (!$unlink_result) {
            $messages[] = 'Error al desvincular la categoría origen.';
            return array('success' => false, 'messages' => $messages);
        }
        
        $messages[] = 'Categoría origen desvinculada exitosamente.';
    }

    $final_categories = get_product_category_ids($product_id);
    $messages[] = 'Categorías finales: ' . implode(', ', $final_categories);
    $messages[] = 'Operación completada exitosamente.';
    
    return array('success' => true, 'messages' => $messages);
}

/**
 * Verifica la integridad de las relaciones de taxonomía y devuelve información diagnóstica.
 * 
 * @param int $category_id ID de la categoría a verificar.
 * @return array Información diagnóstica sobre la categoría.
 */
function check_taxonomy_integrity($category_id) {
    global $wpdb;
    
    $info = array(
        'term_exists' => false,
        'term_taxonomy_exists' => false,
        'product_count' => 0,
        'actual_product_count' => 0,
        'issues' => array()
    );
    
    // Verificar que el término exista
    $term = get_term($category_id, 'product_cat');
    if ($term && !is_wp_error($term)) {
        $info['term_exists'] = true;
        $info['term_info'] = array(
            'name' => $term->name,
            'slug' => $term->slug,
            'count' => $term->count
        );
    } else {
        $info['issues'][] = 'El término no existe en la base de datos';
    }
    
    // Verificar la taxonomía
    $term_taxonomy = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $wpdb->term_taxonomy WHERE term_id = %d AND taxonomy = %s",
        $category_id,
        'product_cat'
    ));
    
    if ($term_taxonomy) {
        $info['term_taxonomy_exists'] = true;
        $info['product_count'] = $term_taxonomy->count;
    } else {
        $info['issues'][] = 'No existe entrada en term_taxonomy para esta categoría';
    }
    
    // Contar relaciones reales en term_relationships
    $actual_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->term_relationships tr
         JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
         JOIN $wpdb->posts p ON tr.object_id = p.ID
         WHERE tt.term_id = %d 
         AND tt.taxonomy = %s
         AND p.post_type = 'product'
         AND p.post_status = 'publish'",
        $category_id,
        'product_cat'
    ));
    
    $info['actual_product_count'] = (int) $actual_count;
    
    // Verificar si hay discrepancia en los conteos
    if ($info['term_exists'] && $info['product_count'] != $info['actual_product_count']) {
        $info['issues'][] = 'Discrepancia en el conteo de productos: ' . 
                          'El contador de la taxonomía muestra ' . $info['product_count'] . 
                          ' pero hay ' . $info['actual_product_count'] . ' productos reales';
    }
    
    return $info;
}

/**
 * Reconstruye las relaciones de taxonomía para una categoría específica.
 * 
 * @param int $category_id ID de la categoría a reconstruir.
 * @return bool True si la reconstrucción fue exitosa.
 */
function rebuild_category_relationships($category_id) {
    global $wpdb;
    
    // Verificar que la categoría exista
    $term = get_term($category_id, 'product_cat');
    if (!$term || is_wp_error($term)) {
        return false;
    }
    
    // Obtener el term_taxonomy_id
    $term_taxonomy_id = $wpdb->get_var($wpdb->prepare(
        "SELECT term_taxonomy_id FROM $wpdb->term_taxonomy 
         WHERE term_id = %d AND taxonomy = %s",
        $category_id,
        'product_cat'
    ));
    
    if (!$term_taxonomy_id) {
        return false;
    }
    
    // Actualizar el contador en term_taxonomy
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->term_relationships tr
         JOIN $wpdb->posts p ON tr.object_id = p.ID
         WHERE tr.term_taxonomy_id = %d
         AND p.post_type = 'product'
         AND p.post_status = 'publish'",
        $term_taxonomy_id
    ));
    
    $wpdb->update(
        $wpdb->term_taxonomy,
        array('count' => $count),
        array('term_taxonomy_id' => $term_taxonomy_id)
    );
    
    // Limpiar caché
    clean_term_cache($category_id, 'product_cat');
    
    return true;
}