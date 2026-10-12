import { Button, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { styleSchema } from './config';

const LABELS = {
	label_color: __( 'Label color', 'formulario-mensaje' ),
	field_color: __( 'Field text color', 'formulario-mensaje' ),
	field_background: __( 'Field background', 'formulario-mensaje' ),
	border_color: __( 'Border color', 'formulario-mensaje' ),
	focus_color: __( 'Focus outline color', 'formulario-mensaje' ),
	button_background: __( 'Button background', 'formulario-mensaje' ),
	button_color: __( 'Button text color', 'formulario-mensaje' ),
	gap: __( 'Space between fields', 'formulario-mensaje' ),
	border_width: __( 'Border width', 'formulario-mensaje' ),
	border_radius: __( 'Border radius', 'formulario-mensaje' ),
	field_padding: __( 'Field padding', 'formulario-mensaje' ),
};

/**
 * Control de un color opcional: sin valor, el color se hereda del tema.
 *
 * @param {Object}   props
 * @param {string}   props.id       Identificador del control.
 * @param {string}   props.label    Etiqueta.
 * @param {?string}  props.value    Color en hexadecimal, o vacío.
 * @param {Function} props.onChange Recibe el color nuevo, o undefined para volver a heredarlo.
 */
function ColorControl( { id, label, value, onChange } ) {
	return (
		<div className="fm-color">
			<label htmlFor={ id }>{ label }</label>
			<input
				id={ id }
				type="color"
				// El selector nativo necesita siempre un color: sin valor muestra un gris neutro.
				value={ value || '#808080' }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
			{ value ? (
				<>
					<code>{ value }</code>
					<Button
						variant="link"
						onClick={ () => onChange( undefined ) }
					>
						{ __( 'Use the theme color', 'formulario-mensaje' ) }
					</Button>
				</>
			) : (
				<span className="fm-admin__muted">
					{ __( 'From the theme', 'formulario-mensaje' ) }
				</span>
			) }
		</div>
	);
}

/**
 * Controles de estilo de un formulario: una lista cerrada de colores y medidas. Lo usan el diseñador
 * del constructor y, con las mismas claves, el bloque.
 *
 * @param {Object}   props
 * @param {Object}   props.style    Estilo: solo las claves con valor.
 * @param {Function} props.onChange Recibe el estilo nuevo.
 * @param {string}   props.idPrefix Prefijo de los identificadores de los controles.
 */
export function StyleControls( { style, onChange, idPrefix = 'fm-style' } ) {
	// Un estilo vacío llega del servidor como lista: se trata siempre como objeto.
	const current = Array.isArray( style ) || ! style ? {} : style;

	const set = ( key, value ) => {
		const next = { ...current };

		if ( value === undefined || value === '' || value === null ) {
			delete next[ key ];
		} else {
			next[ key ] = value;
		}

		onChange( next );
	};

	return (
		<>
			{ styleSchema.map( ( control ) =>
				control.type === 'color' ? (
					<ColorControl
						key={ control.key }
						id={ `${ idPrefix }-${ control.key }` }
						label={ LABELS[ control.key ] || control.key }
						value={ current[ control.key ] }
						onChange={ ( value ) => set( control.key, value ) }
					/>
				) : (
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						key={ control.key }
						label={ LABELS[ control.key ] || control.key }
						min={ control.min }
						max={ control.max }
						allowReset
						resetFallbackValue={ undefined }
						value={ current[ control.key ] }
						onChange={ ( value ) => set( control.key, value ) }
					/>
				)
			) }
		</>
	);
}

/**
 * Pestaña de estilo del constructor.
 *
 * @param {Object}   props
 * @param {Object}   props.style    Estilo del formulario.
 * @param {Function} props.onChange Recibe el estilo nuevo.
 */
export default function StylePanel( { style, onChange } ) {
	const hasStyle =
		style && ! Array.isArray( style ) && Object.keys( style ).length > 0;

	return (
		<div className="fm-settings">
			<p className="fm-settings__note">
				{ __(
					'Without changes, the form takes the fonts and colors of the theme. What you set here applies to this form wherever it is shown; the block and the Elementor widget can override it in one place.',
					'formulario-mensaje'
				) }
			</p>
			<StyleControls style={ style } onChange={ onChange } />
			{ hasStyle && (
				<div>
					<Button
						variant="secondary"
						isDestructive
						onClick={ () => onChange( {} ) }
					>
						{ __( 'Remove all the styles', 'formulario-mensaje' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
