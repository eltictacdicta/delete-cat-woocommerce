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

// Añadir al principio del archivo principal
register_activation_hook(__FILE__, 'dcw_check_dependencies');

function dcw_check_dependencies() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Este plugin requiere WooCommerce. Por favor instala y activa WooCommerce primero.');
    }
}

// Incluir funciones de lógica
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';

/**
 * Muestra un formulario para ingresar la URL de una categoría y devuelve sus productos.
 */
function display_category_id_form() {
    // Mostrar el formulario
    ?>
    <div class="wrap">
        <h1>Cambiar Categoría de Productos</h1>
        
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
                    <tr>
                        <th scope="row"><label for="process_all">Procesar Todos</label></th>
                        <td>
                            <input type="checkbox" name="process_all" id="process_all">
                            <p class="description">Marca esta casilla para procesar todos los productos sin límite.</p>
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
                    security: '<?php echo esc_js(wp_create_nonce("transfer_category_nonce")); ?>'
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
            var processAll = $('#process_all').is(':checked');
            
            progressContainer.html(`
                <div class="dcw-progress-container">
                    <div class="dcw-progress-bar" id="dcw-progress-bar" style="width: 0%">0%</div>
                </div>
                <div id="dcw-current-operation" class="dcw-current-operation">Iniciando proceso...</div>
                <div id="dcw-status-messages" class="dcw-status-messages"></div>
            `);
            
            // Obtener la lista completa de IDs primero
            $.ajax({
                type: 'POST',
                url: ajaxurl,
                dataType: 'json',
                data: {
                    action: 'get_product_ids',
                    category_id_origin: originCategoryId,
                    security: '<?php echo wp_create_nonce("batch_processing_nonce"); ?>'
                },
                success: function(response) {
                    if(response.success) {
                        var productIds = response.data.product_ids;
                        var totalProducts = Math.min(productIds.length, batchSize);
                        
                        // Nueva variable para llevar el conteo
                        let processedCount = 0;
                        
                        // Función recursiva para procesamiento secuencial
                        function processNextProduct(index) {
                            if(index >= totalProducts || index >= productIds.length) {
                                $('#dcw-progress-bar').css('width', '100%').text('100%');
                                $('#dcw-current-operation').html('Proceso completado');
                                return;
                            }
                            
                            // Actualizar operación actual CON el índice correcto
                            $('#dcw-current-operation').html('Procesando producto ' + (index + 1) + ' de ' + totalProducts);
                            
                            // Agregar timeout para evitar bloqueos
                            $.ajax({
                                type: 'POST',
                                url: ajaxurl,
                                dataType: 'json',
                                timeout: 30000, // 30 segundos de timeout
                                data: {
                                    action: 'process_single_product',
                                    product_id: productIds[index],
                                    category_id_origin: originCategoryId,
                                    category_id_destination: destinationCategoryId,
                                    security: '<?php echo wp_create_nonce("batch_processing_nonce"); ?>'
                                },
                                success: function(response) {
                                    // wp_send_json_success envuelve la respuesta en 'data'
                                    // La respuesta de éxito real está en response.data
                                    if(response && response.success && response.data) {
                                        const processedCount = index + 1;
                                        const totalProducts = Math.min(productIds.length, batchSize);
                                        const progress = Math.round((processedCount / totalProducts) * 100);
                                        $('#dcw-progress-bar').css('width', progress + '%').text(progress + '%');
                                        
                                        // Mostrar TODOS los mensajes del producto que vienen en response.data.messages
                                        const messagesHtml = Array.isArray(response.data.messages) ? response.data.messages.join('<br>') : '<p>No hay mensajes detallados disponibles.</p>';
                                        const productTitle = response.data.product_title || 'Producto desconocido';
                                        const productId = productIds[index]; // Usar el ID del producto actual
                                        
                                        $('#dcw-status-messages').prepend(`
                                            <div class="notice notice-success">
                                                <h4>${productTitle} (ID: ${productId})</h4>
                                                <div class="product-details">${messagesHtml}</div>
                                            </div>`
                                        );
                                        
                                        // Procesar siguiente producto inmediatamente
                                        processNextProduct(index + 1);

                                    } else {
                                        // Esto manejaría casos donde response.success es false pero la estructura es inesperada
                                        console.error('Respuesta AJAX inválida o incompleta en success handler:', response);
                                        $('#dcw-status-messages').prepend(`
                                            <div class="notice notice-error">
                                                <h4>Error procesando producto ID: ${productIds[index]}</h4>
                                                <p>Respuesta del servidor no exitosa o incompleta.</p>
                                                 <pre>${JSON.stringify(response, null, 2).substring(0, 500)}...</pre> <!-- Mostrar respuesta parcial -->
                                            </div>`
                                        );
                                        
                                        // Continuar con el siguiente producto incluso si hay error
                                         const processedCount = index + 1;
                                         const totalProducts = Math.min(productIds.length, batchSize);
                                         const progress = Math.round((processedCount / totalProducts) * 100);
                                         $('#dcw-progress-bar').css('width', progress + '%').text(progress + '%');
                                        processNextProduct(index + 1);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.error('Error en AJAX para producto ' + productIds[index] + ':', status, error, xhr.responseText);
                                    
                                    let errorMessage = `Error: ${error || 'Desconocido'}`;
                                    let errorDetails = xhr.responseText ? xhr.responseText.substring(0, 200) + '...' : 'Vacía';

                                    // Intentar parsear la respuesta si es JSON
                                    try {
                                        const responseJson = JSON.parse(xhr.responseText);
                                        if (responseJson.message) errorMessage = `Error: ${responseJson.message}`;
                                        if (responseJson.details && Array.isArray(responseJson.details)) {
                                            errorDetails = responseJson.details.join('<br>');
                                        } else if (typeof responseJson.data === 'object' && responseJson.data.messages && Array.isArray(responseJson.data.messages)) {
                                             // Manejar caso de wp_send_json_error
                                             errorDetails = responseJson.data.messages.join('<br>');
                                        } else {
                                             errorDetails = xhr.responseText.substring(0, 200) + '...';
                                        }
                                    } catch (e) {
                                        // No es JSON válido, usar texto crudo
                                    }

                                    $('#dcw-status-messages').prepend(`
                                        <div class="notice notice-error">
                                            <h4>Error procesando producto ID: ${productIds[index]}</h4>
                                            <p>${errorMessage}</p>
                                            <div class="product-details">${errorDetails}</div>
                                        </div>`
                                    );
                                    
                                    // Actualizar progreso incluso si hay error AJAX completo
                                    const processedCount = index + 1;
                                    const totalProducts = Math.min(productIds.length, batchSize);
                                    const progress = Math.round((processedCount / totalProducts) * 100);
                                    $('#dcw-progress-bar').css('width', progress + '%').text(progress + '%');

                                    // Continuar con el siguiente producto incluso si hay error
                                    processNextProduct(index + 1);
                                }
                            });
                        }
                        
                        // Iniciar procesamiento
                        if(totalProducts > 0) {
                            processNextProduct(0);
                        } else {
                            // Actualizar todos los elementos de UI
                            $('#dcw-progress-bar').css('width', '100%').text('100%');
                            $('#dcw-current-operation').html('Proceso completado');
                            $('#dcw-status-messages').html(
                                '<div class="notice notice-success">' +
                                    '<p>✅ Todos los productos han sido procesados</p>' +
                                    '<p>No se encontraron más productos en la categoría de origen</p>' +
                                '</div>'
                            );
                        }
                    } else {
                        // Mostrar mensaje predeterminado si no viene en la respuesta
                        var errorMessage = 'Error: ' + (response.message || 'Desconocido');
                        var details = response.details ? response.details.join('<br>') : '';
                        
                        $('#dcw-status-messages').html(
                            '<div class="notice notice-error">' +
                                '<p>' + errorMessage + '</p>' +
                                (details ? '<div class="error-details">' + details + '</div>' : '') +
                            '</div>'
                        );
                    }
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
        
        // Removed the single product processing code
        
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

// Mantener solo los hooks AJAX principales
add_action('wp_ajax_transfer_product_category', 'handle_ajax_transfer_product_category');
add_action('wp_ajax_batch_process_categories', 'handle_ajax_batch_processing');
