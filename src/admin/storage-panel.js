import {
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { can } from './config';

/**
 * Pestaña de guardado: si los envíos se guardan, con qué metadatos y durante cuánto tiempo.
 *
 * @param {Object}   props
 * @param {Object}   props.settings Ajustes del formulario.
 * @param {Function} props.onChange Recibe los ajustes nuevos.
 */
export default function StoragePanel( { settings, onChange } ) {
	const set = ( key, value ) => onChange( { ...settings, [ key ]: value } );
	const enabled = settings.store_enabled !== false;

	return (
		<div className="fm-settings">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Store the submissions', 'formulario-mensaje' ) }
				help={ __(
					'Stored submissions can be read, searched and exported on the Messages screen, and a failed notification can be sent again. Without storage, a submission only exists in the notifications it sends.',
					'formulario-mensaje'
				) }
				checked={ enabled }
				onChange={ ( value ) => set( 'store_enabled', value ) }
			/>

			{ enabled && (
				<>
					<p className="fm-settings__note">
						{ __(
							'The date, the form and the address of the page are always stored.',
							'formulario-mensaje'
						) }
					</p>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'IP address', 'formulario-mensaje' ) }
						help={ __(
							'An anonymized address loses its last part: it no longer identifies a person, but still shows roughly where the submission came from.',
							'formulario-mensaje'
						) }
						value={ settings.store_ip || 'none' }
						options={ [
							{
								value: 'none',
								label: __(
									'Do not store it',
									'formulario-mensaje'
								),
							},
							{
								value: 'anonymized',
								label: __(
									'Store it anonymized',
									'formulario-mensaje'
								),
							},
							{
								value: 'full',
								label: __(
									'Store it complete',
									'formulario-mensaje'
								),
							},
						] }
						onChange={ ( value ) => set( 'store_ip', value ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Store the browser (user agent)',
							'formulario-mensaje'
						) }
						checked={ !! settings.store_user_agent }
						onChange={ ( value ) =>
							set( 'store_user_agent', value )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						max={ 3650 }
						label={ __(
							'Delete submissions after (days)',
							'formulario-mensaje'
						) }
						disabled={ ! can.viewSubmissions }
						help={
							can.viewSubmissions
								? __(
										'0 keeps them until someone deletes them. The cleanup runs once a day.',
										'formulario-mensaje'
									)
								: __(
										'Only someone who can delete messages can change this: it deletes them automatically.',
										'formulario-mensaje'
									)
						}
						value={ String( settings.retention_days ?? 0 ) }
						onChange={ ( value ) =>
							set(
								'retention_days',
								Math.max( 0, parseInt( value, 10 ) || 0 )
							)
						}
					/>
				</>
			) }
		</div>
	);
}
