-- Seleccionar clase_backend en phpMyAdmin antes de ejecutar.
CREATE TABLE IF NOT EXISTS productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    categoria VARCHAR(50) NOT NULL,
    precio DECIMAL(10,2) NOT NULL,
    stock INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ejecutar estos INSERT una sola vez para no duplicar los datos de ejemplo.
INSERT INTO productos (nombre, categoria, precio, stock) VALUES
('Teclado Mecánico RGB', 'Periféricos', 25000.00, 15),
('Monitor 24 IPS Full HD', 'Monitores', 135000.00, 8),
('Mouse Inalámbrico Ergonómico', 'Periféricos', 18500.00, 20),
('Auriculares Gamer 7.1', 'Audio', 42000.00, 5);
