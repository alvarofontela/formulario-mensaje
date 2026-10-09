import { Button, SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { getFieldType, operators } from './config';
import { fieldTitle } from './utils';

/**
 * Editor de una condición: un grupo de reglas sobre los valores de los campos.
 *
 * Es el mismo para las acciones y, más adelante, para mostrar u ocultar campos y pasos. La
 * condición la evalúa el servidor con el motor de reglas del plugin.
 *
 * @param {Object}   props
 * @param {Object}   props.condition Condición: `{ match, rules }`.
 * @param {Array}    props.fields    Campos del formulario sobre los que se puede preguntar.
 * @param {string}   props.label     Texto que presenta la condición.
 * @param {string}   props.note      Aclaración que se muestra bajo las reglas, si la hay.
 * @param {Function} props.onChange  Recibe la condición nueva.
 */
export default function ConditionEditor( {
	condition,
	fields,
	label,
	note,
	onChange,
} ) {
	const rules = Array.isArray( condition?.rules ) ? condition.rules : [];
	const match = condition?.match === 'any' ? 'any' : 'all';
	const inputFields = fields.filter(
		( field ) => getFieldType( field.type )?.isInput
	);

	const setRules = ( next ) => onChange( { match, rules: next } );
	const setRule = ( index, patch ) =>
		setRules(
			rules.map( ( rule, position ) =>
				position === index ? { ...rule, ...patch } : rule
			)
		);

	return (
		<fieldset className="fm-options fm-condition">
			<legend className="fm-options__legend">{ label }</legend>

			{ rules.length === 0 && (
				<p className="fm-settings__note">
					{ __(
						'Without rules, it always applies.',
						'formulario-mensaje'
					) }
				</p>
			) }

			{ rules.length > 1 && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					className="fm-condition__match"
					label={ __( 'It applies when', 'formulario-mensaje' ) }
					value={ match }
					options={ [
						{
							value: 'all',
							label: __(
								'all the rules are met',
								'formulario-mensaje'
							),
						},
						{
							value: 'any',
							label: __(
								'any of the rules is met',
								'formulario-mensaje'
							),
						},
					] }
					onChange={ ( value ) =>
						onChange( { match: value, rules } )
					}
				/>
			) }

			{ rules.map( ( rule, index ) => {
				const operator = operators.find(
					( item ) => item.value === rule.operator
				);
				const field = inputFields.find(
					( item ) => item.id === rule.field
				);
				const options = Array.isArray( field?.settings?.options )
					? field.settings.options
					: null;

				return (
					<div
						className="fm-options__row fm-condition__rule"
						key={ index }
					>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Field', 'formulario-mensaje' ) }
							hideLabelFromVision
							value={ rule.field }
							options={ [
								{
									value: '',
									label: __(
										'Select a field',
										'formulario-mensaje'
									),
								},
								...inputFields.map( ( item ) => ( {
									value: item.id,
									label: fieldTitle( item ),
								} ) ),
							] }
							onChange={ ( value ) =>
								setRule( index, { field: value, value: '' } )
							}
						/>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Comparison', 'formulario-mensaje' ) }
							hideLabelFromVision
							value={ rule.operator }
							options={ operators.map( ( item ) => ( {
								value: item.value,
								label: item.label,
							} ) ) }
							onChange={ ( value ) =>
								setRule( index, { operator: value } )
							}
						/>
						{ operator?.needsValue !== false &&
							( options ? (
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'Value',
										'formulario-mensaje'
									) }
									hideLabelFromVision
									value={ rule.value }
									options={ [
										{
											value: '',
											label: __(
												'Select an option',
												'formulario-mensaje'
											),
										},
										...options.map( ( option ) => ( {
											value: option.value,
											label: option.label,
										} ) ),
									] }
									onChange={ ( value ) =>
										setRule( index, { value } )
									}
								/>
							) : (
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'Value',
										'formulario-mensaje'
									) }
									hideLabelFromVision
									placeholder={ __(
										'Value',
										'formulario-mensaje'
									) }
									value={ rule.value || '' }
									onChange={ ( value ) =>
										setRule( index, { value } )
									}
								/>
							) ) }
						<Button
							size="small"
							icon="no-alt"
							isDestructive
							label={ __( 'Remove rule', 'formulario-mensaje' ) }
							onClick={ () =>
								setRules(
									rules.filter(
										( item, position ) => position !== index
									)
								)
							}
						/>
					</div>
				);
			} ) }

			{ note && rules.length > 0 && (
				<p className="fm-settings__note">{ note }</p>
			) }

			<Button
				variant="secondary"
				size="compact"
				disabled={ inputFields.length === 0 }
				onClick={ () =>
					setRules( [
						...rules,
						{ field: '', operator: 'equals', value: '' },
					] )
				}
			>
				{ __( 'Add rule', 'formulario-mensaje' ) }
			</Button>
		</fieldset>
	);
}
