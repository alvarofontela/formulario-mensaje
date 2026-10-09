import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { moveItem } from './utils';

/**
 * Editor de la lista de opciones de un desplegable, un grupo de radios o de casillas.
 *
 * @param {Object}   props
 * @param {string}   props.label    Título del control.
 * @param {Array}    props.value    Opciones: `{ label, value }`.
 * @param {Function} props.onChange Recibe la lista nueva.
 */
function OptionsControl( { label, value, onChange } ) {
	const options = Array.isArray( value ) ? value : [];

	const update = ( index, text ) =>
		// El valor que se guarda con el envío es el mismo texto que ve el visitante.
		onChange(
			options.map( ( option, position ) =>
				position === index ? { label: text, value: text } : option
			)
		);

	return (
		<fieldset className="fm-options">
			<legend className="fm-options__legend">{ label }</legend>
			{ options.map( ( option, index ) => (
				<div className="fm-options__row" key={ index }>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: %d: position of an option in a list, starting at 1. */
							__( 'Option %d', 'formulario-mensaje' ),
							index + 1
						) }
						hideLabelFromVision
						value={ option.label }
						onChange={ ( text ) => update( index, text ) }
					/>
					<Button
						size="small"
						icon="arrow-up-alt2"
						disabled={ index === 0 }
						label={ __( 'Move option up', 'formulario-mensaje' ) }
						onClick={ () =>
							onChange( moveItem( options, index, index - 1 ) )
						}
					/>
					<Button
						size="small"
						icon="arrow-down-alt2"
						disabled={ index === options.length - 1 }
						label={ __( 'Move option down', 'formulario-mensaje' ) }
						onClick={ () =>
							onChange( moveItem( options, index, index + 1 ) )
						}
					/>
					<Button
						size="small"
						icon="no-alt"
						isDestructive
						label={ __( 'Remove option', 'formulario-mensaje' ) }
						onClick={ () =>
							onChange(
								options.filter(
									( item, position ) => position !== index
								)
							)
						}
					/>
				</div>
			) ) }
			<Button
				variant="secondary"
				size="compact"
				onClick={ () =>
					onChange( [ ...options, { label: '', value: '' } ] )
				}
			>
				{ __( 'Add option', 'formulario-mensaje' ) }
			</Button>
		</fieldset>
	);
}

/**
 * Pinta los ajustes de un campo a partir del esquema que declara su tipo en el servidor.
 *
 * No sabe nada de tipos de campo concretos: un tipo registrado por otro plugin se edita igual.
 *
 * @param {Object}   props
 * @param {Array}    props.schema   Controles del tipo de campo.
 * @param {Object}   props.settings Valores actuales.
 * @param {Function} props.onChange Recibe los valores nuevos.
 */
export default function FieldSettings( { schema, settings, onChange } ) {
	const set = ( key, value ) => onChange( { ...settings, [ key ]: value } );

	return (
		<div className="fm-settings">
			{ schema.map( ( control ) => {
				const {
					key,
					type,
					label,
					help,
					choices,
					show_if: showIf,
				} = control;
				const value = settings[ key ] ?? control.default ?? '';

				// Controles que solo tienen sentido según el valor de otro.
				if (
					showIf &&
					! showIf.values.includes( settings[ showIf.key ] ?? '' )
				) {
					return null;
				}

				const common = { label, help, __nextHasNoMarginBottom: true };

				switch ( type ) {
					case 'toggle':
						return (
							<ToggleControl
								key={ key }
								{ ...common }
								checked={ !! value }
								onChange={ ( checked ) => set( key, checked ) }
							/>
						);
					case 'select':
						return (
							<SelectControl
								key={ key }
								{ ...common }
								__next40pxDefaultSize
								value={ value }
								options={ choices || [] }
								onChange={ ( selected ) =>
									set( key, selected )
								}
							/>
						);
					case 'options':
						return (
							<OptionsControl
								key={ key }
								label={ label }
								value={ value }
								onChange={ ( options ) => set( key, options ) }
							/>
						);
					case 'textarea':
					case 'html':
						return (
							<TextareaControl
								key={ key }
								{ ...common }
								rows={ 3 }
								value={ value }
								onChange={ ( text ) => set( key, text ) }
							/>
						);
					default:
						return (
							<TextControl
								key={ key }
								{ ...common }
								__next40pxDefaultSize
								value={ value }
								onChange={ ( text ) => set( key, text ) }
							/>
						);
				}
			} ) }
		</div>
	);
}
