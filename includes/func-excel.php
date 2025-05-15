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

/**
 * Procesa un archivo Excel con pares de categorías
 */
function process_category_excel($file_path) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    
    // Cargar librería PHPExcel/PhpSpreadsheet
    if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        return ['success' => false, 'message' => 'Librería PhpSpreadsheet no instalada'];
    }

    $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
    $worksheet = $spreadsheet->getActiveSheet();
    $results = [];

    foreach ($worksheet->getRowIterator(2) as $row) { // Saltar encabezados
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(true);
        
        $origin = $cellIterator->current()->getValue();
        $cellIterator->next();
        $destination = $cellIterator->current()->getValue();

        // Obtener IDs de categorías
        $origin_id = is_numeric($origin) ? $origin : get_category_id_from_url($origin);
        $dest_id = is_numeric($destination) ? $destination : get_category_id_from_url($destination);

        if (!$origin_id || !$dest_id) {
            $results[] = [
                'success' => false,
                'message' => "Línea {$row->getRowIndex()}: Categorías inválidas"
            ];
            continue;
        }

        // Procesar transferencia
        $products = get_products_by_category_id($origin_id);
        if (!empty($products['products'])) {
            $batch_result = batch_replace_product_categories(
                array_column($products['products'], 'id'),
                $origin_id,
                $dest_id,
                false // No mostrar progreso
            );
            $results[] = $batch_result;
        }
    }

    return ['success' => true, 'results' => $results];
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