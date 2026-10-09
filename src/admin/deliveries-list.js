import { Button, Notice, Spinner } from '@wordpress/components';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { dismissDelivery, listFailedDeliveries, retryDelivery } from './api';
import { useLatest, useReload } from './hooks';

const PER_PAGE = 20;

/**
 * Listado de entregas fallidas pendientes: acciones (un correo, por ejemplo) que no se pudieron
 * completar. Se pueden reintentar, si el envío está guardado, o descartar.
 *
 * @param {Object}   props
 * @param {Function} props.onOpenSubmission Abre el detalle del envío de una entrega.
 */
export default function DeliveriesList( { onOpenSubmission } ) {
	const [ page, setPage ] = useState( 1 );
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( 0 );
	const [ notice, setNotice ] = useState( null );

	const latest = useLatest();
	const [ version, reload ] = useReload();

	const load = useCallback( () => {
		latest( listFailedDeliveries( { page, per_page: PER_PAGE } ) )
			.then( setResult )
			.catch( ( error ) => {
				setResult( { items: [], total: 0, pages: 0 } );
				setNotice( { status: 'error', message: error.message } );
			} );
	}, [ page, latest ] );

	useEffect( () => {
		load();
	}, [ load, version ] );

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
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			)
			.finally( () => {
				setBusy( 0 );
				reload();
			} );
	};

	const dateFormat = getDateSettings().formats.datetimeAbbreviated;
	const items = result?.items || [];

	return (
		<>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ result === null && (
				<div className="fm-admin__loading">
					<Spinner />
				</div>
			) }

			{ result !== null && items.length === 0 && (
				<div className="fm-admin__empty">
					<p>
						{ __(
							'There are no failed deliveries. Everything has been delivered.',
							'formulario-mensaje'
						) }
					</p>
				</div>
			) }

			{ items.length > 0 && (
				<table className="wp-list-table widefat fixed striped fm-admin__table">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Action', 'formulario-mensaje' ) }
							</th>
							<th scope="col">
								{ __( 'Form', 'formulario-mensaje' ) }
							</th>
							<th scope="col" className="column-primary">
								{ __( 'Error', 'formulario-mensaje' ) }
							</th>
							<th scope="col">
								{ __( 'Last attempt', 'formulario-mensaje' ) }
							</th>
							<th scope="col">
								<span className="screen-reader-text">
									{ __( 'Actions', 'formulario-mensaje' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( delivery ) => (
							<tr key={ delivery.id }>
								<td>
									<strong>{ delivery.action_label }</strong>
									<div className="fm-admin__row-actions">
										{ delivery.submission_id > 0 ? (
											<Button
												variant="link"
												onClick={ () =>
													onOpenSubmission(
														delivery.submission_id
													)
												}
											>
												{ __(
													'View submission',
													'formulario-mensaje'
												) }
											</Button>
										) : (
											<span className="fm-admin__muted">
												{ __(
													'Submission not stored',
													'formulario-mensaje'
												) }
											</span>
										) }
									</div>
								</td>
								<td>
									{ delivery.form_title ||
										__(
											'(deleted form)',
											'formulario-mensaje'
										) }
								</td>
								<td className="column-primary fm-admin__summary">
									{ delivery.error }
								</td>
								<td>
									{ dateI18n(
										dateFormat,
										delivery.updated_at
									) }
									<br />
									<span className="fm-admin__muted">
										{ sprintf(
											/* translators: %d: number of attempts. */
											__(
												'Attempts: %d',
												'formulario-mensaje'
											),
											delivery.attempts
										) }
									</span>
								</td>
								<td className="fm-admin__cell-actions">
									{ delivery.can_retry && (
										<Button
											variant="secondary"
											size="compact"
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
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ result !== null && result.pages > 1 && (
				<div className="fm-pagination">
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Previous', 'formulario-mensaje' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: total number of pages. */
							__( 'Page %1$d of %2$d', 'formulario-mensaje' ),
							page,
							result.pages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= result.pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Next', 'formulario-mensaje' ) }
					</Button>
				</div>
			) }
		</>
	);
}
