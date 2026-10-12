# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Sobre todo clientes: personas no técnicas, dueñas de una web hecha con WordPress, que crean y gestionan sus formularios por su cuenta y entran a ver lo que les han escrito. No conocen la jerga (shortcode, webhook, SMTP, captcha) y nadie les acompaña mientras lo usan (confirmado por el usuario el 2026-10-09).

El segundo público es quien instala y mantiene el plugin: el propio autor o un técnico de la agencia. Configura el correo, el antispam y las integraciones, y deja la web lista para el cliente.

## Product Purpose

Constructor de formularios para WordPress, ligero y auditable, para webs propias y de clientes. Existe porque los plugins de formularios habituales pesan, cargan recursos en todas las páginas y llenan el panel de avisos y ventas.

Funciona bien cuando el cliente monta un formulario, lo publica y lee sus mensajes sin pedir ayuda, y cuando ningún mensaje se pierde sin que alguien se entere.

## Positioning

Lo que otro plugin de formularios no puede decir: no carga nada en las páginas sin formulario, no tiene versión de pago ni avisos de venta, y cada envío deja constancia de qué pasó con él (guardado, correo, webhook, reintentos).

## Operating Context

- Vive dentro de wp-admin: comparte pantalla con el menú lateral y la barra superior de WordPress, y el cliente llega desde ahí.
- Tres zonas: formularios (listado y constructor), envíos (listado, detalle y entregas fallidas) y ajustes (general, correo, captcha).
- Un formulario se inserta en una página con el bloque de Gutenberg, el widget de Elementor o un shortcode.
- El cliente lo usa también desde el móvil y la tableta, sobre todo para leer envíos (requisito del usuario, 2026-10-09).

## Capabilities and Constraints

- Constructor: campos que se ordenan arrastrando, anchos por columnas, lógica condicional, formularios por pasos, plantillas de partida, duplicar, exportar e importar, y vista previa en vivo.
- Acciones tras un envío: guardarlo, notificaciones por correo y webhooks, con cola y reintentos.
- Envíos: búsqueda, filtro por formulario, exportación a CSV, detalle con el estado de cada entrega y ficheros adjuntos.
- La interfaz está hecha en React con `@wordpress/components` y se compila con `@wordpress/scripts`. Es traducible; se distribuye con `es_ES`.
- La ligereza es el primer principio: sin dependencias pesadas y sin recursos externos (fuentes, CDN) en el panel.
- Permisos separados: gestionar formularios, ver envíos y cambiar ajustes. Un cliente puede tener solo los dos primeros.
- WordPress 7.0 y PHP 8.1 como mínimo.

Sin decidir: si el plugin tendrá nombre y marca propios más allá de «Formulario Mensaje».

## Evidence on Hand

- El backend actual, funcionando en el entorno local (`rt start`, http://localhost:8088/wp-admin/admin.php?page=formulario-mensaje).
- `PLAN.md`: decisiones de producto y su motivo.
- No hay logotipo, testimonios, cifras de uso ni clientes que se puedan citar: no se inventan.

## Product Principles

1. El cliente no necesita saber cómo funciona por dentro: la jerga se traduce o se esconde detrás de una acción («Copiar código para insertar», no «shortcode»).
2. Nada se pierde en silencio: lo que falla se ve, se entiende y tiene un botón para arreglarlo.
3. Lo frecuente, a un toque; lo técnico, apartado y explicado.
4. Ligero también en la interfaz: sin adornos que no ayuden a completar la tarea.
5. Se integra en WordPress: quien conoce wp-admin no tiene que aprender otra aplicación.

## Accessibility & Inclusion

WCAG 2.2 AA, que es el nivel que sigue el propio panel de WordPress: todo se maneja con teclado, el foco es visible, el arrastre tiene alternativa con botones y los objetivos táctiles miden al menos 44 px en móvil y tableta.
