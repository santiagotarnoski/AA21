<?php
// Ejecutar por consola. Solo crea y borra una base temporal de esta prueba.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Esta prueba solo se ejecuta por consola.');
}

$proyecto = dirname(__DIR__);
$config = require $proyecto . '/config/config.example.php';
if (is_file($proyecto . '/config/local.php')) {
    $config = array_replace($config, require $proyecto . '/config/local.php');
}
$admin = new PDO("mysql:host={$config['host']};port={$config['port']};charset=utf8mb4", $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$basePrueba = 'aa21_test_' . bin2hex(random_bytes(6));
$temporal = rtrim(getenv('AA21_TEST_DIR') ?: sys_get_temp_dir(), '/\\') . '/' . $basePrueba;
$creada = false;
$servidor = null;
$cliente = null;
$checks = 0;

function comprobar(bool $correcto, string $mensaje): void
{
    global $checks;
    if (!$correcto) { throw new RuntimeException('Falló: ' . $mensaje); }
    $checks++;
    echo "OK: $mensaje\n";
}

function copiar_carpeta(string $origen, string $destino): void
{
    if (!is_dir($destino)) { mkdir($destino, 0777, true); }
    foreach (new DirectoryIterator($origen) as $entrada) {
        if ($entrada->isDot() || $entrada->getFilename() === 'local.php') { continue; }
        if ($entrada->isDir()) {
            copiar_carpeta($entrada->getPathname(), $destino . '/' . $entrada->getFilename());
        } else {
            copy($entrada->getPathname(), $destino . '/' . $entrada->getFilename());
        }
    }
}

function pedir(string $ruta, string $metodo = 'GET', ?string $cuerpo = null, array $headers = []): array
{
    global $cliente, $url;
    curl_setopt($cliente, CURLOPT_URL, $url . $ruta);
    curl_setopt($cliente, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json'], $headers));
    if ($metodo === 'GET') {
        curl_setopt($cliente, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($cliente, CURLOPT_POSTFIELDS, $cuerpo ?? '');
    }
    curl_setopt($cliente, CURLOPT_CUSTOMREQUEST, $metodo);
    $texto = curl_exec($cliente);
    if ($texto === false) { throw new RuntimeException('Error HTTP: ' . curl_error($cliente)); }
    return [curl_getinfo($cliente, CURLINFO_HTTP_CODE), json_decode($texto, true), $texto];
}

try {
    // El identificador es generado aquí, no proviene de una petición del usuario.
    $admin->exec("CREATE DATABASE `$basePrueba` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $creada = true;
    $admin->exec("USE `$basePrueba`");
    $sql = preg_replace('/^--.*$/m', '', file_get_contents($proyecto . '/sql/productos.sql'));
    foreach (explode(';', $sql) as $sentencia) {
        if (trim($sentencia) !== '') { $admin->exec($sentencia); }
    }

    // Reproduce la concatenación del ZIP únicamente en la base temporal.
    $ataque = "x' OR 1=1 -- ";
    $consultaOriginal = "SELECT * FROM productos WHERE nombre LIKE '%" . $ataque . "%'";
    comprobar(count($admin->query($consultaOriginal)->fetchAll()) === 4, 'la consulta vulnerable devuelve todos los productos ante una inyección');

    mkdir($temporal . '/app', 0777, true);
    mkdir($temporal . '/sesiones');
    foreach (['api', 'config', 'css', 'js'] as $carpeta) {
        copiar_carpeta($proyecto . '/' . $carpeta, $temporal . '/app/' . $carpeta);
    }
    copy($proyecto . '/index.html', $temporal . '/app/index.html');
    $config['db'] = $basePrueba;
    file_put_contents($temporal . '/app/config/local.php', "<?php\nreturn " . var_export($config, true) . ";\n");

    $socket = stream_socket_server('tcp://127.0.0.1:0', $codigo, $mensaje);
    if (!$socket) { throw new RuntimeException('No se pudo reservar un puerto de prueba.'); }
    $direccion = stream_socket_get_name($socket, false);
    fclose($socket);
    $url = 'http://' . $direccion;
    $servidor = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'session.save_path=' . $temporal . '/sesiones', '-S', $direccion, '-t', $temporal . '/app'],
        [0 => ['pipe', 'r'], 1 => ['file', $temporal . '/servidor.log', 'a'], 2 => ['file', $temporal . '/servidor.log', 'a']], $pipes, $temporal);
    if (!is_resource($servidor)) { throw new RuntimeException('No se pudo iniciar el servidor de prueba.'); }
    fclose($pipes[0]);
    $listo = false;
    for ($intento = 0; $intento < 30; $intento++) {
        $conexion = @stream_socket_client('tcp://' . $direccion, $codigo, $mensaje, .1);
        if ($conexion) { fclose($conexion); $listo = true; break; }
        usleep(100000);
    }
    if (!$listo) { throw new RuntimeException('El servidor de prueba no respondió.'); }

    $cliente = curl_init();
    curl_setopt_array($cliente, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 5]);
    [$estado, $datos] = pedir('/api/buscador-seguro.php');
    comprobar($estado === 200 && $datos['total'] === 4 && strlen($datos['csrf_token']) === 64, 'lectura inicial y token CSRF');
    $headers = ['Content-Type: application/json', 'X-CSRF-Token: ' . $datos['csrf_token']];
    [$estado, $datos] = pedir('/api/buscador-seguro.php?q=teclado');
    comprobar($estado === 200 && $datos['total'] === 1 && $datos['data'][0]['precio'] === '25000.00', 'búsqueda parcial y precio decimal exacto');
    [$estado, $datos] = pedir('/api/buscador-seguro.php?q=' . rawurlencode('Periféricos'));
    comprobar($estado === 200 && $datos['total'] === 2, 'búsqueda por categoría con acentos');
    [$estado, $datos] = pedir('/api/buscador-seguro.php?q=' . rawurlencode($ataque));
    comprobar($estado === 200 && $datos['total'] === 0, 'la consulta preparada trata la inyección como texto');
    foreach (['%', '_', '!'] as $literal) {
        [$estado, $datos] = pedir('/api/buscador-seguro.php?q=' . rawurlencode($literal));
        comprobar($estado === 200 && $datos['total'] === 0, "comodín $literal se busca literalmente");
    }
    [$estado] = pedir('/api/buscador-seguro.php?q[]=teclado');
    comprobar($estado === 400, 'parámetro de búsqueda de tipo array rechazado');
    [$estado] = pedir('/api/buscador-seguro.php?q=' . str_repeat('a', 101));
    comprobar($estado === 400, 'búsqueda demasiado larga rechazada');
    [$estado] = pedir('/api/buscador-seguro.php', 'POST');
    comprobar($estado === 405, 'método incorrecto rechazado en la búsqueda');
    [$estado] = pedir('/api/guardar-producto.php');
    comprobar($estado === 405, 'GET no puede insertar productos');
    $producto = ['nombre' => "Producto O'Reilly <img src=x onerror=alert(1)>", 'categoria' => 'Prueba', 'precio' => '10.20', 'stock' => 0];
    [$estado] = pedir('/api/guardar-producto.php', 'POST', json_encode($producto), ['Content-Type: application/json']);
    comprobar($estado === 403, 'escritura sin token CSRF rechazada');
    [$estado] = pedir('/api/guardar-producto.php', 'POST', json_encode($producto), ['Content-Type: text/plain']);
    comprobar($estado === 415, 'tipo de contenido incorrecto rechazado');
    [$estado] = pedir('/api/guardar-producto.php', 'POST', '{', $headers);
    comprobar($estado === 400, 'JSON mal formado rechazado');
    [$estado] = pedir('/api/guardar-producto.php', 'POST', '[]', $headers);
    comprobar($estado === 400, 'se requiere un objeto JSON');
    [$estado] = pedir('/api/guardar-producto.php', 'POST', str_repeat('a', 8193), $headers);
    comprobar($estado === 413, 'cuerpo demasiado grande rechazado');
    foreach ([['precio' => '-1'], ['precio' => '1.234'], ['precio' => '100000000'], ['stock' => -1], ['stock' => '1.5'], ['nombre' => str_repeat('ñ', 101)], ['categoria' => '']] as $cambio) {
        [$estado] = pedir('/api/guardar-producto.php', 'POST', json_encode(array_replace($producto, $cambio)), $headers);
        comprobar($estado === 422, 'campo inválido rechazado: ' . array_key_first($cambio));
    }
    comprobar((int) $admin->query('SELECT COUNT(*) FROM productos')->fetchColumn() === 4, 'las peticiones inválidas no escribieron datos');
    [$estado, $datos] = pedir('/api/guardar-producto.php', 'POST', json_encode($producto), $headers);
    comprobar($estado === 201 && $datos['id'] > 0, 'alta mediante INSERT preparado con stock cero');
    [$estado, $datos] = pedir('/api/buscador-seguro.php?q=' . rawurlencode("O'Reilly"));
    comprobar($estado === 200 && $datos['total'] === 1 && $datos['data'][0]['nombre'] === $producto['nombre'] && $datos['data'][0]['precio'] === '10.20', 'comillas, HTML y decimales se conservan como datos');
    $producto['nombre'] = "Prueba'); DROP TABLE productos; --";
    [$estado] = pedir('/api/guardar-producto.php', 'POST', json_encode($producto), $headers);
    comprobar($estado === 201 && (int) $admin->query('SELECT COUNT(*) FROM productos')->fetchColumn() === 6, 'la inyección en un INSERT se guarda como texto y no ejecuta SQL');
    echo "\n$checks verificaciones correctas. Se usó una base y un puerto temporales.\n";
} finally {
    if ($cliente) { curl_close($cliente); }
    // Se termina únicamente la instancia creada por esta misma prueba.
    if (is_resource($servidor)) { proc_terminate($servidor); proc_close($servidor); }
    if ($creada && preg_match('/\Aaa21_test_[a-f0-9]{12}\z/', $basePrueba)) {
        $admin->exec("DROP DATABASE `$basePrueba`");
    }
    // Los archivos temporales se conservan para revisar el log si hubo un error.
    if (is_file($temporal . '/app/config/local.php')) { unlink($temporal . '/app/config/local.php'); }
}
