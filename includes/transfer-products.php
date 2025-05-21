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