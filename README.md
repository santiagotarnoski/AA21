# AA21 - Buscador de productos con autenticación

El trabajo consistió en adaptar el buscador entregado en clase para consultar y guardar productos en MySQL desde PHP. Se utilizó la base `clase_backend` y la tabla `productos`, con los campos nombre, categoría, precio y stock.

El buscador original concatenaba el texto ingresado dentro de la consulta SQL, lo que permitía modificarla mediante SQL Injection. Se reemplazó esa concatenación por consultas preparadas con PDO, usando `prepare()`, `bindValue()` y `execute()`. De esta manera, lo ingresado se procesa como un dato y no como parte del código SQL. También se corrigió la configuración de la conexión.

El frontend utiliza JavaScript Vanilla con `fetch` para enviar las búsquedas y mostrar los resultados sin recargar la página. Permite buscar por nombre o categoría y muestra el precio y el stock de cada producto.

Se agregó un formulario para cargar productos mediante un INSERT preparado. El backend valida los campos obligatorios, la longitud de los textos, el precio y que el stock sea un entero no negativo. Las escrituras incluyen un token CSRF.

La autenticación se incorporó con `api/registro.php` y `api/login.php`. El registro recibe JSON y guarda la contraseña con `password_hash()` y `PASSWORD_BCRYPT` en un campo `VARCHAR(255)`. El login la comprueba con `password_verify()`, guarda el identificador del usuario en la sesión y renueva su identificador con `session_regenerate_id(true)`. Las contraseñas antiguas guardadas en texto se conservaron para el ejercicio anterior y no se aceptan en este login.

El perfil y el alta de productos requieren una sesión válida y responden 401 cuando no la hay. Al abrir la página, JavaScript consulta el perfil mediante `fetch` y muestra los formularios de ingreso o las acciones del usuario según la respuesta. También se agregó el cierre de sesión. Los nombres, correos y productos se muestran con `textContent` para evitar que su contenido se interprete como HTML.

Se probaron las búsquedas, las altas, el registro, el login y el cierre de sesión. Se verificaron los hashes BCRYPT, la renovación de la cookie, el rechazo de accesos sin sesión y de datos inválidos, y que las comillas y los intentos de inyección se trataran como texto.
