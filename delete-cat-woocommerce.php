<?php
/**
 * Plugin Name: Delete Categories for WooCommerce
 * Plugin URI:  https://ejemplo.com/delete-categories-woocommerce
 * Description: Plugin para eliminar categorías de WooCommerce.
 * Version:     1.1.0
 * Author:      Javier Trujillo
 * Author URI:  https://ejemplo.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: delete-categories-woocommerce
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 8.7.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar textdomain para internacionalización
function dcw_load_textdomain() {
    load_plugin_textdomain(
        'delete-categories-woocommerce',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('plugins_loaded', 'dcw_load_textdomain');

// Añadir al principio del archivo principal
register_activation_hook(__FILE__, 'dcw_check_dependencies');

function dcw_check_dependencies() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Este plugin requiere WooCommerce. Por favor instala y activa WooCommerce primero.', 'delete-categories-woocommerce'));
    }
}

// Incluir funciones de lógica
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/transfer-products.php';
require_once plugin_dir_path(__FILE__) . 'includes/func-excel.php';
require_once plugin_dir_path(__FILE__) . 'includes/delete-empty-categories.php';
require_once plugin_dir_path(__FILE__) . 'includes/delete-empty-logic.php';
require_once plugin_dir_path(__FILE__) . 'includes/delete-category-products.php';

/**
 * Muestra un formulario para ingresar la URL de una categoría y devuelve sus productos.
 */
function display_category_id_form() {
    // Mostrar el formulario
    ?>
    <div class="wrap">
        <h1><?php _e('Herramientas de Categorías de Productos', 'delete-categories-woocommerce'); ?></h1>
        
        <div class="card dcw-form-section">
            <h2><?php _e('Cambiar Categoría de Productos por Lotes', 'delete-categories-woocommerce'); ?></h2>
            <form id="batch-processing-form" method="post" action="">
                <p><?php _e('Esta opción te permite procesar múltiples productos a la vez para cambiar su categoría.', 'delete-categories-woocommerce'); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="batch_category_url_origin"><?php _e('URL de la Categoría de Origen', 'delete-categories-woocommerce'); ?></label></th>
                        <td>
                            <input type="url" name="batch_category_url_origin" id="batch_category_url_origin" class="regular-text" required placeholder="https://tutienda.com/product-categoria/electronica/">
                            <p class="description"><?php _e('Ingresa la URL completa de la categoría de origen.', 'delete-categories-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_category_url_destination"><?php _e('URL de la Categoría de Destino', 'delete-categories-woocommerce'); ?></label></th>
                        <td>
                            <input type="url" name="batch_category_url_destination" id="batch_category_url_destination" class="regular-text" required placeholder="https://tutienda.com/product-categoria/ropa/">
                            <p class="description"><?php _e('Ingresa la URL completa de la categoría de destino.', 'delete-categories-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_size"><?php _e('Número de Productos', 'delete-categories-woocommerce'); ?></label></th>
                        <td>
                            <input type="number" name="batch_size" id="batch_size" class="small-text" value="10" min="1" max="100">
                            <p class="description"><?php _e('Cantidad máxima de productos a procesar (entre 1 y 100).', 'delete-categories-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="process_all"><?php _e('Procesar Todos', 'delete-categories-woocommerce'); ?></label></th>
                        <td>
                            <input type="checkbox" name="process_all" id="process_all" checked>
                            <p class="description"><?php _e('Marca esta casilla para procesar todos los productos sin límite.', 'delete-categories-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="change_subcategories"><?php _e('Cambiar Subcategorías', 'delete-categories-woocommerce'); ?></label></th>
                        <td>
                            <input type="checkbox" name="change_subcategories" id="change_subcategories">
                            <p class="description"><?php _e('Marca esta casilla para procesar también las subcategorías.', 'delete-categories-woocommerce'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <button type="button" id="start-batch-process" class="button button-primary"><?php _e('Procesar Productos en Lote', 'delete-categories-woocommerce'); ?></button>
                
                <div id="batch-processing-results" style="margin-top: 20px;">
                    <!-- Aquí se mostrará la barra de progreso y resultados -->
                </div>
            </form>
        </div>
        
        <div class="card dcw-form-section" style="margin-top: 20px;">
            <h2><?php _e('Eliminar Categorías Vacías', 'delete-categories-woocommerce'); ?></h2>
            <p><?php _e('Haz clic en el botón para eliminar todas las categorías de producto que no contengan productos y que no sean categorías padre.', 'delete-categories-woocommerce'); ?></p>
            <button type="button" id="delete-empty-categories" class="button button-danger"><?php _e('Eliminar Categorías Vacías', 'delete-categories-woocommerce'); ?></button>
            <div id="delete-results" style="margin-top: 10px;"></div>
        </div>

        <div class="card dcw-form-section" style="margin-top: 20px;">
            <h2><?php _e('Eliminar Productos de una Categoría', 'delete-categories-woocommerce'); ?></h2>
            <p><?php _e('Esta opción te permite eliminar todos los productos de una categoría específica. Los productos que solo pertenecen a esta categoría serán eliminados completamente. Los productos que también pertenecen a otras categorías serán desvinculados de esta categoría.', 'delete-categories-woocommerce'); ?></p>
            <form id="delete-category-products-form" method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="delete_category_url"><?php _e('URL de la Categoría', 'delete-categories-woocommerce'); ?></label></th>
                        <td>
                            <input type="url" name="delete_category_url" id="delete_category_url" class="regular-text" required placeholder="https://tutienda.com/product-categoria/categoria-a-eliminar/">
                            <p class="description"><?php _e('Ingresa la URL completa de la categoría de la que deseas eliminar productos.', 'delete-categories-woocommerce'); ?></p>
                        </td>
                    </tr>
                </table>
                <button type="button" id="delete-category-products-btn" class="button button-primary"><?php _e('Eliminar Productos de Categoría', 'delete-categories-woocommerce'); ?></button>
                <div id="delete-category-results" style="margin-top: 20px;"></div>
            </form>
        </div>
        
        <div class="card dcw-form-section" style="margin-top: 20px;">
            <h2><?php _e('Importar desde Excel', 'delete-categories-woocommerce'); ?></h2>
            <div style="margin-bottom: 15px;">
                <a href="<?php echo admin_url('admin-ajax.php?action=dcw_download_template&nonce='.wp_create_nonce('dcw_template_nonce')); ?>" 
                   class="button button-secondary"
                   id="download-template">
                   📥 <?php _e('Descargar Plantilla Excel', 'delete-categories-woocommerce'); ?>
                </a>
                <p class="description"><?php _e('Descarga un archivo de ejemplo con el formato requerido', 'delete-categories-woocommerce'); ?></p>
            </div>
            <form id="excel-import-form" method="post" enctype="multipart/form-data">
                <table class="form-table">
                    <tr>
                        <th><label><?php _e('Archivo Excel', 'delete-categories-woocommerce'); ?></label></th>
                        <td>
                            <input type="file" name="dcw_excel" accept=".xlsx, .csv" required>
                            <p class="description"><?php _e('Formato requerido: Columnas "Categoría Origen" y "Categoría Destino" (URLs o IDs)', 'delete-categories-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Modo de procesamiento', 'delete-categories-woocommerce'); ?></label></th>
                        <td>
                            <select name="processing_mode">
                                <option value="sequential"><?php _e('Secuencial (más lento pero seguro)', 'delete-categories-woocommerce'); ?></option>
                                <option value="batch"><?php _e('Por lotes (más rápido)', 'delete-categories-woocommerce'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php wp_nonce_field('dcw_excel_import', 'dcw_nonce'); ?>
                <button type="submit" class="button button-primary"><?php _e('Procesar Excel', 'delete-categories-woocommerce'); ?></button>
            </form>
            <div id="excel-results" style="margin-top: 20px;"></div>
        </div>
    </div>
    <?php
}

// Agregar el formulario al menú de administración de WordPress
function add_category_id_form_to_admin_menu() {
    add_menu_page(
        __('Cambiar Categoría de Productos', 'delete-categories-woocommerce'),  // Título de la página
        __('Cambiar Categoría', 'delete-categories-woocommerce'),               // Texto del menú
        'manage_options',                  // Capacidad requerida
        'get-category-id',                 // Slug de la página
        'display_category_id_form',        // Función que muestra la página
        'dashicons-tag',                   // Icono
        6                                  // Posición en el menú
    );
}
add_action('admin_menu', 'add_category_id_form_to_admin_menu');

// Registrar estilos CSS
function dcw_enqueue_admin_styles() {
    $screen = get_current_screen();
    
    if ($screen && $screen->id === 'toplevel_page_get-category-id') {
        // Estilos
        wp_enqueue_style(
            'dcw-admin-styles',
            plugin_dir_url(__FILE__) . 'assets/css/dcw-admin.css',
            array(),
            '1.0.0'
        );
        
        // Scripts
        wp_enqueue_script(
            'dcw-admin-js',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery'),
            '1.0.0',
            true
        );
        
        // Pasar variables de PHP a JavaScript
        wp_localize_script('dcw-admin-js', 'dcwData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonces' => array(
                'transfer' => wp_create_nonce("transfer_category_nonce"),
                'batch' => wp_create_nonce("batch_processing_nonce"),
                'delete' => wp_create_nonce("delete_empty_cats_nonce"),
                'delete_products' => wp_create_nonce("delete_category_products_nonce")
            ),
            'confirmDelete' => __('¿Estás seguro de que quieres eliminar todas las categorías de producto vacías y sin subcategorías? ¡Esta acción no se puede deshacer!', 'delete-categories-woocommerce')
        ));
    }
}
add_action('admin_enqueue_scripts', 'dcw_enqueue_admin_styles');

// Mantener solo los hooks esenciales al final del archivo:
add_action('wp_ajax_delete_empty_product_categories', 'handle_ajax_delete_empty_product_categories');
add_action('wp_ajax_get_product_ids', 'handle_ajax_get_product_ids');
add_action('wp_ajax_delete_category_products', 'handle_ajax_delete_category_products');

// Agregar esta nueva función
function handle_ajax_get_product_ids() {
    check_ajax_referer('batch_processing_nonce', 'security');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }

    $category_id = isset($_POST['category_id_origin']) ? intval($_POST['category_id_origin']) : 0;
    $change_subcategories = isset($_POST['change_subcategories']) ? filter_var($_POST['change_subcategories'], FILTER_VALIDATE_BOOLEAN) : false;
    
    if ($category_id <= 0) {
        wp_send_json_error('ID de categoría inválido');
    }

    // Usamos WP_Query para obtener resultados más precisos y completos
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
                'include_children' => $change_subcategories,
            )
        )
    );
    
    $query = new WP_Query($args);
    $product_ids = $query->posts;
    
    // Devolver los IDs en la respuesta
    wp_send_json_success(array(
        'product_ids' => $product_ids,
        'count' => count($product_ids),
        'category_id' => $category_id
    ));
}
