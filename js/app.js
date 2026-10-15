'use strict';

const formBuscar = document.querySelector('#form-buscar');
const inputBuscar = document.querySelector('#input-buscar');
const resultados = document.querySelector('#resultados');
const estadoBusqueda = document.querySelector('#estado-busqueda');
const formProducto = document.querySelector('#form-producto');
const botonGuardar = document.querySelector('#guardar-producto');
const estadoProducto = document.querySelector('#estado-producto');
const panelInvitado = document.querySelector('#panel-invitado');
const panelUsuario = document.querySelector('#panel-usuario');
const panelProducto = document.querySelector('#panel-producto');
const estadoSesion = document.querySelector('#estado-sesion');
const estadoAuth = document.querySelector('#estado-auth');
const formLogin = document.querySelector('#form-login');
const formRegistro = document.querySelector('#form-registro');
const botonLogout = document.querySelector('#cerrar-sesion');
const moneda = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });
let csrfToken = '';
let peticionBusqueda;
let guardando = false;
let temporizador;
let usuarioActual = null;
let autenticando = false;
let sesionComprobada = false;

async function leerRespuesta(respuesta) {
    const datos = await respuesta.json();
    if (!respuesta.ok || datos.estado !== 'exito') {
        const error = new Error(datos.mensaje || 'No se pudo completar la operación.');
        error.codigo = respuesta.status;
        throw error;
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
    if (!csrfToken || !usuarioActual || guardando) return;
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
        if (error.codigo === 401 || error.codigo === 403) await comprobarSesion();
        estadoProducto.className = 'error';
        estadoProducto.textContent = error instanceof TypeError ? 'No se pudo conectar con el servidor.' : error.message;
    } finally {
        guardando = false;
        actualizarControles();
    }
});

function actualizarControles() {
    panelInvitado.hidden = !sesionComprobada || Boolean(usuarioActual);
    panelUsuario.hidden = !usuarioActual;
    panelProducto.hidden = !usuarioActual;
    document.querySelector('#nombre-usuario').textContent = usuarioActual?.nombre ?? '';
    document.querySelector('#email-usuario').textContent = usuarioActual?.email ?? '';
    formLogin.querySelector('button').disabled = autenticando || !csrfToken;
    formRegistro.querySelector('button').disabled = autenticando || !csrfToken;
    botonLogout.disabled = autenticando;
    botonGuardar.disabled = guardando || autenticando || !usuarioActual || !csrfToken;
    if (!usuarioActual) {
        panelProducto.open = false;
        formProducto.reset();
    }
}

async function comprobarSesion() {
    try {
        const respuesta = await fetch('api/perfil.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (respuesta.status === 401) {
            const datos = await respuesta.json();
            usuarioActual = null;
            csrfToken = datos.csrf_token || '';
            estadoSesion.textContent = 'Podés consultar el catálogo. Iniciá sesión para agregar productos.';
        } else {
            const datos = await leerRespuesta(respuesta);
            usuarioActual = datos.usuario;
            csrfToken = datos.csrf_token;
            estadoSesion.textContent = 'Tu cuenta';
        }
        estadoSesion.className = '';
        return true;
    } catch (error) {
        usuarioActual = null;
        csrfToken = '';
        estadoSesion.className = 'error';
        estadoSesion.textContent = 'No se pudo comprobar la sesión. Actualizá la página para intentar otra vez.';
        return false;
    } finally {
        sesionComprobada = true;
        actualizarControles();
    }
}

async function enviarJson(endpoint, datos) {
    const respuesta = await fetch(`api/${endpoint}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken, Accept: 'application/json' },
        body: JSON.stringify(datos),
    });
    return leerRespuesta(respuesta);
}

formRegistro.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (autenticando || !csrfToken) return;
    autenticando = true;
    actualizarControles();
    estadoAuth.className = '';
    estadoAuth.textContent = 'Creando cuenta…';
    const datos = Object.fromEntries(new FormData(formRegistro));
    try {
        const resultado = await enviarJson('registro.php', datos);
        formRegistro.reset();
        formLogin.elements.namedItem('email').value = datos.email.trim();
        document.querySelector('#registro-cuenta').open = false;
        estadoAuth.className = 'exito';
        estadoAuth.textContent = resultado.mensaje;
        formLogin.elements.namedItem('password').focus();
    } catch (error) {
        if (error.codigo === 403) await comprobarSesion();
        estadoAuth.className = 'error';
        estadoAuth.textContent = error instanceof TypeError ? 'No se pudo conectar con el servidor.' : error.message;
    } finally {
        autenticando = false;
        actualizarControles();
    }
});

formLogin.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (autenticando || !csrfToken) return;
    autenticando = true;
    actualizarControles();
    estadoAuth.className = '';
    estadoAuth.textContent = 'Iniciando sesión…';
    try {
        await enviarJson('login.php', Object.fromEntries(new FormData(formLogin)));
        formLogin.reset();
        formRegistro.reset();
        estadoAuth.textContent = '';
        await comprobarSesion();
    } catch (error) {
        if (error.codigo === 403) await comprobarSesion();
        estadoAuth.className = 'error';
        estadoAuth.textContent = error instanceof TypeError ? 'No se pudo conectar con el servidor.' : error.message;
    } finally {
        autenticando = false;
        actualizarControles();
    }
});

botonLogout.addEventListener('click', async () => {
    if (autenticando || !usuarioActual) return;
    autenticando = true;
    actualizarControles();
    try {
        await enviarJson('logout.php', {});
        formProducto.reset();
        estadoProducto.textContent = '';
        estadoAuth.textContent = '';
        await comprobarSesion();
    } catch (error) {
        if (error.codigo === 401 || error.codigo === 403) await comprobarSesion();
        estadoSesion.className = 'error';
        estadoSesion.textContent = error instanceof TypeError ? 'No se pudo conectar con el servidor.' : error.message;
    } finally {
        autenticando = false;
        actualizarControles();
    }
});

async function iniciar() {
    // El perfil es privado: 401 identifica al visitante y 200 a una sesión válida.
    await comprobarSesion();
    await buscar();
}
iniciar();
