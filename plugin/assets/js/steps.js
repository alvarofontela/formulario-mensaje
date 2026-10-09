/**
 * Formulario Mensaje: formularios por pasos.
 *
 * Sin este módulo, o sin JavaScript, los pasos se ven todos seguidos, como secciones de un único
 * formulario. Con él se muestran de uno en uno, con una barra de progreso y botones para avanzar y
 * volver. Antes de avanzar, el servidor valida el paso.
 */

export class StepNavigator {
	/**
	 * @param {HTMLFormElement} form     Formulario con pasos.
	 * @param {Function}        validate Recibe la posición de un paso y devuelve una promesa: true si es válido.
	 */
	constructor( form, validate ) {
		this.form = form;
		this.validate = validate;
		this.steps = Array.from( form.querySelectorAll( '.fm-step' ) );
		this.progress = form.querySelector( '.fm-progress' );
		this.previousButton = form.querySelector( '.fm-prev' );
		this.nextButton = form.querySelector( '.fm-next' );
		this.submitButton = form.querySelector( '.fm-submit' );
		this.current = 0;
		this.busy = false;

		this.previousButton.addEventListener( 'click', () => this.previous() );
		this.nextButton.addEventListener( 'click', () => this.next() );

		this.refresh();
	}

	/**
	 * @return {number[]} Posiciones de los pasos que se muestran: los que su condición no deja fuera.
	 */
	active() {
		return this.steps
			.map( ( step, index ) => index )
			.filter( ( index ) => this.steps[ index ].dataset.fmSkip !== '1' );
	}

	/**
	 * @return {boolean} Si el paso a la vista es el último.
	 */
	isLast() {
		const active = this.active();

		return active.indexOf( this.current ) >= active.length - 1;
	}

	/**
	 * Deja a la vista solo el paso actual y pone al día la barra de progreso y los botones. Se llama
	 * también cuando cambia qué pasos se muestran.
	 */
	refresh() {
		const active = this.active();

		// Si el paso actual ha dejado de mostrarse, se vuelve al anterior que sí se muestra.
		if ( ! active.includes( this.current ) ) {
			this.current =
				[ ...active ]
					.reverse()
					.find( ( index ) => index < this.current ) ??
				active[ 0 ] ??
				0;
		}

		this.steps.forEach( ( step, index ) => {
			step.hidden = index !== this.current;
		} );

		const position = active.indexOf( this.current );
		const last = position >= active.length - 1;

		this.previousButton.hidden = position <= 0;
		this.nextButton.hidden = last;
		this.submitButton.hidden = ! last;

		if ( ! this.progress ) {
			return;
		}

		const bar = this.progress.querySelector( '.fm-progress__bar' );
		const title = this.steps[ this.current ]
			?.querySelector( '.fm-step__title' )
			?.textContent.trim();
		const text = ( this.form.dataset.fmStepText || '' )
			.replace( '{current}', String( position + 1 ) )
			.replace( '{total}', String( active.length ) );

		this.progress.hidden = active.length < 2;
		this.progress.style.setProperty(
			'--fm-progress',
			`${ ( ( position + 1 ) / Math.max( 1, active.length ) ) * 100 }%`
		);
		bar.setAttribute( 'aria-valuemax', String( active.length ) );
		bar.setAttribute( 'aria-valuenow', String( position + 1 ) );
		bar.setAttribute( 'aria-valuetext', text );
		this.progress.querySelector( '.fm-progress__text' ).textContent = title
			? `${ text }: ${ title }`
			: text;
	}

	/**
	 * Pasa al siguiente paso, si el servidor da por bueno el actual.
	 */
	async next() {
		if ( this.busy || this.isLast() ) {
			return;
		}

		this.busy = true;
		this.nextButton.setAttribute( 'aria-disabled', 'true' );

		try {
			if ( await this.validate( this.current ) ) {
				const active = this.active();

				this.show( active[ active.indexOf( this.current ) + 1 ] );
			}
		} finally {
			this.busy = false;
			this.nextButton.setAttribute( 'aria-disabled', 'false' );
		}
	}

	/**
	 * Vuelve al paso anterior. Lo escrito en el actual se conserva.
	 */
	previous() {
		const active = this.active();
		const position = active.indexOf( this.current );

		if ( position > 0 ) {
			this.show( active[ position - 1 ] );
		}
	}

	/**
	 * Muestra un paso y lleva el foco a su principio.
	 *
	 * @param {number} index Posición del paso.
	 */
	show( index ) {
		this.current = index;
		this.refresh();

		const step = this.steps[ index ];
		const target =
			step.querySelector( '.fm-step__title' ) ||
			step.querySelector( 'input, select, textarea' );

		target?.focus();
	}

	/**
	 * Muestra el paso en el que está un elemento: el campo con un error, por ejemplo.
	 *
	 * @param {Element} element Elemento dentro de un paso.
	 */
	reveal( element ) {
		const index = this.steps.indexOf( element.closest( '.fm-step' ) );

		if ( index >= 0 && index !== this.current ) {
			this.current = index;
			this.refresh();
		}
	}

	/**
	 * Vuelve al primer paso: tras vaciar el formulario para otro envío.
	 */
	reset() {
		this.current = this.active()[ 0 ] ?? 0;
		this.refresh();
	}
}
