'use strict';

const formBuscar = document.querySelector('#form-buscar');
const inputBuscar = document.querySelector('#input-buscar');
const resultados = document.querySelector('#resultados');
const estadoBusqueda = document.querySelector('#estado-busqueda');
const formProducto = document.querySelector('#form-producto');
const botonGuardar = document.querySelector('#guardar-producto');
const estadoProducto = document.querySelector('#estado-producto');
const moneda = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });
let csrfToken = '';
let peticionBusqueda;
let guardando = false;
let temporizador;

async function leerRespuesta(respuesta) {
    const datos = await respuesta.json();
    if (!respuesta.ok || datos.estado !== 'exito') {
        throw new Error(datos.mensaje || 'No se pudo completar la operación.');
    }
    return datos;
}

function mostrarProductos(productos) {
    const fragmento = document.createDocumentFragment();
    for (const producto of productos) {
        const tarjeta = document.createElement('article');
        tarjeta.className = 'card-producto';
        const nombre = document.createElement('h2');
        nombre.textContent = producto.nombre;
        const categoria = document.createElement('p');
        categoria.className = 'categoria';
        categoria.textContent = producto.categoria;
        const precio = document.createElement('p');
        precio.className = 'precio';
        precio.textContent = moneda.format(Number(producto.precio));
        const stock = document.createElement('p');
        stock.textContent = `Stock: ${producto.stock}`;
        tarjeta.append(nombre, categoria, precio, stock);
        fragmento.append(tarjeta);
    }
    // textContent evita interpretar como HTML los valores recibidos de MySQL.
    resultados.replaceChildren(fragmento);
}

async function buscar() {
    peticionBusqueda?.abort();
    const peticion = new AbortController();
    peticionBusqueda = peticion;
    estadoBusqueda.textContent = 'Buscando productos…';
    resultados.setAttribute('aria-busy', 'true');
    try {
        const parametros = new URLSearchParams({ q: inputBuscar.value.trim() });
        const respuesta = await fetch(`api/buscador-seguro.php?${parametros}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: peticion.signal,
        });
        const datos = await leerRespuesta(respuesta);
        if (peticion.signal.aborted) return;
        csrfToken = datos.csrf_token;
        botonGuardar.disabled = guardando;
        mostrarProductos(datos.data);
        estadoBusqueda.textContent = datos.total === 0 ? 'No se encontraron productos.'
            : `${datos.total} producto${datos.total === 1 ? '' : 's'} encontrado${datos.total === 1 ? '' : 's'} (máximo 100).`;
        estadoBusqueda.className = '';
    } catch (error) {
        if (peticion.signal.aborted) return;
        resultados.replaceChildren();
        estadoBusqueda.className = 'error';
        estadoBusqueda.textContent = error instanceof TypeError ? 'No se pudo conectar con el servidor.' : error.message;
    } finally {
        if (peticionBusqueda === peticion) resultados.setAttribute('aria-busy', 'false');
    }
}

formBuscar.addEventListener('submit', (evento) => {
    evento.preventDefault();
    clearTimeout(temporizador);
    buscar();
});
inputBuscar.addEventListener('input', () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(buscar, 300);
});
document.querySelector('#mostrar-todos').addEventListener('click', () => {
    inputBuscar.value = '';
    clearTimeout(temporizador);
    buscar();
});

formProducto.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!csrfToken || guardando) return;
    const datos = Object.fromEntries(new FormData(formProducto));
    guardando = true;
    botonGuardar.disabled = true;
    estadoProducto.className = '';
    estadoProducto.textContent = 'Guardando producto…';
    try {
        const respuesta = await fetch('api/guardar-producto.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken, Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(datos),
        });
        const resultado = await leerRespuesta(respuesta);
        estadoProducto.textContent = resultado.mensaje;
        estadoProducto.className = 'exito';
        formProducto.reset();
        inputBuscar.value = '';
        clearTimeout(temporizador);
        await buscar();
    } catch (error) {
        estadoProducto.className = 'error';
        estadoProducto.textContent = error instanceof TypeError ? 'No se pudo conectar con el servidor.' : error.message;
    } finally {
        guardando = false;
        botonGuardar.disabled = !csrfToken;
    }
});

buscar();
