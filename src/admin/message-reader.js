import { Button, Dashicon, Notice, Spinner } from '@wordpress/components';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	deleteSubmissions,
	dismissDelivery,
	getSubmission,
	markSubmissionsRead,
	retryDelivery,
} from './api';
import ConfirmModal from './confirm-modal';

const BADGES = { success: 'ok', pending: 'wait' };

const STATUS_LABELS = {
	success: __( 'Delivered', 'formulario-mensaje' ),
	failed: __( 'Failed', 'formulario-mensaje' ),
	dismissed: __( 'Failed (dismissed)', 'formulario-mensaje' ),
	pending: __( 'In the queue', 'formulario-mensaje' ),
};

/**
 * Nombre con el que se encabeza un mensaje: quien escribe, su correo o, si no hay nada, la referencia.
 *
 * @param {Object} message Mensaje, del listado o completo.
 * @return {string} Texto del encabezado.
 */
export function messageTitle( message ) {
	return (
		message.sender ||
		message.email ||
		sprintf(
			/* translators: %s: reference number of a message. */
			__( 'Message %s', 'formulario-mensaje' ),
			message.reference
		)
	);
}

/**
 * Reparte los datos de un mensaje: el texto largo, que se lee como el mensaje en sí, y el resto.
 *
 * Lo que ya se ve en el encabezado (quién escribe, su correo y su teléfono) no se repite.
 *
 * @param {Object} message Mensaje completo.
 * @return {{body: ?Object, facts: Array}} El dato que hace de cuerpo, si lo hay, y los demás.
 */
function splitEntries( message ) {
	const body = message.entries.find(
		( entry ) => entry.type === 'textarea' && entry.value
	);
	// Qué campos encabezan el mensaje lo dice el servidor, que es quien decide qué se enseña arriba.
	const header = message.header_fields || [];

	return {
		body,
		facts: message.entries.filter(
			( entry ) => entry !== body && ! header.includes( entry.field_id )
		),
	};
}

/**
 * Lectura de un mensaje: quién escribe, qué dice, sus ficheros y qué pasó con cada entrega.
 *
 * Los valores los escribió un visitante anónimo. Se pintan siempre como texto: React los escapa.
 *
 * @param {Object}   props
 * @param {number}   props.messageId Identificador del mensaje.
 * @param {Function} props.onBack    Vuelve a la lista (en pantallas estrechas).
 * @param {Function} props.onChange  Avisa de que el mensaje ha cambiado: recibe su identificador y los datos nuevos.
 * @param {Function} props.onCounts  Recibe el recuento de sin leer cuando el servidor lo devuelve.
 * @param {Function} props.onDeleted Avisa de que el mensaje se ha borrado.
 */
export default function MessageReader( {
	messageId,
	onBack,
	onChange,
	onCounts,
	onDeleted,
} ) {
	const [ message, setMessage ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( 0 );
	const [ confirming, setConfirming ] = useState( false );

	const load = useCallback(
		() =>
			getSubmission( messageId )
				.then( ( loaded ) => {
					setMessage( loaded );
					onChange( loaded.id, {
						failed_actions: loaded.failed_actions,
					} );

					return loaded;
				} )
				.catch( ( failure ) => setError( failure.message ) ),
		[ messageId, onChange ]
	);

	useEffect( () => {
		setMessage( null );
		setError( '' );
		setNotice( null );

		// Abrir un mensaje es leerlo: se marca en cuanto llega.
		load().then( ( loaded ) => {
			if ( loaded && ! loaded.read ) {
				markSubmissionsRead( [ loaded.id ] )
					.then( ( counts ) => {
						onChange( loaded.id, { read: true } );
						onCounts( counts );
					} )
					.catch( () => {} );
			}
		} );
	}, [ load, onChange, onCounts ] );

	const run = ( id, action, success ) => {
		setBusy( id );
		setNotice( null );

		action( id )
			.then( ( delivery ) =>
				setNotice(
					delivery?.status === 'failed'
						? {
								status: 'error',
								message: sprintf(
									/* translators: %s: error returned by the action. */
									__(
										'It failed again: %s',
										'formulario-mensaje'
									),
									delivery.error
								),
							}
						: { status: 'success', message: success }
				)
			)
			.catch( ( failure ) =>
				setNotice( { status: 'error', message: failure.message } )
			)
			.finally( () => {
				setBusy( 0 );
				load();
			} );
	};

	const markUnread = () =>
		markSubmissionsRead( [ messageId ], false )
			.then( ( counts ) => {
				onChange( messageId, { read: false } );
				onCounts( counts );
				onBack();
			} )
			.catch( ( failure ) =>
				setNotice( { status: 'error', message: failure.message } )
			);

	const remove = () => {
		setConfirming( false );

		deleteSubmissions( [ messageId ] )
			.then( () => onDeleted( messageId ) )
			.catch( ( failure ) =>
				setNotice( { status: 'error', message: failure.message } )
			);
	};

	const back = (
		<Button
			__next40pxDefaultSize
			className="fm-reader__back"
			icon="arrow-left-alt2"
			label={ __( 'Back to the messages', 'formulario-mensaje' ) }
			onClick={ onBack }
		/>
	);

	if ( error ) {
		return (
			<div className="fm-reader">
				<div className="fm-reader__bar">{ back }</div>
				<div className="fm-reader__content">
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				</div>
			</div>
		);
	}

	if ( ! message ) {
		return (
			<div className="fm-reader fm-admin__loading">
				<Spinner />
			</div>
		);
	}

	const formats = getDateSettings().formats;
	const { body, facts } = splitEntries( message );
	const formTitle =
		message.form_title || __( '(deleted form)', 'formulario-mensaje' );

	return (
		<div className="fm-reader">
			<div className="fm-reader__bar">
				{ back }
				{ message.email && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						icon="email-alt"
						// Abre el programa de correo del usuario con la dirección ya puesta: el plugin no envía nada.
						href={ `mailto:${ encodeURIComponent(
							message.email
						) }?subject=${ encodeURIComponent(
							sprintf(
								/* translators: %s: name of a form. */
								__( 'Re: %s', 'formulario-mensaje' ),
								formTitle
							)
						) }` }
					>
						{ __( 'Reply', 'formulario-mensaje' ) }
					</Button>
				) }
				{ message.phone && (
					<Button
						__next40pxDefaultSize
						className="fm-reader__call"
						variant="secondary"
						icon="phone"
						href={ `tel:${ message.phone.replace(
							/[^\d+]/g,
							''
						) }` }
					>
						{ __( 'Call', 'formulario-mensaje' ) }
					</Button>
				) }
				<div className="fm-reader__tools">
					<Button
						__next40pxDefaultSize
						className="fm-reader__close"
						icon="no-alt"
						label={ __(
							'Close the message',
							'formulario-mensaje'
						) }
						onClick={ onBack }
					/>
					<Button
						__next40pxDefaultSize
						icon="marker"
						label={ __( 'Mark as unread', 'formulario-mensaje' ) }
						onClick={ markUnread }
					/>
					<Button
						__next40pxDefaultSize
						icon="trash"
						label={ __( 'Delete', 'formulario-mensaje' ) }
						onClick={ () => setConfirming( true ) }
					/>
				</div>
			</div>

			<div className="fm-reader__content">
				{ notice && (
					<Notice
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
					</Notice>
				) }

				<h2 className="fm-reader__title">
					{ messageTitle( message ) }
				</h2>
				{ ( message.email || message.phone ) && (
					<p className="fm-reader__contact">
						{ message.email && message.sender && (
							<span>{ message.email }</span>
						) }
						{ message.phone && <span>{ message.phone }</span> }
					</p>
				) }
				<p className="fm-reader__meta">
					{ sprintf(
						/* translators: 1: name of a form, 2: date and time. */
						__(
							'Sent with the form %1$s · %2$s',
							'formulario-mensaje'
						),
						formTitle,
						dateI18n( formats.datetime, message.created_at )
					) }
				</p>

				{ body && <p className="fm-reader__text">{ body.value }</p> }

				{ facts.length > 0 && (
					<dl className="fm-reader__facts">
						{ facts.map( ( entry ) => (
							<div key={ entry.field_id }>
								<dt>{ entry.label || entry.field_id }</dt>
								<dd>
									{ entry.value || (
										<span className="fm-admin__muted">
											{ __(
												'(empty)',
												'formulario-mensaje'
											) }
										</span>
									) }
								</dd>
							</div>
						) ) }
					</dl>
				) }

				{ message.files?.length > 0 && (
					<div className="fm-reader__files">
						<h3>{ __( 'Files', 'formulario-mensaje' ) }</h3>
						<ul>
							{ message.files.map( ( file ) => (
								<li key={ file.url }>
									<Dashicon icon="paperclip" />
									<a href={ file.url }>
										{ file.name }
									</a>{ ' ' }
									<span className="fm-admin__muted">
										{ file.size }
									</span>
								</li>
							) ) }
						</ul>
					</div>
				) }

				<h3 className="fm-reader__heading">
					{ __(
						'What happened to this message',
						'formulario-mensaje'
					) }
				</h3>
				<ol className="fm-trail">
					<li className="is-success">
						<Dashicon icon="yes" />
						<div>
							<strong>
								{ __(
									'Stored in Messages',
									'formulario-mensaje'
								) }
							</strong>
							<p className="fm-admin__muted">
								{ dateI18n(
									formats.datetime,
									message.created_at
								) }
							</p>
						</div>
					</li>
					{ message.deliveries.map( ( delivery ) => (
						<li
							key={ delivery.id }
							className={ `is-${ delivery.status }` }
						>
							<Dashicon
								icon={
									{ success: 'yes', pending: 'clock' }[
										delivery.status
									] || 'warning'
								}
							/>
							<div>
								<strong>{ delivery.action_label }</strong>{ ' ' }
								{ delivery.action_name && (
									<span className="fm-admin__muted">
										{ delivery.action_name }
									</span>
								) }{ ' ' }
								<span
									className={ `fm-badge fm-badge--${
										BADGES[ delivery.status ] || 'error'
									}` }
								>
									{ STATUS_LABELS[ delivery.status ] ||
										delivery.status }
								</span>
								{ delivery.error && (
									<p className="fm-trail__error">
										{ delivery.error }
									</p>
								) }
								<p className="fm-admin__muted">
									{ sprintf(
										/* translators: 1: number of attempts, 2: date and time of the last attempt. */
										__(
											'Attempts: %1$d · Last: %2$s',
											'formulario-mensaje'
										),
										delivery.attempts,
										dateI18n(
											formats.datetime,
											delivery.updated_at
										)
									) }
								</p>
								{ delivery.status === 'pending' &&
									delivery.next_attempt && (
										<p className="fm-admin__muted">
											{ sprintf(
												/* translators: %s: date and time. */
												__(
													'Next attempt: %s',
													'formulario-mensaje'
												),
												dateI18n(
													formats.datetime,
													delivery.next_attempt
												)
											) }
										</p>
									) }
								{ delivery.status === 'failed' && (
									<div className="fm-trail__actions">
										{ delivery.can_retry && (
											<Button
												__next40pxDefaultSize
												variant="secondary"
												isBusy={ busy === delivery.id }
												disabled={ busy !== 0 }
												onClick={ () =>
													run(
														delivery.id,
														retryDelivery,
														__(
															'Delivered.',
															'formulario-mensaje'
														)
													)
												}
											>
												{ __(
													'Retry',
													'formulario-mensaje'
												) }
											</Button>
										) }
										<Button
											__next40pxDefaultSize
											variant="tertiary"
											disabled={ busy !== 0 }
											onClick={ () =>
												run(
													delivery.id,
													dismissDelivery,
													__(
														'Failure dismissed.',
														'formulario-mensaje'
													)
												)
											}
										>
											{ __(
												'Dismiss',
												'formulario-mensaje'
											) }
										</Button>
									</div>
								) }
							</div>
						</li>
					) ) }
				</ol>

				<details className="fm-reader__details">
					<summary>
						{ __( 'Technical details', 'formulario-mensaje' ) }
					</summary>
					<dl>
						<dt>{ __( 'Reference', 'formulario-mensaje' ) }</dt>
						<dd>{ message.reference }</dd>
						{ message.page_url && (
							<>
								<dt>{ __( 'Page', 'formulario-mensaje' ) }</dt>
								<dd>{ message.page_url }</dd>
							</>
						) }
						{ message.ip && (
							<>
								<dt>
									{ __( 'IP address', 'formulario-mensaje' ) }
								</dt>
								<dd>{ message.ip }</dd>
							</>
						) }
						{ message.user_agent && (
							<>
								<dt>
									{ __( 'Browser', 'formulario-mensaje' ) }
								</dt>
								<dd>{ message.user_agent }</dd>
							</>
						) }
					</dl>
				</details>
			</div>

			{ confirming && (
				<ConfirmModal
					title={ __( 'Delete message?', 'formulario-mensaje' ) }
					confirm={ __( 'Delete', 'formulario-mensaje' ) }
					onConfirm={ remove }
					onCancel={ () => setConfirming( false ) }
				>
					<p>
						{ __(
							'The message will be deleted and cannot be recovered.',
							'formulario-mensaje'
						) }
					</p>
				</ConfirmModal>
			) }
		</div>
	);
}
