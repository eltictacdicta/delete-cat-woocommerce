<?php
/**
 * Plugin Name: Delete Categories for WooCommerce
 * Plugin URI:  https://ejemplo.com/delete-categories-woocommerce
 * Description: Plugin para eliminar categorías de WooCommerce.
 * Version:     1.0.0
 * Author:      Javier Trujillo
 * Author URI:  https://ejemplo.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: delete-categories-woocommerce
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 5.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Incluir funciones de lógica
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';

// Agregar logging para depuración
function write_log($log) {
    if (true === WP_DEBUG) {
        if (is_array($log) || is_object($log)) {
            error_log(print_r($log, true));
        } else {
            error_log($log);
        }
    }
}

/**
 * Muestra un formulario para ingresar la URL de una categoría y devuelve sus productos.
 */
function display_category_id_form() {
    // Verificar si se enviaron los datos del formulario principal
    if (isset($_POST['submitted'])) {
        write_log('Formulario enviado');
        
        if (isset($_POST['category_url_origin']) && isset($_POST['category_url_destination'])) {
            $category_url_origin = esc_url_raw($_POST['category_url_origin']);
            $category_url_destination = esc_url_raw($_POST['category_url_destination']);
            
            // Obtener productos
            $category_id_origin = get_category_id_from_url($category_url_origin);
            $products_result = get_products_by_category_id($category_id_origin);
            
            if (!$products_result || !isset($products_result['products']) || $products_result['products'] === false) {
                echo '<div class="notice notice-error"><p>No se pudieron obtener productos. Verifica la URL de origen.</p></div>';
                write_log('No se pudieron obtener productos de la URL: ' . $category_url_origin);
            } else {
                $products = $products_result['products'];
                $first_product = reset($products);
                $category_id_destination = get_category_id_from_url($category_url_destination);
                $categorias_antes_de_cambiar = get_product_category_ids($first_product['id']);
                
                echo '<div class="notice notice-info">';
                echo '<p>ID de la categoría origen: ' . esc_html($category_id_origin) . '</p>';
                echo '<p>ID de la categoría destino: ' . esc_html($category_id_destination) . '</p>';
                echo '<p>ID del primer producto de la categoría origen: ' . esc_html($first_product['id']) . '</p>';
                echo '<p>Categorías antes del cambio del primer producto: ' . implode(', ', $categorias_antes_de_cambiar) . '</p>';
                echo '</div>';
                
                $result = replace_product_category($first_product['id'], $category_id_origin, $category_id_destination);
                
                if ($result['success']) {
                    echo '<div class="notice notice-success"><p>Se cambió exitosamente la categoría del producto "' . esc_html($first_product['title']) . '" de la categoría origen a la categoría destino.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Error al cambiar la categoría del producto. Es posible que el producto solo tenga la categoría de origen y no se pueda desvincular para evitar que quede huérfano.</p></div>';
                }
            }
        } else {
            echo '<div class="notice notice-error"><p>Faltan datos del formulario.</p></div>';
            write_log('Faltan campos en el formulario');
        }
    }

    // Mostrar el formulario
    ?>
    <div class="wrap">
        <h1>Cambiar Categoría de Productos</h1>
        
        <div class="card dcw-form-section">
            <h2>Cambiar categoría del primer producto</h2>
            <form id="category-transfer-form" method="post" action="">
                <input type="hidden" name="submitted" value="1">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="category_url_origin">URL de la Categoría de Origen</label></th>
                        <td>
                            <input type="url" name="category_url_origin" id="category_url_origin" class="regular-text" required placeholder="https://tutienda.com/product-categoria/electronica/">
                            <p class="description">Ingresa la URL completa de la categoría de origen.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="category_url_destination">URL de la Categoría de Destino</label></th>
                        <td>
                            <input type="url" name="category_url_destination" id="category_url_destination" class="regular-text" required placeholder="https://tutienda.com/product-categoria/ropa/">
                            <p class="description">Ingresa la URL completa de la categoría de destino.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Cambiar Categoría del Primer Producto', 'primary', 'submit', false); ?>
                <div id="transfer-status"></div>
            </form>
        </div>
        
        <div class="card dcw-form-section">
            <h2>Procesar Productos por Lotes</h2>
            <form id="batch-processing-form" method="post" action="">
                <p>Esta opción te permite procesar múltiples productos a la vez para cambiar su categoría.</p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="batch_category_url_origin">URL de la Categoría de Origen</label></th>
                        <td>
                            <input type="url" name="batch_category_url_origin" id="batch_category_url_origin" class="regular-text" required placeholder="https://tutienda.com/product-categoria/electronica/">
                            <p class="description">Ingresa la URL completa de la categoría de origen.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_category_url_destination">URL de la Categoría de Destino</label></th>
                        <td>
                            <input type="url" name="batch_category_url_destination" id="batch_category_url_destination" class="regular-text" required placeholder="https://tutienda.com/product-categoria/ropa/">
                            <p class="description">Ingresa la URL completa de la categoría de destino.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_size">Número de Productos</label></th>
                        <td>
                            <input type="number" name="batch_size" id="batch_size" class="small-text" value="10" min="1" max="100">
                            <p class="description">Cantidad máxima de productos a procesar (entre 1 y 100).</p>
                        </td>
                    </tr>
                </table>
                
                <button type="button" id="start-batch-process" class="button button-primary">Procesar Productos en Lote</button>
                
                <div id="batch-processing-results" style="margin-top: 20px;">
                    <!-- Aquí se mostrará la barra de progreso y resultados -->
                </div>
            </form>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Código existente para el formulario de un solo producto
        $('#category-transfer-form').on('submit', function(e) {
            e.preventDefault();
            
            var form = $(this);
            var statusDiv = $('#transfer-status');
            statusDiv.html('<div class="notice notice-info"><p>Procesando solicitud...</p></div>');
            
            $.ajax({
                type: 'POST',
                url: ajaxurl,
                dataType: 'json',
                data: {
                    action: 'transfer_product_category',
                    category_url_origin: $('#category_url_origin').val(),
                    category_url_destination: $('#category_url_destination').val(),
                    security: '<?php echo wp_create_nonce("transfer_category_nonce"); ?>'
                },
                success: function(response) {
                    var html = '';
                    if(response.success) {
                        html = '<div class="notice notice-success"><p>' + response.message + '</p>';
                    } else {
                        html = '<div class="notice notice-error"><p>' + response.message + '</p>';
                    }
                    
                    if(response.details && response.details.length > 0) {
                        html += '<ul>';
                        response.details.forEach(function(detail) {
                            html += '<li>' + detail + '</li>';
                        });
                        html += '</ul>';
                    }
                    
                    html += '</div>';
                    statusDiv.html(html);
                },
                error: function(xhr, status, error) {
                    var errorMessage = 'Error en la comunicación con el servidor';
                    try {
                        if(xhr.responseJSON && xhr.responseJSON.message) {
                            errorMessage = xhr.responseJSON.message;
                        }
                    } catch(e) {
                        console.error('Error parsing response:', e);
                    }
                    statusDiv.html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
                }
            });
        });
        
        // Nuevo código para el procesamiento por lotes
        $('#start-batch-process').on('click', function() {
            var originUrl = $('#batch_category_url_origin').val();
            var destinationUrl = $('#batch_category_url_destination').val();
            var batchSize = $('#batch_size').val();
            var resultsDiv = $('#batch-processing-results');
            
            if(!originUrl || !destinationUrl) {
                resultsDiv.html('<div class="notice notice-error"><p>Por favor, ingresa las URLs de ambas categorías.</p></div>');
                return;
            }
            
            // Preparar la interfaz para mostrar el proceso
            resultsDiv.html('<div class="notice notice-info"><p>Obteniendo información de las categorías...</p></div>');
            
            // Primer paso: obtener los IDs de las categorías
            $.ajax({
                type: 'POST',
                url: ajaxurl,
                dataType: 'json',
                data: {
                    action: 'transfer_product_category',
                    category_url_origin: originUrl,
                    category_url_destination: destinationUrl,
                    security: '<?php echo wp_create_nonce("transfer_category_nonce"); ?>'
                },
                success: function(response) {
                    if(!response.success) {
                        resultsDiv.html('<div class="notice notice-error"><p>' + response.message + '</p></div>');
                        return;
                    }
                    
                    // Si llegamos aquí, tenemos los IDs de las categorías
                    var originCategoryId = response.debug.origin_category;
                    var destinationCategoryId = response.debug.destination_category;
                    
                    // Mostrar la información de las categorías
                    resultsDiv.html('<div class="notice notice-info">' +
                        '<p>Categoría origen: ID ' + originCategoryId + '</p>' +
                        '<p>Categoría destino: ID ' + destinationCategoryId + '</p>' +
                        '<p>Comenzando el procesamiento de hasta ' + batchSize + ' productos...</p>' +
                        '</div>' +
                        '<div id="batch-progress-container"></div>');
                    
                    // Iniciar el procesamiento por lotes
                    processBatch(originCategoryId, destinationCategoryId, batchSize);
                },
                error: function() {
                    resultsDiv.html('<div class="notice notice-error"><p>Error al obtener información de las categorías.</p></div>');
                }
            });
        });
        
        // Función para procesar el lote
        function processBatch(originCategoryId, destinationCategoryId, batchSize) {
            var progressContainer = $('#batch-progress-container');
            
            // Iniciar el procesamiento por lotes
            $.ajax({
                type: 'POST',
                url: ajaxurl,
                dataType: 'json',
                data: {
                    action: 'batch_process_categories',
                    category_id_origin: originCategoryId,
                    category_id_destination: destinationCategoryId,
                    batch_size: batchSize,
                    security: '<?php echo wp_create_nonce("batch_processing_nonce"); ?>'
                },
                success: function(response) {
                    if(response.success) {
                        // Mostrar la barra de progreso y resultados
                        progressContainer.html(response.data.progress_html);
                        
                        // Obtener los valores de los contadores con valores por defecto
                        var successCount = response.data.success_count !== undefined ? response.data.success_count : 0;
                        var skippedCount = response.data.skipped_count !== undefined ? response.data.skipped_count : 0;
                        var failedCount = response.data.failed_count !== undefined ? response.data.failed_count : 0;
                        var totalProcessed = response.data.total_processed !== undefined ? response.data.total_processed : 0;
                        
                        // Añadir resumen de resultados
                        progressContainer.append(
                            '<div class="notice notice-success">' +
                            '<p><strong>Resultados:</strong></p>' +
                            '<p>✓ Procesados con éxito: ' + successCount + '</p>' +
                            '<p>⚠ Saltados: ' + skippedCount + '</p>' +
                            '<p>✗ Fallidos: ' + failedCount + '</p>' +
                            '<p><strong>Total procesado: ' + totalProcessed + '</strong></p>' +
                            '</div>'
                        );
                    } else {
                        var errorMessage = response.data && response.data.message ? response.data.message : 'Error durante el procesamiento.';
                        progressContainer.html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
                    }
                },
                error: function() {
                    progressContainer.html('<div class="notice notice-error"><p>Error durante el procesamiento por lotes.</p></div>');
                }
            });
        }
    });
    </script>
    <?php
}

// Agregar el formulario al menú de administración de WordPress
function add_category_id_form_to_admin_menu() {
    add_menu_page(
        'Cambiar Categoría de Productos',  // Título de la página
        'Cambiar Categoría',               // Texto del menú
        'manage_options',                  // Capacidad requerida
        'get-category-id',                 // Slug de la página
        'display_category_id_form',        // Función que muestra la página
        'dashicons-tag',                   // Icono
        6                                  // Posición en el menú
    );
}
add_action('admin_menu', 'add_category_id_form_to_admin_menu');

// Añadir el manejador AJAX
add_action('wp_ajax_transfer_product_category', 'handle_ajax_transfer_product_category');

function handle_ajax_transfer_product_category() {
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
        
        // Obtener productos
        $products_data = get_products_by_category_id($category_id_origin);
        if ($products_data['products'] === false) {
            throw new Exception('No se encontraron productos en la categoría de origen.');
        }
        
        $first_product = reset($products_data['products']);
        if (!$first_product) {
            throw new Exception('No hay productos disponibles en la categoría de origen.');
        }
        
        // Get product title before changes
        $product_title = get_the_title($first_product['id']);
        
        // Procesar el cambio de categoría
        $result = replace_product_category($first_product['id'], $category_id_origin, $category_id_destination);
        
        $response = [
            'success' => $result['success'],
            'message' => $result['success'] ? 
                'Operación completada exitosamente.' : 
                'Error en la operación.',
            'product_title' => $product_title,
            'details' => $result['messages'],
            'debug' => [
                'origin_category' => $category_id_origin,
                'destination_category' => $category_id_destination,
                'product_id' => $first_product['id']
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

// Registrar estilos CSS
function dcw_enqueue_admin_styles() {
    $screen = get_current_screen();
    
    // Solo cargar en la página de nuestro plugin
    if ($screen && $screen->id === 'toplevel_page_get-category-id') {
        wp_enqueue_style(
            'dcw-admin-styles',
            plugin_dir_url(__FILE__) . 'assets/css/dcw-admin.css',
            array(),
            '1.0.0'
        );
    }
}
add_action('admin_enqueue_scripts', 'dcw_enqueue_admin_styles');

// Función para manejar el procesamiento por lotes vía AJAX
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
    $products_result = get_products_by_category_id($category_id_origin);
    
    if (!$products_result || !isset($products_result['products']) || !$products_result['products']) {
        wp_send_json_error(array(
            'message' => 'No se encontraron productos en la categoría origen.',
            'details' => isset($products_result['output']) ? $products_result['output'] : []
        ));
        return;
    }
    
    $products = $products_result['products'];
    
    // Limitar al tamaño del lote especificado
    if (count($products) > $batch_size) {
        $products = array_slice($products, 0, $batch_size);
    }
    
    // Extraer solo los IDs de los productos
    $product_ids = array_column($products, 'id');
    
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
