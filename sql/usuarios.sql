-- Seleccionar clase_backend. Si usuarios ya existe, conserva su estructura y sus datos.
-- En esta instalación password ya es VARCHAR(255).
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    rol VARCHAR(20) DEFAULT 'cliente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
