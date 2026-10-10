# Accesibilidad · WCAG 2.1 AA (CG-14)

Alcance: launcher, panel de chat, mensajes y streaming, formularios, confirmaciones, resultados (en el
panel y en el contenedor de la página), errores y la consola de administración. Las páginas del portal
anfitrión son responsabilidad del portal.

## Evidencias automatizadas

| Prueba | Qué comprueba |
| --- | --- |
| `apps/widget/e2e/widget.spec.ts` (Playwright + axe-core, etiquetas `wcag2a/aa`, `wcag21a/aa`) | Cero violaciones con el chat cerrado, abierto, con resultados ricos, con el diálogo de confirmación y en la página de CSS agresivo; flujo completo solo con teclado; foco inicial, retorno del foco y cierre con Escape |
| `apps/widget/e2e/mobile.spec.ts` | Móvil: panel a pantalla completa, sin desplazamiento horizontal, objetivos táctiles ≥ 44 px y axe |
| `apps/widget/tests/a11y-events.test.ts` | Anuncios únicos en regiones vivas; diálogo `alertdialog` con foco en *Cancelar*, Escape y retorno del foco |
| `apps/widget/tests/chat.test.ts` | `aria-expanded`, `role="dialog"`, etiqueta del campo, foco al abrir y al cerrar |
| `apps/admin/e2e/console.spec.ts` | Cero violaciones axe en el login y en las 15 secciones de la consola; foco en el `h1` al navegar; errores de formulario asociados a cada campo |
| CI (`e2e` en Chromium, Firefox y WebKit) | Repite las pruebas en los tres motores |

## Checklist trazable

Estado: ✅ cumplido con evidencia automatizada o de diseño; 🔎 requiere confirmación en la revisión
manual (§ Revisión manual).

| Criterio | Cómo se cumple | Componentes | Estado |
| --- | --- | --- | --- |
| 1.1.1 Contenido no textual | Imágenes de producto con `alt` del producto; logo decorativo `alt=""`; iconos `aria-hidden` con nombre accesible en el botón | renderers, cabecera | ✅ |
| 1.3.1 Información y relaciones | Lista `ol` de mensajes con autor accesible, `article`/`h3`/`h4` en resultados, `dl` en detalles, tablas con `th scope`, `label for` en campos, `fieldset/legend` | widget, consola | ✅ |
| 1.3.2 Secuencia con significado | Orden del DOM = orden visual; el diálogo se inserta al final con foco gestionado | widget | ✅ |
| 1.3.4 Orientación | Sin bloqueo de orientación; panel a pantalla completa en móvil | widget | ✅ |
| 1.3.5 Propósito de los campos | `autocomplete` en login (`username`, `current-password`) y contraseñas nuevas | consola | ✅ |
| 1.4.1 Uso del color | Estados con texto (badges, "Cita verificada", mensajes) además del color | widget, consola | ✅ |
| 1.4.3 Contraste mínimo | Paleta por defecto ≥ 4,5:1; los temas se validan en el servidor (contraste AA) y axe lo comprueba | tema, consola | ✅ |
| 1.4.4 Redimensionar texto | Unidades relativas a 16 px base; reflow sin pérdida al 200 % | widget, consola | 🔎 |
| 1.4.5 Imágenes de texto | No se usan | — | ✅ |
| 1.4.10 Reflow | Sin scroll horizontal a 320 px (prueba móvil); tablas de la consola con desplazamiento propio y foco | widget, consola | ✅ |
| 1.4.11 Contraste no textual | Bordes de campos #8a8580 (≥ 3:1), foco de 3 px en `--wa-highlight` | widget, consola | ✅ |
| 1.4.12 Espaciado del texto | Sin alturas fijas en burbujas ni tarjetas; `overflow-wrap: anywhere` | widget | 🔎 |
| 1.4.13 Contenido en hover/foco | No hay tooltips ni contenido que aparezca al pasar el ratón | — | ✅ |
| 2.1.1 Teclado | Todo es operable con teclado (prueba E2E sin ratón); Enter envía, Mayús+Enter salto de línea | widget, consola | ✅ |
| 2.1.2 Sin trampas de teclado | El panel es no modal; el diálogo de confirmación atrapa el foco y se cierra con Escape | widget | ✅ |
| 2.1.4 Atajos de una tecla | No se definen | — | ✅ |
| 2.2.1 Tiempo ajustable | La sesión se renueva sola; las confirmaciones caducan a los 2 min pero se pueden repetir | widget | ✅ |
| 2.2.2 Pausar, detener, ocultar | El indicador de carga se detiene al terminar y respeta `prefers-reduced-motion`; *Detener* cancela la respuesta | widget | ✅ |
| 2.3.1 Destellos | Sin destellos | — | ✅ |
| 2.4.1 Evitar bloques | Enlace "Saltar al contenido" en la consola y la demo; el widget es un único bloque al final | consola, demo | ✅ |
| 2.4.2 Título de página | `document.title` por sección en la consola | consola | ✅ |
| 2.4.3 Orden del foco | Foco al campo al abrir, al launcher al cerrar, al `h1` al navegar en la consola, a *Cancelar* en confirmaciones | widget, consola | ✅ |
| 2.4.4 Propósito de los enlaces | Enlaces con el título del producto/documento; los externos anuncian "se abre en una pestaña nueva" | renderers | ✅ |
| 2.4.6 Encabezados y etiquetas | Título del panel, encabezados de resultados, etiquetas visibles | widget, consola | ✅ |
| 2.4.7 Foco visible | `:focus-visible` de 3 px con contraste; `forced-colors` soportado | widget, consola | ✅ |
| 2.5.3 Etiqueta en el nombre | Los nombres accesibles contienen el texto visible ("Añadir al carrito: <producto>") | renderers | ✅ |
| 2.5.5 Tamaño del objetivo (AAA, aplicado) | Controles ≥ 44 px en el widget | widget | ✅ |
| 3.1.1 Idioma de la página | `lang` en el panel según el locale del assistant | widget | ✅ |
| 3.2.1 / 3.2.2 Al recibir foco / entrada | Sin cambios de contexto automáticos; enviar requiere acción explícita | widget, consola | ✅ |
| 3.3.1 Identificación de errores | Errores en texto, anunciados (región asertiva) y, en formularios, asociados al campo con `aria-invalid` | widget, consola | ✅ |
| 3.3.2 Etiquetas o instrucciones | Etiqueta visible, contador de caracteres y aviso de privacidad asociados con `aria-describedby` | widget, consola | ✅ |
| 3.3.3 Sugerencias ante errores | Mensajes de validación concretos por campo (servidor y cliente) | consola | ✅ |
| 3.3.4 Prevención de errores | Las acciones con efectos piden confirmación con resumen de parámetros; importaciones con simulación previa | widget, consola | ✅ |
| 4.1.1 Procesamiento | DOM generado por API del navegador (sin HTML concatenado), ids únicos (`uid`) | widget, consola | ✅ |
| 4.1.2 Nombre, función, valor | `aria-expanded`/`aria-controls` en el launcher, `role="dialog"`/`alertdialog`, `aria-busy` durante el streaming | widget | ✅ |
| 4.1.3 Mensajes de estado | Regiones `polite`/`assertive` propias del widget; cada cambio relevante se anuncia una vez (deduplicación) | widget, consola | ✅ |

## Decisiones de diseño

- El streaming no se lee token a token: la burbuja tiene `aria-busy` y al terminar se anuncia una sola
  vez "El asistente ha respondido: …" (resumen) en la región `polite`. Los estados intermedios
  (*Pensando*, *Buscando información*) se anuncian una vez cada uno.
- Los errores se anuncian en la región `assertive` y quedan como mensaje en la conversación.
- Los resultados en el contenedor de la página son una región con nombre ("Resultado del asistente") y
  el chat ofrece *Ver resultado* para llevar el foco allí.
- La auditoría del módulo Drupal sirve de regresión mínima: sin ids duplicados, sin controles ocultos
  enfocables, sin botones sin nombre, sin contraste insuficiente y sin resultados dinámicos sin anuncio.

## Revisión manual

La revisión manual con tecnologías de apoyo es obligatoria antes de la release (CG-14.8) y no puede
sustituirse por las pruebas automáticas. Protocolo:

| Entorno | Flujos |
| --- | --- |
| NVDA + Firefox/Chrome (Windows) | Abrir, enviar, escuchar streaming y respuesta, recorrer resultados, confirmar una acción, error de red, nuevo chat |
| VoiceOver + Safari (macOS e iOS) | Los mismos flujos; rotor de encabezados y enlaces en resultados |
| TalkBack + Chrome (Android) | Abrir, enviar, resultado y confirmación |
| Zoom 200 % y 400 %, espaciado de texto (bookmarklet WCAG 1.4.12) | Lectura y operación sin pérdida de contenido |
| `prefers-reduced-motion`, alto contraste de Windows | Indicadores y foco visibles |

Registro: hallazgo, criterio, severidad (bloqueante, alta, media, baja), evidencia (captura/vídeo),
componente y corrección. Cada corrección añade un caso de regresión automatizado cuando sea posible. El
checklist se firma para la release cuando no quedan hallazgos bloqueantes ni altos.

| Fecha | Revisor | Entorno | Resultado |
| --- | --- | --- | --- |
| _pendiente_ | | | Requiere la revisión manual con lectores de pantalla (no automatizable en CI) |
