# CMDBVilaseca - Prototipo (PHP puro)

Descripción corta: prototipo en PHP puro para importar hojas Excel como tablas en MySQL, con detección de duplicados y subida de imágenes local.

Requisitos:
- PHP 7.4+
- Composer
- Extensiones: pdo_mysql

Instalación rápida:
1. Ejecutar `composer install` en el proyecto.
2. Revisar `config.php` y ajustar si es necesario (host, user, password, database).
3. Ejecutar `php scripts/migrate.php` para crear la base de datos y tablas base.
4. Levantar servidor PHP: `php -S 0.0.0.0:8000 -t public` y abrir `http://localhost:8000` para subir un Excel y probar la importación.

Notas técnicas:
- El importador crea tablas `sheet_{sheet_name}` por cada hoja encontrada.
- Cada tabla incluye un campo `_row_hash` para evitar duplicados, y un `estado_actual` (USADO, ENTREGADO, NO_APARECE, DANADO).
- Los logs de importación quedan en la tabla `import_logs` (ahora incluyen `updated_count` para registrar filas actualizadas al re-importar en modo `update`).
- Puedes configurar columnas únicas por hoja en `sheet_configs` (UI disponible en `sheet_configs.php`) para que el importador detecte duplicados por columnas significativas en lugar de solo por `_row_hash`.

Comprobación rápida (cómo probar):
1. Importa un Excel con una hoja que contenga, por ejemplo, una columna "serie" con valores únicos.
2. Ve a `sheet_configs.php` y marca la columna `serie` como clave única para esa hoja.
3. Vuelve a subir el mismo archivo en modo `add` — las filas duplicadas por `serie` serán omitidas.
4. Sube en modo `update` para que las filas coincidentes por `serie` sean actualizadas con los nuevos valores.

Siguientes pasos realizados / recomendados:
- Autenticación y control de roles implementados (SUPER_ADMIN / ADMIN / USER). Páginas `login.php` y `logout.php` disponibles. Usuarios demo: `superadmin` (ChangeMe123!), `admin` (AdminPass123!), `user` (UserView123!) — cambia contraseñas al desplegar en producción.
- UI: importador ahora requiere rol `ADMIN` o `SUPER_ADMIN`.
- UI para ver listados y filtros, modales de detalle y subida de imágenes por elemento (próximo).
- Opcional: permitir mapeo manual de claves únicas por hoja (en `sheet_configs`).
- Config: `IMAGE_MAX_BYTES` = 32MB (config.php) para reglas de subida de imágenes.
