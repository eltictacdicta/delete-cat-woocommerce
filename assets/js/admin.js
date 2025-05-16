jQuery(document).ready(function($) {
    // Primero declara las funciones
    function dcwBatchProcessing() {
        const originUrl = $('#batch_category_url_origin').val();
        const destinationUrl = $('#batch_category_url_destination').val();
        const batchSize = $('#batch_size').val();
        const resultsDiv = $('#batch-processing-results');
        
        if(!originUrl || !destinationUrl) {
            resultsDiv.html('<div class="notice notice-error"><p>Por favor, ingresa las URLs de ambas categorías.</p></div>');
            return;
        }

        resultsDiv.html('<div class="notice notice-info"><p>Obteniendo información de las categorías...</p></div>');

        $.ajax({
            type: 'POST',
            url: dcwData.ajaxurl,
            dataType: 'json',
            data: {
                action: 'transfer_product_category',
                category_url_origin: originUrl,
                category_url_destination: destinationUrl,
                security: dcwData.nonces.transfer
            },
            success: function(response) {
                if(!response.success) {
                    resultsDiv.html(`<div class="notice notice-error"><p>${response.message}</p></div>`);
                    return;
                }
                
                const originCategoryId = response.debug.origin_category;
                const destinationCategoryId = response.debug.destination_category;
                
                resultsDiv.html(`
                    <div class="notice notice-info">
                        <p>Categoría origen: ID ${originCategoryId}</p>
                        <p>Categoría destino: ID ${destinationCategoryId}</p>
                        <p>Comenzando el procesamiento...</p>
                    </div>
                    <div id="batch-progress-container"></div>
                `);
                
                processBatch(originCategoryId, destinationCategoryId, batchSize);
            },
            error: () => resultsDiv.html('<div class="notice notice-error"><p>Error al obtener información de las categorías.</p></div>')
        });
    }

    const dcwDeleteEmptyCategories = function() {
        if (!confirm(dcwData.confirmDelete)) return;
        
        const resultsDiv = $('#delete-results');
        resultsDiv.html('<div class="notice notice-info"><p>Buscando y eliminando categorías vacías...</p></div>');

        $.ajax({
            type: 'POST',
            url: dcwData.ajaxurl,
            dataType: 'json',
            data: {
                action: 'delete_empty_product_categories',
                security: dcwData.nonces.delete
            },
            success: function(response) {
                if(response.success) {
                    let detailsHtml = '';
                    if (response.deleted_count > 0) {
                        detailsHtml = '<p>Categorías eliminadas:</p><ul>';
                        response.deleted_categories.forEach(cat => {
                            detailsHtml += `<li>ID: ${cat.id}, Nombre: ${cat.name}</li>`;
                        });
                        detailsHtml += '</ul>';
                    }
                    resultsDiv.html(`
                        <div class="notice notice-success">
                            <p>${response.message}</p>
                            ${detailsHtml}
                        </div>
                    `);
                } else {
                    resultsDiv.html(`<div class="notice notice-error"><p>${response.message}</p></div>`);
                }
            },
            error: () => resultsDiv.html('<div class="notice notice-error"><p>Error al procesar la solicitud AJAX.</p></div>')
        });
    };

    const dcwProcessExcel = function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const resultsDiv = $('#excel-results');
        
        resultsDiv.html('<div class="notice notice-info"><p>Procesando archivo Excel...</p></div>');

        $.ajax({
            url: `${dcwData.ajaxurl}?action=dcw_process_excel`,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                if(response.success) {
                    let successHtml = '<div class="notice notice-success"><p>✅ Proceso completado</p><h4>Resultados:</h4>';
                    
                    // Verificar el modo de procesamiento
                    if (response.mode === 'sequential') {
                        response.results.forEach((result, index) => {
                            if (result.row) {
                                // Formato específico para modo secuencial
                                if (result.result && result.result.success) {
                                    successHtml += `<div class="result-item success"><p>Línea ${result.row}: Correcto (${result.result.success} productos procesados)</p></div>`;
                                } else if (result.success === false) {
                                    successHtml += `<div class="notice notice-error"><p>❌ Línea ${result.row}: ${result.message}</p></div>`;
                                } else {
                                    successHtml += `<div class="notice notice-error"><p>❌ Línea ${result.row}: Error desconocido</p></div>`;
                                }
                            } else {
                                // Cuando no hay información de fila específica
                                successHtml += `<div class="notice notice-error"><p>❌ Línea ${index + 1}: Error en el formato de respuesta</p></div>`;
                            }
                        });
                    } else {
                        // Modo batch u otros formatos
                        response.results.forEach((result, index) => {
                            if (typeof result === 'object' && result !== null) {
                                if (result.success) {
                                    successHtml += `<div class="result-item success"><p>Línea ${index + 1}: Correcto</p></div>`;
                                } else if (result.total) {
                                    // Para resultados de batch que tienen contadores
                                    successHtml += `<div class="result-item success"><p>Procesamiento por lotes: ${result.total} productos (${result.success} exitosos, ${result.failed} fallidos, ${result.skipped} omitidos)</p></div>`;
                                } else {
                                    successHtml += `<div class="notice notice-error"><p>❌ Línea ${index + 1}: ${result.message || 'Error no especificado'}</p></div>`;
                                }
                            } else {
                                successHtml += `<div class="notice notice-error"><p>❌ Línea ${index + 1}: Error en el formato de respuesta</p></div>`;
                            }
                        });
                    }
                    
                    resultsDiv.html(`${successHtml}</div>`);
                } else {
                    resultsDiv.html(`<div class="notice notice-error"><p>${response.message}</p></div>`);
                }
            },
            error: (xhr) => {
                const errorMessage = xhr.responseJSON?.message ? 
                    `Error: ${xhr.responseJSON.message}` : 
                    `Error en la conexión (${xhr.status})`;
                resultsDiv.html(`<div class="notice notice-error"><p>${errorMessage}</p></div>`);
            }
        });
    };

    // Luego asigna los event handlers
    $('#start-batch-process').on('click', dcwBatchProcessing);
    $('#delete-empty-categories').on('click', dcwDeleteEmptyCategories);
    $('#excel-import-form').on('submit', dcwProcessExcel);

    // Función auxiliar para procesamiento por lotes
    const processBatch = function(originCategoryId, destinationCategoryId, batchSize) {
        const progressContainer = $('#batch-progress-container');
        const processAll = $('#process_all').is(':checked');

        progressContainer.html(`
            <div class="dcw-progress-container">
                <div class="dcw-progress-bar" id="dcw-progress-bar" style="width: 0%">0%</div>
            </div>
            <div id="dcw-current-operation" class="dcw-current-operation">Iniciando...</div>
            <div id="dcw-status-messages" class="dcw-status-messages"></div>
        `);

        $.ajax({
            type: 'POST',
            url: dcwData.ajaxurl,
            dataType: 'json',
            data: {
                action: 'get_product_ids',
                category_id_origin: originCategoryId,
                security: dcwData.nonces.batch
            },
            success: function(response) {
                if(response.success) {
                    const productIds = response.data.product_ids;
                    
                    if (productIds.length === 0) {
                        $('#dcw-progress-bar').css('width', '100%').text('100%');
                        $('#dcw-current-operation').html('No hay productos para procesar');
                        $('#dcw-status-messages').prepend(
                            `<div class="notice notice-warning">
                                <p>No se encontraron productos en la categoría de origen (ID: ${originCategoryId})</p>
                            </div>`
                        );
                        return;
                    }
                    
                    const totalProducts = processAll ? productIds.length : Math.min(productIds.length, batchSize);
                    
                    let processedCount = 0;
                    let successCount = 0;
                    let failureCount = 0;
                    
                    const processNextProduct = (index) => {
                        if(index >= totalProducts) {
                            $('#dcw-progress-bar').css('width', '100%').text('100%');
                            $('#dcw-current-operation').html('Proceso completado');
                            $('#dcw-status-messages').prepend(
                                `<div class="notice notice-success">
                                    <p>✅ ${totalProducts} productos procesados</p>
                                    <p>${successCount} exitosos - ${failureCount} con errores</p>
                                </div>`
                            );
                            return;
                        }

                        const progress = Math.round(((index + 1) / totalProducts) * 100);
                        $('#dcw-progress-bar').css('width', `${progress}%`).text(`${progress}%`);
                        $('#dcw-current-operation').html(`Procesando producto ${index + 1} de ${totalProducts}`);
                        
                        $.ajax({
                            type: 'POST',
                            url: dcwData.ajaxurl,
                            dataType: 'json',
                            data: {
                                action: 'process_single_product',
                                product_id: productIds[index],
                                category_id_origin: originCategoryId,
                                category_id_destination: destinationCategoryId,
                                security: dcwData.nonces.batch
                            },
                            success: function(response) {
                                processedCount++;
                                
                                if(response.success) {
                                    successCount++;
                                    const messages = response.data.messages?.join('<br>') || 'Sin detalles';
                                    $('#dcw-status-messages').prepend(`
                                        <div class="notice notice-success">
                                            <h4>${response.data.product_title} (ID: ${productIds[index]})</h4>
                                            <div class="product-details">${messages}</div>
                                        </div>
                                    `);
                                } else {
                                    failureCount++;
                                    $('#dcw-status-messages').prepend(`
                                        <div class="notice notice-error">
                                            <h4>Error en producto ID: ${productIds[index]}</h4>
                                            <p>${response.data?.messages?.join('<br>') || response.message || 'Error desconocido'}</p>
                                        </div>
                                    `);
                                }
                                
                                // Procesar el siguiente producto
                                processNextProduct(index + 1);
                            },
                            error: function(xhr) {
                                processedCount++;
                                failureCount++;
                                
                                const errorMessage = xhr.responseJSON?.message || 'Error de conexión';
                                
                                $('#dcw-status-messages').prepend(`
                                    <div class="notice notice-error">
                                        <h4>Error en producto ID: ${productIds[index]}</h4>
                                        <p>${errorMessage}</p>
                                        ${xhr.status ? `<p>Código error: ${xhr.status}</p>` : ''}
                                    </div>
                                `);
                                
                                // Procesar el siguiente producto a pesar del error
                                processNextProduct(index + 1);
                            }
                        });
                    };

                    // Iniciar el procesamiento con el primer producto
                    processNextProduct(0);
                } else {
                    $('#dcw-progress-bar').css('width', '100%').text('100%');
                    $('#dcw-current-operation').html('Error al obtener productos');
                    $('#dcw-status-messages').prepend(
                        `<div class="notice notice-error">
                            <p>${response.message || 'No se pudieron obtener los productos para procesar'}</p>
                        </div>`
                    );
                }
            },
            error: function(xhr) {
                const errorDetails = xhr.responseJSON?.data || 'Error desconocido';
                const errorMessage = `Error ${xhr.status}: ${errorDetails}`;
                
                $('#batch-progress-container').html(`
                    <div class="notice notice-error">
                        <h4>Error al obtener productos</h4>
                        <p>${errorMessage}</p>
                        <p>Acciones:</p>
                        <ul>
                            <li>Verificar que las categorías existen</li>
                            <li>Recargar la página para renovar nonces</li>
                            <li>Revisar consola para más detalles</li>
                        </ul>
                    </div>
                `);
                
                console.error('Error en get_product_ids:', {
                    status: xhr.status,
                    response: xhr.responseJSON,
                    requestData: {
                        originCategoryId,
                        destinationCategoryId,
                        batchSize
                    }
                });
            }
        });
    };
}); 