<?php
require_once __DIR__ . '/comun.php';

$usuarioId = requerir_usuario();
$datos = leer_json_post();

$nombre = $datos->nombre ?? null;
$categoria = $datos->categoria ?? null;
$precio = $datos->precio ?? null;
$stock = $datos->stock ?? null;

if (!is_string($nombre) || !is_string($categoria)) {
    error_api('Nombre y categoría son obligatorios y deben ser textos.', 422);
}
$nombre = trim($nombre);
$categoria = trim($categoria);
if (!mb_check_encoding($nombre . $categoria, 'UTF-8') || $nombre === '' || $categoria === ''
    || mb_strlen($nombre, 'UTF-8') > 100 || mb_strlen($categoria, 'UTF-8') > 50) {
    error_api('El nombre admite de 1 a 100 caracteres y la categoría de 1 a 50.', 422);
}

// Se conserva el precio como decimal textual para no perder precisión con float.
if ((!is_string($precio) && !is_int($precio))
    || !preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', (string) $precio)) {
    error_api('El precio debe estar entre 0 y 99999999.99, con hasta dos decimales.', 422);
}
if ((!is_int($stock) && !is_string($stock)) || filter_var($stock, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 2147483647]]) === false) {
    error_api('El stock debe ser un entero entre 0 y 2147483647.', 422);
}
$precio = (string) $precio;
$stock = (int) $stock;

try {
    require __DIR__ . '/../config/conexion.php';
    $statement = $pdo->prepare('INSERT INTO productos (nombre, categoria, precio, stock)
                               VALUES (:nombre, :categoria, :precio, :stock)');
    $statement->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $statement->bindValue(':categoria', $categoria, PDO::PARAM_STR);
    $statement->bindValue(':precio', $precio, PDO::PARAM_STR);
    $statement->bindValue(':stock', $stock, PDO::PARAM_INT);
    $statement->execute();

    responder(['estado' => 'exito', 'mensaje' => 'Producto guardado.', 'id' => (int) $pdo->lastInsertId()], 201);
} catch (PDOException $e) {
    error_log($e->getMessage());
    error_api('No se pudo guardar el producto. Revisá la conexión y la tabla productos.', 503);
}
