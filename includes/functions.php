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
    $output = [];
    
    // Validación adicional de la categoría
    if (!term_exists($category_id, 'product_cat')) {
        $output[] = '<div class="notice notice-error"><p>La categoría ID ' . $category_id . ' no existe</p></div>';
        return ['products' => false, 'output' => $output];
    }

    $args = array(
        'post_type' => array('product', 'product_variation'),
        'post_status' => 'any',
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
        return ['products' => [], 'output' => $output];
    }

    $products = array('products' => array(), 'output' => array());
    while ($products_query->have_posts()) {
        $products_query->the_post();
        $product_id = get_the_ID();
        
        $actual_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        
        if (in_array($category_id, $actual_categories)) {
            $products['products'][] = array(
                'id' => $product_id,
                'title' => get_the_title(),
            );
        }
    }
    wp_reset_postdata();
    
    return ['products' => $products['products'], 'output' => $products['output']];
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
    $product_title = get_the_title($product_id);
    $product = wc_get_product($product_id);
    
    // Obtener información completa del producto
    $product_data = [
        'title' => $product->get_name(),
        'sku' => $product->get_sku(),
        'type' => $product->get_type(),
        'permalink' => get_permalink($product_id)
    ];
    
    // Obtener información de categorías con validación
    $category_from = get_term($category_to_unlink, 'product_cat');
    $category_to = get_term($category_to_assign, 'product_cat');
    
    // Construir URLs usando la función nativa de WordPress
    $url_origen = $category_from ? get_term_link($category_from) : 'Categoría no existe';
    $url_destino = $category_to ? get_term_link($category_to) : 'Categoría no existe';

    // Verificar que el producto y las categorías existan
    if (!get_post($product_id) || !$category_from || !$category_to) {
        $messages[] = 'El producto o las categorías no existen.';
        return array('success' => false, 'messages' => $messages);
    }

    // Limpiar caché antes de obtener categorías
    clean_term_cache(array($category_to_unlink, $category_to_assign), 'product_cat');
    clean_post_cache($product_id);

    $current_categories = get_product_category_ids($product_id);
    $messages[] = 'Categorías actuales: ' . implode(', ', $current_categories);
    $messages[] = 'Producto afectado: ' . $product_title;

    // Verificar si la categoría destino ya está asignada
    $is_assign_category_present = in_array($category_to_assign, $current_categories);
    
    // Verificar que el producto tenga la categoría a desvincular
    $is_unlink_category_present = in_array($category_to_unlink, $current_categories);
    
    if (!$is_unlink_category_present) {
        $messages[] = 'Error: El producto "' . $product_title . '" (ID ' . $product_id . ') no pertenece a la categoría origen con ID ' . $category_to_unlink . '.';
        
        if ($is_assign_category_present) {
            $messages[] = 'El producto ya está asignado a la categoría destino. Operación parcialmente completada.';
            return array('success' => true, 'messages' => $messages, 'product_title' => $product_title);
        }
    }

    // 1. Asignamos la nueva categoría si no está ya asignada
    if (!$is_assign_category_present) {
        $assign_result = assign_product_to_category($product_id, $category_to_assign);
        if (!$assign_result) {
            $messages[] = 'Error al asignar la categoría destino.';
            return array('success' => false, 'messages' => $messages, 'product_title' => $product_title);
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
            return array('success' => false, 'messages' => $messages, 'product_title' => $product_title);
        }
        
        $unlink_result = unlink_product_from_category($product_id, $category_to_unlink);
        
        if (!$unlink_result) {
            $current_categories = get_product_category_ids($product_id);
            $updated_categories = array_values(array_diff($current_categories, [$category_to_unlink]));
            
            if (!empty($updated_categories)) {
                wp_set_object_terms($product_id, $updated_categories, 'product_cat');
                $messages[] = 'Categoría origen desvinculada manualmente';
            }
        }
    }

    $final_categories = get_product_category_ids($product_id);
    $messages[] = 'Categorías finales: ' . implode(', ', $final_categories);
    $messages[] = 'Operación completada para: ' . $product_title;
    $messages[] = '=== DATOS DEL PRODUCTO ===';
    $messages[] = 'Nombre: ' . $product_data['title'];
    $messages[] = 'SKU: ' . ($product_data['sku'] ?: 'N/A');
    $messages[] = 'Tipo: ' . $product_data['type'];
    $messages[] = 'URL producto: ' . esc_url($product_data['permalink']);
    
    $messages[] = '=== CATEGORÍAS ===';
    $messages[] = 'Origen: ' . $category_from->name . ' | Slug: ' . $category_from->slug;
    $messages[] = 'URL Origen: ' . (is_string($url_origen) ? esc_url($url_origen) : 'Error en URL');
    $messages[] = 'Destino: ' . $category_to->name . ' | Slug: ' . $category_to->slug;
    $messages[] = 'URL Destino: ' . (is_string($url_destino) ? esc_url($url_destino) : 'Error en URL');
    
    return array(
        'success' => true, 
        'messages' => $messages, 
        'product_title' => $product_title,
        'category_from' => $category_from,
        'category_to' => $category_to
    );
}

/**
 * Procesa un lote de productos para cambiar su categoría con indicador de progreso.
 * 
 * @param array $product_ids Lista de IDs de productos a procesar.
 * @param int $category_to_unlink El ID de la categoría a desvincular.
 * @param int $category_to_assign El ID de la categoría a asignar.
 * @param bool $echo_progress Si es true, muestra el progreso en tiempo real.
 * @param int $batch_size Tamaño del lote para procesamiento. Por defecto 50.
 * @return array Resultados del procesamiento por lotes.
 */
function batch_replace_product_categories($product_ids, $category_to_unlink, $category_to_assign, $echo_progress = true, $batch_size = 50) {
    $results = array(
        'total' => count($product_ids),
        'processed' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'details' => array()
    );
    
    // Verificar que las categorías existan
    if (!term_exists($category_to_unlink, 'product_cat') || !term_exists($category_to_assign, 'product_cat')) {
        return array(
            'success' => false,
            'message' => __('Una o ambas categorías no existen', 'delete-categories-woocommerce'),
            'total' => 0,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0
        );
    }
    
    // Obtener nombres de categorías para mostrar en el progreso
    $cat_from = get_term($category_to_unlink, 'product_cat');
    $cat_to = get_term($category_to_assign, 'product_cat');
    
    $cat_from_name = $cat_from ? $cat_from->name : __('Desconocida', 'delete-categories-woocommerce');
    $cat_to_name = $cat_to ? $cat_to->name : __('Desconocida', 'delete-categories-woocommerce');
    
    // Inicializar barra de progreso si se solicita
    if ($echo_progress) {
        echo '<div class="dcw-progress-container">';
        echo '<div class="dcw-progress-bar" id="dcw-progress-bar" style="width: 0%;">0%</div>';
        echo '</div>';
        echo '<div id="dcw-current-operation">' . __('Iniciando proceso...', 'delete-categories-woocommerce') . '</div>';
        echo '<div id="dcw-status-messages"></div>';
        
        // Asegurar que la salida se envíe al navegador
        if (ob_get_level() == 0) ob_start();
        flush();
    }
    
    // Dividir los productos en lotes para mejor rendimiento
    $total_products = count($product_ids);
    $batches = array_chunk($product_ids, $batch_size);
    $batch_count = count($batches);
    
    foreach ($batches as $batch_index => $batch) {
        // Realizar la transacción por lotes para mejor rendimiento
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($batch as $product_id) {
                $replace_result = replace_product_category($product_id, $category_to_unlink, $category_to_assign);
                $results['processed']++;
                
                // Actualizar resultados
                if ($replace_result['success']) {
                    $results['success']++;
                    $results['details'][] = array(
                        'id' => $product_id,
                        'status' => 'success',
                        'title' => isset($replace_result['product_title']) ? $replace_result['product_title'] : __('Producto', 'delete-categories-woocommerce') . ' #' . $product_id
                    );
                } else {
                    if (isset($replace_result['skipped']) && $replace_result['skipped']) {
                        $results['skipped']++;
                        $results['details'][] = array(
                            'id' => $product_id,
                            'status' => 'skipped',
                            'title' => isset($replace_result['product_title']) ? $replace_result['product_title'] : __('Producto', 'delete-categories-woocommerce') . ' #' . $product_id,
                            'messages' => isset($replace_result['messages']) ? $replace_result['messages'] : []
                        );
                    } else {
                        $results['failed']++;
                        $results['details'][] = array(
                            'id' => $product_id,
                            'status' => 'failed',
                            'title' => isset($replace_result['product_title']) ? $replace_result['product_title'] : __('Producto', 'delete-categories-woocommerce') . ' #' . $product_id,
                            'messages' => isset($replace_result['messages']) ? $replace_result['messages'] : []
                        );
                    }
                }
                
                // Actualizar la barra de progreso cada 5 productos o al finalizar
                if ($echo_progress && ($results['processed'] % 5 === 0 || $results['processed'] === $total_products)) {
                    $progress = round(($results['processed'] / $total_products) * 100);
                    echo '<script>
                        document.getElementById("dcw-progress-bar").style.width = "' . $progress . '%";
                        document.getElementById("dcw-progress-bar").innerHTML = "' . $progress . '%";
                        document.getElementById("dcw-current-operation").innerHTML = "' . 
                            sprintf(__('Procesando lote %d de %d - Producto %d de %d', 'delete-categories-woocommerce'), 
                                $batch_index + 1, $batch_count, $results['processed'], $total_products) . '";
                    </script>';
                    
                    // Forzar actualización
                    flush();
                }
            }
            
            // Si todo va bien, confirmar la transacción
            $wpdb->query('COMMIT');
            
            // Limpiar caché después de cada lote
            clean_term_cache(array($category_to_unlink, $category_to_assign), 'product_cat');
            clean_post_cache($batch);
            
        } catch (Exception $e) {
            // Si hay errores, revertir los cambios
            $wpdb->query('ROLLBACK');
            
            if ($echo_progress) {
                echo '<div class="notice notice-error"><p>' . 
                    sprintf(__('Error en el lote %d: %s', 'delete-categories-woocommerce'), $batch_index + 1, $e->getMessage()) . 
                '</p></div>';
                flush();
            }
        }
        
        // Pequeña pausa entre lotes para evitar sobrecarga del servidor
        if ($batch_count > 1 && $batch_index < $batch_count - 1) {
            usleep(500000); // 0.5 segundos
        }
    }
    
    // Finalizar barra de progreso
    if ($echo_progress) {
        echo '<script>
            document.getElementById("dcw-progress-bar").style.width = "100%";
            document.getElementById("dcw-progress-bar").innerHTML = "100%";
            document.getElementById("dcw-current-operation").innerHTML = "' . __('Proceso completado', 'delete-categories-woocommerce') . '";
        </script>';
        
        // Mostrar resumen
        echo '<div class="notice notice-success"><p>' . 
            sprintf(
                __('Proceso completado: %d productos procesados. %d exitosos, %d fallidos, %d omitidos.', 'delete-categories-woocommerce'),
                $results['processed'], $results['success'], $results['failed'], $results['skipped']
            ) . 
        '</p></div>';
        flush();
    }
    
    return $results;
}

/**
 * Procesa un producto individual, moviendo de una categoría a otra.
 * Este handler es llamado por AJAX para procesar cada producto.
 */
function handle_ajax_process_single_product() {
    // Verificar el nonce por seguridad
    check_ajax_referer('batch_processing_nonce', 'security');
    
    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_send_json_error([
            'message' => 'No tienes permisos para realizar esta acción.'
        ]);
    }
    
    // Obtener los parámetros
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $category_id_origin = isset($_POST['category_id_origin']) ? intval($_POST['category_id_origin']) : 0;
    $category_id_destination = isset($_POST['category_id_destination']) ? intval($_POST['category_id_destination']) : 0;
    
    // Validar datos
    if (!$product_id || !$category_id_origin || !$category_id_destination) {
        wp_send_json_error([
            'message' => 'Faltan parámetros requeridos',
            'data' => [
                'messages' => ['El ID del producto o de las categorías es inválido']
            ]
        ]);
    }
    
    // Procesar el producto
    $result = replace_product_category($product_id, $category_id_origin, $category_id_destination);
    
    if ($result['success']) {
        wp_send_json_success([
            'success' => true,
            'product_title' => $result['product_title'],
            'messages' => $result['messages']
        ]);
    } else {
        wp_send_json_error([
            'message' => 'Error al procesar el producto',
            'data' => [
                'product_id' => $product_id,
                'messages' => $result['messages']
            ]
        ]);
    }
}

// Registrar el handler AJAX para procesar productos individuales
add_action('wp_ajax_process_single_product', 'handle_ajax_process_single_product');

