import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	Spinner,
} from '@wordpress/components';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { deleteForm, listForms, restoreForm, trashForm } from './api';
import { can, urls } from './config';
import { useLatest, useReload } from './hooks';

const STATUSES = [
	{ name: 'publish', title: __( 'Published', 'formulario-mensaje' ) },
	{ name: 'trash', title: __( 'Trash', 'formulario-mensaje' ) },
];

/**
 * Listado de formularios, con su papelera.
 *
 * @param {Object}   props
 * @param {Function} props.onOpen Abre el constructor con un formulario (`new` para uno nuevo).
 */
export default function FormsList( { onOpen } ) {
	const [ status, setStatus ] = useState( 'publish' );
	const [ forms, setForms ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ pendingDelete, setPendingDelete ] = useState( null );
	const [ deleteSubmissions, setDeleteSubmissions ] = useState( false );
	const [ copied, setCopied ] = useState( 0 );

	const latest = useLatest();
	const [ version, reload ] = useReload();

	const load = useCallback( () => {
		setForms( null );

		latest( listForms( status ) )
			.then( setForms )
			.catch( ( error ) => {
				setForms( [] );
				setNotice( { status: 'error', message: error.message } );
			} );
	}, [ status, latest ] );

	useEffect( () => {
		load();
	}, [ load, version ] );

	const run = ( action, message ) => {
		setNotice( null );

		action()
			.then( () => {
				setNotice( { status: 'success', message } );
				reload();
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	};

	const copyShortcode = ( form ) => {
		window.navigator.clipboard?.writeText( form.shortcode ).then( () => {
			setCopied( form.id );
			setTimeout( () => setCopied( 0 ), 2000 );
		} );
	};

	const dateFormat = getDateSettings().formats.datetimeAbbreviated;

	return (
		<div className="fm-admin">
			<div className="fm-admin__header">
				<h1 className="fm-admin__title">
					{ __( 'Forms', 'formulario-mensaje' ) }
				</h1>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ () => onOpen( 'new' ) }
				>
					{ __( 'Add form', 'formulario-mensaje' ) }
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

			<ul className="subsubsub fm-admin__statuses">
				{ STATUSES.map( ( item, index ) => (
					<li key={ item.name }>
						<button
							type="button"
							className={ `fm-admin__status${ status === item.name ? ' is-current' : '' }` }
							aria-current={
								status === item.name ? 'page' : undefined
							}
							onClick={ () => setStatus( item.name ) }
						>
							{ item.title }
						</button>
						{ index < STATUSES.length - 1 && (
							<span aria-hidden="true"> | </span>
						) }
					</li>
				) ) }
			</ul>

			{ forms === null && (
				<div className="fm-admin__loading">
					<Spinner />
				</div>
			) }

			{ forms !== null && forms.length === 0 && (
				<div className="fm-admin__empty">
					{ status === 'trash' ? (
						<p>
							{ __(
								'The trash is empty.',
								'formulario-mensaje'
							) }
						</p>
					) : (
						<>
							<h2>
								{ __(
									'Create your first form',
									'formulario-mensaje'
								) }
							</h2>
							<p>
								{ __(
									'A new form starts as a contact form with name, email and message. You can change everything.',
									'formulario-mensaje'
								) }
							</p>
							<Button
								__next40pxDefaultSize
								variant="primary"
								onClick={ () => onOpen( 'new' ) }
							>
								{ __( 'Add form', 'formulario-mensaje' ) }
							</Button>
						</>
					) }
				</div>
			) }

			{ forms !== null && forms.length > 0 && (
				<table className="wp-list-table widefat fixed striped fm-admin__table">
					<thead>
						<tr>
							<th scope="col" className="column-primary">
								{ __( 'Name', 'formulario-mensaje' ) }
							</th>
							<th scope="col">
								{ __( 'Shortcode', 'formulario-mensaje' ) }
							</th>
							<th scope="col">
								{ __( 'Fields', 'formulario-mensaje' ) }
							</th>
							{ can.viewSubmissions && (
								<th scope="col">
									{ __(
										'Submissions',
										'formulario-mensaje'
									) }
								</th>
							) }
							<th scope="col">
								{ __( 'Last modified', 'formulario-mensaje' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ forms.map( ( form ) => (
							<tr key={ form.id }>
								<td className="column-primary">
									{ status === 'trash' ? (
										<strong>{ form.title }</strong>
									) : (
										<strong>
											<button
												type="button"
												className="fm-admin__row-title"
												onClick={ () =>
													onOpen( form.id )
												}
											>
												{ form.title }
											</button>
										</strong>
									) }
									<div className="fm-admin__row-actions">
										{ status === 'trash' ? (
											<>
												<Button
													variant="link"
													aria-label={ sprintf(
														/* translators: %s: name of a form. */
														__(
															'Restore “%s”',
															'formulario-mensaje'
														),
														form.title
													) }
													onClick={ () =>
														run(
															() =>
																restoreForm(
																	form.id
																),
															__(
																'Form restored.',
																'formulario-mensaje'
															)
														)
													}
												>
													{ __(
														'Restore',
														'formulario-mensaje'
													) }
												</Button>
												<Button
													variant="link"
													isDestructive
													aria-label={ sprintf(
														/* translators: %s: name of a form. */
														__(
															'Delete “%s” permanently',
															'formulario-mensaje'
														),
														form.title
													) }
													onClick={ () =>
														setPendingDelete( form )
													}
												>
													{ __(
														'Delete permanently',
														'formulario-mensaje'
													) }
												</Button>
											</>
										) : (
											<>
												<Button
													variant="link"
													aria-label={ sprintf(
														/* translators: %s: name of a form. */
														__(
															'Edit “%s”',
															'formulario-mensaje'
														),
														form.title
													) }
													onClick={ () =>
														onOpen( form.id )
													}
												>
													{ __(
														'Edit',
														'formulario-mensaje'
													) }
												</Button>
												<Button
													variant="link"
													isDestructive
													aria-label={ sprintf(
														/* translators: %s: name of a form. */
														__(
															'Move “%s” to the trash',
															'formulario-mensaje'
														),
														form.title
													) }
													onClick={ () =>
														run(
															() =>
																trashForm(
																	form.id
																),
															__(
																'Form moved to the trash.',
																'formulario-mensaje'
															)
														)
													}
												>
													{ __(
														'Trash',
														'formulario-mensaje'
													) }
												</Button>
											</>
										) }
									</div>
								</td>
								<td>
									<code className="fm-admin__shortcode">
										{ form.shortcode }
									</code>
									{ status !== 'trash' && (
										<Button
											variant="link"
											onClick={ () =>
												copyShortcode( form )
											}
										>
											{ copied === form.id
												? __(
														'Copied',
														'formulario-mensaje'
													)
												: __(
														'Copy',
														'formulario-mensaje'
													) }
										</Button>
									) }
								</td>
								<td>
									{ sprintf(
										/* translators: %d: number of fields in a form. */
										_n(
											'%d field',
											'%d fields',
											form.field_count,
											'formulario-mensaje'
										),
										form.field_count
									) }
								</td>
								{ can.viewSubmissions && (
									<td>
										{ form.submission_count > 0 ? (
											<a
												href={ addQueryArgs(
													urls.submissions,
													{ form: form.id }
												) }
											>
												{ form.submission_count }
											</a>
										) : (
											0
										) }
									</td>
								) }
								<td>
									{ form.modified
										? dateI18n( dateFormat, form.modified )
										: '' }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ pendingDelete && (
				<Modal
					title={ __(
						'Delete form permanently?',
						'formulario-mensaje'
					) }
					onRequestClose={ () => setPendingDelete( null ) }
					size="small"
				>
					<p>
						{ sprintf(
							/* translators: %s: name of a form. */
							__(
								'“%s” will be deleted and cannot be recovered.',
								'formulario-mensaje'
							),
							pendingDelete.title
						) }
					</p>
					{ can.viewSubmissions &&
						pendingDelete.submission_count > 0 && (
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ sprintf(
									/* translators: %d: number of stored submissions of the form. */
									_n(
										'Delete also its %d stored submission',
										'Delete also its %d stored submissions',
										pendingDelete.submission_count,
										'formulario-mensaje'
									),
									pendingDelete.submission_count
								) }
								help={ __(
									'If you keep them, they stay in the Submissions screen without a form.',
									'formulario-mensaje'
								) }
								checked={ deleteSubmissions }
								onChange={ setDeleteSubmissions }
							/>
						) }
					<div className="fm-admin__modal-actions">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ () => setPendingDelete( null ) }
						>
							{ __( 'Cancel', 'formulario-mensaje' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							isDestructive
							onClick={ () => {
								const id = pendingDelete.id;
								const withSubmissions = deleteSubmissions;

								setPendingDelete( null );
								setDeleteSubmissions( false );
								run(
									() => deleteForm( id, withSubmissions ),
									__( 'Form deleted.', 'formulario-mensaje' )
								);
							} }
						>
							{ __( 'Delete permanently', 'formulario-mensaje' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
