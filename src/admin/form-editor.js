import {
	Button,
	Notice,
	Spinner,
	TabPanel,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { createForm, getForm, updateForm } from './api';
import { templates } from './config';
import FieldsPanel from './fields-panel';
import FormSettingsPanel from './form-settings-panel';
import NotificationPanel from './notification-panel';
import Preview from './preview';
import StoragePanel from './storage-panel';
import StylePanel from './style-panel';
import WebhooksPanel from './webhooks-panel';

const TABS = [
	{ name: 'fields', title: __( 'Fields', 'formulario-mensaje' ) },
	{
		name: 'notification',
		title: __( 'Notifications', 'formulario-mensaje' ),
	},
	{ name: 'style', title: __( 'Style', 'formulario-mensaje' ) },
	{ name: 'webhooks', title: __( 'Webhooks', 'formulario-mensaje' ) },
	{ name: 'storage', title: __( 'Storage', 'formulario-mensaje' ) },
	{ name: 'settings', title: __( 'After sending', 'formulario-mensaje' ) },
];

/**
 * Constructor de un formulario: campos, notificación y comportamiento, con vista previa en vivo.
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
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const noticeRef = useRef( null );

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
			.then( ( form ) => {
				setTitle( form.title );
				setDefinition( form.definition );
				setShortcode( form.shortcode );
			} )
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
				setTitle( form.title );
				setDefinition( form.definition );
				setShortcode( form.shortcode );
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
			onClose();
		}
	};

	if ( loadError ) {
		return (
			<div className="fm-admin">
				<Notice status="error" isDismissible={ false }>
					{ loadError }
				</Notice>
				<p>
					<Button variant="secondary" onClick={ onClose }>
						{ __( 'Back to forms', 'formulario-mensaje' ) }
					</Button>
				</p>
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

	return (
		<div className="fm-admin fm-editor">
			<div className="fm-editor__header">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					icon="arrow-left-alt2"
					onClick={ close }
				>
					{ __( 'Forms', 'formulario-mensaje' ) }
				</Button>
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
				{ shortcode && (
					<code className="fm-admin__shortcode">{ shortcode }</code>
				) }
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

			<div ref={ noticeRef }>
				{ notice && (
					<Notice
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
					</Notice>
				) }
			</div>

			<div className="fm-editor__body">
				<div className="fm-editor__panel">
					<TabPanel tabs={ TABS }>
						{ ( tab ) => (
							<div className="fm-editor__tab">
								{ tab.name === 'fields' && (
									<FieldsPanel
										fields={ definition.fields }
										onChange={ ( fields ) =>
											change( { fields } )
										}
									/>
								) }
								{ tab.name === 'notification' && (
									<NotificationPanel
										title={ title }
										definition={ definition }
										onChange={ ( actions ) =>
											change( { actions } )
										}
									/>
								) }
								{ tab.name === 'style' && (
									<StylePanel
										style={ definition.style }
										onChange={ ( style ) =>
											change( { style } )
										}
									/>
								) }
								{ tab.name === 'webhooks' && (
									<WebhooksPanel
										title={ title }
										definition={ definition }
										onChange={ ( actions ) =>
											change( { actions } )
										}
									/>
								) }
								{ tab.name === 'storage' && (
									<StoragePanel
										settings={ definition.settings }
										onChange={ ( settings ) =>
											change( { settings } )
										}
									/>
								) }
								{ tab.name === 'settings' && (
									<FormSettingsPanel
										settings={ definition.settings }
										fields={ definition.fields }
										onChange={ ( settings ) =>
											change( { settings } )
										}
									/>
								) }
							</div>
						) }
					</TabPanel>
				</div>

				<div className="fm-editor__preview">
					<Preview title={ title } definition={ definition } />
				</div>
			</div>
		</div>
	);
}
