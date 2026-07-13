# PROMIN — Sitio web

Sitio web institucional de **ProMin Consultoria** (consultoría en minería y canteras, BR/PR), desarrollado por **Neura**.

## Stack

Sitio estático construido sobre un sistema de componentes propio (**"dc"**):

- Cada página es un archivo `Nombre.dc.html` (un componente React `class Component extends DCLogic` embebido en un `<script type="text/x-dc">`).
- La UI compartida se incluye con `<dc-import name="Nav" />` / `<dc-import name="Footer" />`.
- Runtime del framework: [`support.js`](support.js).
- Motor de animaciones + capa responsive/adaptive: [`site.js`](site.js) (`initMotion()`).
- **Bilingüe PT/ES**: el idioma se guarda en `localStorage['promin_lang']`; cada componente resuelve un diccionario `pt` / `es`.

## Páginas

| Archivo | Ruta |
|---|---|
| `Inicio.dc.html` | Home (portada + secciones) |
| `Sobre.dc.html` | Nosotros |
| `Servicos.dc.html` | Servicios |
| `Areas.dc.html` | Áreas de actuación |
| `Processo.dc.html` | Proceso |
| `Contato.dc.html` | Contacto |
| `privacidad.dc.html` | Política de Privacidad (estándar Neura) |

`Nav.dc.html`, `Footer.dc.html` y `CtaBand.dc.html` son componentes compartidos.

## Ejecutar en local

Las páginas cargan componentes vía `fetch`, por lo que **requiere un servidor HTTP** (no funciona abriendo el archivo con `file://`):

```bash
npx http-server -p 8123 -c-1
# luego abrir http://localhost:8123/Inicio.dc.html
```

## Política de Privacidad (estándar Neura)

`privacidad.dc.html` es una plantilla reutilizable por cliente. Para adaptarla a otro proyecto se edita **únicamente** el objeto `company` (nombre, RUC/CNPJ, correo, teléfono, dirección) dentro de su `<script>`. Los campos que se dejen vacíos se ocultan automáticamente.

Marco legal: el **cliente** es el responsable del tratamiento de los datos; **Neura** figura solo como proveedor tecnológico / desarrollador (encargado técnico), no como propietario de los datos.

## Diseño

- Color de marca: `#DE694E` (naranja) sobre fondos oscuros `#1A2026` / `#12171C`.
- Tipografías: Sora + JetBrains Mono.
- Adaptive/responsive: los grids colapsan a una columna ≤900px; capa de ajustes de diseño para teléfono en `site.js`.

---

Desarrollado por **Neura**.
