import {
	Button,
	RadioControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { can, getFieldType, siteHost, urls } from './config';
import TagsHelp from './tags-help';
import { fieldTitle } from './utils';

/**
 * Pestaña con lo que ocurre tras un envío correcto y el texto del botón.
 *
 * @param {Object}   props
 * @param {Object}   props.settings Ajustes del formulario.
 * @param {Array}    props.fields   Campos del formulario.
 * @param {Function} props.onChange Recibe los ajustes nuevos.
 */
export default function FormSettingsPanel( { settings, fields, onChange } ) {
	const set = ( key, value ) => onChange( { ...settings, [ key ]: value } );
	const inputFields = fields.filter(
		( field ) => getFieldType( field.type )?.isInput
	);
	const mappings = Array.isArray( settings.redirect_fields )
		? settings.redirect_fields
		: [];

	const setMapping = ( index, patch ) =>
		set(
			'redirect_fields',
			mappings.map( ( mapping, position ) =>
				position === index ? { ...mapping, ...patch } : mapping
			)
		);

	return (
		<div className="fm-settings">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Button text', 'formulario-mensaje' ) }
				value={ settings.submit_label || '' }
				onChange={ ( value ) => set( 'submit_label', value ) }
			/>

			<h2 className="fm-settings__heading">
				{ __( 'After a successful submission', 'formulario-mensaje' ) }
			</h2>

			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Success message', 'formulario-mensaje' ) }
				help={ __(
					'You can use basic HTML (bold, italics and links) and tags.',
					'formulario-mensaje'
				) }
				rows={ 4 }
				value={ settings.success_message || '' }
				onChange={ ( value ) => set( 'success_message', value ) }
			/>
			<TagsHelp
				fields={ fields }
				onInsert={ ( tag ) =>
					set(
						'success_message',
						`${ settings.success_message || '' }${ tag }`
					)
				}
			/>

			<RadioControl
				label={ __( 'What happens to the form', 'formulario-mensaje' ) }
				selected={ settings.after_submit || 'hide' }
				options={ [
					{
						value: 'hide',
						label: __(
							'Hide it and show only the message',
							'formulario-mensaje'
						),
					},
					{
						value: 'reset',
						label: __(
							'Empty it to allow another submission',
							'formulario-mensaje'
						),
					},
				] }
				onChange={ ( value ) => set( 'after_submit', value ) }
			/>

			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Redirect to another page', 'formulario-mensaje' ) }
				help={ __(
					'The visitor is taken to this address instead of seeing the message.',
					'formulario-mensaje'
				) }
				checked={ !! settings.redirect_enabled }
				onChange={ ( value ) => set( 'redirect_enabled', value ) }
			/>

			{ settings.redirect_enabled && (
				<div className="fm-settings__group">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="url"
						label={ __( 'Address', 'formulario-mensaje' ) }
						placeholder={ `https://${ siteHost }/` }
						help={
							<>
								{ __(
									'Any address of this site is valid. Other domains must be in the list of allowed domains.',
									'formulario-mensaje'
								) }{ ' ' }
								{ can.manageSettings && (
									<a href={ urls.settings }>
										{ __(
											'Manage allowed domains',
											'formulario-mensaje'
										) }
									</a>
								) }
							</>
						}
						value={ settings.redirect_url || '' }
						onChange={ ( value ) => set( 'redirect_url', value ) }
					/>

					<fieldset className="fm-options">
						<legend className="fm-options__legend">
							{ __(
								'Fields added to the address',
								'formulario-mensaje'
							) }
						</legend>
						<p className="fm-settings__note">
							{ __(
								'Their values travel in the address and may end up in browser history and server logs. Add only what the destination page needs.',
								'formulario-mensaje'
							) }
						</p>
						{ mappings.map( ( mapping, index ) => (
							<div className="fm-options__row" key={ index }>
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'Field',
										'formulario-mensaje'
									) }
									hideLabelFromVision
									value={ mapping.field }
									options={ [
										{
											value: '',
											label: __(
												'Select a field',
												'formulario-mensaje'
											),
										},
										...inputFields.map( ( field ) => ( {
											value: field.id,
											label: fieldTitle( field ),
										} ) ),
									] }
									onChange={ ( field ) =>
										setMapping( index, { field } )
									}
								/>
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'Parameter name',
										'formulario-mensaje'
									) }
									hideLabelFromVision
									placeholder={ __(
										'Parameter name',
										'formulario-mensaje'
									) }
									value={ mapping.param }
									onChange={ ( param ) =>
										setMapping( index, { param } )
									}
								/>
								<Button
									size="small"
									icon="no-alt"
									isDestructive
									label={ __(
										'Remove field',
										'formulario-mensaje'
									) }
									onClick={ () =>
										set(
											'redirect_fields',
											mappings.filter(
												( item, position ) =>
													position !== index
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
								set( 'redirect_fields', [
									...mappings,
									{ field: '', param: '' },
								] )
							}
						>
							{ __( 'Add field', 'formulario-mensaje' ) }
						</Button>
					</fieldset>
				</div>
			) }

			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Report the submission for conversion tracking',
					'formulario-mensaje'
				) }
				help={ __(
					'Fires a JavaScript event and pushes “formulario_mensaje_submit” to dataLayer (Google Tag Manager). No personal data is included.',
					'formulario-mensaje'
				) }
				checked={ settings.tracking_enabled !== false }
				onChange={ ( value ) => set( 'tracking_enabled', value ) }
			/>
		</div>
	);
}
