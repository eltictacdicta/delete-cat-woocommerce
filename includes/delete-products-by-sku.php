<?php
/**
 * Funciones para eliminar productos por una lista de SKUs.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manejador AJAX para eliminar productos por SKU.
 */
function handle_ajax_delete_products_by_sku() {
    check_ajax_referer('delete_products_by_sku_nonce', 'security');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permisos insuficientes']);
    }
    
    $skus_string = isset($_POST['skus']) ? sanitize_textarea_field($_POST['skus']) : '';
    $delete_images = isset($_POST['delete_images']) ? filter_var($_POST['delete_images'], FILTER_VALIDATE_BOOLEAN) : false;
    
    if (empty($skus_string)) {
        wp_send_json_error(['message' => 'Lista de SKUs vacía']);
    }
    
    $skus = array_filter(array_map('trim', explode("\n", $skus_string)));
    
    if (empty($skus)) {
        wp_send_json_error(['message' => 'No se encontraron SKUs válidos en la lista']);
    }
    
    $results = [
        'total_skus' => count($skus),
        'deleted_count' => 0,
        'not_found_skus' => [],
        'failed_to_delete_skus' => [],
        'log' => []
    ];
    
    foreach ($skus as $sku) {
        // Buscar el producto por SKU
        $product_id = wc_get_product_id_by_sku($sku);
        
        if (!$product_id) {
            $results['not_found_skus'][] = $sku;
            $results['log'][] = "SKU no encontrado: " . $sku;
            continue;
        }
        
        // Obtener el objeto producto para acceder a la galería
        $product = wc_get_product($product_id);
        if (!$product) {
            $results['failed_to_delete_skus'][] = $sku;
            $results['log'][] = "Error al obtener objeto producto para SKU: " . $sku . " (ID: " . $product_id . ")";
            continue;
        }
        
        // Eliminar imágenes asociadas si la opción está marcada
        if ($delete_images) {
            $thumbnail_id = $product->get_image_id();
            if ($thumbnail_id) {
                if (wp_delete_attachment($thumbnail_id, true)) {
                    $results['log'][] = "Imagen destacada eliminada para el producto ID: " . $product_id;
                } else {
                    $results['log'][] = "Error al eliminar la imagen destacada para el producto ID: " . $product_id;
                }
            }
            
            $gallery_image_ids = $product->get_gallery_image_ids();
            if (!empty($gallery_image_ids)) {
                foreach ($gallery_image_ids as $gallery_image_id) {
                    if (wp_delete_attachment($gallery_image_id, true)) {
                        $results['log'][] = "Imagen de galería eliminada (ID: " . $gallery_image_id . ") para el producto ID: " . $product_id;
                    } else {
                        $results['log'][] = "Error al eliminar la imagen de galería (ID: " . $gallery_image_id . ") para el producto ID: " . $product_id;
                    }
                }
            }
        }
        
        // Eliminar el producto
        $delete_result = wp_delete_post($product_id, true);
        
        if ($delete_result) {
            $results['deleted_count']++;
            $results['log'][] = "Producto eliminado exitosamente para SKU: " . $sku . " (ID: " . $product_id . ")";
        } else {
            $results['failed_to_delete_skus'][] = $sku;
            $results['log'][] = "Error al eliminar el producto para SKU: " . $sku . " (ID: " . $product_id . ")";
        }
    }
    
    wp_send_json_success($results);
}

// Nota: El hook AJAX para esta función se registra en delete-cat-woocommerce.php 