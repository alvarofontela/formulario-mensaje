import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { createForm, getForm, updateForm } from './api';
import { templates } from './config';
import FieldsPanel from './fields-panel';
import { useUrlParam } from './hooks';
import Preview from './preview';
import PublishPanel from './publish-panel';
import ReceivedPanel from './received-panel';
import StylePanel from './style-panel';

// Los pasos van en el orden en que se piensa un formulario: qué se pregunta, qué pasa al recibirlo,
// cómo se ve y dónde se pone. No obligan a nada: se puede saltar de uno a otro.
const STEPS = [
	{
		name: 'fields',
		title: __( 'Questions', 'formulario-mensaje' ),
		heading: __( 'What do you want to ask?', 'formulario-mensaje' ),
		lead: __(
			'Drag to change the order. Press a field to change it.',
			'formulario-mensaje'
		),
	},
	{
		name: 'received',
		title: __( 'When it arrives', 'formulario-mensaje' ),
		heading: __(
			'What happens when someone sends it?',
			'formulario-mensaje'
		),
		lead: __( 'You can combine several things.', 'formulario-mensaje' ),
	},
	{
		name: 'style',
		title: __( 'Look', 'formulario-mensaje' ),
		heading: __( 'How should it look?', 'formulario-mensaje' ),
		lead: __(
			'The changes show right away in the preview.',
			'formulario-mensaje'
		),
	},
	{
		name: 'publish',
		title: __( 'Publish', 'formulario-mensaje' ),
		heading: __( 'Where do we put it?', 'formulario-mensaje' ),
		lead: '',
	},
];

/**
 * Constructor de un formulario, por pasos y con vista previa en vivo.
 *
 * @param {Object}   props
 * @param {number}   props.formId     Identificador del formulario, o 0 para uno nuevo.
 * @param {string}   props.templateId Plantilla de partida de un formulario nuevo.
 * @param {Function} props.onClose    Vuelve al listado.
 * @param {Function} props.onCreated  Avisa del identificador de un formulario recién creado.
 */
export default function FormEditor( {
	formId,
	templateId,
	onClose,
	onCreated,
} ) {
	const [ id, setId ] = useState( formId );
	const [ title, setTitle ] = useState( '' );
	const [ definition, setDefinition ] = useState( null );
	const [ shortcode, setShortcode ] = useState( '' );
	const [ placements, setPlacements ] = useState( [] );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ peeking, setPeeking ] = useState( false );
	const [ stepParam, setStepParam ] = useUrlParam( 'step' );
	const noticeRef = useRef( null );

	const stepIndex = Math.max(
		0,
		STEPS.findIndex( ( item ) => item.name === stepParam )
	);
	const step = STEPS[ stepIndex ];
	const nextStep = STEPS[ stepIndex + 1 ];

	const apply = ( form ) => {
		setTitle( form.title );
		setDefinition( form.definition );
		setShortcode( form.shortcode );
		setPlacements( form.placements || [] );
	};

	useEffect( () => {
		if ( ! formId ) {
			setTitle( '' );
			setDefinition(
				structuredClone(
					(
						templates.find(
							( template ) => template.id === templateId
						) || templates[ 0 ]
					).definition
				)
			);
			// Un formulario nuevo todavía no existe: hay algo que guardar desde el principio.
			setDirty( true );
			return;
		}

		getForm( formId )
			.then( apply )
			.catch( ( error ) => setLoadError( error.message ) );
	}, [ formId, templateId ] );

	// Aviso del navegador si se cierra o recarga la pestaña con cambios sin guardar.
	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}

		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};

		window.addEventListener( 'beforeunload', warn );

		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	const change = useCallback( ( patch ) => {
		setDefinition( ( current ) => ( { ...current, ...patch } ) );
		setDirty( true );
	}, [] );

	const save = () => {
		setSaving( true );
		setNotice( null );

		const payload = { title, definition };

		( id ? updateForm( id, payload ) : createForm( payload ) )
			.then( ( form ) => {
				if ( ! id ) {
					setId( form.id );
					onCreated( form.id );
				}

				// El servidor devuelve la definición saneada: es la que queda en pantalla.
				apply( form );
				setDirty( false );
				setNotice( {
					status: 'success',
					message: __( 'Form saved.', 'formulario-mensaje' ),
				} );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			)
			.finally( () => {
				setSaving( false );
				noticeRef.current?.scrollIntoView( { block: 'nearest' } );
			} );
	};

	const close = () => {
		if (
			! dirty ||
			! id ||
			// eslint-disable-next-line no-alert -- Confirmación nativa: el usuario está a punto de perder cambios.
			window.confirm(
				__(
					'You have unsaved changes. Leave without saving?',
					'formulario-mensaje'
				)
			)
		) {
			// El paso es del formulario que se cierra: el listado no lo necesita.
			if ( stepParam ) {
				const url = new URL( window.location.href );

				url.searchParams.delete( 'step' );
				window.history.replaceState( {}, '', url );
			}

			onClose();
		}
	};

	if ( loadError ) {
		return (
			<div className="fm-admin">
				<div className="fm-page__body">
					<Notice status="error" isDismissible={ false }>
						{ loadError }
					</Notice>
					<p>
						<Button variant="secondary" onClick={ onClose }>
							{ __( 'Back to forms', 'formulario-mensaje' ) }
						</Button>
					</p>
				</div>
			</div>
		);
	}

	if ( ! definition ) {
		return (
			<div className="fm-admin fm-admin__loading">
				<Spinner />
			</div>
		);
	}

	// Un formulario así acepta el envío y da las gracias, pero no hace nada con él: conviene decirlo antes de publicarlo.
	const losesSubmissions =
		definition.settings?.store_enabled === false &&
		! ( definition.actions || [] ).some(
			( action ) => action.enabled !== false
		);

	let saveState = __( 'No changes to save', 'formulario-mensaje' );

	if ( dirty ) {
		saveState = id
			? __( 'Unsaved changes', 'formulario-mensaje' )
			: __( 'Not created yet', 'formulario-mensaje' );
	}

	return (
		<div
			className={ `fm-admin fm-editor${ peeking ? ' is-peeking' : '' }` }
		>
			<div className="fm-editor__header">
				<Button
					__next40pxDefaultSize
					icon="arrow-left-alt2"
					label={ __( 'Back to forms', 'formulario-mensaje' ) }
					onClick={ close }
				/>
				<h1 className="screen-reader-text">
					{ id
						? __( 'Edit form', 'formulario-mensaje' )
						: __( 'New form', 'formulario-mensaje' ) }
				</h1>
				<div className="fm-editor__title">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Form name', 'formulario-mensaje' ) }
						hideLabelFromVision
						placeholder={ __( 'Form name', 'formulario-mensaje' ) }
						value={ title }
						onChange={ ( value ) => {
							setTitle( value );
							setDirty( true );
						} }
					/>
				</div>
				<span className="fm-editor__state">{ saveState }</span>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ saving }
					disabled={ saving || ! dirty }
					onClick={ save }
				>
					{ id
						? __( 'Save', 'formulario-mensaje' )
						: __( 'Create form', 'formulario-mensaje' ) }
				</Button>
			</div>

			<div
				className="fm-steps"
				role="tablist"
				aria-label={ __(
					'Steps to build the form',
					'formulario-mensaje'
				) }
			>
				{ STEPS.map( ( item ) => (
					<button
						key={ item.name }
						type="button"
						role="tab"
						id={ `fm-step-${ item.name }` }
						className="fm-steps__item"
						aria-selected={ item.name === step.name }
						aria-controls="fm-step-panel"
						onClick={ () => setStepParam( item.name ) }
					>
						{ item.title }
					</button>
				) ) }
			</div>

			<div className="fm-editor__body">
				<div
					className="fm-editor__panel"
					role="tabpanel"
					id="fm-step-panel"
					aria-labelledby={ `fm-step-${ step.name }` }
				>
					<div ref={ noticeRef }>
						{ notice && (
							<Notice
								status={ notice.status }
								onRemove={ () => setNotice( null ) }
							>
								{ notice.message }
							</Notice>
						) }
						{ losesSubmissions && (
							<Notice
								className="fm-editor__warning"
								status="warning"
								isDismissible={ false }
							>
								{ __(
									'This form does not store its submissions and has no active notification or webhook: whatever visitors send will be lost.',
									'formulario-mensaje'
								) }
							</Notice>
						) }
					</div>

					<h2 className="fm-editor__heading">{ step.heading }</h2>
					{ step.lead && (
						<p className="fm-editor__lead">{ step.lead }</p>
					) }

					{ step.name === 'fields' && (
						<FieldsPanel
							fields={ definition.fields }
							onChange={ ( fields ) => change( { fields } ) }
						/>
					) }
					{ step.name === 'received' && (
						<ReceivedPanel
							title={ title }
							definition={ definition }
							onChange={ change }
						/>
					) }
					{ step.name === 'style' && (
						<StylePanel
							style={ definition.style }
							onChange={ ( style ) => change( { style } ) }
						/>
					) }
					{ step.name === 'publish' && (
						<PublishPanel
							title={ title }
							shortcode={ shortcode }
							placements={ placements }
						/>
					) }

					<div className="fm-editor__footer">
						<Button
							__next40pxDefaultSize
							className="fm-editor__peek"
							variant="secondary"
							icon="visibility"
							onClick={ () => setPeeking( true ) }
						>
							{ __( 'Preview', 'formulario-mensaje' ) }
						</Button>
						{ nextStep && (
							<Button
								__next40pxDefaultSize
								variant="secondary"
								icon="arrow-right-alt2"
								iconPosition="right"
								onClick={ () => {
									setStepParam( nextStep.name );
									window.scrollTo( 0, 0 );
								} }
							>
								{ sprintf(
									/* translators: %s: name of the next step. */
									__( 'Next: %s', 'formulario-mensaje' ),
									nextStep.title
								) }
							</Button>
						) }
					</div>
				</div>

				<div className="fm-editor__preview">
					<Preview
						title={ title }
						definition={ definition }
						onClose={ () => setPeeking( false ) }
					/>
				</div>
			</div>
		</div>
	);
}
