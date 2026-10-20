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

function cookie_sesion(): string
{
    global $cliente;
    foreach (curl_getinfo($cliente, CURLINFO_COOKIELIST) as $cookie) {
        $partes = explode("\t", $cookie);
        if (($partes[5] ?? '') === 'buscador_seguro') { return $partes[6]; }
    }
    return '';
}

function pedir_visitante(string $ruta, array $headers = [], string $metodo = 'GET', ?string $cuerpo = null): array
{
    global $cliente;
    $principal = $cliente;
    $cliente = curl_init();
    curl_setopt_array($cliente, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 5]);
    try { return pedir($ruta, $metodo, $cuerpo, $headers); }
    finally { curl_close($cliente); $cliente = $principal; }
}

try {
    // El identificador es generado aquí, no proviene de una petición del usuario.
    $admin->exec("CREATE DATABASE `$basePrueba` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $creada = true;
    $admin->exec("USE `$basePrueba`");
    foreach (['productos', 'usuarios'] as $tabla) {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents($proyecto . '/sql/' . $tabla . '.sql'));
        foreach (explode(';', $sql) as $sentencia) {
            if (trim($sentencia) !== '') { $admin->exec($sentencia); }
        }
    }
    comprobar($admin->query("SHOW COLUMNS FROM usuarios LIKE 'password'")->fetch(PDO::FETCH_ASSOC)['Type'] === 'varchar(255)', 'usuarios.password es VARCHAR(255) para guardar BCRYPT');
    $admin->prepare('INSERT INTO usuarios (nombre, email, password) VALUES (?, ?, ?)')
        ->execute(['Usuario anterior', 'anterior@example.test', '12345678']);

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
    comprobar($estado === 200 && $datos['total'] === 4, 'catálogo público disponible sin sesión');
    [$estado, $datos] = pedir('/api/perfil.php');
    comprobar($estado === 401 && strlen($datos['csrf_token']) === 64 && !isset($datos['usuario']), 'perfil privado devuelve 401 y token para el formulario');
    $csrfInvitado = $datos['csrf_token'];
    $headersInvitado = ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrfInvitado];
    $cookieInvitado = cookie_sesion();
    comprobar($cookieInvitado !== '', 'la sesión usa una cookie propia del buscador');
    [$estado] = pedir('/api/guardar-producto.php', 'POST', '{"nombre":"No autorizado"}', $headersInvitado);
    comprobar($estado === 401, 'un visitante no puede guardar productos aunque tenga un token CSRF');
    [$estado] = pedir('/api/registro.php');
    comprobar($estado === 405, 'registro exige POST');
    [$estado] = pedir('/api/registro.php', 'POST', '{}', ['Content-Type: application/json']);
    comprobar($estado === 403, 'registro exige token CSRF');
    $cuenta = ['nombre' => 'Ana <img src=x onerror=alert(1)>', 'email' => 'ana@example.test', 'password' => 'Clave segura 123'];
    foreach ([['password' => 'corta'], ['password' => str_repeat('a', 73)], ['password' => "clave\0segura"], ['nombre' => []], ['email' => 'correo-invalido']] as $cambio) {
        [$estado] = pedir('/api/registro.php', 'POST', json_encode(array_replace($cuenta, $cambio)), $headersInvitado);
        comprobar($estado === 422, 'registro rechaza un campo inválido: ' . array_key_first($cambio));
    }
    [$estado] = pedir('/api/registro.php', 'POST', json_encode($cuenta), $headersInvitado);
    comprobar($estado === 201, 'registro JSON crea la cuenta');
    $consultaCuenta = $admin->prepare('SELECT id, nombre, password FROM usuarios WHERE email = ?');
    $consultaCuenta->execute([$cuenta['email']]);
    $registrado = $consultaCuenta->fetch(PDO::FETCH_ASSOC);
    comprobar(password_get_info($registrado['password'])['algoName'] === 'bcrypt' && password_verify($cuenta['password'], $registrado['password']) && $registrado['password'] !== $cuenta['password'], 'MySQL guarda BCRYPT verificable y nunca la contraseña original');
    [$estado] = pedir('/api/registro.php', 'POST', json_encode($cuenta), $headersInvitado);
    comprobar($estado === 409, 'registro rechaza un correo duplicado');
    [$estado] = pedir('/api/perfil.php');
    comprobar($estado === 401, 'registrarse no concede una sesión sin pasar por el login');
    [$estado, $falloConocido] = pedir('/api/login.php', 'POST', json_encode(['email' => $cuenta['email'], 'password' => 'incorrecta']), $headersInvitado);
    comprobar($estado === 401, 'contraseña incorrecta rechazada');
    [$estado, $falloDesconocido] = pedir('/api/login.php', 'POST', json_encode(['email' => 'inexistente@example.test', 'password' => 'incorrecta']), $headersInvitado);
    comprobar($estado === 401 && $falloConocido['mensaje'] === $falloDesconocido['mensaje'], 'login no revela si el correo existe');
    [$estado] = pedir('/api/login.php', 'POST', json_encode(['email' => 'anterior@example.test', 'password' => '12345678']), $headersInvitado);
    comprobar($estado === 401 && $admin->query("SELECT password FROM usuarios WHERE email = 'anterior@example.test'")->fetchColumn() === '12345678', 'las cuentas antiguas en texto no acceden y sus datos se conservan');
    [$estado] = pedir('/api/login.php', 'POST', json_encode(['email' => $cuenta['email'], 'password' => $registrado['password']]), $headersInvitado);
    comprobar($estado === 401, 'conocer el hash no permite usarlo como contraseña');
    [$estado] = pedir('/api/login.php', 'POST', json_encode(['email' => $cuenta['email'], 'password' => $cuenta['password']]), $headersInvitado);
    comprobar($estado === 200, 'login correcto con password_verify');
    $cookieAutenticada = cookie_sesion();
    comprobar($cookieAutenticada !== '' && $cookieAutenticada !== $cookieInvitado, 'el login regenera el identificador de sesión');
    $contenidoSesion = file_get_contents($temporal . '/sesiones/sess_' . $cookieAutenticada);
    comprobar(str_contains($contenidoSesion, 'usuario_id|i:' . $registrado['id'] . ';'), 'el identificador del usuario queda guardado en la sesión del servidor');
    [$estado, $datos] = pedir('/api/perfil.php');
    comprobar($estado === 200 && $datos['usuario']['nombre'] === $cuenta['nombre'] && $datos['usuario']['email'] === $cuenta['email'] && !isset($datos['usuario']['password']), 'perfil autenticado devuelve nombre y correo como datos sin hashes');
    comprobar($datos['csrf_token'] !== $csrfInvitado, 'el login renueva también el token CSRF');
    $headers = ['Content-Type: application/json', 'X-CSRF-Token: ' . $datos['csrf_token']];
    [$estado, $perfilRecuperado] = pedir('/api/perfil.php');
    comprobar($estado === 200 && $perfilRecuperado['usuario'] === $datos['usuario'], 'la sesión recupera el perfil completo en una nueva petición');
    [$estado] = pedir('/api/perfil.php', 'POST', '{}', $headers);
    comprobar($estado === 405, 'el perfil solo acepta consultas GET');
    [$estado] = pedir_visitante('/api/perfil.php', ['Cookie: buscador_seguro=' . $cookieInvitado]);
    comprobar($estado === 401, 'la cookie anterior al login no da acceso al perfil');
    $cookieAnterior = bin2hex(random_bytes(16));
    file_put_contents($temporal . '/sesiones/sess_' . $cookieAnterior, 'usuario_id|i:' . $registrado['id'] . ';');
    [$estado] = pedir_visitante('/api/perfil.php', ['Cookie: PHPSESSID=' . $cookieAnterior]);
    comprobar($estado === 401, 'una sesión del login anterior no autentica en el buscador');
    [$estado] = pedir_visitante('/api/guardar-producto.php', $headers, 'POST', '{"nombre":"Sin sesión"}');
    comprobar($estado === 401, 'un token conocido sin la cookie de sesión no autoriza escrituras');
    [$estado] = pedir('/api/guardar-producto.php', 'POST', '{}', $headersInvitado);
    comprobar($estado === 403, 'el token anterior al login deja de ser válido');
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
    [$estado] = pedir('/api/logout.php');
    comprobar($estado === 405, 'el logout no se ejecuta mediante GET');
    [$estado] = pedir('/api/logout.php', 'POST', '{}', ['Content-Type: application/json']);
    comprobar($estado === 403, 'logout exige CSRF');
    [$estado] = pedir('/api/logout.php', 'POST', '{}', $headers);
    comprobar($estado === 200, 'cierre de sesión correcto');
    [$estado] = pedir('/api/perfil.php');
    comprobar($estado === 401, 'el perfil vuelve a responder 401 después del logout');
    [$estado] = pedir('/api/guardar-producto.php', 'POST', json_encode($producto), $headers);
    comprobar($estado === 401, 'el alta vuelve a estar protegida después del logout');
    [$estado] = pedir_visitante('/api/perfil.php', ['Cookie: buscador_seguro=' . $cookieAutenticada]);
    comprobar($estado === 401, 'no se puede reutilizar la cookie autenticada después del logout');
    [$estado, $datos] = pedir('/api/buscador-seguro.php');
    comprobar($estado === 200 && $datos['total'] === 6, 'el catálogo público continúa funcionando sin sesión');
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
