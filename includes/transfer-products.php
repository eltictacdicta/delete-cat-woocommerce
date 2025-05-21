<?php

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Asegurar que las dependencias estén cargadas
if (!function_exists('get_category_id_from_url')) {
    require_once plugin_dir_path(__FILE__) . 'functions.php';
}

/**
 * Maneja la solicitud AJAX inicial para obtener IDs de categoría a partir de URLs.
 */
function handle_ajax_transfer_product_category() {
    if (!defined('DOING_AJAX') || !DOING_AJAX) {
        wp_die('Acceso no permitido');
    }
    
    check_ajax_referer('transfer_category_nonce', 'security');
    
    // Limpiar cualquier output previo
    if (ob_get_length()) ob_clean();
    
    header('Content-Type: application/json');
    
    try {
        if (!isset($_POST['category_url_origin']) || !isset($_POST['category_url_destination'])) {
            throw new Exception('Faltan datos del formulario.');
        }
        
        $category_url_origin = esc_url_raw($_POST['category_url_origin']);
        $category_url_destination = esc_url_raw($_POST['category_url_destination']);
        
        // Obtener IDs de categorías
        $category_id_origin = get_category_id_from_url($category_url_origin);
        if (!$category_id_origin) {
            throw new Exception('No se pudo obtener el ID de la categoría de origen. Verifica la URL.');
        }
        
        $category_id_destination = get_category_id_from_url($category_url_destination);
        if (!$category_id_destination) {
            throw new Exception('No se pudo obtener el ID de la categoría de destino. Verifica la URL.');
        }
        
        $response = [
            'success' => true,
            'message' => 'IDs de categoría obtenidos correctamente',
            'debug' => [
                'origin_category' => $category_id_origin,
                'destination_category' => $category_id_destination
            ]
        ];
        
        wp_send_json($response);
        
    } catch (Exception $e) {
        wp_send_json([
            'success' => false,
            'message' => $e->getMessage(),
            'details' => []
        ]);
    }
    
    exit;
}

// Añadir el manejador AJAX
add_action('wp_ajax_transfer_product_category', 'handle_ajax_transfer_product_category');

/**
 * Función para manejar el procesamiento por lotes vía AJAX.
 */
function handle_ajax_batch_processing() {
    // Verificar nonce
    check_ajax_referer('batch_processing_nonce', 'security');
    
    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No tienes permisos para realizar esta acción.'));
        return;
    }
    
    // Obtener parámetros
    // Expecting product_ids as an array from the JS loop
    $product_ids_to_process = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : [];
    $category_id_origin = isset($_POST['category_id_origin']) ? intval($_POST['category_id_origin']) : 0;
    $category_id_destination = isset($_POST['category_id_destination']) ? intval($_POST['category_id_destination']) : 0;
    
    // Validar IDs de categorías y productos
    if (empty($product_ids_to_process)) {
        wp_send_json_error(array('message' => 'No se proporcionaron IDs de productos para procesar.'));
        return;
    }
    if ($category_id_origin <= 0 || $category_id_destination <= 0) {
        wp_send_json_error(array('message' => 'IDs de categoría inválidos.'));
        return;
    }
    if ($category_id_origin === $category_id_destination) {
         wp_send_json_error(array('message' => 'La categoría de origen y destino no pueden ser la misma.'));
        return;
    }

    // This function is now only for non-subcategory logic, directly calling batch_replace_product_categories.
    if (!function_exists('batch_replace_product_categories')) {
        wp_send_json_error(array('message' => 'La función de reemplazo de categorías por lotes no está disponible.'));
        return;
    }

    // Call batch_replace_product_categories, echo_progress is false as JS handles UI.
    $batch_results = batch_replace_product_categories($product_ids_to_process, $category_id_origin, $category_id_destination, false);
    
    // Asegurarnos de que tenemos valores válidos para los contadores
    $success_count = isset($batch_results['success']) ? intval($batch_results['success']) : 0;
    $failed_count = isset($batch_results['failed']) ? intval($batch_results['failed']) : 0;
    $skipped_count = isset($batch_results['skipped']) ? intval($batch_results['skipped']) : 0;
    $log_messages = isset($batch_results['log']) ? $batch_results['log'] : [];

    // Preparar la respuesta para devolver al cliente
    $response = array(
        'success' => true, // Overall AJAX success
        'message' => 'Lote de productos procesado.',
        'results' => [
            'success' => $success_count,
            'failed'  => $failed_count,
            'skipped' => $skipped_count,
            'log'     => $log_messages
        ],
        'total_processed' => count($product_ids_to_process) // How many IDs were sent in this specific batch
    );
    
    wp_send_json_success($response);
}
add_action('wp_ajax_batch_process_categories', 'handle_ajax_batch_processing');

/**
 * Handles the AJAX request to process the entire subcategory tree structure.
 */
function handle_ajax_process_subcategory_tree() {
    check_ajax_referer('batch_processing_nonce', 'security');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
        return;
    }

    $category_id_origin = isset($_POST['category_id_origin']) ? intval($_POST['category_id_origin']) : 0;
    $category_id_destination = isset($_POST['category_id_destination']) ? intval($_POST['category_id_destination']) : 0;

    if ($category_id_origin <= 0 || $category_id_destination <= 0) {
        wp_send_json_error(['message' => 'IDs de categoría de origen o destino inválidos.'], 400);
        return;
    }

    if ($category_id_origin === $category_id_destination) {
        wp_send_json_error(['message' => 'La categoría de origen y destino no pueden ser la misma.'], 400);
        return;
    }

    if (!function_exists('process_categories_with_subcategories')) {
        wp_send_json_error(['message' => 'La función de procesamiento de subcategorías no está disponible.'], 500);
        return;
    }

    // The $product_ids parameter for process_categories_with_subcategories was originally for all products in the origin tree.
    // Since this function now handles the entire tree operation, we might not need to pre-fetch all product IDs here.
    // The function itself queries products for each specific subcategory it processes.
    // Let's pass null or an empty array for now, and adjust if process_categories_with_subcategories truly needs it pre-fetched.
    $results = process_categories_with_subcategories($category_id_origin, $category_id_destination, []); 

    $response_data = [
        'log' => isset($results['log']) ? $results['log'] : ['Proceso completado sin registros detallados.'],
        'success_count' => isset($results['success']) ? $results['success'] : 0,
        'failed_count' => isset($results['failed']) ? $results['failed'] : 0,
        'skipped_count' => isset($results['skipped']) ? $results['skipped'] : 0,
        'parent_changed_count' => isset($results['parent_changed_count']) ? $results['parent_changed_count'] : 0,
    ];

    wp_send_json_success($response_data);
}
add_action('wp_ajax_process_subcategory_tree', 'handle_ajax_process_subcategory_tree'); 

/**
 * Helper function to check if a term is a descendant of another term.
 * @param int $term_id The ID of the potential descendant term.
 * @param int $ancestor_id The ID of the potential ancestor term.
 * @param string $taxonomy The taxonomy.
 * @return bool True if $term_id is a descendant of $ancestor_id, false otherwise.
 */
function dcw_term_is_descendant_of($term_id, $ancestor_id, $taxonomy) {
    if (empty($term_id) || empty($ancestor_id)) {
        return false;
    }
    if ($term_id == $ancestor_id) { // A term is not its own descendant for this logic
        return false;
    }
    $ancestors = get_ancestors($term_id, $taxonomy);
    return in_array($ancestor_id, $ancestors);
}

/**
 * Helper function to list child categories for preview when a category is moved as a block.
 * @param int $parent_term_id The ID of the parent term.
 * @return array A list of child category names and IDs.
 */
function dcw_list_child_categories_for_preview($parent_term_id) {
    $children_data = [];
    $child_terms = get_terms(array(
        'taxonomy' => 'product_cat',
        'parent' => $parent_term_id,
        'hide_empty' => false,
    ));
    foreach ($child_terms as $child) {
        $children_data[] = array(
            'name' => $child->name,
            'id' => $child->term_id,
            'product_count' => dcw_get_direct_product_count_for_term($child->term_id), // Contar productos también para estos hijos
            'children_preview' => dcw_list_child_categories_for_preview($child->term_id) // Recursivo para toda la subestructura
        );
    }
    return $children_data;
}

/**
 * Helper function to get direct product count for a term.
 * @param int $term_id The term ID.
 * @return int Product count.
 */
function dcw_get_direct_product_count_for_term($term_id) {
    $query_args = array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids', // Solo necesitamos IDs para contar
        'tax_query'      => array(
            array(
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => $term_id,
                'include_children' => false // Muy importante: solo productos directos
            )
        )
    );
    $products = new WP_Query($query_args);
    return $products->post_count;
}

/**
 * Generates a preview of the subcategory reorganization.
 *
 * @param int $category_id_origin The ID of the origin category.
 * @param int $category_id_destination The ID of the destination category.
 * @return array An array detailing the expected changes.
 */
function preview_subcategory_reorganization($category_id_origin, $category_id_destination) {
    $preview_log = [];
    $origin_category_term = get_term($category_id_origin, 'product_cat');
    $destination_parent_term = get_term($category_id_destination, 'product_cat');

    if (!$origin_category_term || is_wp_error($origin_category_term)) {
        $preview_log[] = sprintf(__('Error: Categoría de origen base con ID %d no encontrada.', 'delete-categories-woocommerce'), $category_id_origin);
        return ['log' => $preview_log, 'actions' => []];
    }
    if (!$destination_parent_term || is_wp_error($destination_parent_term)) {
        $preview_log[] = sprintf(__('Error: Categoría de destino base con ID %d no encontrada.', 'delete-categories-woocommerce'), $category_id_destination);
        return ['log' => $preview_log, 'actions' => []];
    }

    // Esta previsualización es para los hijos directos de $category_id_origin
    $preview_log[] = sprintf(__('Previsualizando reorganización de subcategorías de "%s" (ID: %d) hacia la jerarquía de "%s" (ID: %d)', 'delete-categories-woocommerce'), $origin_category_term->name, $category_id_origin, $destination_parent_term->name, $category_id_destination);

    $actions = [];
    $subcategories_to_process = get_terms(array(
        'taxonomy' => 'product_cat',
        'parent' => $category_id_origin, // Procesamos los hijos directos del origen actual
        'hide_empty' => false,
    ));

    if (empty($subcategories_to_process)) {
        $preview_log[] = sprintf(__('La categoría "%s" (ID: %d) no tiene subcategorías directas para procesar en este nivel.', 'delete-categories-woocommerce'), $origin_category_term->name, $category_id_origin);
    }

    foreach ($subcategories_to_process as $origin_term) { // $origin_term es la subcategoría que estamos evaluando
        $action_detail = [
            'origin_name' => $origin_term->name,
            'origin_id' => $origin_term->term_id,
            'product_count' => dcw_get_direct_product_count_for_term($origin_term->term_id),
            'action' => '',
            'target_name' => '',
            'target_id' => null,
            'target_parent_name' => '',
            'target_parent_id' => null,
            'details' => '',
            'children_preview' => [] // Puede ser una lista de acciones o una lista de hijos
        ];

        $term_with_same_slug = get_term_by('slug', $origin_term->slug, 'product_cat');
        $transfer_to_this_existing_term = null;

        if ($term_with_same_slug && $term_with_same_slug->term_id != $origin_term->term_id) {
            // Existe otro término con el mismo slug.
            // ¿Está este término ($term_with_same_slug) dentro de la jerarquía de $destination_parent_term o es $destination_parent_term mismo?
            if ($term_with_same_slug->term_id == $destination_parent_term->term_id || dcw_term_is_descendant_of($term_with_same_slug->term_id, $destination_parent_term->term_id, 'product_cat')) {
                $transfer_to_this_existing_term = $term_with_same_slug;
            }
        }

        if ($transfer_to_this_existing_term) {
            // Caso 1: Transferir productos a un término existente en la jerarquía de destino. $origin_term no se mueve.
            $action_detail['action'] = 'transferir_productos_a_existente_en_destino';
            $action_detail['target_name'] = $transfer_to_this_existing_term->name;
            $action_detail['target_id'] = $transfer_to_this_existing_term->term_id;
            $parent_of_target = get_term($transfer_to_this_existing_term->parent, 'product_cat');
            $action_detail['target_parent_name'] = $parent_of_target ? $parent_of_target->name : __('Raíz', 'delete-categories-woocommerce');
            $action_detail['target_parent_id'] = $parent_of_target ? $parent_of_target->term_id : 0;
            $action_detail['details'] = sprintf(
                __('Los productos de "%1$s" (ID: %2$d) se transferirán a la categoría existente "%3$s" (ID: %4$d) encontrada en la jerarquía de destino (bajo "%5$s"). La categoría origen "%1$s" no se moverá.', 'delete-categories-woocommerce'),
                $origin_term->name, $origin_term->term_id, $transfer_to_this_existing_term->name, $transfer_to_this_existing_term->term_id, $destination_parent_term->name
            );
            // Los hijos de $origin_term ahora se evalúan contra $transfer_to_this_existing_term como su nuevo destino.
            $children_preview_result = preview_subcategory_reorganization($origin_term->term_id, $transfer_to_this_existing_term->term_id);
            $action_detail['children_preview'] = $children_preview_result['actions'];
            $preview_log = array_merge($preview_log, $children_preview_result['log']);
        } else {
            // Caso 2: Mover $origin_term (y sus hijos como un bloque) bajo $destination_parent_term.
            $action_detail['action'] = 'mover_categoria_con_hijos';
            $action_detail['target_name'] = $origin_term->name; // El nombre se mantiene
            $action_detail['target_parent_name'] = $destination_parent_term->name;
            $action_detail['target_parent_id'] = $destination_parent_term->term_id;
            $action_detail['details'] = sprintf(
                __('La categoría "%s" (ID: %d) y su estructura de subcategorías se moverán para ser hijas de "%s". Si ya existe un hijo directo con el mismo slug bajo "%s", WordPress podría añadir un sufijo (ej. %s-2).', 'delete-categories-woocommerce'),
                $origin_term->name, $origin_term->term_id, $destination_parent_term->name, $destination_parent_term->name, $origin_term->slug
            );
            // Los hijos se mueven con el padre, su estructura interna no cambia respecto a $origin_term.
            // Listamos los hijos para información.
            $action_detail['children_preview'] = dcw_list_child_categories_for_preview($origin_term->term_id);
            // No es necesario fusionar $preview_log aquí ya que dcw_list_child_categories_for_preview no genera logs de este tipo.
        }
        $actions[] = $action_detail;
    }
    return ['log' => $preview_log, 'actions' => $actions];
}

/**
 * Handles the AJAX request for previewing subcategory reorganization.
 */
function handle_ajax_preview_subcategory_reorganization() {
    check_ajax_referer('preview_subcategories_nonce', 'security'); // Usar el nonce específico para previsualización

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permisos insuficientes.', 'delete-categories-woocommerce')], 403);
        return;
    }

    $category_id_origin = isset($_POST['category_id_origin']) ? intval($_POST['category_id_origin']) : 0;
    $category_id_destination = isset($_POST['category_id_destination']) ? intval($_POST['category_id_destination']) : 0;

    if ($category_id_origin <= 0 || $category_id_destination <= 0) {
        wp_send_json_error(['message' => __('IDs de categoría de origen o destino inválidos para la previsualización.', 'delete-categories-woocommerce')], 400);
        return;
    }

    if ($category_id_origin === $category_id_destination) {
        wp_send_json_error(['message' => __('La categoría de origen y destino no pueden ser la misma para la previsualización.', 'delete-categories-woocommerce')], 400);
        return;
    }

    $preview_data = preview_subcategory_reorganization($category_id_origin, $category_id_destination);

    wp_send_json_success($preview_data);
}
add_action('wp_ajax_preview_subcategory_reorganization', 'handle_ajax_preview_subcategory_reorganization'); 