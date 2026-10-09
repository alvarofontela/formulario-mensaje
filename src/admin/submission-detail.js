import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	deleteSubmissions,
	dismissDelivery,
	getSubmission,
	retryDelivery,
} from './api';

const BADGES = { success: 'ok', pending: 'wait' };

const STATUS_LABELS = {
	success: __( 'Delivered', 'formulario-mensaje' ),
	failed: __( 'Failed', 'formulario-mensaje' ),
	dismissed: __( 'Failed (dismissed)', 'formulario-mensaje' ),
	pending: __( 'In the queue', 'formulario-mensaje' ),
};

/**
 * Detalle de un envío: sus datos, de dónde vino y qué pasó con cada acción.
 *
 * Los valores los escribió un visitante anónimo. Se pintan siempre como texto: React los escapa.
 *
 * @param {Object}   props
 * @param {number}   props.submissionId Identificador del envío.
 * @param {Function} props.onClose      Vuelve al listado.
 */
export default function SubmissionDetail( { submissionId, onClose } ) {
	const [ submission, setSubmission ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( 0 );
	const [ confirming, setConfirming ] = useState( false );

	const load = useCallback( () => {
		getSubmission( submissionId )
			.then( setSubmission )
			.catch( ( failure ) => setError( failure.message ) );
	}, [ submissionId ] );

	useEffect( load, [ load ] );

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

	const remove = () => {
		setConfirming( false );

		deleteSubmissions( [ submissionId ] )
			.then( onClose )
			.catch( ( failure ) =>
				setNotice( { status: 'error', message: failure.message } )
			);
	};

	const back = (
		<Button
			__next40pxDefaultSize
			variant="tertiary"
			icon="arrow-left-alt2"
			onClick={ onClose }
		>
			{ __( 'Submissions', 'formulario-mensaje' ) }
		</Button>
	);

	if ( error ) {
		return (
			<div className="fm-admin">
				<div className="fm-admin__header">{ back }</div>
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			</div>
		);
	}

	if ( ! submission ) {
		return (
			<div className="fm-admin fm-admin__loading">
				<Spinner />
			</div>
		);
	}

	const dateFormat = getDateSettings().formats.datetime;

	return (
		<div className="fm-admin fm-detail">
			<div className="fm-admin__header">
				{ back }
				<h1 className="fm-admin__title">
					{ sprintf(
						/* translators: %s: reference number of a submission. */
						__( 'Submission %s', 'formulario-mensaje' ),
						submission.reference
					) }
				</h1>
				<Button
					__next40pxDefaultSize
					className="fm-detail__delete"
					variant="secondary"
					isDestructive
					onClick={ () => setConfirming( true ) }
				>
					{ __( 'Delete', 'formulario-mensaje' ) }
				</Button>
			</div>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<div className="fm-detail__body">
				<section className="fm-card">
					<h2>{ __( 'Data received', 'formulario-mensaje' ) }</h2>
					<dl className="fm-detail__fields">
						{ submission.entries.map( ( entry ) => (
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
				</section>

				<div>
					<section className="fm-card">
						<h2>{ __( 'Details', 'formulario-mensaje' ) }</h2>
						<dl className="fm-detail__meta">
							<dt>{ __( 'Date', 'formulario-mensaje' ) }</dt>
							<dd>
								{ dateI18n(
									dateFormat,
									submission.created_at
								) }
							</dd>
							<dt>{ __( 'Form', 'formulario-mensaje' ) }</dt>
							<dd>
								{ submission.form_title ||
									__(
										'(deleted form)',
										'formulario-mensaje'
									) }
							</dd>
							{ submission.page_url && (
								<>
									<dt>
										{ __( 'Page', 'formulario-mensaje' ) }
									</dt>
									<dd className="fm-detail__break">
										{ submission.page_url }
									</dd>
								</>
							) }
							{ submission.ip && (
								<>
									<dt>
										{ __(
											'IP address',
											'formulario-mensaje'
										) }
									</dt>
									<dd>{ submission.ip }</dd>
								</>
							) }
							{ submission.user_agent && (
								<>
									<dt>
										{ __(
											'Browser',
											'formulario-mensaje'
										) }
									</dt>
									<dd className="fm-detail__break">
										{ submission.user_agent }
									</dd>
								</>
							) }
						</dl>
					</section>

					<section className="fm-card">
						<h2>{ __( 'Actions', 'formulario-mensaje' ) }</h2>
						{ submission.deliveries.length === 0 && (
							<p>
								{ __(
									'This form did not run any action with this submission.',
									'formulario-mensaje'
								) }
							</p>
						) }
						<ul className="fm-detail__deliveries">
							{ submission.deliveries.map( ( delivery ) => (
								<li key={ delivery.id }>
									<div>
										<strong>
											{ delivery.action_label }
										</strong>{ ' ' }
										<span
											className={ `fm-badge fm-badge--${
												BADGES[ delivery.status ] ||
												'error'
											}` }
										>
											{ STATUS_LABELS[
												delivery.status
											] || delivery.status }
										</span>
									</div>
									{ delivery.error && (
										<p className="fm-detail__error">
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
												dateFormat,
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
														dateFormat,
														delivery.next_attempt
													)
												) }
											</p>
										) }
									{ delivery.status === 'failed' && (
										<div className="fm-detail__delivery-actions">
											{ delivery.can_retry && (
												<Button
													variant="secondary"
													size="compact"
													isBusy={
														busy === delivery.id
													}
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
												variant="tertiary"
												size="compact"
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
								</li>
							) ) }
						</ul>
					</section>
				</div>
			</div>

			{ confirming && (
				<Modal
					title={ __( 'Delete submission?', 'formulario-mensaje' ) }
					onRequestClose={ () => setConfirming( false ) }
					size="small"
				>
					<p>
						{ __(
							'The submission will be deleted and cannot be recovered.',
							'formulario-mensaje'
						) }
					</p>
					<div className="fm-admin__modal-actions">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ () => setConfirming( false ) }
						>
							{ __( 'Cancel', 'formulario-mensaje' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							isDestructive
							onClick={ remove }
						>
							{ __( 'Delete', 'formulario-mensaje' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
