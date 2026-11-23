-- =====================================================================
-- GestiÓnate · Sistema web KDS + control de inventario para restaurante
-- Script de creación de la base de datos (MySQL 8 / MariaDB 10.4+ · XAMPP)
-- Taller de Proyecto de Especialidad TPE401 · AIEP La Serena
-- Integrantes: Benjamín Mayea · Benjamín Ibacache
--
-- 8 tablas relacionales:
--   1. usuarios                 5. plato_ingredientes (receta)
--   2. categorias_platos        6. pedidos (cola FIFO)
--   3. platos                   7. detalle_pedidos
--   4. ingredientes             8. movimientos_inventario (entradas/salidas)
--
-- Lógica central (procedimientos):
--   sp_descontar_stock_pedido  -> valida y descuenta stock según receta.
--   sp_registrar_entrada       -> ingresa mercadería y reactiva platos.
--   sp_cambiar_estado_pedido   -> avanza el pedido y registra los tiempos.
-- Vistas:
--   vista_cola_cocina          -> pantalla de cocina (FIFO + semáforo).
--   vista_alertas_stock        -> ingredientes bajo el mínimo.
-- =====================================================================

SET NAMES utf8mb4;

DROP DATABASE IF EXISTS gestionate_db;
CREATE DATABASE gestionate_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci;
USE gestionate_db;

-- ---------------------------------------------------------------------
-- 1. USUARIOS
-- Ley 19.628 / 21.719: solo se guardan los datos mínimos para operar
-- (nombre, correo y rol). La contraseña se guarda cifrada con
-- password_hash() de PHP (bcrypt); nunca en texto plano.
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
  id_usuario     INT AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(100) NOT NULL,
  correo         VARCHAR(100) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  rol            ENUM('administrador','cocina','garzon') NOT NULL,
  activo         BOOLEAN NOT NULL DEFAULT TRUE,
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- 2. CATEGORÍAS DE PLATOS (fondos, postres, bebestibles...)
-- ---------------------------------------------------------------------
CREATE TABLE categorias_platos (
  id_categoria      INT AUTO_INCREMENT PRIMARY KEY,
  nombre_categoria  VARCHAR(50) NOT NULL UNIQUE,
  descripcion       VARCHAR(255) NULL
);

-- ---------------------------------------------------------------------
-- 3. PLATOS
-- tiempo_preparacion_minutos: tiempo estimado; se usa para el semáforo
-- de la cocina y para medir tiempo real vs. estimado.
-- Para "eliminar" un plato que ya tiene pedidos se usa estado='inactivo'
-- (así no se pierde el historial de ventas).
-- ---------------------------------------------------------------------
CREATE TABLE platos (
  id_plato                    INT AUTO_INCREMENT PRIMARY KEY,
  id_categoria                INT NOT NULL,
  nombre                      VARCHAR(100) NOT NULL,
  descripcion                 TEXT NULL,
  precio                      INT NOT NULL CHECK (precio > 0),
  tiempo_preparacion_minutos  INT NOT NULL DEFAULT 10
                              CHECK (tiempo_preparacion_minutos > 0),
  estado                      ENUM('disponible','agotado','inactivo')
                              NOT NULL DEFAULT 'disponible',
  CONSTRAINT fk_platos_categoria FOREIGN KEY (id_categoria)
    REFERENCES categorias_platos(id_categoria) ON DELETE RESTRICT
);

-- ---------------------------------------------------------------------
-- 4. INGREDIENTES (stock)
-- stock_actual solo se modifica a través de los procedimientos, para
-- que todo cambio quede registrado en movimientos_inventario.
-- ---------------------------------------------------------------------
CREATE TABLE ingredientes (
  id_ingrediente       INT AUTO_INCREMENT PRIMARY KEY,
  nombre               VARCHAR(100) NOT NULL UNIQUE,
  unidad_medida        ENUM('kg','gr','litro','ml','unidad') NOT NULL,
  stock_actual         DECIMAL(10,2) NOT NULL DEFAULT 0.00
                       CHECK (stock_actual >= 0),
  stock_minimo_alerta  DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  fecha_actualizacion  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- 5. PLATO_INGREDIENTES (receta: cuánto de cada ingrediente usa 1 plato)
-- ---------------------------------------------------------------------
CREATE TABLE plato_ingredientes (
  id_plato            INT NOT NULL,
  id_ingrediente      INT NOT NULL,
  cantidad_requerida  DECIMAL(10,2) NOT NULL CHECK (cantidad_requerida > 0),
  PRIMARY KEY (id_plato, id_ingrediente),
  CONSTRAINT fk_receta_plato FOREIGN KEY (id_plato)
    REFERENCES platos(id_plato) ON DELETE CASCADE,
  CONSTRAINT fk_receta_ingrediente FOREIGN KEY (id_ingrediente)
    REFERENCES ingredientes(id_ingrediente) ON DELETE RESTRICT
);

-- ---------------------------------------------------------------------
-- 6. PEDIDOS (cola FIFO: se atienden por fecha_hora_ingreso ascendente)
-- fecha_inicio_preparacion y fecha_listo permiten calcular el tiempo
-- real de preparación (indicador del Impacto esperado).
-- ---------------------------------------------------------------------
CREATE TABLE pedidos (
  id_pedido                 INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario                INT NOT NULL,            -- garzón que lo tomó
  numero_mesa               VARCHAR(10) NULL,        -- NULL si es para llevar
  tipo_pedido               ENUM('local','llevar') NOT NULL DEFAULT 'local',
  estado                    ENUM('pendiente','en_preparacion','listo',
                                 'entregado','cancelado')
                            NOT NULL DEFAULT 'pendiente',
  fecha_hora_ingreso        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_inicio_preparacion  DATETIME NULL,
  fecha_listo               DATETIME NULL,
  CONSTRAINT fk_pedidos_usuario FOREIGN KEY (id_usuario)
    REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
  INDEX idx_cola_fifo (estado, fecha_hora_ingreso)
);

-- ---------------------------------------------------------------------
-- 7. DETALLE_PEDIDOS
-- ---------------------------------------------------------------------
CREATE TABLE detalle_pedidos (
  id_detalle     INT AUTO_INCREMENT PRIMARY KEY,
  id_pedido      INT NOT NULL,
  id_plato       INT NOT NULL,
  cantidad       INT NOT NULL DEFAULT 1 CHECK (cantidad > 0),
  observaciones  VARCHAR(255) NULL,
  CONSTRAINT fk_detalle_pedido FOREIGN KEY (id_pedido)
    REFERENCES pedidos(id_pedido) ON DELETE CASCADE,
  CONSTRAINT fk_detalle_plato FOREIGN KEY (id_plato)
    REFERENCES platos(id_plato) ON DELETE RESTRICT
);

-- ---------------------------------------------------------------------
-- 8. MOVIMIENTOS_INVENTARIO (historial de entradas y salidas de stock)
-- Respalda el objetivo específico 1: "registrar entradas y salidas".
-- cantidad siempre positiva; el tipo indica si suma o resta.
-- ---------------------------------------------------------------------
CREATE TABLE movimientos_inventario (
  id_movimiento   INT AUTO_INCREMENT PRIMARY KEY,
  id_ingrediente  INT NOT NULL,
  id_usuario      INT NOT NULL,
  id_pedido       INT NULL,                  -- solo en salidas por venta
  tipo            ENUM('entrada','salida_venta','merma','ajuste') NOT NULL,
  cantidad        DECIMAL(10,2) NOT NULL CHECK (cantidad > 0),
  stock_resultante DECIMAL(10,2) NOT NULL,
  motivo          VARCHAR(255) NULL,
  fecha           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mov_ingrediente FOREIGN KEY (id_ingrediente)
    REFERENCES ingredientes(id_ingrediente) ON DELETE RESTRICT,
  CONSTRAINT fk_mov_usuario FOREIGN KEY (id_usuario)
    REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
  CONSTRAINT fk_mov_pedido FOREIGN KEY (id_pedido)
    REFERENCES pedidos(id_pedido) ON DELETE SET NULL,
  INDEX idx_mov_fecha (fecha)
);

-- =====================================================================
-- PROCEDIMIENTOS
-- =====================================================================
DELIMITER $$

-- ---------------------------------------------------------------------
-- Recalcula el estado de los platos según el stock:
--  · 'disponible' -> 'agotado' si algún ingrediente no alcanza para 1 porción.
--  · 'agotado' -> 'disponible' si ya alcanzan todos.
-- Los platos 'inactivo' no se tocan.
-- ---------------------------------------------------------------------
CREATE PROCEDURE sp_actualizar_disponibilidad()
BEGIN
  UPDATE platos p
  SET p.estado = CASE
    WHEN EXISTS (SELECT 1
                 FROM plato_ingredientes pi
                 JOIN ingredientes i ON i.id_ingrediente = pi.id_ingrediente
                 WHERE pi.id_plato = p.id_plato
                   AND i.stock_actual < pi.cantidad_requerida)
      THEN 'agotado'
    ELSE 'disponible'
  END
  WHERE p.estado IN ('disponible','agotado');
END$$

-- ---------------------------------------------------------------------
-- Valida y descuenta el stock de un pedido ya insertado.
-- NO abre su propia transacción: PHP debe hacer
--   beginTransaction() -> INSERT pedido -> INSERT detalles
--   -> CALL sp_descontar_stock_pedido(id, usuario) -> commit()
-- Si falta stock o un plato no está disponible, lanza un error
-- (SQLSTATE 45000) y PHP hace rollBack(): el pedido no se guarda.
-- ---------------------------------------------------------------------
CREATE PROCEDURE sp_descontar_stock_pedido(IN p_id_pedido INT,
                                           IN p_id_usuario INT)
BEGIN
  DECLARE v_lineas INT DEFAULT 0;
  DECLARE v_no_disponibles INT DEFAULT 0;
  DECLARE v_faltante VARCHAR(100) DEFAULT NULL;
  DECLARE v_mensaje VARCHAR(255);
  DECLARE v_bloqueados INT DEFAULT 0;

  -- 1) El pedido debe tener al menos un plato
  SELECT COUNT(*) INTO v_lineas
  FROM detalle_pedidos WHERE id_pedido = p_id_pedido;
  IF v_lineas = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pedido no tiene platos';
  END IF;

  -- 2) Todos los platos deben estar 'disponible'
  SELECT COUNT(*) INTO v_no_disponibles
  FROM detalle_pedidos dp
  JOIN platos p ON p.id_plato = dp.id_plato
  WHERE dp.id_pedido = p_id_pedido AND p.estado <> 'disponible';
  IF v_no_disponibles > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'El pedido incluye un plato agotado o inactivo';
  END IF;

  -- 3) Bloquear las filas de ingredientes usados (evita que dos garzones
  --    vendan la última porción al mismo tiempo)
  --    (se guarda en una variable para no devolver resultados a PHP)
  SELECT COUNT(*) INTO v_bloqueados
  FROM ingredientes i
  WHERE i.id_ingrediente IN (
    SELECT pi.id_ingrediente
    FROM detalle_pedidos dp
    JOIN plato_ingredientes pi ON pi.id_plato = dp.id_plato
    WHERE dp.id_pedido = p_id_pedido)
  FOR UPDATE;

  -- 4) Verificar que alcance el stock (suma de todas las líneas)
  SELECT i.nombre INTO v_faltante
  FROM detalle_pedidos dp
  JOIN plato_ingredientes pi ON pi.id_plato = dp.id_plato
  JOIN ingredientes i ON i.id_ingrediente = pi.id_ingrediente
  WHERE dp.id_pedido = p_id_pedido
  GROUP BY i.id_ingrediente, i.nombre, i.stock_actual
  HAVING SUM(pi.cantidad_requerida * dp.cantidad) > i.stock_actual
  LIMIT 1;

  IF v_faltante IS NOT NULL THEN
    SET v_mensaje = CONCAT('Stock insuficiente de: ', v_faltante);
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_mensaje;
  END IF;

  -- 5) Descontar stock
  UPDATE ingredientes i
  JOIN (SELECT pi.id_ingrediente,
               SUM(pi.cantidad_requerida * dp.cantidad) AS total
        FROM detalle_pedidos dp
        JOIN plato_ingredientes pi ON pi.id_plato = dp.id_plato
        WHERE dp.id_pedido = p_id_pedido
        GROUP BY pi.id_ingrediente) c
    ON c.id_ingrediente = i.id_ingrediente
  SET i.stock_actual = i.stock_actual - c.total;

  -- 6) Registrar la salida en el historial
  INSERT INTO movimientos_inventario
    (id_ingrediente, id_usuario, id_pedido, tipo, cantidad,
     stock_resultante, motivo)
  SELECT pi.id_ingrediente, p_id_usuario, p_id_pedido, 'salida_venta',
         SUM(pi.cantidad_requerida * dp.cantidad),
         MAX(i.stock_actual),
         CONCAT('Venta pedido #', p_id_pedido)
  FROM detalle_pedidos dp
  JOIN plato_ingredientes pi ON pi.id_plato = dp.id_plato
  JOIN ingredientes i ON i.id_ingrediente = pi.id_ingrediente
  WHERE dp.id_pedido = p_id_pedido
  GROUP BY pi.id_ingrediente;

  -- 7) Marcar como agotados los platos que ya no alcanzan
  CALL sp_actualizar_disponibilidad();
END$$

-- ---------------------------------------------------------------------
-- Registra una entrada de mercadería (compra/reposición).
-- ---------------------------------------------------------------------
CREATE PROCEDURE sp_registrar_entrada(IN p_id_ingrediente INT,
                                      IN p_cantidad DECIMAL(10,2),
                                      IN p_id_usuario INT,
                                      IN p_motivo VARCHAR(255))
BEGIN
  IF p_cantidad IS NULL OR p_cantidad <= 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'La cantidad debe ser mayor que cero';
  END IF;

  UPDATE ingredientes
  SET stock_actual = stock_actual + p_cantidad
  WHERE id_ingrediente = p_id_ingrediente;

  IF ROW_COUNT() = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ingrediente no existe';
  END IF;

  INSERT INTO movimientos_inventario
    (id_ingrediente, id_usuario, tipo, cantidad, stock_resultante, motivo)
  SELECT p_id_ingrediente, p_id_usuario, 'entrada', p_cantidad,
         stock_actual, p_motivo
  FROM ingredientes WHERE id_ingrediente = p_id_ingrediente;

  CALL sp_actualizar_disponibilidad();
END$$

-- ---------------------------------------------------------------------
-- Avanza el estado del pedido y guarda los tiempos reales.
-- pendiente -> en_preparacion -> listo -> entregado
-- ---------------------------------------------------------------------
CREATE PROCEDURE sp_cambiar_estado_pedido(IN p_id_pedido INT,
                                          IN p_nuevo_estado VARCHAR(20))
BEGIN
  DECLARE v_actual VARCHAR(20);

  SELECT estado INTO v_actual FROM pedidos WHERE id_pedido = p_id_pedido;

  IF v_actual IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pedido no existe';
  END IF;

  IF NOT ((v_actual = 'pendiente'      AND p_nuevo_estado = 'en_preparacion')
       OR (v_actual = 'en_preparacion' AND p_nuevo_estado = 'listo')
       OR (v_actual = 'listo'          AND p_nuevo_estado = 'entregado')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cambio de estado no permitido';
  END IF;

  UPDATE pedidos
  SET estado = p_nuevo_estado,
      fecha_inicio_preparacion = IF(p_nuevo_estado = 'en_preparacion',
                                    NOW(), fecha_inicio_preparacion),
      fecha_listo = IF(p_nuevo_estado = 'listo', NOW(), fecha_listo)
  WHERE id_pedido = p_id_pedido;
END$$

DELIMITER ;

-- =====================================================================
-- VISTAS
-- =====================================================================

-- Pantalla de cocina. En PHP consultar con:
--   SELECT * FROM vista_cola_cocina ORDER BY fecha_hora_ingreso ASC;  (FIFO)
-- Semáforo: verde < 80 % del tiempo estimado, amarillo hasta 100 %,
-- rojo cuando se pasa del tiempo estimado.
CREATE VIEW vista_cola_cocina AS
SELECT p.id_pedido,
       p.numero_mesa,
       p.tipo_pedido,
       p.estado,
       p.fecha_hora_ingreso,
       u.nombre AS garzon,
       GROUP_CONCAT(CONCAT(dp.cantidad, 'x ', pl.nombre,
                    IFNULL(CONCAT(' (', dp.observaciones, ')'), ''))
                    ORDER BY dp.id_detalle SEPARATOR ' | ') AS platos,
       MAX(pl.tiempo_preparacion_minutos) AS minutos_estimados,
       TIMESTAMPDIFF(MINUTE, p.fecha_hora_ingreso, NOW()) AS minutos_transcurridos,
       CASE
         WHEN TIMESTAMPDIFF(MINUTE, p.fecha_hora_ingreso, NOW())
              < 0.8 * MAX(pl.tiempo_preparacion_minutos) THEN 'verde'
         WHEN TIMESTAMPDIFF(MINUTE, p.fecha_hora_ingreso, NOW())
              <= MAX(pl.tiempo_preparacion_minutos) THEN 'amarillo'
         ELSE 'rojo'
       END AS semaforo
FROM pedidos p
JOIN usuarios u         ON u.id_usuario = p.id_usuario
JOIN detalle_pedidos dp ON dp.id_pedido = p.id_pedido
JOIN platos pl          ON pl.id_plato  = dp.id_plato
WHERE p.estado IN ('pendiente','en_preparacion')
GROUP BY p.id_pedido, p.numero_mesa, p.tipo_pedido, p.estado,
         p.fecha_hora_ingreso, u.nombre;

-- Ingredientes en o bajo el stock mínimo
CREATE VIEW vista_alertas_stock AS
SELECT id_ingrediente, nombre, unidad_medida,
       stock_actual, stock_minimo_alerta
FROM ingredientes
WHERE stock_actual <= stock_minimo_alerta;

-- Tiempo real vs. estimado de pedidos terminados (indicador de impacto)
CREATE VIEW vista_tiempos_preparacion AS
SELECT p.id_pedido,
       MAX(pl.tiempo_preparacion_minutos) AS minutos_estimados,
       TIMESTAMPDIFF(MINUTE, p.fecha_hora_ingreso, p.fecha_listo) AS minutos_reales
FROM pedidos p
JOIN detalle_pedidos dp ON dp.id_pedido = p.id_pedido
JOIN platos pl          ON pl.id_plato  = dp.id_plato
WHERE p.fecha_listo IS NOT NULL
GROUP BY p.id_pedido, p.fecha_hora_ingreso, p.fecha_listo;

-- =====================================================================
-- DATOS DE PRUEBA
-- Contraseña de los 3 usuarios de prueba: Gestionate2026
-- (hash generado con password_hash() de PHP; validar con password_verify)
-- =====================================================================
INSERT INTO usuarios (nombre, correo, password_hash, rol) VALUES
('Admin Local',   'admin@gestionate.cl',   '$2y$10$9UtKX76wNkH.vHUHN6MCBOMlnRUDTiLJI4ePlOiHBxius1xJSn6my', 'administrador'),
('Maestro Cocina','cocina@gestionate.cl',  '$2y$10$9UtKX76wNkH.vHUHN6MCBOMlnRUDTiLJI4ePlOiHBxius1xJSn6my', 'cocina'),
('Garzón 1',      'garzon1@gestionate.cl', '$2y$10$9UtKX76wNkH.vHUHN6MCBOMlnRUDTiLJI4ePlOiHBxius1xJSn6my', 'garzon');

INSERT INTO categorias_platos (nombre_categoria, descripcion) VALUES
('Fondos',      'Platos principales del menú del día'),
('Postres',     'Postres caseros'),
('Bebestibles', 'Bebidas y jugos');

-- Ingredientes parten en 0: el stock inicial se carga como "entrada"
-- para que quede en el historial de movimientos.
INSERT INTO ingredientes (nombre, unidad_medida, stock_minimo_alerta) VALUES
('Posta Negra (Carne)',    'kg',     1.00),
('Pechuga de Pollo',       'kg',     1.00),
('Arroz Grado 1',          'kg',     2.00),
('Papas para Puré',        'kg',     2.00),
('Pino Empanada / Pastel', 'kg',     1.50),
('Leche Asada (porción)',  'unidad', 5.00),
('Bebida en lata 350 ml',  'unidad', 10.00);

INSERT INTO platos (id_categoria, nombre, descripcion, precio,
                    tiempo_preparacion_minutos) VALUES
(1, 'Carne al Jugo con Arroz',     'Posta negra cocinada a fuego lento con porción de arroz', 5500, 15),
(1, 'Pollo a la Plancha con Puré', 'Filete de pechuga grillada con puré casero',             5000, 12),
(1, 'Pastel de Papas Casero',      'Pastel en greda con pino de carne y huevo',              5200, 20),
(2, 'Leche Asada',                 'Postre casero',                                          1800,  2),
(3, 'Bebida en lata',              'Bebida 350 ml',                                          1200,  1);

INSERT INTO plato_ingredientes (id_plato, id_ingrediente, cantidad_requerida) VALUES
(1, 1, 0.25), (1, 3, 0.15),
(2, 2, 0.25), (2, 4, 0.30),
(3, 5, 0.30), (3, 4, 0.25),
(4, 6, 1.00),
(5, 7, 1.00);

-- Stock inicial (usuario 1 = administrador)
CALL sp_registrar_entrada(1,  3.50, 1, 'Inventario inicial');
CALL sp_registrar_entrada(2,  4.00, 1, 'Inventario inicial');
CALL sp_registrar_entrada(3, 10.00, 1, 'Inventario inicial');
CALL sp_registrar_entrada(4,  8.00, 1, 'Inventario inicial');
CALL sp_registrar_entrada(5,  1.20, 1, 'Inventario inicial'); -- bajo el mínimo: genera alerta
CALL sp_registrar_entrada(6, 12.00, 1, 'Inventario inicial');
CALL sp_registrar_entrada(7, 24.00, 1, 'Inventario inicial');

-- Pedidos de demostración (garzón = usuario 3), con el mismo flujo que usará PHP
START TRANSACTION;
INSERT INTO pedidos (id_usuario, numero_mesa, tipo_pedido) VALUES (3, '4', 'local');
SET @p1 = LAST_INSERT_ID();
INSERT INTO detalle_pedidos (id_pedido, id_plato, cantidad, observaciones) VALUES
(@p1, 1, 2, NULL), (@p1, 5, 2, NULL);
CALL sp_descontar_stock_pedido(@p1, 3);
COMMIT;

START TRANSACTION;
INSERT INTO pedidos (id_usuario, numero_mesa, tipo_pedido) VALUES (3, NULL, 'llevar');
SET @p2 = LAST_INSERT_ID();
INSERT INTO detalle_pedidos (id_pedido, id_plato, cantidad, observaciones) VALUES
(@p2, 2, 1, 'Sin sal'), (@p2, 4, 1, NULL);
CALL sp_descontar_stock_pedido(@p2, 3);
COMMIT;
