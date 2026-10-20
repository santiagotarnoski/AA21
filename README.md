# AA21 y AA23 - Buscador de productos con autenticación

En AA21 adapté el buscador entregado en clase para consultar y guardar productos en MySQL desde PHP. Usé la base `clase_backend` y la tabla `productos`, con nombre, categoría, precio y stock. Reemplacé la concatenación de SQL por consultas preparadas con PDO para que las búsquedas y las altas trataran lo ingresado como datos.

Para AA23 continué ese mismo buscador e incorporé registro, inicio y cierre de sesión. La tabla `usuarios` tiene `password VARCHAR(255)`. El registro recibe JSON, valida los campos y guarda la contraseña con `password_hash()` y `PASSWORD_BCRYPT` mediante un INSERT preparado. El login usa `password_verify()`, inicia la sesión con `session_start()`, guarda `$_SESSION['usuario_id']` y renueva el identificador con `session_regenerate_id(true)`.

El perfil (`api/perfil.php`) y el alta de productos (`api/guardar-producto.php`) requieren una sesión válida y responden HTTP 401 si el usuario no ingresó. El catálogo sigue disponible para consultar productos. Las escrituras incluyen un token CSRF y el cierre de sesión invalida la cookie.

La interfaz usa JavaScript Vanilla y `fetch`. Al cargar la página consulta el perfil y muestra los formularios de acceso o los datos del usuario y el formulario de alta según la sesión. El nombre, el correo y los productos se muestran con `textContent` para prevenir XSS. La búsqueda por nombre o categoría y las altas funcionan sin recargar la página.

Probé el registro, los correos duplicados, las contraseñas incorrectas, la recuperación del perfil y el logout, además de las búsquedas y altas del catálogo. Verifiqué los hashes BCRYPT, la renovación de la sesión y el rechazo de accesos sin autorización. Los usuarios antiguos se conservan; las contraseñas guardadas en texto no se aceptan en este login.
