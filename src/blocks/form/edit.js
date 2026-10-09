import apiFetch from '@wordpress/api-fetch';
import {
	InspectorControls,
	PanelColorSettings,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ServerSideRender } from '@wordpress/server-side-render';

import metadata from './block.json';

/*
 * Controles de estilo del bloque. Las claves son las del estilo de un formulario (Form_Style en
 * PHP), que es quien valida los valores y fija los límites definitivos al pintar.
 */
const COLORS = [
	{ key: 'label_color', label: __( 'Labels', 'formulario-mensaje' ) },
	{ key: 'field_color', label: __( 'Field text', 'formulario-mensaje' ) },
	{
		key: 'field_background',
		label: __( 'Field background', 'formulario-mensaje' ),
	},
	{ key: 'border_color', label: __( 'Borders', 'formulario-mensaje' ) },
	{ key: 'focus_color', label: __( 'Focus outline', 'formulario-mensaje' ) },
	{
		key: 'button_background',
		label: __( 'Button background', 'formulario-mensaje' ),
	},
	{ key: 'button_color', label: __( 'Button text', 'formulario-mensaje' ) },
];

const SIZES = [
	{
		key: 'gap',
		label: __( 'Space between fields', 'formulario-mensaje' ),
		min: 4,
		max: 64,
	},
	{
		key: 'field_padding',
		label: __( 'Field padding', 'formulario-mensaje' ),
		min: 2,
		max: 32,
	},
	{
		key: 'border_width',
		label: __( 'Border width', 'formulario-mensaje' ),
		min: 0,
		max: 8,
	},
	{
		key: 'border_radius',
		label: __( 'Border radius', 'formulario-mensaje' ),
		min: 0,
		max: 40,
	},
];

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
	const formStyle =
		attributes.formStyle && ! Array.isArray( attributes.formStyle )
			? attributes.formStyle
			: {};
	const { forms, error } = useForms();
	const blockProps = useBlockProps();

	// Sin valor, el ajuste se quita: el formulario vuelve a usar su propio estilo o el del tema.
	const setStyle = ( key, value ) => {
		const next = { ...formStyle };

		if ( value === undefined || value === null || value === '' ) {
			delete next[ key ];
		} else {
			next[ key ] = value;
		}

		setAttributes( { formStyle: next } );
	};

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

			<InspectorControls group="styles">
				<PanelColorSettings
					__experimentalIsRenderedInSidebar
					title={ __( 'Form colors', 'formulario-mensaje' ) }
					initialOpen={ false }
					colorSettings={ COLORS.map( ( color ) => ( {
						value: formStyle[ color.key ],
						label: color.label,
						onChange: ( value ) => setStyle( color.key, value ),
					} ) ) }
				/>
				<PanelBody
					title={ __(
						'Form spacing and borders',
						'formulario-mensaje'
					) }
					initialOpen={ false }
				>
					{ SIZES.map( ( size ) => (
						<RangeControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							key={ size.key }
							label={ size.label }
							min={ size.min }
							max={ size.max }
							allowReset
							resetFallbackValue={ undefined }
							value={ formStyle[ size.key ] }
							onChange={ ( value ) =>
								setStyle( size.key, value )
							}
						/>
					) ) }
				</PanelBody>
			</InspectorControls>

			{ formId ? (
				// El formulario se ve, pero no se puede usar dentro del editor.
				<Disabled>
					<ServerSideRender
						block={ metadata.name }
						attributes={ { formId, formStyle } }
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
