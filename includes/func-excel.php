<?php
/**
 * Funciones y lógica para la importación de Excel del plugin Delete Categories for WooCommerce
 */

require_once __DIR__.'/../vendor/autoload.php';

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_dcw_process_excel', 'handle_excel_import');
add_action('wp_ajax_dcw_download_template', 'generate_excel_template');

function handle_excel_import() {
    // Verificar seguridad y permisos
    if (!wp_verify_nonce($_POST['dcw_nonce'], 'dcw_excel_import') || !current_user_can('manage_options')) {
        wp_send_json_error('Acceso no autorizado');
    }

    $file = $_FILES['dcw_excel'];
    $upload = wp_handle_upload($file, ['test_form' => false]);
    
    if (isset($upload['error'])) {
        wp_send_json_error($upload['error']);
    }

    $result = process_category_excel($upload['file']);
    wp_send_json($result);
}

/** * Procesa un archivo Excel con pares de categorías */
function process_category_excel($file_path) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    
    // Cargar librería PHPExcel/PhpSpreadsheet
    if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        return ['success' => false, 'message' => __('Librería PhpSpreadsheet no instalada', 'delete-categories-woocommerce')];
    }
    $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
    $worksheet = $spreadsheet->getActiveSheet();
    $results = [];
    $processing_mode = isset($_POST['processing_mode']) ? sanitize_text_field($_POST['processing_mode']) : 'sequential';
    $batch_size = 50; // Tamaño de lote predeterminado para procesamiento optimizado
    
    // Preparar un array de todas las tareas de procesamiento
    $tasks = [];
    $total_rows = 0;
    
    // Primera pasada: recopilamos todas las tareas de procesamiento
    foreach ($worksheet->getRowIterator(2) as $row) { // Saltar encabezados
        $total_rows++;
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(true);
        
        $origin = $cellIterator->current()->getValue();
        $cellIterator->next();
        $destination = $cellIterator->current()->getValue();
        
        // Obtener IDs de categorías
        $origin_id = is_numeric($origin) ? intval($origin) : get_category_id_from_url($origin);
        $dest_id = is_numeric($destination) ? intval($destination) : get_category_id_from_url($destination);
        if (!$origin_id || !$dest_id) {
            $results[] = [
                'success' => false,
                'message' => sprintf(__("Línea %d: Categorías inválidas", 'delete-categories-woocommerce'), $row->getRowIndex())
            ];
            continue;
        }
        $tasks[] = [
            'row' => $row->getRowIndex(),
            'origin_id' => $origin_id,
            'dest_id' => $dest_id
        ];
    }
    
    // Procesamiento según el modo seleccionado
    if ($processing_mode === 'batch') {
        // Modo por lotes: procesamos todas las categorías de una vez
        $all_product_ids = [];
        $category_mapping = [];
        
        // Recopilar todos los productos de todas las categorías origen
        foreach ($tasks as $task) {
            $products = get_products_by_category_id($task['origin_id']);
            if (!empty($products['products'])) {
                $product_ids = array_column($products['products'], 'id');
                foreach ($product_ids as $product_id) {
                    $all_product_ids[] = $product_id;
                    // Mapear cada producto a su categoría de destino
                    $category_mapping[$product_id] = [
                        'origin' => $task['origin_id'],
                        'destination' => $task['dest_id']
                    ];
                }
            }
        }
        
        // Eliminar duplicados
        $all_product_ids = array_unique($all_product_ids);
        
        // Procesar todos los productos en lotes optimizados
        $batches = array_chunk($all_product_ids, $batch_size);
        $batch_results = [
            'total' => count($all_product_ids),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0
        ];
        foreach ($batches as $batch) {
            foreach ($batch as $product_id) {
                $mapping = $category_mapping[$product_id];
                $result = replace_product_category(
                    $product_id,
                    $mapping['origin'],
                    $mapping['destination']
                );
                if ($result['success']) {
                    $batch_results['success']++;
                } else if (isset($result['skipped']) && $result['skipped']) {
                    $batch_results['skipped']++;
                } else {
                    $batch_results['failed']++;
                }
            }
        }
        $results[] = $batch_results;
    } else {
        // Modo secuencial: procesamos cada fila por separado
        foreach ($tasks as $task) {
            $products = get_products_by_category_id($task['origin_id']);
            if (!empty($products['products'])) {
                $batch_result = batch_replace_product_categories(
                    array_column($products['products'], 'id'),
                    $task['origin_id'],
                    $task['dest_id'],
                    false, // No mostrar progreso
                    $batch_size // Usar tamaño de lote optimizado
                );
                $results[] = [
                    'row' => $task['row'],
                    'success' => true,
                    'message' => sprintf(__("Procesados %d productos (%d éxitos, %d fallos, %d omitidos)", 'delete-categories-woocommerce'), 
                                $batch_result['processed'], 
                                $batch_result['success'], 
                                $batch_result['failed'], 
                                $batch_result['skipped']),
                    'result' => $batch_result
                ];
            } else {
                $results[] = [
                    'row' => $task['row'],
                    'success' => false,
                    'message' => sprintf(__("Línea %d: No se encontraron productos en la categoría origen", 'delete-categories-woocommerce'), $task['row'])
                ];
            }
        }
    }
    return [
        'success' => true, 
        'results' => $results,
        'total_rows' => $total_rows,
        'mode' => $processing_mode
    ];
}

function generate_excel_template() {
    // Verificar seguridad y permisos
    if (!wp_verify_nonce($_GET['nonce'], 'dcw_template_nonce') || !current_user_can('manage_options')) {
        wp_die('Acceso no autorizado');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Encabezados
    $sheet->setCellValue('A1', 'Categoría Origen');
    $sheet->setCellValue('B1', 'Categoría Destino');
    
    // Ejemplos de datos
    $examples = [
        ['https://tutienda.com/product-category/electronica', 'https://tutienda.com/product-category/tecnologia'],
        ['25', '32'],
        ['https://tutienda.com/product-category/ropa', '15']
    ];
    
    $row = 2;
    foreach ($examples as $example) {
        $sheet->setCellValue('A'.$row, $example[0]);
        $sheet->setCellValue('B'.$row, $example[1]);
        $row++;
    }

    // Estilos
    $headerStyle = [
        'font' => ['bold' => true],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF00']]
    ];
    $sheet->getStyle('A1:B1')->applyFromArray($headerStyle);
    
    // Forzar descarga
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="plantilla-categorias.xlsx"');
    
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
} 