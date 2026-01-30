# Documentación: Sincronización Maestra de Categorías WooCommerce

Esta documentación detalla el funcionamiento del comando Artisan `woocommerce:sync-categories`, diseñado para sincronizar de manera eficiente y jerárquica las categorías desde Laravel hacia WooCommerce.

## 🚀 Cómo ejecutar
Para iniciar la sincronización manual, ejecute el siguiente comando desde la raíz del proyecto:

```bash
php artisan woocommerce:sync-categories
```

## 🏗️ Arquitectura de Jerarquía
El sistema organiza más de 200 categorías en **5 Grupos Raíz** principales, garantizando que la tienda tenga una navegación estructurada y limpia:

1.  **Por Catálogo (group-1-by-catalog)**: Incluye jerarquías de etiquetas (TagCategory → TagSubcategory → Tag) y Tipos de Catálogo (A6).
2.  **Por Característica (group-2-by-characteristic)**: Incluye jerarquía del Tipo de Catálogo A9.
3.  **Otros (group-3-others)**: Incluye jerarquías de Tipos de Catálogo 20 y 18.
4.  **Lanzamientos (group-4-releases)**: Reservado para futuras implementaciones.
5.  **Marcas (group-5-brands)**: Sincroniza todos los Laboratorios, marcando los nuevos con un flag en la descripción.

## 🆔 Estrategia de Slugs (ID-Based)
Para garantizar la inmunidad ante cambios de nombres y evitar problemas de enlaces rotos, todos los slugs siguen un patrón basado en la Clave Primaria (PK) del modelo en Laravel:

- **Categorías**: `cat-{id}`
- **Subcategorías**: `subcat-{id}`
- **Etiquetas**: `tag-{id}`
- **Tipos de Catálogo**: `type-{id}`
- **Categorías de Catálogo**: `typecat-{id}`
- **Subcategorías de Catálogo**: `typesub-{id}`
- **Laboratorios (Marcas)**: `lab-{id}`

## ⚡ Optimización de Rendimiento
El comando ha sido optimizado para completar la sincronización en segundos (aprox. **3-5 segundos** para ~230 categorías):

- **Batch API**: Utiliza el endpoint de lote (`batch`) de WooCommerce para enviar múltiples creaciones y actualizaciones en una sola petición por nivel de jerarquía.
- **In-Memory Cache**: Se realiza una única carga inicial de todas las categorías de WooCommerce al comienzo del proceso, evitando cientos de llamadas API individuales de consulta.
- **Detección de Cambios Inteligente**: El sistema compara nombres, padres, orden y slugs. Además, normaliza los espacios en blanco (trim y colapso de espacios dobles) para evitar actualizaciones redundantes por diferencias mínimas de formato entre sistemas.

## 🧹 Limpieza de Huérfanos e Intrusos
El sistema mantiene la integridad de la base de datos de WooCommerce mediante dos mecanismos:

1.  **Eliminación de Huérfanos**: Categorías que tienen nuestros prefijos de slug pero ya no existen en Laravel.
2.  **Detección de Intrusos**: Cualquier categoría creada manualmente dentro de WooCommerce que se cuelgue de nuestros 5 grupos maestros será detectada y eliminada automáticamente para mantener la jerarquía programada.

## 🛠️ Detalles Técnicos
- **Comando**: `App\Console\Commands\SyncWooCommerceCategories`
- **Servicio**: `App\Services\WooCommerceService`
- **Modelos Involucrados**: 
    - `TagCategory`, `TagSubcategory`, `Tag`
    - `CatalogType`, `CatalogCategory`, `CatalogSubcategory`
    - `Laboratory`
- **Mapeo de Campos**: 
    - `Orden` (Laravel) → `menu_order` (WooCommerce)
    - `FlgNuevo` (Laboratorios) → Setea "1" en la `description` de WooCommerce.

---
*Desarrollado para la optimización de sincronización ERP-WooCommerce.*
