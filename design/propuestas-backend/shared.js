/* Piezas comunes de las maquetas: iconos, datos de ejemplo, marco de wp-admin y navegación entre pantallas.
   Los datos son inventados para la demostración: una clínica dental con cinco formularios.

   Sobre innerHTML e insertAdjacentHTML: aquí solo componen textos fijos escritos en este mismo fichero (DATA,
   ICONS). Las maquetas no leen nada del usuario, de la URL ni de la red, salvo el ancla, que solo se compara con
   los nombres de pantalla conocidos. No forman parte del plugin ni se distribuyen con él. */

const ICONS = {
	plus: 'M12 5v14M5 12h14',
	search: 'M11 4.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13zM16 16l4.5 4.5',
	chevL: 'M14.5 6l-6 6 6 6',
	chevR: 'M9.5 6l6 6-6 6',
	chevD: 'M6 9.5l6 6 6-6',
	chevU: 'M6 14.5l6-6 6 6',
	copy: 'M11 9h7a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2zM5 15V6a2 2 0 0 1 2-2h9',
	trash: 'M4 7h16M9 7V4.5h6V7M6.5 7l1 12.5h9l1-12.5M10 11v5M14 11v5',
	pencil: 'M4 20l1-4.5L16.5 4a2.1 2.1 0 0 1 3 3L8 18.5 4 20zM14.5 6l3 3',
	eye: 'M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12s-3.5 6.5-9.5 6.5S2.5 12 2.5 12zM12 9.2a2.8 2.8 0 1 0 0 5.6 2.8 2.8 0 0 0 0-5.6z',
	mail: 'M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM3.5 7.5l8.5 6 8.5-6',
	phone: 'M6 3.5h3l1.5 4.5-2 1.5a12 12 0 0 0 6 6l1.5-2 4.5 1.5v3a2 2 0 0 1-2 2C10.5 20 4 13.5 4 5.500a2 2 0 0 1 2-2z',
	check: 'M5 12.5l4.5 4.5L19 7.5',
	x: 'M6 6l12 12M18 6L6 18',
	alert: 'M12 4l9 16H3L12 4zM12 10v4.5M12 17.2v.3',
	clock: 'M12 3.5a8.500 8.500 0 1 0 0 17 8.500 8.500 0 0 0 0-17zM12 7.5V12l3 2',
	clip: 'M19 11.5l-7.5 7.5a4.5 4.5 0 0 1-6.4-6.4l8-8a3 3 0 0 1 4.300 4.300l-8 8a1.500 1.500 0 0 1-2.200-2.200l7.300-7.200',
	download: 'M12 4v11M7.5 11l4.500 4.500 4.500-4.500M5 19.500h14',
	filter: 'M4 6h16M7 12h10M10 18h4',
	desktop: 'M4.500 4.500h15a1.500 1.500 0 0 1 1.500 1.500v9a1.500 1.500 0 0 1-1.500 1.500h-15A1.500 1.500 0 0 1 3 15V6a1.500 1.500 0 0 1 1.500-1.500zM9 20h6M12 16.500V20',
	tablet: 'M7.500 3h9a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-9a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM11 18h2',
	mobile: 'M9.500 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM11 18h2',
	sliders: 'M4 7h9M17 7h3M4 17h3M11 17h9M15 5a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM9 15a2 2 0 1 0 0 4 2 2 0 0 0 0-4z',
	inbox: 'M4 13.500l2.500-8h11l2.500 8v5a1.500 1.500 0 0 1-1.500 1.500h-13A1.500 1.500 0 0 1 4 18.500v-5zM4 13.500h4.500l1 2.500h5l1-2.500H20',
	form: 'M6 4h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 9h8M8 13h8M8 17h4',
	send: 'M20.500 3.500l-17 7 6.500 3 3 6.500 7.500-16.500zM10 13.500l4-4',
	link: 'M10 14a4 4 0 0 0 5.700 0l3-3a4 4 0 0 0-5.700-5.700l-1 1M14 10a4 4 0 0 0-5.700 0l-3 3a4 4 0 0 0 5.700 5.700l1-1',
	retry: 'M19.500 12a7.500 7.500 0 1 1-2.200-5.300M19.500 4.500V9H15',
	shield: 'M12 3.500l7 2.500v5.500c0 4.500-3 7.500-7 9-4-1.500-7-4.500-7-9V6l7-2.500zM9 12l2.200 2.200L15 10',
	text: 'M5 6.500h14M12 6.500V19M9.500 19h5',
	calendar: 'M6 5.500h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-10a2 2 0 0 1 2-2zM4 10h16M8.500 3.500v4M15.500 3.500v4',
	arrowR: 'M5 12h14M13.500 6.500L19 12l-5.500 5.500',
	external: 'M14 4.500h5.500V10M19.500 4.500l-8 8M18 14v4a1.500 1.500 0 0 1-1.500 1.500h-11A1.500 1.500 0 0 1 4 18V7a1.500 1.500 0 0 1 1.500-1.500H10',
	home: 'M4 11l8-6.500 8 6.500v8a1 1 0 0 1-1 1h-4.500v-6h-5v6H5a1 1 0 0 1-1-1v-8z',
	menu: 'M4 7h16M4 12h16M4 17h16',
	list: 'M9 7h11M9 12h11M9 17h11M4.500 7h.500M4.500 12h.500M4.500 17h.500',
	gauge: 'M4.500 17a7.500 7.500 0 1 1 15 0M12 17l3.500-5.500',
	pin: 'M9 4h6l-1 6 3 3H7l3-3-1-6zM12 13v7',
	image: 'M5.500 5h13a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM4 17l5-4.500 4 3.500 3-2.500 4 3.500M8.500 8.800a1.200 1.200 0 1 0 0 2.400 1.200 1.200 0 0 0 0-2.400z',
	page: 'M7 3.500h7l4 4v12.500a.500.500 0 0 1-.500.500h-10.500a.500.500 0 0 1-.500-.500V4a.500.500 0 0 1 .500-.500zM14 3.500V8h4',
	comment: 'M4.500 5.500h15V16H11l-4 3.500V16H4.500V5.500z',
	brush: 'M14 4l6 6-8 8H6v-6l8-8zM11.500 6.500l6 6',
	plug: 'M9 3.500v4M15 3.500v4M6.500 7.500h11V11a5.500 5.500 0 0 1-11 0V7.500zM12 16.500v4',
	users: 'M9 5.300a3.200 3.200 0 1 0 0 6.400 3.200 3.200 0 0 0 0-6.400zM3 19.500c0-3.300 2.700-5.500 6-5.500s6 2.200 6 5.500M16 5.600a3.200 3.200 0 0 1 0 5.800M17.500 14.300c2 .700 3.500 2.500 3.500 5.200',
	columns: 'M5.500 5h13A1.500 1.500 0 0 1 20 6.500v11a1.500 1.500 0 0 1-1.500 1.500h-13A1.500 1.500 0 0 1 4 17.500v-11A1.500 1.500 0 0 1 5.500 5zM12 5v14',
	branch: 'M6 4a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM6 16a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM18 7a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM6 8v8M6 13h5a5 5 0 0 0 5-2.500',
	undo: 'M9 7l-4.500 4.500L9 16M4.500 11.500H15a4.500 4.500 0 0 1 0 9h-3',
	save: 'M5.500 4h10.500l4 4v10.500a1.500 1.500 0 0 1-1.500 1.500h-13A1.500 1.500 0 0 1 4 18.500v-13A1.500 1.500 0 0 1 5.500 4zM8 4v5h7V4M8 20v-6h8v6',
	select: 'M5 6.500h14a1.500 1.500 0 0 1 1.500 1.500v8a1.500 1.500 0 0 1-1.500 1.500H5A1.500 1.500 0 0 1 3.500 16V8A1.500 1.500 0 0 1 5 6.500zM14 11l2 2 2-2',
	radio: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM12 9.500a2.500 2.500 0 1 0 0 5 2.500 2.500 0 0 0 0-5z',
	checkbox: 'M6 4.500h12A1.500 1.500 0 0 1 19.500 6v12a1.500 1.500 0 0 1-1.500 1.500H6A1.500 1.500 0 0 1 4.500 18V6A1.500 1.500 0 0 1 6 4.500zM8.500 12.200l2.500 2.500 4.500-5',
	area: 'M5 5h14a1.500 1.500 0 0 1 1.500 1.500v11A1.500 1.500 0 0 1 19 19H5a1.500 1.500 0 0 1-1.500-1.500v-11A1.500 1.500 0 0 1 5 5zM7.500 9.500h9M7.500 13h6',
	steps: 'M4 7h6M4 12h10M4 17h16M13.500 4.500l2.500 2.500-2.500 2.500',
};

const DOTS = {
	more: [ [ 12, 5.500 ], [ 12, 12 ], [ 12, 18.500 ] ],
	grip: [ [ 9, 6 ], [ 15, 6 ], [ 9, 12 ], [ 15, 12 ], [ 9, 18 ], [ 15, 18 ] ],
};

function icon( name, size = 20 ) {
	const open = `<svg width="${ size }" height="${ size }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.600" stroke-linecap="round" stroke-linejoin="round" focusable="false">`;

	if ( DOTS[ name ] ) {
		return open + DOTS[ name ].map( ( [ x, y ] ) => `<circle cx="${ x }" cy="${ y }" r="1.300" fill="currentColor" stroke="none"/>` ).join( '' ) + '</svg>';
	}

	return `${ open }<path d="${ ICONS[ name ] || '' }"/></svg>`;
}

/* Sustituye cada <i data-i="nombre"> por su icono. */
function hydrateIcons( root = document ) {
	root.querySelectorAll( 'i[data-i]:empty' ).forEach( ( el ) => {
		el.classList.add( 'ic' );
		el.setAttribute( 'aria-hidden', 'true' );
		el.innerHTML = icon( el.dataset.i, Number( el.dataset.s ) || 20 );
	} );
}

const DATA = {
	site: 'Clínica Dental Ribera',
	forms: [
		{ id: 'cita', name: 'Pedir cita', fields: 8, month: 38, unread: 2, last: 'hace 12 min', pages: [ 'Inicio', 'Pide tu cita' ], route: [ 'save', 'mail', 'hook' ], failed: 1, note: '' },
		{ id: 'contacto', name: 'Contacto', fields: 4, month: 21, unread: 1, last: 'hoy, 9:15', pages: [ 'Contacto' ], route: [ 'save', 'mail' ], failed: 0, note: '' },
		{ id: 'orto', name: 'Presupuesto de ortodoncia', fields: 9, month: 7, unread: 0, last: 'ayer, 18:30', pages: [ 'Ortodoncia invisible' ], route: [ 'save', 'mail', 'hook' ], failed: 0, note: 'En 3 pasos' },
		{ id: 'empleo', name: 'Trabaja con nosotros', fields: 5, month: 2, unread: 0, last: 'lunes', pages: [ 'Empleo' ], route: [ 'save', 'mail' ], failed: 0, note: 'Con currículum adjunto' },
		{ id: 'encuesta', name: 'Encuesta de satisfacción', fields: 6, month: 0, unread: 0, last: '', pages: [], route: [ 'save' ], failed: 0, note: '' },
	],
	fields: [
		{ id: 'nombre', label: 'Nombre y apellidos', type: 'Texto', plain: 'Respuesta corta', ico: 'text', req: true, half: true, kind: 'input' },
		{ id: 'telefono', label: 'Teléfono', type: 'Teléfono', plain: 'Número de teléfono', ico: 'phone', req: true, half: true, kind: 'input' },
		{ id: 'correo', label: 'Correo electrónico', type: 'Correo electrónico', plain: 'Dirección de correo', ico: 'mail', req: true, kind: 'input' },
		{ id: 'motivo', label: '¿Qué necesitas?', type: 'Desplegable', plain: 'Elegir de una lista', ico: 'select', req: true, kind: 'select', value: 'Elige una opción', options: [ 'Revisión', 'Limpieza', 'Ortodoncia', 'Urgencia' ] },
		{ id: 'dia', label: 'Día que prefieres', type: 'Fecha', plain: 'Fecha', ico: 'calendar', half: true, kind: 'date', value: 'dd/mm/aaaa' },
		{ id: 'horario', label: 'Horario', type: 'Opción única', plain: 'Elegir una opción', ico: 'radio', half: true, kind: 'radio', options: [ 'Mañana', 'Tarde' ] },
		{ id: 'mensaje', label: 'Cuéntanos algo más', type: 'Área de texto', plain: 'Respuesta larga', ico: 'area', kind: 'area' },
		{ id: 'privacidad', label: 'He leído y acepto la <u>política de privacidad</u>', type: 'Consentimiento', plain: 'Casilla de consentimiento', ico: 'checkbox', req: true, kind: 'consent' },
	],
	messages: [
		{
			ref: 'LAKL-8DJF', name: 'Marta Ruiz Ocaña', email: 'marta.ruiz@correo.example', phone: '612 345 078', form: 'Pedir cita',
			time: '10:42', day: 'Hoy', unread: true,
			excerpt: 'Me duele una muela desde el fin de semana y me gustaría que me vierais cuanto antes.',
			answers: [ [ 'Nombre y apellidos', 'Marta Ruiz Ocaña' ], [ 'Teléfono', '612 345 078' ], [ 'Correo electrónico', 'marta.ruiz@correo.example' ], [ '¿Qué necesitas?', 'Urgencia' ], [ 'Día que prefieres', 'Martes 13 de octubre' ], [ 'Horario', 'Tarde' ], [ 'Cuéntanos algo más', 'Me duele una muela desde el fin de semana y me gustaría que me vierais cuanto antes. Puedo salir del trabajo a partir de las cinco.' ] ],
			copies: [
				{ kind: 'save', label: 'Guardado en WordPress', to: '', state: 'ok', when: '10:42' },
				{ kind: 'mail', label: 'Aviso por correo', to: 'recepcion@clinicaribera.example', state: 'ok', when: '10:42' },
				{ kind: 'hook', label: 'Agenda de citas', to: 'Conexión con n8n', state: 'fail', when: '10:47', detail: 'El otro servicio respondió con un error (500). Segundo intento de cinco; el siguiente será a las 11:12.' },
			],
		},
		{ ref: 'C5RG-AMCZ', name: 'Luis Gómez Prieto', email: 'luis.gomez@correo.example', form: 'Contacto', time: '9:15', day: 'Hoy', unread: true, excerpt: '¿Tenéis aparcamiento cerca de la clínica? Voy con mi madre, que tiene movilidad reducida.', state: 'ok' },
		{ ref: 'HPGC-9BW8', name: 'Carmen Vidal Soto', email: 'carmen.vidal@correo.example', form: 'Presupuesto de ortodoncia', time: '18:30', day: 'Ayer', unread: false, excerpt: 'Os adjunto la radiografía que me hicieron en junio. Me interesa la ortodoncia invisible.', file: 'radiografia-junio.pdf', state: 'ok' },
		{ ref: 'BJCB-ZDMB', name: 'Javier Ortega Luna', email: 'javier.ortega@correo.example', form: 'Pedir cita', time: '12:04', day: 'Ayer', unread: false, excerpt: 'Limpieza anual para dos personas, si puede ser el mismo día por la mañana.', state: 'ok' },
		{ ref: '4PU5-8Q8Z', name: 'Nuria Blanco Rey', email: 'nuria.blanco@correo.example', form: 'Trabaja con nosotros', time: '16:20', day: 'Lunes 5 de octubre', unread: false, excerpt: 'Soy higienista dental con seis años de experiencia y me gustaría trabajar con vosotros.', file: 'cv-nuria-blanco.pdf', state: 'ok' },
		{ ref: '2HHG-M6H5', name: 'Andrés Molina Paz', email: 'andres.molina@correo.example', form: 'Contacto', time: '11:02', day: 'Lunes 5 de octubre', unread: false, excerpt: '¿Trabajáis con mi seguro dental? Es el de la mutua de funcionarios.', state: 'ok' },
		{ ref: 'JUVK-3LZN', name: 'Elena Sanz Mora', email: 'elena.sanz@correo.example', form: 'Pedir cita', time: '9:48', day: 'Sábado 3 de octubre', unread: false, excerpt: 'Revisión para mi hijo de ocho años. Cualquier tarde nos viene bien.', state: 'ok' },
	],
};

const WP_MENU = [
	[ 'gauge', 'Escritorio' ], [ 'pin', 'Entradas' ], [ 'image', 'Medios' ], [ 'page', 'Páginas' ], [ 'comment', 'Comentarios' ],
	[ 'form', 'Formularios', true ],
	[ 'brush', 'Apariencia' ], [ 'plug', 'Plugins' ], [ 'users', 'Usuarios' ], [ 'sliders', 'Ajustes' ],
];

/* Pinta la barra y el menú de wp-admin. `sub` son las entradas del submenú del plugin: [etiqueta, pantalla, contador]. */
function wpChrome( sub ) {
	const items = WP_MENU.map( ( [ ico, label, current ] ) => {
		const inner = current
			? `<ul class="wp-sub">${ sub.map( ( [ text, screen, count ] ) => `<li><a href="#${ screen }" data-nav="${ screen }">${ text }${ count ? ` <span class="wp-count">${ count }</span>` : '' }</a></li>` ).join( '' ) }</ul>`
			: '';

		return `<li class="${ current ? 'is-current' : '' }"><a href="#${ current ? sub[ 0 ][ 1 ] : '' }"><i data-i="${ ico }"></i><span>${ label }</span></a>${ inner }</li>`;
	} ).join( '' );

	document.body.insertAdjacentHTML(
		'afterbegin',
		`<div class="wp-bar">
			<button class="wp-bar__burger" type="button" aria-label="Menú"><i data-i="menu" data-s="26"></i></button>
			<span class="wp-bar__site"><i data-i="home" data-s="18"></i><span>${ DATA.site }</span></span>
			<span class="wp-bar__user"><span>Hola, Lucía</span><span class="wp-bar__avatar"></span></span>
		</div>
		<nav class="wp-menu" aria-label="Menú de WordPress"><ul>${ items }</ul></nav>`
	);

	document.querySelector( '.wp-bar__burger' ).addEventListener( 'click', () => document.body.classList.toggle( 'menu-open' ) );
}

/* Muestra la pantalla que indica el ancla de la URL y marca su entrada en los menús. */
function router( fallback, onChange ) {
	const apply = () => {
		const [ screen, extra ] = ( location.hash.slice( 1 ) || fallback ).split( ':' );
		const known = /^[a-z-]+$/.test( screen ) && document.querySelector( `[data-screen="${ screen }"]` ) ? screen : fallback;

		document.querySelectorAll( '[data-screen]' ).forEach( ( el ) => {
			el.hidden = el.dataset.screen !== known;
		} );
		document.querySelectorAll( '[data-nav]' ).forEach( ( el ) => {
			if ( el.dataset.nav === known ) {
				el.setAttribute( 'aria-current', 'page' );
			} else {
				el.removeAttribute( 'aria-current' );
			}
		} );
		document.body.dataset.at = known;
		document.body.classList.remove( 'menu-open' );
		window.scrollTo( 0, 0 );

		if ( onChange ) {
			onChange( known, extra || '' );
		}
	};

	window.addEventListener( 'hashchange', apply );
	apply();
}

/* El formulario «Pedir cita» tal como lo ve el visitante. `selected` marca el campo elegido en el constructor. */
function formPreview( selected = '' ) {
	const control = ( f ) => {
		if ( f.kind === 'radio' ) {
			return `<div class="pf-options">${ f.options.map( ( o ) => `<span>${ o }</span>` ).join( '' ) }</div>`;
		}
		if ( f.kind === 'area' ) {
			return '<div class="pf-control is-area"></div>';
		}

		return `<div class="pf-control"><span>${ f.value || '' }</span>${ f.kind === 'select' ? icon( 'chevD', 18 ) : '' }${ f.kind === 'date' ? icon( 'calendar', 18 ) : '' }</div>`;
	};

	const fields = DATA.fields.map( ( f ) => {
		const req = f.req ? ' <span class="pf-req">*</span>' : '';
		const body = f.kind === 'consent'
			? `<label class="pf-consent"><span>${ f.label }${ req }</span></label>`
			: `<label>${ f.label }${ req }</label>${ control( f ) }`;

		return `<div class="pf-field${ f.half ? ' is-half' : '' }${ f.id === selected ? ' is-selected' : '' }" data-field="${ f.id }" tabindex="0">${ body }</div>`;
	} ).join( '' );

	return `<div class="pf"><p class="pf__note">Los campos marcados con <b>*</b> son obligatorios.</p><div class="pf__grid">${ fields }</div><button class="pf-submit" type="button">Pedir cita</button></div>`;
}

function field( id ) {
	return DATA.fields.find( ( f ) => f.id === id );
}
