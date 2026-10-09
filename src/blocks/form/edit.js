import apiFetch from '@wordpress/api-fetch';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ServerSideRender } from '@wordpress/server-side-render';

import metadata from './block.json';

/**
 * Lista los formularios publicados. Quien edita contenidos recibe solo su nombre e identificador.
 *
 * @return {{forms: ?Array, error: string}} Formularios (null mientras cargan) y mensaje de error.
 */
function useForms() {
	const [ forms, setForms ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;

		apiFetch( { path: '/formulario-mensaje/v1/forms' } )
			.then( ( items ) => ! cancelled && setForms( items ) )
			.catch( ( failure ) => {
				if ( ! cancelled ) {
					setForms( [] );
					setError(
						failure?.message ||
							__(
								'The forms could not be loaded.',
								'formulario-mensaje'
							)
					);
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	return { forms, error };
}

export default function Edit( { attributes, setAttributes } ) {
	const { formId } = attributes;
	const { forms, error } = useForms();
	const blockProps = useBlockProps();

	const options = [
		{ value: 0, label: __( 'Select a form', 'formulario-mensaje' ) },
		...( forms || [] ).map( ( form ) => ( {
			value: form.id,
			label: form.title,
		} ) ),
	];

	const selector = (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Form', 'formulario-mensaje' ) }
			value={ formId }
			options={ options }
			onChange={ ( value ) =>
				setAttributes( { formId: Number( value ) } )
			}
		/>
	);

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Form', 'formulario-mensaje' ) }>
					{ selector }
				</PanelBody>
			</InspectorControls>

			{ formId ? (
				// El formulario se ve, pero no se puede usar dentro del editor.
				<Disabled>
					<ServerSideRender
						block={ metadata.name }
						attributes={ { formId } }
					/>
				</Disabled>
			) : (
				<Placeholder
					icon="feedback"
					label={ __( 'Form', 'formulario-mensaje' ) }
					instructions={
						error ||
						__(
							'Choose the form to show. Forms are created in the Forms menu.',
							'formulario-mensaje'
						)
					}
				>
					{ forms === null ? <Spinner /> : selector }
				</Placeholder>
			) }
		</div>
	);
}
