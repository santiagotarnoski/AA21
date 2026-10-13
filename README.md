# AA21 - Buscador de productos

El trabajo consistió en adaptar el buscador entregado en clase para consultar y guardar productos en MySQL desde PHP. Se utilizó la base `clase_backend` y la tabla `productos`, con los campos nombre, categoría, precio y stock.

El buscador original concatenaba el texto ingresado dentro de la consulta SQL, lo que permitía modificarla mediante SQL Injection. Se reemplazó esa concatenación por consultas preparadas con PDO, usando `prepare()`, `bindValue()` y `execute()`. De esta manera, lo ingresado se procesa como un dato y no como parte del código SQL. También se corrigió la configuración de la conexión.

El frontend utiliza JavaScript Vanilla con `fetch` para enviar las búsquedas y mostrar los resultados sin recargar la página. Permite buscar por nombre o categoría y muestra el precio y el stock de cada producto.

Se agregó un formulario para cargar productos mediante un INSERT preparado. El backend valida los campos obligatorios, la longitud de los textos, el precio y que el stock sea un entero no negativo. Las escrituras incluyen un token CSRF y los resultados se muestran con `textContent` para evitar interpretar los datos como HTML.

Se probaron búsquedas, altas y datos inválidos. También se comprobó que las comillas y los intentos de inyección se trataran como texto, sin alterar las consultas ni eliminar la tabla.
