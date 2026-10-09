/**
 * Formulario Mensaje: captcha.
 *
 * Módulo sin dependencias que el script del formulario importa solo si el formulario lleva captcha.
 * El script del servicio (Cloudflare, Google, hCaptcha) se carga cuando el visitante empieza a usar
 * el formulario, no al abrir la página.
 */

/** Milisegundos que se espera a que el servicio entregue su testigo antes de dar el envío por incompleto. */
const TOKEN_WAIT = 10000;

/** Milisegundos que se espera a que el script del servicio esté listo. */
const SCRIPT_WAIT = 15000;

const scripts = new Map();

/**
 * Carga un script externo una sola vez.
 *
 * @param {string} src Dirección del script.
 * @return {Promise<void>} Se resuelve cuando el script ha cargado.
 */
function loadScript( src ) {
	if ( ! scripts.has( src ) ) {
		scripts.set(
			src,
			new Promise( ( resolve, reject ) => {
				const script = document.createElement( 'script' );

				script.src = src;
				script.async = true;
				script.onload = () => resolve();
				script.onerror = () => {
					scripts.delete( src );
					reject( new Error( 'captcha-script' ) );
				};
				document.head.append( script );
			} )
		);
	}

	return scripts.get( src );
}

/**
 * Espera a que el script del servicio haya dejado su objeto global.
 *
 * @param {string} name   Nombre del objeto global.
 * @param {string} method Método que debe tener para darlo por listo.
 * @return {Promise<Object>} El objeto global.
 */
function globalApi( name, method ) {
	return new Promise( ( resolve, reject ) => {
		const deadline = Date.now() + SCRIPT_WAIT;
		const check = () => {
			if ( typeof window[ name ]?.[ method ] === 'function' ) {
				resolve( window[ name ] );
			} else if ( Date.now() > deadline ) {
				reject( new Error( 'captcha-script' ) );
			} else {
				setTimeout( check, 50 );
			}
		};

		check();
	} );
}

/**
 * Envuelve un captcha con casilla (Turnstile, hCaptcha): el servicio entrega el testigo cuando el
 * visitante la resuelve, y lo retira cuando caduca.
 *
 * @param {Function} render      Recibe los callbacks del servicio, pinta la casilla y devuelve su identificador.
 * @param {Function} reset       Recibe el identificador y pide una comprobación nueva.
 * @param {boolean}  interactive Si la casilla espera desde el principio a que el visitante la pulse.
 * @return {{getToken: Function, reset: Function}} Captcha listo para usar.
 */
function widget( render, reset, interactive ) {
	let token = '';
	let needsVisitor = interactive;
	const waiting = [];
	const settle = ( value ) => {
		token = typeof value === 'string' ? value : '';
		waiting.splice( 0 ).forEach( ( resolve ) => resolve( token ) );
	};

	const id = render( {
		callback: settle,
		'expired-callback': () => {
			token = '';
		},
		'error-callback': () => settle( '' ),
		// Turnstile avisa cuando su comprobación deja de ser automática y pasa a necesitar al visitante.
		'before-interactive-callback': () => {
			needsVisitor = true;
			settle( '' );
		},
	} );

	return {
		getToken() {
			// Si falta que el visitante pulse la casilla, esperar no sirve: se le avisa al momento.
			if ( token || needsVisitor ) {
				return Promise.resolve( token );
			}

			// Una comprobación automática puede seguir en curso: se le da un tiempo.
			return new Promise( ( resolve ) => {
				waiting.push( resolve );
				setTimeout( () => resolve( token ), TOKEN_WAIT );
			} );
		},
		// Un testigo solo vale para un envío: tras un intento hay que pedir otro.
		reset() {
			token = '';
			reset( id );
		},
	};
}

/**
 * Adaptadores de los servicios, por el identificador de su proveedor en el servidor. Cada uno carga
 * el script del servicio y devuelve un objeto con `getToken()` y `reset()`.
 */
const builtIn = {
	turnstile: async ( slot, siteKey ) => {
		await loadScript(
			'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
		);

		const api = await globalApi( 'turnstile', 'render' );

		return widget(
			( callbacks ) =>
				api.render( slot, { sitekey: siteKey, ...callbacks } ),
			( id ) => api.reset( id ),
			false
		);
	},

	hcaptcha: async ( slot, siteKey ) => {
		await loadScript( 'https://js.hcaptcha.com/1/api.js?render=explicit' );

		const api = await globalApi( 'hcaptcha', 'render' );

		// La casilla de hCaptcha siempre espera a que el visitante la pulse.
		return widget(
			( callbacks ) =>
				api.render( slot, { sitekey: siteKey, ...callbacks } ),
			( id ) => api.reset( id ),
			true
		);
	},

	// reCAPTCHA v3 no tiene casilla: da un testigo nuevo cada vez que se le pide.
	recaptcha: async ( slot, siteKey ) => {
		await loadScript(
			`https://www.google.com/recaptcha/api.js?render=${ encodeURIComponent(
				siteKey
			) }`
		);

		const api = await globalApi( 'grecaptcha', 'execute' );

		return {
			getToken: () =>
				new Promise( ( resolve ) => {
					api.ready( () =>
						api
							.execute( siteKey, {
								action: 'formulario_mensaje_submit',
							} )
							.then( resolve, () => resolve( '' ) )
					);
				} ),
			reset() {},
		};
	},
};

/**
 * Prepara el captcha de un formulario.
 *
 * @param {HTMLFormElement} form Formulario generado por el plugin, con los datos de su captcha.
 * @return {Promise<{getToken: Function, reset: Function}>} Captcha listo para usar.
 */
export async function mountCaptcha( form ) {
	// Un proveedor de terceros trae su propio adaptador.
	const adapters = {
		...builtIn,
		...( window.formularioMensajeCaptchaAdapters || {} ),
	};
	const adapter = adapters[ form.dataset.fmCaptcha ];

	if ( typeof adapter !== 'function' ) {
		throw new Error( 'captcha-adapter' );
	}

	return adapter(
		form.querySelector( '[data-fm-captcha-slot]' ),
		form.dataset.fmCaptchaKey
	);
}
