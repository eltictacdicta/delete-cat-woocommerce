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
    $category_id_origin = isset($_POST['category_id_origin']) ? intval($_POST['category_id_origin']) : 0;
    $category_id_destination = isset($_POST['category_id_destination']) ? intval($_POST['category_id_destination']) : 0;
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;
    
    // Validar IDs de categorías
    if ($category_id_origin <= 0 || $category_id_destination <= 0) {
        wp_send_json_error(array('message' => 'IDs de categoría inválidos.'));
        return;
    }
    
    // Obtener productos de la categoría origen
    // Nota: get_products_by_category_id y batch_replace_product_categories deben estar definidos en includes/functions.php o similar
    $args = array(
        'status' => 'publish',
        'limit' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $category_id_origin,
                'operator' => 'IN',
                'include_children' => false
            )
        ),
        'fields' => 'ids' // Obtener solo IDs para mejor rendimiento
    );

    $product_ids = get_posts($args);
    
    if (!$product_ids || !isset($product_ids) || !$product_ids) {
        wp_send_json_error(array(
            'message' => 'No se encontraron productos en la categoría origen.',
            'details' => []
        ));
        return;
    }
    
    // Limitar al tamaño del lote especificado
    if (count($product_ids) > $batch_size) {
        $product_ids = array_slice($product_ids, 0, $batch_size);
    }
    
    // Iniciar el buffer de salida para capturar la barra de progreso
    ob_start();
    
    // Procesar el lote de productos
    $batch_results = batch_replace_product_categories($product_ids, $category_id_origin, $category_id_destination);
    
    // Obtener la salida de la barra de progreso
    $progress_output = ob_get_clean();
    
    // Asegurarnos de que tenemos valores válidos para los contadores
    $success_count = isset($batch_results['success']) ? intval($batch_results['success']) : 0;
    $failed_count = isset($batch_results['failed']) ? intval($batch_results['failed']) : 0;
    $skipped_count = isset($batch_results['skipped']) ? intval($batch_results['skipped']) : 0;
    
    // Preparar la respuesta para devolver al cliente
    $response = array(
        'success' => true,
        'message' => 'Procesamiento completado.',
        'progress_html' => $progress_output,
        'results' => $batch_results,
        'total_processed' => count($product_ids),
        'success_count' => $success_count,
        'failed_count' => $failed_count,
        'skipped_count' => $skipped_count
    );
    
    wp_send_json_success($response);
}
add_action('wp_ajax_batch_process_categories', 'handle_ajax_batch_processing'); 