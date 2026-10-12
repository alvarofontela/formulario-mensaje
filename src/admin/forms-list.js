import {
	Button,
	CheckboxControl,
	Dashicon,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Modal,
	Notice,
	Spinner,
} from '@wordpress/components';
import { humanTimeDiff } from '@wordpress/date';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import {
	deleteForm,
	duplicateForm,
	exportForm,
	importForm,
	listForms,
	restoreForm,
	trashForm,
} from './api';
import { can, initialUnread, templates, urls } from './config';
import ConfirmModal from './confirm-modal';
import { useLatest, useReload } from './hooks';
import PageHeader from './page-header';
import SectionNav from './section-nav';

const STATUSES = [
	{ name: 'publish', title: __( 'Published', 'formulario-mensaje' ) },
	{ name: 'trash', title: __( 'Trash', 'formulario-mensaje' ) },
];

// Páginas que se nombran antes de resumir el resto en un «y N más».
const MAX_PAGES = 3;

/**
 * Dónde está puesto un formulario, en una frase.
 *
 * @param {Object} props
 * @param {Array}  props.placements Páginas publicadas en las que se ha encontrado.
 */
function Placement( { placements } ) {
	if ( placements.length === 0 ) {
		return (
			<p className="fm-form-row__where is-missing">
				<Dashicon icon="warning" size={ 16 } />
				{ __(
					'It has not been found on any published page',
					'formulario-mensaje'
				) }
			</p>
		);
	}

	const extra = placements.length - MAX_PAGES;

	return (
		<p className="fm-form-row__where">
			<Dashicon icon="yes" size={ 16 } />
			<span>
				{ __( 'Receiving messages on:', 'formulario-mensaje' ) }{ ' ' }
				{ placements.slice( 0, MAX_PAGES ).map( ( page, index ) => (
					<span key={ page.url }>
						{ index > 0 && ', ' }
						<a href={ page.url }>
							{ page.title ||
								__( '(no title)', 'formulario-mensaje' ) }
						</a>
					</span>
				) ) }
				{ extra > 0 &&
					' ' +
						sprintf(
							/* translators: %d: number of pages not listed. */
							_n(
								'and %d more',
								'and %d more',
								extra,
								'formulario-mensaje'
							),
							extra
						) }
			</span>
		</p>
	);
}

/**
 * Actividad de un formulario: cuántos mensajes ha recibido este mes y cuándo llegó el último.
 *
 * @param {Object} props
 * @param {Object} props.form Formulario del listado.
 */
function Activity( { form } ) {
	if ( ! form.submission_count ) {
		return (
			<p className="fm-form-row__activity">
				<span>{ __( 'No messages yet', 'formulario-mensaje' ) }</span>
			</p>
		);
	}

	return (
		<p className="fm-form-row__activity">
			<strong>
				{ form.recent_count > 0
					? sprintf(
							/* translators: %d: number of messages received this month. */
							_n(
								'%d message this month',
								'%d messages this month',
								form.recent_count,
								'formulario-mensaje'
							),
							form.recent_count
						)
					: __( 'None this month', 'formulario-mensaje' ) }
			</strong>
			<span>
				{ form.unread_count > 0 &&
					sprintf(
						/* translators: %d: number of unread messages. */
						__( '%d unread', 'formulario-mensaje' ),
						form.unread_count
					) + ' · ' }
				{ sprintf(
					/* translators: %s: how long ago, like "5 minutes ago". */
					__( 'last one %s', 'formulario-mensaje' ),
					humanTimeDiff( form.last_submission )
				) }
			</span>
		</p>
	);
}

/**
 * Listado de formularios, con su papelera: de cada uno, dónde está puesto y qué actividad tiene.
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
	const [ picking, setPicking ] = useState( false );
	const importInput = useRef( null );

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

	const copyShortcode = ( form ) =>
		window.navigator.clipboard?.writeText( form.shortcode ).then( () =>
			setNotice( {
				status: 'success',
				message: __(
					'Code copied. Paste it wherever you want the form to appear.',
					'formulario-mensaje'
				),
			} )
		);

	// El documento se descarga desde el navegador: la respuesta ya llega sin secretos.
	const download = ( form ) => {
		setNotice( null );

		exportForm( form.id )
			.then( ( document ) => {
				const url = URL.createObjectURL(
					new window.Blob( [ JSON.stringify( document, null, 2 ) ], {
						type: 'application/json',
					} )
				);
				const link = window.document.createElement( 'a' );

				link.href = url;
				link.download = `formulario-${ form.id }.json`;
				link.click();
				URL.revokeObjectURL( url );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	};

	const importFile = ( event ) => {
		const file = event.target.files?.[ 0 ];

		// Se vacía para poder elegir el mismo fichero otra vez.
		event.target.value = '';

		if ( ! file ) {
			return;
		}

		setNotice( null );

		file.text()
			.then( ( text ) => JSON.parse( text ) )
			.catch( () => {
				throw new Error(
					__(
						'The file is not a form exported from Formulario Mensaje.',
						'formulario-mensaje'
					)
				);
			} )
			.then( importForm )
			.then( ( form ) => {
				setStatus( 'publish' );
				setNotice( {
					status: 'success',
					message: sprintf(
						/* translators: %s: name of a form. */
						__(
							'“%s” imported. Its notifications and webhooks are turned off: review them and turn them on.',
							'formulario-mensaje'
						),
						form.title
					),
				} );
				reload();
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	};

	return (
		<div className="fm-admin fm-forms-page">
			<PageHeader title={ __( 'Forms', 'formulario-mensaje' ) }>
				<input
					ref={ importInput }
					type="file"
					accept="application/json,.json"
					hidden
					aria-label={ __(
						'File of a form to import',
						'formulario-mensaje'
					) }
					onChange={ importFile }
				/>
				<Button
					__next40pxDefaultSize
					className="fm-page__wide-only"
					variant="tertiary"
					onClick={ () => importInput.current?.click() }
				>
					{ __( 'Import', 'formulario-mensaje' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ () => setPicking( true ) }
				>
					{ __( 'Add form', 'formulario-mensaje' ) }
				</Button>
			</PageHeader>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<div className="fm-page__body">
				<div
					className="fm-chips"
					role="group"
					aria-label={ __(
						'Which forms to show',
						'formulario-mensaje'
					) }
				>
					{ STATUSES.map( ( item ) => (
						<button
							key={ item.name }
							type="button"
							className="fm-chips__item"
							aria-pressed={ status === item.name }
							onClick={ () => setStatus( item.name ) }
						>
							{ item.title }
						</button>
					) ) }
				</div>

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
										'Start from a template or from a blank form. You can change everything afterwards.',
										'formulario-mensaje'
									) }
								</p>
								<Button
									__next40pxDefaultSize
									variant="primary"
									onClick={ () => setPicking( true ) }
								>
									{ __( 'Add form', 'formulario-mensaje' ) }
								</Button>
							</>
						) }
					</div>
				) }

				{ forms !== null && forms.length > 0 && (
					<ul className="fm-form-rows">
						{ forms.map( ( form ) => (
							<li key={ form.id } className="fm-form-row">
								<div className="fm-form-row__main">
									<h2 className="fm-form-row__title">
										{ status === 'trash' ? (
											form.title
										) : (
											<button
												type="button"
												className="fm-admin__row-title"
												onClick={ () =>
													onOpen( form.id )
												}
											>
												{ form.title }
											</button>
										) }
									</h2>
									{ status !== 'trash' && (
										<Placement
											placements={ form.placements || [] }
										/>
									) }
								</div>

								{ can.viewSubmissions && (
									<Activity form={ form } />
								) }

								{ status === 'trash' ? (
									<div className="fm-form-row__actions">
										<Button
											__next40pxDefaultSize
											variant="secondary"
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
														restoreForm( form.id ),
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
											__next40pxDefaultSize
											variant="tertiary"
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
									</div>
								) : (
									<div className="fm-form-row__actions">
										{ ( form.placements || [] ).length ===
											0 && (
											<Button
												__next40pxDefaultSize
												variant="primary"
												href={ addQueryArgs(
													urls.forms,
													{
														form: form.id,
														step: 'publish',
													}
												) }
											>
												{ __(
													'Put it on a page',
													'formulario-mensaje'
												) }
											</Button>
										) }
										{ ( form.placements || [] ).length >
											0 &&
											can.viewSubmissions && (
												<Button
													__next40pxDefaultSize
													variant="secondary"
													href={ addQueryArgs(
														urls.submissions,
														{ form: form.id }
													) }
												>
													{ __(
														'See messages',
														'formulario-mensaje'
													) }
												</Button>
											) }
										<Button
											__next40pxDefaultSize
											variant="tertiary"
											aria-label={ sprintf(
												/* translators: %s: name of a form. */
												__(
													'Edit “%s”',
													'formulario-mensaje'
												),
												form.title
											) }
											onClick={ () => onOpen( form.id ) }
										>
											{ __(
												'Edit',
												'formulario-mensaje'
											) }
										</Button>
										<DropdownMenu
											icon="ellipsis"
											label={ sprintf(
												/* translators: %s: name of a form. */
												__(
													'More actions for “%s”',
													'formulario-mensaje'
												),
												form.title
											) }
											toggleProps={ {
												__next40pxDefaultSize: true,
											} }
										>
											{ ( { onClose } ) => (
												<>
													<MenuGroup>
														<MenuItem
															onClick={ () => {
																onClose();
																run(
																	() =>
																		duplicateForm(
																			form.id
																		),
																	__(
																		'Form duplicated.',
																		'formulario-mensaje'
																	)
																);
															} }
														>
															{ __(
																'Duplicate',
																'formulario-mensaje'
															) }
														</MenuItem>
														<MenuItem
															onClick={ () => {
																onClose();
																download(
																	form
																);
															} }
														>
															{ __(
																'Export',
																'formulario-mensaje'
															) }
														</MenuItem>
														<MenuItem
															info={
																form.shortcode
															}
															onClick={ () => {
																onClose();
																copyShortcode(
																	form
																);
															} }
														>
															{ __(
																'Copy the code to paste',
																'formulario-mensaje'
															) }
														</MenuItem>
														{ can.viewSubmissions &&
															form.submission_count >
																0 && (
																<MenuItem
																	href={ addQueryArgs(
																		urls.submissions,
																		{
																			form: form.id,
																		}
																	) }
																>
																	{ __(
																		'See messages',
																		'formulario-mensaje'
																	) }
																</MenuItem>
															) }
													</MenuGroup>
													<MenuGroup>
														<MenuItem
															isDestructive
															onClick={ () => {
																onClose();
																run(
																	() =>
																		trashForm(
																			form.id
																		),
																	__(
																		'Form moved to the trash.',
																		'formulario-mensaje'
																	)
																);
															} }
														>
															{ __(
																'Move to the trash',
																'formulario-mensaje'
															) }
														</MenuItem>
													</MenuGroup>
												</>
											) }
										</DropdownMenu>
									</div>
								) }
							</li>
						) ) }
					</ul>
				) }

				{ status === 'publish' &&
					forms !== null &&
					forms.length > 0 && (
						<div className="fm-starters">
							<h2>
								{ __(
									'Start a new one',
									'formulario-mensaje'
								) }
							</h2>
							<p>
								{ __(
									'Pick a template and change it as you like, or start from a blank form.',
									'formulario-mensaje'
								) }
							</p>
							<div className="fm-chips">
								{ templates.map( ( template ) => (
									<button
										key={ template.id }
										type="button"
										className="fm-chips__item"
										onClick={ () =>
											onOpen( `new:${ template.id }` )
										}
									>
										{ template.label }
									</button>
								) ) }
							</div>
						</div>
					) }
			</div>

			{ picking && (
				<Modal
					title={ __( 'Choose a template', 'formulario-mensaje' ) }
					onRequestClose={ () => setPicking( false ) }
					size="medium"
				>
					<ul className="fm-templates">
						{ templates.map( ( template ) => (
							<li key={ template.id }>
								<button
									type="button"
									className="fm-templates__item"
									onClick={ () =>
										onOpen( `new:${ template.id }` )
									}
								>
									<strong>{ template.label }</strong>
									<span>{ template.description }</span>
								</button>
							</li>
						) ) }
					</ul>
				</Modal>
			) }

			{ pendingDelete && (
				<ConfirmModal
					title={ __(
						'Delete form permanently?',
						'formulario-mensaje'
					) }
					confirm={ __( 'Delete permanently', 'formulario-mensaje' ) }
					onCancel={ () => {
						setPendingDelete( null );
						// La casilla de borrar los mensajes es de este diálogo: no se hereda en el siguiente.
						setDeleteSubmissions( false );
					} }
					onConfirm={ () => {
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
									/* translators: %d: number of stored messages of the form. */
									_n(
										'Also delete its %d stored message',
										'Also delete its %d stored messages',
										pendingDelete.submission_count,
										'formulario-mensaje'
									),
									pendingDelete.submission_count
								) }
								help={ __(
									'If you keep them, they stay on the Messages screen without a form.',
									'formulario-mensaje'
								) }
								checked={ deleteSubmissions }
								onChange={ setDeleteSubmissions }
							/>
						) }
				</ConfirmModal>
			) }

			<SectionNav current="forms" unread={ initialUnread } />
		</div>
	);
}
