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
    // Verificar si se está realizando un diagnóstico de categoría
    if (isset($_POST['diagnosticar_categoria'])) {
        if (isset($_POST['diagnostic_category_id']) && is_numeric($_POST['diagnostic_category_id'])) {
            $category_id = intval($_POST['diagnostic_category_id']);
            $info = check_taxonomy_integrity($category_id);
            
            echo '<div class="wrap">';
            echo '<h2>Diagnóstico de Categoría ID: ' . esc_html($category_id) . '</h2>';
            echo '<div class="notice notice-info">';
            
            if ($info['term_exists']) {
                echo '<p><strong>Nombre de categoría:</strong> ' . esc_html($info['term_info']['name']) . '</p>';
                echo '<p><strong>Slug:</strong> ' . esc_html($info['term_info']['slug']) . '</p>';
                echo '<p><strong>Contador interno:</strong> ' . esc_html($info['term_info']['count']) . '</p>';
            } else {
                echo '<p class="notice notice-error">La categoría no existe</p>';
            }
            
            echo '<p><strong>Contador en taxonomía:</strong> ' . esc_html($info['product_count']) . '</p>';
            echo '<p><strong>Productos reales:</strong> ' . esc_html($info['actual_product_count']) . '</p>';
            
            if (!empty($info['issues'])) {
                echo '<div class="notice notice-warning">';
                echo '<p><strong>Problemas detectados:</strong></p>';
                echo '<ul>';
                foreach ($info['issues'] as $issue) {
                    echo '<li>' . esc_html($issue) . '</li>';
                }
                echo '</ul>';
                echo '</div>';
                
                // Mostrar formulario para reparar
                echo '<form method="post" action="">';
                echo '<input type="hidden" name="repair_category_id" value="' . esc_attr($category_id) . '">';
                echo '<input type="submit" name="reparar_categoria" class="button button-primary" value="Reparar Categoría">';
                echo '</form>';
            } else {
                echo '<p class="notice notice-success">No se detectaron problemas con esta categoría.</p>';
            }
            
            echo '</div>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=get-category-id')) . '" class="button">Volver</a>';
            echo '</div>';
            return;
        }
    }
    
    // Verificar si se está reparando una categoría
    if (isset($_POST['reparar_categoria']) && isset($_POST['repair_category_id']) && is_numeric($_POST['repair_category_id'])) {
        $category_id = intval($_POST['repair_category_id']);
        $result = rebuild_category_relationships($category_id);
        
        echo '<div class="wrap">';
        echo '<h2>Reparación de Categoría</h2>';
        
        if ($result) {
            echo '<div class="notice notice-success"><p>La categoría ID ' . esc_html($category_id) . ' ha sido reparada exitosamente.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>No se pudo reparar la categoría ID ' . esc_html($category_id) . '.</p></div>';
        }
        
        echo '<a href="' . esc_url(admin_url('admin.php?page=get-category-id')) . '" class="button">Volver</a>';
        echo '</div>';
        return;
    }

    // Verificar si se enviaron los datos del formulario principal
    if (isset($_POST['submitted'])) {
        write_log('Formulario enviado');
        
        if (isset($_POST['category_url_origin']) && isset($_POST['category_url_destination'])) {
            $category_url_origin = esc_url_raw($_POST['category_url_origin']);
            $category_url_destination = esc_url_raw($_POST['category_url_destination']);
            
            
            // Obtener productos
            $category_id_origin = get_category_id_from_url($category_url_origin);
            $products = get_products_by_category_id($category_id_origin);
            
            if ($products === false) {
                echo '<div class="notice notice-error"><p>No se pudieron obtener productos. Verifica la URL de origen.</p></div>';
                write_log('No se pudieron obtener productos de la URL: ' . $category_url_origin);
            } else {
                
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
                if ($result) {
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
        
        <div class="card">
            <h2>Cambiar categoría del primer producto</h2>
            <form method="post" action="">
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
                <?php submit_button('Cambiar Categoría del Primer Producto'); ?>
            </form>
        </div>
        
        <div class="card" style="margin-top: 20px;">
            <h2>Diagnosticar Categoría</h2>
            <p>Utiliza esta herramienta para diagnosticar problemas con una categoría específica.</p>
            
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="diagnostic_category_id">ID de Categoría</label></th>
                        <td>
                            <input type="number" name="diagnostic_category_id" id="diagnostic_category_id" class="regular-text" required min="1">
                            <p class="description">Ingresa el ID de la categoría que deseas diagnosticar.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Diagnosticar Categoría', 'secondary', 'diagnosticar_categoria'); ?>
            </form>
        </div>
    </div>
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
