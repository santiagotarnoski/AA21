<?php
require_once __DIR__ . '/comun.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    error_api('Este endpoint acepta únicamente GET.', 405);
}

$q = $_GET['q'] ?? '';
if (!is_string($q) || !mb_check_encoding($q, 'UTF-8')) {
    error_api('La búsqueda debe ser un texto válido.', 400);
}
$q = trim($q);
if (mb_strlen($q, 'UTF-8') > 100) {
    error_api('La búsqueda admite hasta 100 caracteres.', 400);
}

try {
    require __DIR__ . '/../config/conexion.php';

    // !, % y _ se buscan literalmente. Los % exteriores permiten coincidencias parciales.
    $patron = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $sql = "SELECT id, nombre, categoria, precio, stock FROM productos
            WHERE nombre LIKE :nombre ESCAPE '!' OR categoria LIKE :categoria ESCAPE '!'
            ORDER BY id DESC LIMIT 100";
    $statement = $pdo->prepare($sql);
    $statement->bindValue(':nombre', $patron, PDO::PARAM_STR);
    $statement->bindValue(':categoria', $patron, PDO::PARAM_STR);
    $statement->execute();
    $productos = $statement->fetchAll();

    responder([
        'estado' => 'exito',
        'total' => count($productos),
        'data' => $productos,
        'csrf_token' => iniciar_sesion_api(),
    ]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    error_api('No se pudo consultar la base. Revisá la conexión y la tabla productos.', 503);
}
