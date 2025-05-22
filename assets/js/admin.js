jQuery(document).ready(function($) {
    // Primero declara las funciones
    function dcwBatchProcessing() {
        const originUrl = $('#batch_category_url_origin').val();
        const destinationUrl = $('#batch_category_url_destination').val();
        const batchSize = $('#batch_size').val();
        const resultsDiv = $('#batch-processing-results');
        const changeSubcategories = $('#change_subcategories').is(':checked');
        
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
                change_subcategories: changeSubcategories,
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
                
                processBatch(originCategoryId, destinationCategoryId, batchSize, changeSubcategories);
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

    // Nueva función para eliminar productos de una categoría
    const dcwDeleteCategoryProducts = function() {
        const categoryUrl = $('#delete_category_url').val();
        const resultsDiv = $('#delete-category-results');
        
        if(!categoryUrl) {
            resultsDiv.html('<div class="notice notice-error"><p>Por favor, ingresa la URL de la categoría.</p></div>');
            return;
        }

        // Mostrar confirmación
        if(!confirm('¿Estás seguro de que deseas eliminar los productos de esta categoría? Esta acción no se puede deshacer.')) {
            return;
        }

        resultsDiv.html('<div class="notice notice-info"><p>Procesando solicitud...</p></div>');

        $.ajax({
            type: 'POST',
            url: dcwData.ajaxurl,
            dataType: 'json',
            data: {
                action: 'delete_category_products',
                category_url: categoryUrl,
                security: dcwData.nonces.delete_products
            },
            success: function(response) {
                if(response.success) {
                    let statsHtml = '';
                    if(response.data.stats) {
                        statsHtml = `
                            <h4>Estadísticas:</h4>
                            <ul>
                                <li>Total de productos procesados: ${response.data.stats.total}</li>
                                <li>Productos eliminados completamente: ${response.data.stats.deleted}</li>
                                <li>Productos desvinculados de la categoría: ${response.data.stats.unlinked}</li>
                                <li>Productos con categoría principal cambiada: ${response.data.stats.changed_primary}</li>
                                <li>Errores: ${response.data.stats.errors}</li>
                            </ul>
                        `;
                    }
                    
                    let logHtml = '';
                    if(response.data.log && response.data.log.length > 0) {
                        logHtml = '<h4>Registro de operaciones:</h4><div class="dcw-log-container">';
                        response.data.log.forEach(logEntry => {
                            logHtml += `<div class="log-entry">${logEntry}</div>`;
                        });
                        logHtml += '</div>';
                    }
                    
                    resultsDiv.html(`
                        <div class="notice notice-success">
                            <p>${response.data.message}</p>
                            ${statsHtml}
                            ${logHtml}
                        </div>
                    `);
                } else {
                    resultsDiv.html(`<div class="notice notice-error"><p>${response.data.message || 'Error al procesar la solicitud'}</p></div>`);
                }
            },
            error: () => resultsDiv.html('<div class="notice notice-error"><p>Error al procesar la solicitud AJAX.</p></div>')
        });
    };

    // Nueva función para eliminar productos por SKU
    const dcwDeleteProductsBySku = function() {
        const skusString = $('#skus_to_delete').val();
        const resultsDiv = $('#delete-sku-results');
        const deleteImages = $('#delete_sku_images').is(':checked');
        
        if(!skusString.trim()) {
            resultsDiv.html('<div class="notice notice-error"><p>Por favor, ingresa una lista de SKUs.</p></div>');
            return;
        }

        // Mostrar confirmación
        if(!confirm('¿Estás seguro de que deseas eliminar estos productos por SKU? Esta acción no se puede deshacer.')) {
            return;
        }

        if (deleteImages && !confirm(dcwData.confirmDeleteSkuImages)) {
            return;
        }

        resultsDiv.html('<div class="notice notice-info"><p>Procesando eliminación por SKU...</p></div>');

        $.ajax({
            type: 'POST',
            url: dcwData.ajaxurl,
            dataType: 'json',
            data: {
                action: 'delete_products_by_sku',
                skus: skusString,
                delete_images: deleteImages,
                security: dcwData.nonces.delete_by_sku
            },
            success: function(response) {
                if(response.success) {
                    let statsHtml = '';
                    if(response.data) {
                        statsHtml = `
                            <h4>Resultados:</h4>
                            <ul>
                                <li>Total de SKUs en la lista: ${response.data.total_skus}</li>
                                <li>Productos eliminados exitosamente: ${response.data.deleted_count}</li>
                            </ul>
                        `;

                        if (response.data.not_found_skus && response.data.not_found_skus.length > 0) {
                            statsHtml += `<p>SKUs no encontrados: ${response.data.not_found_skus.join(', ')}</p>`;
                        }

                        if (response.data.failed_to_delete_skus && response.data.failed_to_delete_skus.length > 0) {
                             statsHtml += `<p>SKUs con errores al eliminar: ${response.data.failed_to_delete_skus.join(', ')}</p>`;
                        }
                    }
                    
                    let logHtml = '';
                    if(response.data.log && response.data.log.length > 0) {
                        logHtml = '<h4>Registro de operaciones:</h4><div class="dcw-log-container">';
                        response.data.log.forEach(logEntry => {
                            logHtml += `<div class="log-entry">${logEntry}</div>`;
                        });
                        logHtml += '</div>';
                    }

                    resultsDiv.html(`
                        <div class="notice notice-success">
                            <p>✅ Eliminación por SKU completada.</p>
                            ${statsHtml}
                            ${logHtml}
                        </div>
                    `);
                } else {
                    resultsDiv.html(`<div class="notice notice-error"><p>❌ ${response.data.message || 'Error al procesar la solicitud'}</p></div>`);
                }
            },
            error: (xhr) => {
                const errorMessage = xhr.responseJSON?.data?.message ? 
                    `Error: ${xhr.responseJSON.data.message}` : 
                    `Error en la conexión (${xhr.status})`;
                resultsDiv.html(`<div class="notice notice-error"><p>${errorMessage}</p></div>`);
            }
        });
    };

    // Luego asigna los event handlers
    $('#start-batch-process').on('click', dcwBatchProcessing);
    $('#delete-empty-categories').on('click', dcwDeleteEmptyCategories);
    $('#excel-import-form').on('submit', dcwProcessExcel);
    $('#delete-category-products-btn').on('click', dcwDeleteCategoryProducts);
    $('#delete-products-by-sku-btn').on('click', dcwDeleteProductsBySku);

    // Función auxiliar para procesamiento por lotes
    const processBatch = function(originCategoryId, destinationCategoryId, batchSize, changeSubcategories) {
        const progressContainer = $('#batch-progress-container');
        const processAll = $('#process_all').is(':checked');

        progressContainer.html(`
            <div class="dcw-progress-container">
                <div class="dcw-progress-bar" id="dcw-progress-bar" style="width: 0%">0%</div>
            </div>
            <div id="dcw-current-operation" class="dcw-current-operation">Iniciando...</div>
            <div id="dcw-status-messages" class="dcw-status-messages"></div>
        `);

        if (changeSubcategories) {
            $('#dcw-current-operation').text('Procesando estructura de subcategorías...');
            $.ajax({
                type: 'POST',
                url: dcwData.ajaxurl,
                dataType: 'json',
                data: {
                    action: 'process_subcategory_tree',
                    category_id_origin: originCategoryId,
                    category_id_destination: destinationCategoryId,
                    security: dcwData.nonces.batch
                },
                success: function(response) {
                    $('#dcw-progress-bar').css('width', '100%').text('100%');
                    if (response.success && response.data && response.data.log) {
                        $('#dcw-current-operation').text('Proceso de subcategorías completado.');
                        response.data.log.forEach(function(log_message) {
                            $('#dcw-status-messages').append('<p><small>' + log_message + '</small></p>');
                        });
                        let summary = `Resumen: ${response.data.success_count || 0} productos movidos, ${response.data.parent_changed_count || 0} subcategorías re-parentadas, ${response.data.failed_count || 0} errores.`;
                        $('#dcw-status-messages').append('<p><strong>' + summary + '</strong></p>');
                    } else {
                        $('#dcw-current-operation').text('Error procesando subcategorías.');
                        $('#dcw-status-messages').append('<p class="dcw-error-message">'+ (response.data.message || 'Error desconocido.') + '</p>');
                    }
                },
                error: function(xhr) {
                    $('#dcw-progress-bar').css('width', '100%').text('Error');
                    $('#dcw-current-operation').text('Error en la solicitud AJAX para subcategorías.');
                    $('#dcw-status-messages').append('<p class="dcw-error-message">'+ (xhr.responseJSON?.data?.message || xhr.statusText || 'Error de conexión') + '</p>');
                }
            });
        } else {
            // Original product-by-product processing (if not changing subcategories)
            $.ajax({
                type: 'POST',
                url: dcwData.ajaxurl,
                dataType: 'json',
                data: {
                    action: 'get_product_ids',
                    category_id_origin: originCategoryId,
                    change_subcategories: false,
                    security: dcwData.nonces.batch
                },
                success: function(response) {
                    if(response.success) {
                        const productIds = response.data.product_ids;
                        if (productIds.length === 0) {
                            $('#dcw-current-operation').text('No hay productos para procesar en la categoría origen.');
                            $('#dcw-progress-bar').css('width', '100%').text('N/A');
                            return;
                        }

                        let currentBatch = [];
                        let processedInLoop = 0;
                        const productsToProcess = processAll ? productIds.length : Math.min(productIds.length, parseInt(batchSize, 10));
                        
                        // If not processAll, slice the productIds to the batchSize
                        const idsForLoop = processAll ? productIds : productIds.slice(0, productsToProcess);

                        let processedCount = 0;
                        let successCount = 0;
                        let failedCount = 0;
                        let skippedCount = 0; // This might not be directly applicable with current batch_replace_product_categories
                        const totalProductsToProcessInLoop = idsForLoop.length;

                        const processNextProductInLoop = (index) => {
                            if (index >= totalProductsToProcessInLoop) {
                                $('#dcw-current-operation').text('Proceso completado.');
                                $('#dcw-status-messages').append('<p><strong>Resumen final:</strong> ' + successCount + ' exitosos, ' + failedCount + ' fallidos de ' + totalProductsToProcessInLoop + ' productos seleccionados.</p>');
                                return;
                            }

                            const productId = idsForLoop[index];
                            $('#dcw-current-operation').text('Procesando producto ID: ' + productId + ' (' + (index + 1) + '/' + totalProductsToProcessInLoop + ')');

                            $.ajax({
                                type: 'POST',
                                url: dcwData.ajaxurl,
                                dataType: 'json',
                                data: {
                                    action: 'batch_process_categories',
                                    product_ids: [productId],
                                    category_id_origin: originCategoryId,
                                    category_id_destination: destinationCategoryId,
                                    security: dcwData.nonces.batch
                                },
                                success: function(batchResponse) {
                                    processedCount++;
                                    if(batchResponse.success && batchResponse.results) {
                                        successCount += batchResponse.results.success || 0;
                                        failedCount += batchResponse.results.failed || 0;
                                        skippedCount += batchResponse.results.skipped || 0;
                                        if(batchResponse.results.log && batchResponse.results.log.length > 0){
                                            batchResponse.results.log.forEach(function(log_message){
                                                $('#dcw-status-messages').append('<p><small>' + log_message + '</small></p>');
                                            });
                                        }
                                    } else if (batchResponse.success) {
                                        successCount++;
                                        $('#dcw-status-messages').append('<p>Producto ID ' + productId + ': ' + batchResponse.message + '</p>');
                                    } else {
                                        failedCount++;
                                        $('#dcw-status-messages').append('<p class="dcw-error-message">Producto ID ' + productId + ': Error - ' + (batchResponse.message || 'Error desconocido') + '</p>');
                                    }
                                    const percentage = Math.round((processedCount / totalProductsToProcessInLoop) * 100);
                                    $('#dcw-progress-bar').css('width', percentage + '%').text(percentage + '%');
                                    setTimeout(() => processNextProductInLoop(index + 1), 100);
                                },
                                error: function(xhr, status, error) {
                                    processedCount++;
                                    failedCount++;
                                    $('#dcw-status-messages').append('<p class="dcw-error-message">Producto ID ' + productId + ': Falló la solicitud AJAX. ('+error+')</p>');
                                    const percentage = Math.round((processedCount / totalProductsToProcessInLoop) * 100);
                                    $('#dcw-progress-bar').css('width', percentage + '%').text(percentage + '%');
                                    setTimeout(() => processNextProductInLoop(index + 1), 100);
                                }
                            });
                        };
                        processNextProductInLoop(0);
                    } else {
                        $('#dcw-current-operation').text('Error obteniendo IDs de productos.');
                        $('#dcw-status-messages').append('<p class="dcw-error-message">'+ (response.data.message || 'Error desconocido') + '</p>');
                    }
                },
                error: function(xhr) {
                     $('#dcw-current-operation').text('Error en la solicitud AJAX para obtener IDs de productos.');
                     $('#dcw-status-messages').append('<p class="dcw-error-message">'+ (xhr.responseJSON?.data?.message || xhr.statusText || 'Error de conexión') + '</p>');
                }
            });
        }
    };

    // Previsualizar cambios de subcategorías
    $('#preview-subcategory-changes').on('click', function() {
        const originUrl = $('#batch_category_url_origin').val();
        const destinationUrl = $('#batch_category_url_destination').val();
        const changeSubcategories = $('#change_subcategories').is(':checked');
        const resultsDiv = $('#preview-subcategory-results');
        resultsDiv.html('<p>Cargando previsualización...</p>');

        if (!changeSubcategories) {
            resultsDiv.html('<p>La opción "Cambiar Subcategorías" debe estar marcada para la previsualización.</p>');
            return;
        }
        if (!originUrl || !destinationUrl) {
            resultsDiv.html('<p>Por favor, ingresa las URLs de las categorías de origen y destino.</p>');
            return;
        }

        // Primero, obtener los IDs de las categorías desde las URLs
        $.ajax({
            url: dcwData.ajaxurl,
            type: 'POST',
            data: {
                action: 'transfer_product_category', // Acción AJAX para obtener IDs
                category_url_origin: originUrl,
                category_url_destination: destinationUrl,
                security: dcwData.nonces.transfer
            },
            success: function(response) {
                if (response.success && response.debug) {
                    const originId = response.debug.origin_category;
                    const destinationId = response.debug.destination_category;

                    // Ahora llamar a la previsualización con los IDs
                    $.ajax({
                        url: dcwData.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'preview_subcategory_reorganization',
                            category_id_origin: originId,
                            category_id_destination: destinationId,
                            security: dcwData.nonces.preview_subcategories // Usar el nuevo nonce
                        },
                        success: function(previewResponse) {
                            if (previewResponse.success) {
                                displayPreviewResults(previewResponse.data, resultsDiv);
                            } else {
                                resultsDiv.html('<p>Error al previsualizar: ' + (previewResponse.data.message || 'Error desconocido') + '</p>');
                            }
                        },
                        error: function(xhr) {
                            resultsDiv.html('<p>Error de AJAX al previsualizar: ' + xhr.statusText + '</p>');
                        }
                    });
                } else {
                    resultsDiv.html('<p>Error al obtener IDs de categoría: ' + (response.message || 'Error desconocido') + '</p>');
                }
            },
            error: function(xhr) {
                resultsDiv.html('<p>Error de AJAX al obtener IDs de categoría: ' + xhr.statusText + '</p>');
            }
        });
    });

    function displayPreviewResults(data, container) {
        let html = '<h4>Resultados de la Previsualización:</h4>';
        if (data.log && data.log.length > 0) {
            html += '<h5>Log:</h5><ul>';
            data.log.forEach(function(entry) {
                html += '<li>' + entry + '</li>';
            });
            html += '</ul>';
        }

        if (data.actions && data.actions.length > 0) {
            html += '<h5>Acciones de Reorganización Propuestas:</h5>';
            html += formatPreviewActions(data.actions, 0);
        } else {
            html += '<p>No se proponen acciones de reorganización o no hay subcategorías para procesar.</p>';
        }
        container.html(html);
    }

    function formatPreviewActions(actions, level) {
        let listHtml = '<ul style="list-style-type: none; padding-left: ' + (level * 20) + 'px;">';
        actions.forEach(function(action) {
            listHtml += '<li style="margin-bottom: 10px; padding: 5px; border-left: 3px solid #0073aa;">';
            listHtml += '<strong>Subcategoría Origen:</strong> ' + action.origin_name + ' (ID: ' + action.origin_id + ')';
            if (typeof action.product_count !== 'undefined') {
                listHtml += ' - <em>' + action.product_count + (action.product_count === 1 ? ' producto directo' : ' productos directos') + '</em>';
            }
            listHtml += '<br>';
            let actionText = '';
            switch(action.action) {
                case 'transferir_productos_a_existente_en_destino':
                    actionText = '<strong>Acción:</strong> <span style="color: purple;">TRANSFERIR PRODUCTOS</span> a la categoría existente \'' + action.target_name + '\' (ID: ' + action.target_id + ') bajo \'' + action.target_parent_name + '\'. La categoría origen \'' + action.origin_name + '\' no se mueve.';
                    break;
                case 'mover_categoria_con_hijos':
                    actionText = '<strong>Acción:</strong> <span style="color: orange;">MOVER CATEGORÍA Y SU ESTRUCTURA</span> a ser hija de \'' + action.target_parent_name + '\'.';
                    break;
                // Mantener casos anteriores por si acaso o para lógica mixta futura
                case 'fusionar': // Este caso podría ser obsoleto o necesitar re-evaluación con la nueva lógica
                    actionText = '<strong>Acción:</strong> <span style="color: blue;">FUSIONAR (Lógica Antigua)</span> con ' + action.target_name + ' (ID: ' + action.target_id + ') bajo ' + action.target_parent_name + '.';
                    break;
                case 'mover_y_renombrar_si_es_necesario': // Este caso podría ser obsoleto o necesitar re-evaluación
                    actionText = '<strong>Acción:</strong> <span style="color: green;">MOVER (Lógica Antigua)</span> a ser hija de ' + action.target_parent_name + '.';
                    break;
                default:
                    actionText = '<strong>Acción:</strong> Desconocida (' + action.action + ')';
            }
            listHtml += actionText + '<br>';
            if (action.details) {
                listHtml += '<em>Detalles:</em> ' + action.details + '<br>';
            }

            if (action.children_preview && action.children_preview.length > 0) {
                if (action.action === 'transferir_productos_a_existente_en_destino') {
                    // Para esta acción, children_preview contiene más *acciones* para los hijos de origin_name
                    // contra el target_name como nuevo destino.
                    listHtml += '<strong>Sub-proceso para hijos de \'' + action.origin_name + '\' (evaluados contra \'' + action.target_name + '\'):</strong>';
                    listHtml += formatPreviewActions(action.children_preview, level + 1); // Recursión con la misma función
                } else if (action.action === 'mover_categoria_con_hijos') {
                    // Para esta acción, children_preview es una lista de la estructura de hijos que se mueven con el padre.
                    listHtml += '<strong>Estructura de subcategorías que se mueven con \'' + action.origin_name + '\':</strong>';
                    listHtml += formatSimpleChildrenList(action.children_preview, level + 1);
                } else {
                    // Fallback para lógica antigua si aún es posible
                    listHtml += '<strong>Hijos de \'' + action.origin_name + '\':</strong>';
                    listHtml += formatPreviewActions(action.children_preview, level + 1);
                }
            }
            listHtml += '</li>';
        });
        listHtml += '</ul>';
        return listHtml;
    }

    // Nueva función para formatear una lista simple de hijos (cuando se mueven como bloque)
    function formatSimpleChildrenList(children, level) {
        let listHtml = '<ul style="list-style-type: circle; padding-left: ' + (level * 20) + 'px;">';
        children.forEach(function(child) {
            listHtml += '<li>' + child.name + ' (ID: ' + child.id + ')';
            if (typeof child.product_count !== 'undefined') {
                listHtml += ' - <em>' + child.product_count + (child.product_count === 1 ? ' producto directo' : ' productos directos') + '</em>';
            }
            if (child.children_preview && child.children_preview.length > 0) {
                listHtml += formatSimpleChildrenList(child.children_preview, level + 1);
            }
            listHtml += '</li>';
        });
        listHtml += '</ul>';
        return listHtml;
    }
}); 