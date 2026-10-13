<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function error_api(string $mensaje, int $codigo): never
{
    responder(['estado' => 'error', 'mensaje' => $mensaje], $codigo);
}

function iniciar_sesion_api(): string
{
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    if (empty($_SESSION['productos_csrf'])) {
        $_SESSION['productos_csrf'] = bin2hex(random_bytes(32));
    }
    $token = $_SESSION['productos_csrf'];
    session_write_close();
    return $token;
}
