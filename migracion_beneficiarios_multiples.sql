-- ============================================================================
-- SCRIPT DE MIGRACIÓN: Múltiples Beneficiarios por Expediente
-- Sistema: SISDEJU (Sistema de Depósitos Judiciales)
-- Fecha: 2025-11-11
-- ============================================================================
-- 
-- OBJETIVO:
-- Permitir que un expediente tenga múltiples beneficiarios.
-- Cada depósito mantiene su beneficiario específico.
--
-- CAMBIOS PRINCIPALES:
-- 1. Crear tabla intermedia expediente_beneficiario
-- 2. Agregar columna documento_beneficiario a deposito_judicial
-- 3. Migrar datos existentes
-- 4. Eliminar columna documento_beneficiario de expediente (OPCIONAL)
--
-- IMPORTANTE: 
-- - Hacer BACKUP de la base de datos antes de ejecutar este script
-- - Revisar los datos migrados antes de eliminar la columna de expediente
-- - Este script está diseñado para MySQL/MariaDB
-- ============================================================================

-- Iniciar transacción para poder revertir en caso de error
START TRANSACTION;

-- ============================================================================
-- PASO 1: CREAR TABLA INTERMEDIA expediente_beneficiario
-- ============================================================================
-- Esta tabla permite la relación muchos a muchos entre expedientes y beneficiarios

CREATE TABLE IF NOT EXISTS `expediente_beneficiario` (
  `id_expediente_beneficiario` INT(11) AUTO_INCREMENT PRIMARY KEY,
  `n_expediente` VARCHAR(20) NOT NULL,
  `documento_beneficiario` VARCHAR(11) NOT NULL,
  `fecha_registro` DATETIME DEFAULT CURRENT_TIMESTAMP,
  
  -- Índices para optimizar consultas
  INDEX `idx_n_expediente` (`n_expediente`),
  INDEX `idx_documento_beneficiario` (`documento_beneficiario`),
  
  -- Evitar duplicados: un beneficiario no puede estar dos veces en el mismo expediente
  UNIQUE KEY `unique_exp_benef` (`n_expediente`, `documento_beneficiario`),
  
  -- Claves foráneas (comentadas por si no existen en tu esquema actual)
  -- Descomenta las siguientes líneas si tu BD tiene definidas estas FKs:
  -- FOREIGN KEY (`n_expediente`) REFERENCES `expediente`(`n_expediente`) ON DELETE CASCADE,
  -- FOREIGN KEY (`documento_beneficiario`) REFERENCES `beneficiario`(`documento`) ON DELETE CASCADE
  
  CONSTRAINT `fk_eb_expediente` 
    FOREIGN KEY (`n_expediente`) 
    REFERENCES `expediente`(`n_expediente`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PASO 2: MIGRAR RELACIONES EXISTENTES DESDE expediente
-- ============================================================================
-- Poblar expediente_beneficiario con las relaciones actuales

INSERT INTO `expediente_beneficiario` (`n_expediente`, `documento_beneficiario`)
SELECT DISTINCT `n_expediente`, `documento_beneficiario` 
FROM `expediente` 
WHERE `documento_beneficiario` IS NOT NULL 
  AND `documento_beneficiario` != ''
ON DUPLICATE KEY UPDATE 
    `expediente_beneficiario`.`n_expediente` = VALUES(`n_expediente`);

-- Verificar migración
SELECT 
    'Relaciones migradas a expediente_beneficiario' AS verificacion,
    COUNT(*) AS total_relaciones
FROM `expediente_beneficiario`;

-- ============================================================================
-- PASO 3: AGREGAR COLUMNA documento_beneficiario A deposito_judicial
-- ============================================================================
-- Esta columna vinculará cada depósito con su beneficiario específico

-- Verificar si la columna ya existe
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'deposito_judicial' 
      AND COLUMN_NAME = 'documento_beneficiario'
);

-- Solo agregar la columna si no existe
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE `deposito_judicial` 
     ADD COLUMN `documento_beneficiario` VARCHAR(11) NULL 
     AFTER `n_expediente`',
    'SELECT "La columna documento_beneficiario ya existe en deposito_judicial" AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PASO 4: MIGRAR documento_beneficiario A DEPÓSITOS EXISTENTES
-- ============================================================================
-- Copiar el beneficiario desde expediente hacia cada depósito

UPDATE `deposito_judicial` dj
INNER JOIN `expediente` ex ON dj.n_expediente = ex.n_expediente
SET dj.documento_beneficiario = ex.documento_beneficiario
WHERE dj.documento_beneficiario IS NULL
  AND ex.documento_beneficiario IS NOT NULL
  AND ex.documento_beneficiario != '';

-- Verificar migración de beneficiarios a depósitos
SELECT 
    'Depósitos con beneficiario asignado' AS verificacion,
    COUNT(*) AS total_con_beneficiario
FROM `deposito_judicial`
WHERE `documento_beneficiario` IS NOT NULL;

SELECT 
    'Depósitos SIN beneficiario (requieren atención)' AS verificacion,
    COUNT(*) AS total_sin_beneficiario
FROM `deposito_judicial`
WHERE `documento_beneficiario` IS NULL;

-- ============================================================================
-- PASO 5: CREAR ÍNDICE EN documento_beneficiario DE deposito_judicial
-- ============================================================================
-- Optimizar consultas que filtren por beneficiario

SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'deposito_judicial' 
      AND INDEX_NAME = 'idx_documento_beneficiario'
);

SET @sql_index = IF(@index_exists = 0,
    'ALTER TABLE `deposito_judicial` 
     ADD INDEX `idx_documento_beneficiario` (`documento_beneficiario`)',
    'SELECT "El índice idx_documento_beneficiario ya existe" AS info'
);

PREPARE stmt_index FROM @sql_index;
EXECUTE stmt_index;
DEALLOCATE PREPARE stmt_index;

-- ============================================================================
-- PASO 6 (OPCIONAL): ELIMINAR documento_beneficiario DE expediente
-- ============================================================================
-- ⚠️ ATENCIÓN: Este paso es IRREVERSIBLE una vez confirmado el COMMIT
-- 
-- ANTES de ejecutar esta sección:
-- 1. Verificar que TODOS los depósitos tengan su beneficiario asignado
-- 2. Verificar que la aplicación funcione correctamente con el nuevo modelo
-- 3. Confirmar que NO hay otras dependencias en la columna documento_beneficiario
--
-- Si estás seguro, descomenta las siguientes líneas:

/*
ALTER TABLE `expediente` 
DROP COLUMN `documento_beneficiario`;

SELECT 'Columna documento_beneficiario eliminada de expediente' AS confirmacion;
*/

-- ============================================================================
-- VALIDACIONES FINALES
-- ============================================================================

-- 1. Verificar que no haya depósitos huérfanos (sin beneficiario)
SELECT 
    'ADVERTENCIA: Depósitos sin beneficiario' AS alerta,
    dj.id_deposito,
    dj.n_deposito,
    dj.n_expediente,
    dj.id_estado
FROM `deposito_judicial` dj
WHERE dj.documento_beneficiario IS NULL
LIMIT 10;

-- 2. Verificar integridad de relaciones expediente-beneficiario
SELECT 
    'Verificación: beneficiarios por expediente' AS info,
    eb.n_expediente,
    COUNT(DISTINCT eb.documento_beneficiario) AS total_beneficiarios
FROM `expediente_beneficiario` eb
GROUP BY eb.n_expediente
HAVING COUNT(DISTINCT eb.documento_beneficiario) > 1
ORDER BY total_beneficiarios DESC
LIMIT 10;

-- 3. Resumen de la migración
SELECT 
    'RESUMEN DE MIGRACIÓN' AS seccion,
    (SELECT COUNT(*) FROM expediente) AS total_expedientes,
    (SELECT COUNT(*) FROM expediente_beneficiario) AS total_relaciones_exp_benef,
    (SELECT COUNT(*) FROM deposito_judicial WHERE documento_beneficiario IS NOT NULL) AS depositos_con_beneficiario,
    (SELECT COUNT(*) FROM deposito_judicial WHERE documento_beneficiario IS NULL) AS depositos_sin_beneficiario,
    (SELECT COUNT(DISTINCT n_expediente) FROM expediente_beneficiario 
     WHERE n_expediente IN (
         SELECT n_expediente FROM expediente_beneficiario GROUP BY n_expediente HAVING COUNT(*) > 1
     )) AS expedientes_con_multiples_beneficiarios;

-- ============================================================================
-- INSTRUCCIONES FINALES
-- ============================================================================
-- 
-- Si todo se ve correcto en las validaciones:
--   COMMIT;
--
-- Si hay problemas o errores:
--   ROLLBACK;
--
-- ⚠️ NO EJECUTAR AUTOMÁTICAMENTE - Revisar los resultados primero
-- ============================================================================

-- Descomentar UNA de las siguientes líneas después de revisar:

-- COMMIT;    -- Para confirmar los cambios
-- ROLLBACK;  -- Para revertir los cambios

SELECT '
========================================
SCRIPT DE MIGRACIÓN COMPLETADO
========================================

PRÓXIMOS PASOS:

1. Revisar los resultados de las validaciones arriba
2. Verificar que no haya depósitos sin beneficiario
3. Si todo está correcto, ejecutar: COMMIT;
4. Si hay problemas, ejecutar: ROLLBACK;
5. Después del COMMIT, probar la aplicación:
   - Agregar nuevos depósitos
   - Editar depósitos existentes
   - Verificar listados y reportes
6. Si todo funciona correctamente por varios días:
   - Descomentar el PASO 6 para eliminar la columna
   - Ejecutar de nuevo este script solo con el ALTER TABLE

ARCHIVOS MODIFICADOS EN EL CÓDIGO:
- code_back/back_deposito_agregar.php
- code_back/back_deposito_editar.php
- code_back/back_deposito_get_full.php
- code_back/get_datos_beneficiario.php
- code_front/vistas/listado_depositos.php
- code_front/vistas/agregar_deposito.php
- api/get_reportes_depositos_paginados.php
- eliminar/listado_op.php
- eliminar/back_orden_pago_agregar.php

¡Buena suerte!
========================================
' AS instrucciones_finales;
