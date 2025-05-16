# Delete Categories for WooCommerce

Plugin de WordPress para gestionar categorías de productos en WooCommerce, facilitando la transferencia de productos entre categorías y la eliminación de categorías vacías.

## Características

- **Transferencia de productos entre categorías**: Mueve productos de una categoría a otra, manteniendo los datos del producto intactos.
- **Procesamiento por lotes**: Procesa grandes cantidades de productos de forma eficiente.
- **Eliminación de categorías vacías**: Identifica y elimina categorías que no contengan productos.
- **Importación desde Excel**: Permite realizar cambios masivos mediante archivos Excel.

## Requisitos

- WordPress 5.0 o superior
- WooCommerce 3.0.0 o superior (probado hasta WooCommerce 8.7.0)
- PHP 7.4 o superior

## Instalación

1. Descarga el archivo ZIP del plugin
2. Ve a tu panel de administración de WordPress > Plugins > Añadir nuevo
3. Haz clic en "Subir plugin" y selecciona el archivo ZIP descargado
4. Activa el plugin a través del menú 'Plugins' en WordPress
5. Accede a las funcionalidades desde el menú "Cambiar Categoría" en el panel de administración

## Uso

### Cambiar categoría de productos por lotes

1. Navega a "Cambiar Categoría" en el menú de administración
2. Ingresa la URL completa de la categoría de origen (donde están los productos actualmente)
3. Ingresa la URL completa de la categoría de destino (donde quieres mover los productos)
4. Configura el número de productos a procesar o selecciona "Procesar todos"
5. Haz clic en "Procesar productos en lote"

### Eliminar categorías vacías

1. Navega a "Cambiar Categoría" en el menú de administración
2. Desplázate hasta la sección "Eliminar Categorías Vacías"
3. Haz clic en el botón "Eliminar Categorías Vacías"
4. Confirma la acción

### Importación desde Excel

1. Navega a "Cambiar Categoría" en el menú de administración
2. Desplázate hasta la sección "Importar desde Excel"
3. Descarga la plantilla Excel proporcionada
4. Completa la plantilla con las categorías de origen y destino
5. Sube el archivo Excel completado
6. Selecciona el modo de procesamiento (Secuencial o Por lotes)
7. Haz clic en "Procesar Excel"

## Modos de procesamiento

- **Secuencial**: Procesa cada fila del Excel de forma individual, garantizando mayor precisión.
- **Por lotes**: Procesa todas las categorías de una vez, optimizando el rendimiento para grandes conjuntos de datos.

## Soporte

Para soporte y consultas, contacte al desarrollador en: [ejemplo.com](https://ejemplo.com)

## Licencia

Este plugin está licenciado bajo GPL-2.0+. Consulta el archivo LICENSE para más detalles.

## Notas técnicas

- El plugin utiliza transacciones de base de datos para garantizar la integridad de los datos durante el procesamiento por lotes.
- Implementa limpieza de caché para evitar problemas de rendimiento después de procesar grandes cantidades de productos.
- Todas las operaciones críticas están protegidas con nonces y verificaciones de permisos de usuario.
- Los mensajes de texto están completamente internacionalizados y son traducibles. 