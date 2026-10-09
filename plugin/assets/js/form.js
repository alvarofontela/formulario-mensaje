/**
 * Formulario Mensaje: envío del formulario sin recargar la página.
 *
 * Módulo sin dependencias. Si no se carga, el formulario sigue funcionando como POST normal.
 */

const FORM_SELECTOR = 'form.fm-form[data-fm-form]';
const TOKEN_ERRORS = [
	'formulario_mensaje_token_invalid',
	'formulario_mensaje_token_expired',
	'formulario_mensaje_too_fast',
];
const controllers = new WeakMap();

const wait = ( milliseconds ) =>
	new Promise( ( resolve ) =>
		setTimeout( resolve, Math.max( 0, milliseconds ) )
	);

class FormController {
	/**
	 * @param {HTMLFormElement} form Formulario generado por el plugin.
	 */
	constructor( form ) {
		this.form = form;
		this.root = form.closest( '.formulario-mensaje' );
		this.endpoint = form.dataset.fmEndpoint;
		this.status = form.querySelector( '.fm-status' );
		this.success = this.root.querySelector( '.fm-success' );
		this.token = null;
		this.captcha = null;
		this.busy = false;

		// La validación la hace el servidor y los errores se muestran junto a cada campo.
		form.noValidate = true;

		this.fillContext();

		// El token antispam y el captcha se piden cuando el visitante empieza a usar el formulario, no al cargar la página.
		form.addEventListener(
			'focusin',
			() => {
				this.requestToken().catch( () => {} );
				this.prepareCaptcha().catch( () => {} );
			},
			{ once: true }
		);
		form.addEventListener( 'submit', ( event ) => this.onSubmit( event ) );
		form.addEventListener( 'input', ( event ) =>
			this.clearFieldError( event.target )
		);
		form.addEventListener( 'change', ( event ) =>
			this.clearFieldError( event.target )
		);
	}

	/**
	 * Rellena en el navegador lo que el servidor no puede saber en una página servida desde caché:
	 * la URL real, la página de procedencia y los parámetros de la URL.
	 */
	fillContext() {
		const query = new URLSearchParams( window.location.search );

		this.form
			.querySelectorAll( '[data-fm-context]' )
			.forEach( ( input ) => {
				const key = input.dataset.fmContext;

				if ( key === 'page_url' ) {
					input.value = window.location.href;
				} else if ( key === 'page_title' ) {
					input.value = document.title;
				} else if ( key === 'referrer' ) {
					input.value = document.referrer;
				} else if (
					key.startsWith( 'query:' ) &&
					query.has( key.slice( 6 ) )
				) {
					input.value = query.get( key.slice( 6 ) );
				}
			} );

		this.form
			.querySelectorAll( '[data-fm-prefill-query]' )
			.forEach( ( input ) => {
				const param = input.dataset.fmPrefillQuery;

				if ( ! input.value && query.has( param ) ) {
					input.value = query.get( param );
				}
			} );
	}

	/**
	 * Pide el token antispam. Las llamadas repetidas comparten la misma petición.
	 *
	 * @return {Promise<{value: string, readyAt: number}>} Token y momento a partir del cual se puede enviar.
	 */
	requestToken() {
		if ( ! this.token ) {
			this.token = fetch( `${ this.endpoint }/token`, {
				method: 'POST',
				headers: { Accept: 'application/json' },
			} )
				.then( ( response ) =>
					response.ok
						? response.json()
						: Promise.reject( new Error( 'token' ) )
				)
				.then( ( data ) => ( {
					value: data.token,
					readyAt: Date.now() + ( Number( data.wait ) || 0 ) * 1000,
				} ) )
				.catch( ( error ) => {
					this.token = null;
					throw error;
				} );
		}

		return this.token;
	}

	/**
	 * Prepara el captcha, si el formulario lo lleva. Las llamadas repetidas comparten la misma carga.
	 *
	 * @return {Promise<?{getToken: Function, reset: Function}>} El captcha, o null si el formulario no lleva.
	 */
	prepareCaptcha() {
		if ( ! this.form.dataset.fmCaptcha ) {
			return Promise.resolve( null );
		}

		if ( ! this.captcha ) {
			// El módulo del captcha solo se descarga en los formularios que lo usan.
			this.captcha = import( this.form.dataset.fmCaptchaModule )
				.then( ( module ) => module.mountCaptcha( this.form ) )
				.catch( ( error ) => {
					this.captcha = null;
					throw error;
				} );
		}

		return this.captcha;
	}

	/**
	 * @param {SubmitEvent} event Evento de envío.
	 */
	async onSubmit( event ) {
		event.preventDefault();

		if ( this.busy ) {
			return;
		}

		this.setBusy( true );
		this.clearErrors();

		try {
			let result = await this.send();

			// Un token caducado o no válido se renueva una vez sin molestar al visitante.
			if ( ! result.ok && TOKEN_ERRORS.includes( result.body?.code ) ) {
				this.token = null;
				result = await this.send();
			}

			if ( result.ok && result.body?.success ) {
				this.onSuccess( result.body );
			} else {
				this.onError( result.body );
			}

			// El testigo del captcha solo vale para un envío: para el siguiente intento hace falta otro.
			if ( this.captcha ) {
				this.captcha
					.then( ( captcha ) => captcha.reset() )
					.catch( () => {} );
			}
		} catch {
			this.onError( null );
		} finally {
			this.setBusy( false );
		}
	}

	/**
	 * Envía los datos del formulario a la ruta REST.
	 *
	 * @return {Promise<{ok: boolean, body: ?Object}>} Resultado de la petición.
	 */
	async send() {
		const token = await this.requestToken();

		// El servidor exige un tiempo mínimo entre la primera interacción y el envío.
		await wait( token.readyAt - Date.now() );

		const data = new FormData( this.form );

		// Campos que solo usa el POST sin JavaScript.
		data.delete( 'action' );
		data.delete( 'fm_form_id' );
		data.set( 'token', token.value );

		if ( this.form.dataset.fmCaptcha ) {
			// Sin testigo no se envía nada: el servidor lo rechazaría igual.
			const captchaToken = await this.prepareCaptcha()
				.then( ( captcha ) => captcha.getToken() )
				.catch( () => '' );

			if ( ! captchaToken ) {
				return {
					ok: false,
					body: { message: this.form.dataset.fmCaptchaMessage },
				};
			}

			data.set( 'captcha', captchaToken );
		}

		const response = await fetch( `${ this.endpoint }/submissions`, {
			method: 'POST',
			headers: { Accept: 'application/json' },
			body: data,
		} );

		return {
			ok: response.ok,
			body: await response.json().catch( () => null ),
		};
	}

	/**
	 * @param {Object} result Respuesta de un envío correcto.
	 */
	onSuccess( result ) {
		this.form.dispatchEvent(
			new CustomEvent( 'formulario-mensaje:submitted', {
				bubbles: true,
				detail: { formId: result.form_id, reference: result.reference },
			} )
		);

		// Aviso para medir conversiones. No lleva ningún dato del envío.
		if ( result.tracking ) {
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push( {
				event: 'formulario_mensaje_submit',
				form_id: result.form_id,
				form_name: this.form.getAttribute( 'aria-label' ) || '',
			} );
		}

		if ( result.redirect ) {
			window.location.assign( result.redirect );
			return;
		}

		// El mensaje llega del servidor ya filtrado y con los datos del envío escapados.
		this.success.innerHTML = result.message;
		this.success.hidden = false;

		if ( result.after_submit === 'reset' ) {
			this.form.reset();
			this.fillContext();
		} else {
			this.form.hidden = true;
		}

		this.success.focus();
	}

	/**
	 * @param {?Object} error Respuesta de error de la REST API, o null si la petición no llegó a completarse.
	 */
	onError( error ) {
		const fieldErrors = error?.data?.errors || {};
		let firstInvalid = null;

		Object.entries( fieldErrors ).forEach( ( [ fieldId, message ] ) => {
			const field = this.form.querySelector(
				`[data-fm-field="${ window.CSS.escape( fieldId ) }"]`
			);
			const slot = field?.querySelector( '.fm-error' );

			if ( ! slot ) {
				return;
			}

			slot.textContent = message;
			slot.hidden = false;

			// En un grupo de radios o casillas el error se asocia al grupo; en el resto, al control.
			const described = field.matches( 'fieldset' )
				? [ field ]
				: Array.from(
						field.querySelectorAll( 'input, select, textarea' )
					);

			described.forEach( ( element ) =>
				this.describe( element, slot.id, true )
			);
			field
				.querySelectorAll( 'input, select, textarea' )
				.forEach( ( control ) =>
					control.setAttribute( 'aria-invalid', 'true' )
				);

			firstInvalid =
				firstInvalid ||
				field.querySelector( 'input, select, textarea' );
		} );

		this.status.textContent =
			error?.message || this.form.dataset.fmErrorMessage || '';
		this.status.hidden = false;

		// El foco va al primer campo con error; si no hay ninguno, al aviso general.
		( firstInvalid || this.status ).focus();
	}

	/**
	 * Añade o quita un identificador de `aria-describedby`.
	 *
	 * @param {Element} element Elemento descrito.
	 * @param {string}  id      Identificador del texto que lo describe.
	 * @param {boolean} add     Si se añade o se quita.
	 */
	describe( element, id, add ) {
		const ids = new Set(
			( element.getAttribute( 'aria-describedby' ) || '' )
				.split( /\s+/ )
				.filter( Boolean )
		);

		if ( add ) {
			ids.add( id );
		} else {
			ids.delete( id );
		}

		if ( ids.size ) {
			element.setAttribute(
				'aria-describedby',
				Array.from( ids ).join( ' ' )
			);
		} else {
			element.removeAttribute( 'aria-describedby' );
		}
	}

	/**
	 * Retira el error de un campo cuando el visitante lo corrige.
	 *
	 * @param {EventTarget} target Control modificado.
	 */
	clearFieldError( target ) {
		const field =
			target instanceof window.Element
				? target.closest( '[data-fm-field]' )
				: null;
		const slot = field?.querySelector( '.fm-error' );

		if ( ! slot || slot.hidden ) {
			return;
		}

		slot.hidden = true;
		slot.textContent = '';

		[
			field,
			...field.querySelectorAll( 'input, select, textarea' ),
		].forEach( ( element ) => {
			element.removeAttribute( 'aria-invalid' );
			this.describe( element, slot.id, false );
		} );
	}

	clearErrors() {
		this.status.hidden = true;
		this.status.textContent = '';
		this.form
			.querySelectorAll( '.fm-error:not([hidden])' )
			.forEach( ( slot ) => this.clearFieldError( slot ) );
	}

	/**
	 * @param {boolean} busy Si hay un envío en curso.
	 */
	setBusy( busy ) {
		this.busy = busy;
		this.form.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		this.form.querySelectorAll( '.fm-submit' ).forEach( ( button ) => {
			// aria-disabled y no disabled: el botón conserva el foco mientras dura el envío.
			button.setAttribute( 'aria-disabled', busy ? 'true' : 'false' );
		} );
	}
}

/**
 * Activa los formularios que haya dentro de un nodo. Repetir la llamada no los activa dos veces.
 *
 * @param {Document|Element} root Nodo en el que buscar; por defecto, el documento.
 */
export function init( root = document ) {
	root.querySelectorAll( FORM_SELECTOR ).forEach( ( form ) => {
		if ( ! controllers.has( form ) ) {
			controllers.set( form, new FormController( form ) );
		}
	} );
}

// Para contenido insertado después de cargar la página (ventanas emergentes, constructores visuales).
window.formularioMensaje = { init };

init();
