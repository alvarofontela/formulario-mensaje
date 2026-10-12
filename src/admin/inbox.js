import {
	Button,
	CheckboxControl,
	Dashicon,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	deleteSubmissions,
	getSubmissionCounts,
	listForms,
	listSubmissions,
	markAllSubmissionsRead,
	markSubmissionsRead,
} from './api';
import { can, initialUnread, urls } from './config';
import ConfirmModal from './confirm-modal';
import DeliveriesList from './deliveries-list';
import ExportModal from './export-modal';
import { useLatest, useReload } from './hooks';
import MessageReader, { messageTitle } from './message-reader';
import PageHeader from './page-header';
import SectionNav from './section-nav';

const PER_PAGE = 20;

/**
 * Agrupa los mensajes de una página por el día en que llegaron, en la zona horaria del sitio.
 *
 * @param {Array} items Mensajes, del más reciente al más antiguo.
 * @return {Array<{day: string, label: string, items: Array}>} Un grupo por día, en el mismo orden.
 */
function groupByDay( items ) {
	const formats = getDateSettings().formats;
	const today = dateI18n( 'Y-m-d', new Date() );
	const yesterday = dateI18n( 'Y-m-d', Date.now() - 86400000 );
	const groups = [];

	items.forEach( ( item ) => {
		const day = dateI18n( 'Y-m-d', item.created_at );
		const last = groups[ groups.length - 1 ];

		if ( last?.day === day ) {
			last.items.push( item );
			return;
		}

		let label = dateI18n( formats.date, item.created_at );

		if ( day === today ) {
			label = __( 'Today', 'formulario-mensaje' );
		} else if ( day === yesterday ) {
			label = __( 'Yesterday', 'formulario-mensaje' );
		}

		groups.push( { day, label, items: [ item ] } );
	} );

	return groups;
}

/**
 * Bandeja de mensajes: la lista de lo que ha llegado por los formularios y, al lado, el mensaje abierto.
 *
 * En pantallas estrechas se ve una cosa cada vez: la lista o el mensaje.
 *
 * @param {Object}   props
 * @param {number}   props.initialFormId Formulario por el que se filtra al abrir la pantalla.
 * @param {number}   props.openId        Mensaje abierto, o 0.
 * @param {Function} props.onOpen        Abre un mensaje, o cierra el abierto si recibe null.
 */
export default function Inbox( { initialFormId, openId, onOpen } ) {
	const [ view, setView ] = useState( 'messages' );
	const [ forms, setForms ] = useState( [] );
	const [ formId, setFormId ] = useState( initialFormId );
	const [ filter, setFilter ] = useState( 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ result, setResult ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ counts, setCounts ] = useState( {
		unread: initialUnread,
		failed_deliveries: 0,
	} );
	const [ notice, setNotice ] = useState( null );
	const [ confirming, setConfirming ] = useState( false );
	const [ exporting, setExporting ] = useState( false );

	// Sin leer y entregas fallidas, tal como están en el servidor.
	const refreshCounts = useCallback(
		() =>
			getSubmissionCounts()
				.then( setCounts )
				.catch( () => {} ),
		[]
	);

	useEffect( () => {
		// Sin la capability de gestionar formularios se reciben solo los nombres: basta para filtrar.
		listForms( 'publish' )
			.then( setForms )
			.catch( () => setForms( [] ) );
		refreshCounts();
	}, [ refreshCounts ] );

	// La búsqueda se lanza al dejar de escribir, no con cada tecla.
	useEffect( () => {
		const timer = setTimeout( () => {
			setQuery( search );
			setPage( 1 );
		}, 350 );

		return () => clearTimeout( timer );
	}, [ search ] );

	const latest = useLatest();
	const [ version, reload ] = useReload();

	const load = useCallback( () => {
		setSelected( [] );

		latest(
			listSubmissions( {
				form: formId,
				search: query,
				failed: filter === 'failed',
				unread: filter === 'unread',
				page,
				per_page: PER_PAGE,
			} )
		)
			.then( ( response ) => {
				// Si la página se ha quedado vacía (se ha borrado o leído todo lo que tenía), se vuelve a
				// la última que tiene algo: sin esto se vería una bandeja vacía habiendo mensajes.
				if ( page > 1 && response.items.length === 0 ) {
					setPage(
						Math.max( 1, Math.min( page - 1, response.pages ) )
					);
					return;
				}

				setResult( response );
			} )
			.catch( ( error ) => {
				setResult( { items: [], total: 0, pages: 0 } );
				setNotice( { status: 'error', message: error.message } );
			} );
	}, [ formId, query, filter, page, latest ] );

	useEffect( () => {
		load();
	}, [ load, version ] );

	// El mensaje abierto avisa de lo que cambia en él: se refleja en la lista sin volver a pedirla.
	const patchItem = useCallback(
		( id, changes ) =>
			setResult(
				( current ) =>
					current && {
						...current,
						items: current.items.map( ( item ) =>
							item.id === id ? { ...item, ...changes } : item
						),
					}
			),
		[]
	);

	const applyCounts = useCallback(
		( response ) =>
			setCounts( {
				unread: response.unread,
				failed_deliveries: response.failed_deliveries,
			} ),
		[]
	);

	const changeFilter = ( name ) => {
		setFilter( name );
		setPage( 1 );
	};

	const run = ( action, message ) => {
		setNotice( null );

		action()
			.then( ( response ) => {
				// Marcar como leído devuelve el recuento; borrar no, y también lo cambia.
				if ( response?.unread !== undefined ) {
					applyCounts( response );
				} else {
					refreshCounts();
				}

				setNotice( {
					status: 'success',
					message: message( response ),
				} );
				reload();
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	};

	const remove = () => {
		setConfirming( false );

		if ( selected.includes( openId ) ) {
			onOpen( null );
		}

		run(
			() => deleteSubmissions( selected ),
			( response ) =>
				sprintf(
					/* translators: %d: number of messages deleted. */
					_n(
						'%d message deleted.',
						'%d messages deleted.',
						response.deleted,
						'formulario-mensaje'
					),
					response.deleted
				)
		);
	};

	const items = result?.items || [];
	const isFiltered = formId || query || filter !== 'all';
	const formats = getDateSettings().formats;
	const today = dateI18n( 'Y-m-d', new Date() );

	const toggle = ( id, checked ) =>
		setSelected( ( current ) =>
			checked
				? [ ...current, id ]
				: current.filter( ( item ) => item !== id )
		);

	const filters = [
		{ name: 'all', label: __( 'All', 'formulario-mensaje' ) },
		{
			name: 'unread',
			label:
				counts.unread > 0
					? sprintf(
							/* translators: %d: number of unread messages. */
							__( 'Unread · %d', 'formulario-mensaje' ),
							counts.unread
						)
					: __( 'Unread', 'formulario-mensaje' ),
		},
		{ name: 'failed', label: __( 'With problems', 'formulario-mensaje' ) },
	];

	return (
		<div
			className={ `fm-admin fm-inbox-page${
				openId ? ' is-reading' : ''
			}` }
		>
			<PageHeader title={ __( 'Messages', 'formulario-mensaje' ) }>
				{ ( counts.failed_deliveries > 0 || view === 'deliveries' ) && (
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						isPressed={ view === 'deliveries' }
						onClick={ () =>
							setView(
								view === 'deliveries'
									? 'messages'
									: 'deliveries'
							)
						}
					>
						{ counts.failed_deliveries > 0
							? sprintf(
									/* translators: %d: number of failed deliveries. */
									__(
										'Failed deliveries · %d',
										'formulario-mensaje'
									),
									counts.failed_deliveries
								)
							: __( 'Failed deliveries', 'formulario-mensaje' ) }
					</Button>
				) }
				<Button
					__next40pxDefaultSize
					className="fm-page__wide-only"
					variant="secondary"
					disabled={ forms.length === 0 }
					onClick={ () => setExporting( true ) }
				>
					{ __( 'Export CSV', 'formulario-mensaje' ) }
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

			{ view === 'deliveries' && (
				<div className="fm-page__body">
					<DeliveriesList
						onOpenSubmission={ ( id ) => {
							setView( 'messages' );
							onOpen( id );
						} }
						onChange={ refreshCounts }
					/>
				</div>
			) }

			{ view === 'messages' && (
				<div className="fm-inbox">
					<div className="fm-inbox__list">
						<div className="fm-inbox__tools">
							<SearchControl
								__nextHasNoMarginBottom
								label={ __(
									'Search messages',
									'formulario-mensaje'
								) }
								placeholder={ __(
									'Search by name or by what they wrote',
									'formulario-mensaje'
								) }
								value={ search }
								onChange={ setSearch }
							/>
							<div
								className="fm-chips"
								role="group"
								aria-label={ __(
									'Filter the messages',
									'formulario-mensaje'
								) }
							>
								{ filters.map( ( item ) => (
									<button
										key={ item.name }
										type="button"
										className="fm-chips__item"
										aria-pressed={ filter === item.name }
										onClick={ () =>
											changeFilter( item.name )
										}
									>
										{ item.label }
									</button>
								) ) }
							</div>
							{ forms.length > 1 && (
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __( 'Form', 'formulario-mensaje' ) }
									hideLabelFromVision
									value={ formId }
									options={ [
										{
											value: 0,
											label: __(
												'All forms',
												'formulario-mensaje'
											),
										},
										...forms.map( ( form ) => ( {
											value: form.id,
											label: form.title,
										} ) ),
									] }
									onChange={ ( value ) => {
										setFormId( Number( value ) );
										setPage( 1 );
									} }
								/>
							) }
							{ selected.length > 0 && (
								<div className="fm-inbox__bulk">
									<Button
										variant="secondary"
										size="compact"
										onClick={ () =>
											run(
												() =>
													markSubmissionsRead(
														selected
													),
												() =>
													__(
														'Marked as read.',
														'formulario-mensaje'
													)
											)
										}
									>
										{ __(
											'Mark as read',
											'formulario-mensaje'
										) }
									</Button>
									<Button
										variant="secondary"
										size="compact"
										isDestructive
										onClick={ () => setConfirming( true ) }
									>
										{ sprintf(
											/* translators: %d: number of selected messages. */
											_n(
												'Delete %d message',
												'Delete %d messages',
												selected.length,
												'formulario-mensaje'
											),
											selected.length
										) }
									</Button>
								</div>
							) }
							{ selected.length === 0 &&
								filter === 'unread' &&
								counts.unread > 0 && (
									<div className="fm-inbox__bulk">
										<Button
											variant="tertiary"
											size="compact"
											onClick={ () =>
												run(
													markAllSubmissionsRead,
													() =>
														__(
															'All the messages are marked as read.',
															'formulario-mensaje'
														)
												)
											}
										>
											{ __(
												'Mark all as read',
												'formulario-mensaje'
											) }
										</Button>
									</div>
								) }
						</div>

						{ result === null && (
							<div className="fm-admin__loading">
								<Spinner />
							</div>
						) }

						{ result !== null && items.length === 0 && (
							<div className="fm-inbox__empty">
								{ isFiltered ? (
									<p>
										{ __(
											'No messages match these filters.',
											'formulario-mensaje'
										) }
									</p>
								) : (
									<>
										<h2>
											{ __(
												'No messages yet',
												'formulario-mensaje'
											) }
										</h2>
										<p>
											{ __(
												'What people send through your forms will appear here. Each message stays until you delete it.',
												'formulario-mensaje'
											) }
										</p>
										{ can.manageForms && (
											<Button
												__next40pxDefaultSize
												variant="secondary"
												href={ urls.forms }
											>
												{ __(
													'Go to the forms',
													'formulario-mensaje'
												) }
											</Button>
										) }
									</>
								) }
							</div>
						) }

						{ items.length > 0 && (
							<div className="fm-inbox__items">
								{ groupByDay( items ).map( ( group ) => (
									<section key={ group.day }>
										<h2 className="fm-inbox__day">
											{ group.label }
										</h2>
										<ul>
											{ group.items.map( ( item ) => (
												<li
													key={ item.id }
													className={ [
														'fm-message',
														item.read
															? ''
															: 'is-unread',
														item.id === openId
															? 'is-open'
															: '',
													].join( ' ' ) }
												>
													<CheckboxControl
														__nextHasNoMarginBottom
														className="fm-message__check"
														aria-label={ sprintf(
															/* translators: %s: who sent a message. */
															__(
																'Select the message from %s',
																'formulario-mensaje'
															),
															messageTitle( item )
														) }
														checked={ selected.includes(
															item.id
														) }
														onChange={ (
															checked
														) =>
															toggle(
																item.id,
																checked
															)
														}
													/>
													<button
														type="button"
														className="fm-message__open"
														aria-current={
															item.id === openId
																? 'true'
																: undefined
														}
														onClick={ () =>
															onOpen( item.id )
														}
													>
														<span className="fm-message__who">
															{ ! item.read && (
																<span className="screen-reader-text">
																	{ __(
																		'Unread:',
																		'formulario-mensaje'
																	) }{ ' ' }
																</span>
															) }
															{ messageTitle(
																item
															) }
														</span>
														<span className="fm-message__when">
															{ dateI18n(
																dateI18n(
																	'Y-m-d',
																	item.created_at
																) === today
																	? formats.time
																	: 'j M',
																item.created_at
															) }
														</span>
														<span className="fm-message__form">
															{ item.form_title ||
																__(
																	'(deleted form)',
																	'formulario-mensaje'
																) }
															{ item.has_files && (
																<Dashicon
																	icon="paperclip"
																	size={ 14 }
																/>
															) }
															{ item.failed_actions >
																0 && (
																<span className="fm-badge fm-badge--error">
																	{ __(
																		'Error',
																		'formulario-mensaje'
																	) }
																</span>
															) }
														</span>
														<span className="fm-message__text">
															{ item.excerpt }
														</span>
													</button>
												</li>
											) ) }
										</ul>
									</section>
								) ) }
							</div>
						) }

						{ result !== null && result.pages > 1 && (
							<div className="fm-pagination">
								<Button
									variant="secondary"
									size="compact"
									disabled={ page <= 1 }
									onClick={ () => setPage( page - 1 ) }
								>
									{ __( 'Previous', 'formulario-mensaje' ) }
								</Button>
								<span>
									{ sprintf(
										/* translators: 1: current page, 2: total number of pages. */
										__(
											'Page %1$d of %2$d',
											'formulario-mensaje'
										),
										page,
										result.pages
									) }
								</span>
								<Button
									variant="secondary"
									size="compact"
									disabled={ page >= result.pages }
									onClick={ () => setPage( page + 1 ) }
								>
									{ __( 'Next', 'formulario-mensaje' ) }
								</Button>
							</div>
						) }
					</div>

					<div className="fm-inbox__reader">
						{ openId ? (
							<MessageReader
								messageId={ openId }
								onBack={ () => onOpen( null ) }
								onChange={ patchItem }
								onCounts={ applyCounts }
								onDeleted={ () => {
									onOpen( null );
									reload();
									// Con el mensaje se van sus entregas fallidas.
									refreshCounts();
								} }
							/>
						) : (
							<p className="fm-inbox__placeholder">
								{ __(
									'Choose a message to read it.',
									'formulario-mensaje'
								) }
							</p>
						) }
					</div>
				</div>
			) }

			{ confirming && (
				<ConfirmModal
					title={ __( 'Delete messages?', 'formulario-mensaje' ) }
					confirm={ __( 'Delete', 'formulario-mensaje' ) }
					onConfirm={ remove }
					onCancel={ () => setConfirming( false ) }
				>
					<p>
						{ __(
							'The selected messages will be deleted and cannot be recovered.',
							'formulario-mensaje'
						) }
					</p>
				</ConfirmModal>
			) }

			{ exporting && (
				<ExportModal
					forms={ forms }
					initialFormId={ formId }
					onClose={ () => setExporting( false ) }
				/>
			) }

			<SectionNav current="submissions" unread={ counts.unread } />
		</div>
	);
}
