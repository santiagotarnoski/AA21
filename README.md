# AA21 — Buscador y alta de productos con PDO y fetch

Módulo en PHP 8.1 o superior, MySQL/MariaDB y JavaScript Vanilla. Permite buscar productos por nombre o categoría y guardar nuevos productos sin recargar la página.

Repositorio de entrega: https://github.com/santiagotarnoski/AA21

## Ejecutar en XAMPP

1. Copiar esta carpeta como `C:\xampp\htdocs\buscador-seguro`.
2. Usar Apache y MySQL de XAMPP. Si ya están ejecutándose, no hace falta reiniciarlos.
3. En `http://localhost/phpmyadmin/`, seleccionar la base `clase_backend` y ejecutar `sql/productos.sql`. Crea la tabla de la consigna y agrega sus cuatro productos. Los INSERT se ejecutan una sola vez para no duplicar filas. No modifica la tabla `usuarios` del login.
4. Revisar `config/config.example.php`. Incluye los valores de desarrollo habituales: `127.0.0.1`, puerto `3306`, base `clase_backend`, usuario `root` y contraseña vacía.
5. Para otros datos, copiar `config/config.example.php` como `config/local.php` y editarlo. `local.php` está excluido de Git para no publicar credenciales. La carpeta config está protegida con `.htaccess` en Apache.
6. Abrir `http://localhost/buscador-seguro/`. Si Apache usa otro puerto, mantener ese puerto en la URL.

No abrir index.html con doble clic ni con Live Server: el frontend necesita el mismo servidor PHP para llamar a la API.

## Uso

- El catálogo carga los productos al abrir la página.
- Escribir un nombre o categoría activa la búsqueda; el botón Buscar también permite ejecutarla.
- Ver todos limpia la búsqueda y muestra hasta 100 productos.
- Agregar producto despliega un formulario con nombre, categoría, precio y stock.
- Guardar producto realiza un POST mediante fetch. Si el servidor lo acepta, se actualiza el catálogo.

## Análisis del ZIP inicial

El archivo `api/buscador-vulnerable.php` del ZIP usaba:

```php
$sql = "SELECT * FROM productos WHERE nombre LIKE '%" . $q . "%'";
$resultado = $pdo->query($sql);
```

Al concatenar `$q`, su contenido pasa a formar parte del código SQL. En una prueba local aislada, la búsqueda `x' OR 1=1 -- ` transformó la condición y devolvió los cuatro productos, aunque no existía un producto con ese nombre.

En `config/conexion.php` del ZIP, la asignación de `$host` quedó dentro de un comentario; además, las credenciales no coincidían con esta instalación. Los errores de PDO se devolvían al cliente con sus detalles internos. Se corrigieron estos puntos.

El frontend original ya utilizaba `textContent` para mostrar nombres. Se conserva ese criterio para que el texto de la base no se interprete como HTML.

## Corrección de la lectura

La nueva API separa el código SQL de los valores de búsqueda:

```php
$sql = "SELECT id, nombre, categoria, precio, stock FROM productos
        WHERE nombre LIKE :nombre ESCAPE '!' OR categoria LIKE :categoria ESCAPE '!'
        ORDER BY id DESC LIMIT 100";
$statement = $pdo->prepare($sql);
$statement->bindValue(':nombre', $patron, PDO::PARAM_STR);
$statement->bindValue(':categoria', $patron, PDO::PARAM_STR);
$statement->execute();
$productos = $statement->fetchAll();
```

La protección contra SQL Injection proviene de `prepare()` y los parámetros vinculados, no de borrar comillas ni de `htmlspecialchars()`. Los nombres de tabla y columnas son constantes, y las consultas preparadas emuladas están desactivadas.

También se escapan los caracteres de patrón `%`, `_` y `!` para buscarlos literalmente. Esto controla el comportamiento de LIKE; es independiente de la protección contra inyección. Los `%` agregados por el backend permiten la búsqueda parcial.

## Escritura y validaciones

`api/guardar-producto.php` inserta mediante otro PDOStatement:

```php
$statement = $pdo->prepare('INSERT INTO productos (nombre, categoria, precio, stock)
                           VALUES (:nombre, :categoria, :precio, :stock)');
```

Los valores se vinculan con `bindValue()`. El backend comprueba:

- Nombre obligatorio, hasta 100 caracteres; categoría obligatoria, hasta 50.
- Precio entre `0` y `99999999.99`, con hasta dos decimales. Se recibe como texto decimal para conservar la precisión de DECIMAL(10,2).
- Stock entero entre `0` y `2147483647`, incluyendo cero.
- Objeto JSON válido, cuerpo de hasta 8192 bytes y Content-Type application/json.
- Método POST y token CSRF perteneciente a la sesión, enviado en `X-CSRF-Token`.

La sesión usa cookies HttpOnly y SameSite=Lax; Secure se activa al usar HTTPS. El token se obtiene con la primera búsqueda. No hay CORS abierto ni acceso de escritura por GET.

Las respuestas son JSON con el estado HTTP correspondiente: 200 para lecturas, 201 para altas, 400/403/405/413/415/422 para peticiones rechazadas y 503 para fallos de base de datos. Los detalles internos se registran en el servidor.

Este módulo cubre lectura y alta de productos. Es un ejercicio local; no incluye autenticación ni permisos por usuario, edición o eliminación de productos.

## API

### GET api/buscador-seguro.php?q=teclado

Devuelve `estado`, `total`, `data` y `csrf_token`. Si q está vacío, lista hasta 100 productos. Acepta búsquedas de hasta 100 caracteres.

### POST api/guardar-producto.php

Enviar Content-Type application/json y X-CSRF-Token, conservando la cookie de sesión:

```json
{
  "nombre": "Parlante USB",
  "categoria": "Audio",
  "precio": "9500.00",
  "stock": 12
}
```

Devuelve `estado`, `mensaje` e `id`. El frontend usa `fetch`, revisa `response.ok` y muestra los resultados con `textContent`. Las búsquedas anteriores se cancelan para evitar mostrar una respuesta desactualizada.

## Archivos

```text
buscador-seguro/
├── index.html
├── js/app.js
├── css/styles.css
├── config/
│   ├── conexion.php
│   ├── config.example.php
│   └── .htaccess
├── api/
│   ├── comun.php
│   ├── buscador-seguro.php
│   └── guardar-producto.php
├── sql/productos.sql
├── tests/integracion.php
├── .gitignore
└── README.md
```

## Verificación

Desde la carpeta del proyecto:

```powershell
& 'C:\xampp\php\php.exe' 'tests\integracion.php'
```

La prueba crea una base con nombre aleatorio `aa21_test_...`, carga los cuatro ejemplos y levanta su propia instancia PHP en un puerto libre. Realiza 28 comprobaciones HTTP y SQL y luego elimina únicamente su base y termina únicamente su instancia. No modifica clase_backend ni detiene Apache o MySQL. La cuenta usada para esta prueba necesita permisos para crear y borrar esa base temporal.

Se comprueban la consulta vulnerable del ZIP, la búsqueda preparada, acentos, comillas, comodines literales, tipos inválidos, límites de longitud, JSON mal formado, métodos HTTP, CSRF, precisión decimal, stock cero, rechazo de escrituras inválidas y persistencia literal de intentos de inyección. Los archivos temporales conservan el log de prueba, pero se retira su archivo de credenciales.

También se revisaron la sintaxis PHP y JavaScript. Para repetir la revisión de JavaScript, ejecutar `node --check js/app.js` si Node está instalado.

## Referencias

- [PHP: PDO::prepare](https://www.php.net/manual/es/pdo.prepare.php)
- [MDN: uso de fetch](https://developer.mozilla.org/es/docs/Web/API/Fetch_API/Using_Fetch)
