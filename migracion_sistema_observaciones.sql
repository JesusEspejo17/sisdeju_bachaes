-- ============================================================================
-- SCRIPT DE MIGRACIÓN: Sistema de Observaciones para Depósitos Judiciales
-- Sistema: SISDEJU
-- Fecha: 2025-11-12
-- ============================================================================
--
-- OBJETIVO:
-- Implementar un sistema de observaciones que permita al Secretario marcar
-- depósitos como "OBSERVADO" y al MAU atenderlos marcándolos como 
-- "OBSERVACIÓN ATENDIDA", con trazabilidad completa y colores distintivos.
--
-- CAMBIOS PRINCIPALES:
-- 1. Crear dos nuevos estados: OBSERVADO y OBSERVACIÓN ATENDIDA
-- 2. Agregar columna estado_observacion a deposito_judicial
-- 3. Agregar columna motivo_observacion a deposito_judicial
-- 4. Crear índices para optimizar consultas
--
-- IMPORTANTE:
-- - Hacer BACKUP de la base de datos antes de ejecutar
-- - Este script es para MySQL/MariaDB
-- - Los IDs de estados pueden variar según tu BD
-- ============================================================================

-- NO usar transacciones en phpMyAdmin (auto-commit activado)
-- Si usas línea de comandos MySQL, descomenta las siguientes líneas:
-- START TRANSACTION;

-- ============================================================================
-- PASO 1: CREAR NUEVOS ESTADOS
-- ============================================================================
-- Verificar primero qué IDs de estados ya existen
-- Ejecuta esto primero para ver los estados actuales:

SELECT id_estado, nombre_estado FROM estado ORDER BY id_estado;

-- Basándote en los resultados, ajusta los IDs de los nuevos estados
-- Por defecto, asumiendo que existen hasta el ID 10:

INSERT INTO `estado` (`id_estado`, `nombre_estado`) VALUES 
(11, 'OBSERVADO'),
(12, 'OBSERVACIÓN ATENDIDA')
ON DUPLICATE KEY UPDATE nombre_estado = VALUES(nombre_estado);

-- Verificar que se crearon correctamente
SELECT * FROM estado WHERE id_estado IN (11, 12);

-- ============================================================================
-- PASO 2: AGREGAR COLUMNA estado_observacion A deposito_judicial
-- ============================================================================
-- Esta columna almacenará el estado de observación (NULL, 11, o 12)

SET @column_exists_estado_obs = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'deposito_judicial' 
      AND COLUMN_NAME = 'estado_observacion'
);

SET @sql_estado_obs = IF(@column_exists_estado_obs = 0,
    'ALTER TABLE `deposito_judicial` 
     ADD COLUMN `estado_observacion` INT(11) NULL DEFAULT NULL COMMENT ''NULL=Sin observar, 11=OBSERVADO, 12=ATENDIDA'' 
     AFTER `id_estado`',
    'SELECT "La columna estado_observacion ya existe en deposito_judicial" AS info'
);

PREPARE stmt_estado_obs FROM @sql_estado_obs;
EXECUTE stmt_estado_obs;
DEALLOCATE PREPARE stmt_estado_obs;

-- ============================================================================
-- PASO 3: AGREGAR COLUMNA motivo_observacion A deposito_judicial
-- ============================================================================
-- Esta columna almacenará el motivo/descripción de la observación

SET @column_exists_motivo = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'deposito_judicial' 
      AND COLUMN_NAME = 'motivo_observacion'
);

SET @sql_motivo = IF(@column_exists_motivo = 0,
    'ALTER TABLE `deposito_judicial` 
     ADD COLUMN `motivo_observacion` TEXT NULL DEFAULT NULL COMMENT ''Motivo de la observación del Secretario'' 
     AFTER `estado_observacion`',
    'SELECT "La columna motivo_observacion ya existe en deposito_judicial" AS info'
);

PREPARE stmt_motivo FROM @sql_motivo;
EXECUTE stmt_motivo;
DEALLOCATE PREPARE stmt_motivo;

-- ============================================================================
-- PASO 4: AGREGAR COLUMNA fecha_observacion A deposito_judicial
-- ============================================================================
-- Registrar cuándo se observó el depósito

SET @column_exists_fecha_obs = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'deposito_judicial' 
      AND COLUMN_NAME = 'fecha_observacion'
);

SET @sql_fecha_obs = IF(@column_exists_fecha_obs = 0,
    'ALTER TABLE `deposito_judicial` 
     ADD COLUMN `fecha_observacion` DATETIME NULL DEFAULT NULL COMMENT ''Fecha/hora en que se marcó como OBSERVADO'' 
     AFTER `motivo_observacion`',
    'SELECT "La columna fecha_observacion ya existe en deposito_judicial" AS info'
);

PREPARE stmt_fecha_obs FROM @sql_fecha_obs;
EXECUTE stmt_fecha_obs;
DEALLOCATE PREPARE stmt_fecha_obs;

-- ============================================================================
-- PASO 5: AGREGAR COLUMNA fecha_atencion_observacion A deposito_judicial
-- ============================================================================
-- Registrar cuándo se atendió la observación

SET @column_exists_fecha_atencion = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'deposito_judicial' 
      AND COLUMN_NAME = 'fecha_atencion_observacion'
);

SET @sql_fecha_atencion = IF(@column_exists_fecha_atencion = 0,
    'ALTER TABLE `deposito_judicial` 
     ADD COLUMN `fecha_atencion_observacion` DATETIME NULL DEFAULT NULL COMMENT ''Fecha/hora en que se marcó como ATENDIDA'' 
     AFTER `fecha_observacion`',
    'SELECT "La columna fecha_atencion_observacion ya existe en deposito_judicial" AS info'
);

PREPARE stmt_fecha_atencion FROM @sql_fecha_atencion;
EXECUTE stmt_fecha_atencion;
DEALLOCATE PREPARE stmt_fecha_atencion;

-- ============================================================================
-- PASO 6: CREAR ÍNDICES PARA OPTIMIZAR CONSULTAS
-- ============================================================================

SET @index_exists_estado_obs = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'deposito_judicial' 
      AND INDEX_NAME = 'idx_estado_observacion'
);

SET @sql_index_estado_obs = IF(@index_exists_estado_obs = 0,
    'ALTER TABLE `deposito_judicial` 
     ADD INDEX `idx_estado_observacion` (`estado_observacion`)',
    'SELECT "El índice idx_estado_observacion ya existe" AS info'
);

PREPARE stmt_index_estado_obs FROM @sql_index_estado_obs;
EXECUTE stmt_index_estado_obs;
DEALLOCATE PREPARE stmt_index_estado_obs;

-- ============================================================================
-- PASO 7: AGREGAR FOREIGN KEY (OPCIONAL - solo si tu BD ya usa FKs)
-- ============================================================================
-- Descomenta esto solo si tu BD ya tiene foreign keys configuradas:

/*
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'deposito_judicial' 
      AND CONSTRAINT_NAME = 'fk_deposito_estado_observacion'
);

SET @sql_fk = IF(@fk_exists = 0,
    'ALTER TABLE `deposito_judicial` 
     ADD CONSTRAINT `fk_deposito_estado_observacion` 
     FOREIGN KEY (`estado_observacion`) 
     REFERENCES `estado`(`id_estado`) 
     ON DELETE SET NULL 
     ON UPDATE CASCADE',
    'SELECT "La foreign key fk_deposito_estado_observacion ya existe" AS info'
);

PREPARE stmt_fk FROM @sql_fk;
EXECUTE stmt_fk;
DEALLOCATE PREPARE stmt_fk;
*/

-- ============================================================================
-- VALIDACIONES FINALES
-- ============================================================================

-- 1. Verificar que los estados se crearon correctamente
SELECT 'Estados de observación creados:' AS verificacion;
SELECT id_estado, nombre_estado 
FROM estado 
WHERE id_estado IN (11, 12);

-- 2. Verificar que las columnas se agregaron
SELECT 'Estructura de deposito_judicial:' AS verificacion;
DESCRIBE deposito_judicial;

-- 3. Ver columnas relacionadas con observaciones
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'deposito_judicial' 
  AND COLUMN_NAME IN ('estado_observacion', 'motivo_observacion', 'fecha_observacion', 'fecha_atencion_observacion')
ORDER BY ORDINAL_POSITION;

-- 4. Contar depósitos por estado actual (todos deberían tener estado_observacion = NULL)
SELECT 
    'Depósitos por estado de observación:' AS verificacion,
    COALESCE(estado_observacion, 'NULL') AS estado_obs,
    COUNT(*) AS total
FROM deposito_judicial
GROUP BY estado_observacion;

-- 5. Verificar índices
SELECT 
    'Índices en deposito_judicial:' AS verificacion,
    INDEX_NAME,
    COLUMN_NAME,
    NON_UNIQUE
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'deposito_judicial'
  AND INDEX_NAME LIKE '%observacion%';

-- ============================================================================
-- RESUMEN DE CAMBIOS
-- ============================================================================

SELECT '
========================================
MIGRACIÓN DE OBSERVACIONES COMPLETADA
========================================

NUEVOS ESTADOS CREADOS:
- ID 11: OBSERVADO (color fucsia/rosa)
- ID 12: OBSERVACIÓN ATENDIDA (color naranja neón)

NUEVAS COLUMNAS EN deposito_judicial:
- estado_observacion: INT NULL (11, 12, o NULL)
- motivo_observacion: TEXT NULL (descripción del Secretario)
- fecha_observacion: DATETIME NULL (cuándo se observó)
- fecha_atencion_observacion: DATETIME NULL (cuándo se atendió)

FUNCIONALIDAD:
1. Secretario puede marcar depósito como OBSERVADO (botón alerta)
2. Depósitos observados se muestran en color FUCSIA
3. MAU ve filtrados los depósitos observados por defecto
4. MAU puede marcar como OBSERVACIÓN ATENDIDA (color NARANJA)
5. Se mantiene el id_estado original (flujo del proceso)
6. estado_observacion es un incidente paralelo

PRÓXIMOS PASOS:
1. Implementar backend: back_deposito_observar.php
2. Implementar backend: back_deposito_marcar_atendido.php
3. Modificar listado_depositos.php (colores y filtros)
4. Modificar listado_depositos.js (botones y modales)
5. Actualizar CSS con colores fucsia y naranja neón

¡Todo listo para continuar con el código PHP!
========================================
' AS instrucciones_finales;

-- ============================================================================
-- Si usas línea de comandos MySQL, descomenta esto:
-- COMMIT;
-- ============================================================================
