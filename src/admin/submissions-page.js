import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { deleteSubmissions, listForms, listSubmissions } from './api';
import { can, urls } from './config';
import DeliveriesList from './deliveries-list';
import { useLatest, useReload } from './hooks';

const PER_PAGE = 20;

const TABS = [
	{ name: 'submissions', title: __( 'Submissions', 'formulario-mensaje' ) },
	{
		name: 'failures',
		title: __( 'Failed deliveries', 'formulario-mensaje' ),
	},
];

/**
 * Formulario para exportar a CSV los envíos de un formulario, con un rango de fechas opcional.
 *
 * La descarga es una navegación normal a `admin-post.php`, con el nonce que entrega el servidor.
 *
 * @param {Object}   props
 * @param {number}   props.formId  Formulario cuyos envíos se exportan.
 * @param {Function} props.onClose Cierra la ventana.
 */
function ExportModal( { formId, onClose } ) {
	const [ from, setFrom ] = useState( '' );
	const [ to, setTo ] = useState( '' );

	return (
		<Modal
			title={ __( 'Export submissions', 'formulario-mensaje' ) }
			onRequestClose={ onClose }
			size="small"
		>
			<p>
				{ __(
					'Downloads a CSV file with the submissions of the selected form. Leave the dates empty to export all of them.',
					'formulario-mensaje'
				) }
			</p>
			<div className="fm-settings">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="date"
					label={ __( 'From', 'formulario-mensaje' ) }
					value={ from }
					onChange={ setFrom }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="date"
					label={ __( 'To', 'formulario-mensaje' ) }
					value={ to }
					onChange={ setTo }
				/>
			</div>
			<div className="fm-admin__modal-actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
				>
					{ __( 'Cancel', 'formulario-mensaje' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					href={ addQueryArgs( urls.export, {
						form: formId,
						from,
						to,
					} ) }
					// La ventana se cierra cuando el navegador ya ha empezado la descarga.
					onClick={ () => setTimeout( onClose, 200 ) }
				>
					{ __( 'Download CSV', 'formulario-mensaje' ) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * Pantalla de envíos: listado con búsqueda y filtros, borrado, exportación y entregas fallidas.
 *
 * @param {Object}   props
 * @param {number}   props.initialFormId Formulario por el que se filtra al abrir la pantalla.
 * @param {Function} props.onOpen        Abre el detalle de un envío.
 */
export default function SubmissionsPage( { initialFormId, onOpen } ) {
	const [ tab, setTab ] = useState( 'submissions' );
	const [ forms, setForms ] = useState( [] );
	const [ formId, setFormId ] = useState( initialFormId );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ onlyFailed, setOnlyFailed ] = useState( false );
	const [ page, setPage ] = useState( 1 );
	const [ result, setResult ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ notice, setNotice ] = useState( null );
	const [ confirming, setConfirming ] = useState( false );
	const [ exporting, setExporting ] = useState( false );

	useEffect( () => {
		// Sin la capability de gestionar formularios se reciben solo los nombres: basta para filtrar.
		listForms( 'publish' )
			.then( setForms )
			.catch( () => setForms( [] ) );
	}, [] );

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
		setResult( null );
		setSelected( [] );

		latest(
			listSubmissions( {
				form: formId,
				search: query,
				failed: onlyFailed,
				page,
				per_page: PER_PAGE,
			} )
		)
			.then( setResult )
			.catch( ( error ) => {
				setResult( { items: [], total: 0, pages: 0 } );
				setNotice( { status: 'error', message: error.message } );
			} );
	}, [ formId, query, onlyFailed, page, latest ] );

	useEffect( () => {
		load();
	}, [ load, version ] );

	const remove = () => {
		setConfirming( false );

		deleteSubmissions( selected )
			.then( ( response ) => {
				setNotice( {
					status: 'success',
					message: sprintf(
						/* translators: %d: number of submissions deleted. */
						_n(
							'%d submission deleted.',
							'%d submissions deleted.',
							response.deleted,
							'formulario-mensaje'
						),
						response.deleted
					),
				} );
				reload();
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	};

	const items = result?.items || [];
	const allSelected = items.length > 0 && selected.length === items.length;
	const dateFormat = getDateSettings().formats.datetimeAbbreviated;
	const isFiltered = formId || query || onlyFailed;

	const toggle = ( id, checked ) =>
		setSelected( ( current ) =>
			checked
				? [ ...current, id ]
				: current.filter( ( item ) => item !== id )
		);

	return (
		<div className="fm-admin">
			<div className="fm-admin__header">
				<h1 className="fm-admin__title">
					{ __( 'Submissions', 'formulario-mensaje' ) }
				</h1>
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
				{ TABS.map( ( item, index ) => (
					<li key={ item.name }>
						<button
							type="button"
							className={ `fm-admin__status${
								tab === item.name ? ' is-current' : ''
							}` }
							aria-current={
								tab === item.name ? 'page' : undefined
							}
							onClick={ () => setTab( item.name ) }
						>
							{ item.title }
						</button>
						{ index < TABS.length - 1 && (
							<span aria-hidden="true"> | </span>
						) }
					</li>
				) ) }
			</ul>

			{ tab === 'failures' && (
				<DeliveriesList onOpenSubmission={ onOpen } />
			) }

			{ tab === 'submissions' && (
				<>
					<div className="fm-toolbar">
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
						<SearchControl
							__nextHasNoMarginBottom
							label={ __(
								'Search submissions',
								'formulario-mensaje'
							) }
							placeholder={ __(
								'Search by reference or content',
								'formulario-mensaje'
							) }
							value={ search }
							onChange={ setSearch }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Only with errors',
								'formulario-mensaje'
							) }
							checked={ onlyFailed }
							onChange={ ( value ) => {
								setOnlyFailed( value );
								setPage( 1 );
							} }
						/>
						<div className="fm-toolbar__actions">
							{ selected.length > 0 && (
								<Button
									__next40pxDefaultSize
									variant="secondary"
									isDestructive
									onClick={ () => setConfirming( true ) }
								>
									{ sprintf(
										/* translators: %d: number of selected submissions. */
										_n(
											'Delete %d submission',
											'Delete %d submissions',
											selected.length,
											'formulario-mensaje'
										),
										selected.length
									) }
								</Button>
							) }
							<Button
								__next40pxDefaultSize
								variant="secondary"
								disabled={ ! formId || ! can.viewSubmissions }
								// El CSV tiene una columna por campo: hay que elegir de qué formulario.
								label={
									formId
										? undefined
										: __(
												'Choose a form to export its submissions',
												'formulario-mensaje'
											)
								}
								showTooltip={ ! formId }
								accessibleWhenDisabled
								onClick={ () => setExporting( true ) }
							>
								{ __( 'Export CSV', 'formulario-mensaje' ) }
							</Button>
						</div>
					</div>

					{ result === null && (
						<div className="fm-admin__loading">
							<Spinner />
						</div>
					) }

					{ result !== null && items.length === 0 && (
						<div className="fm-admin__empty">
							<p>
								{ isFiltered
									? __(
											'No submissions match these filters.',
											'formulario-mensaje'
										)
									: __(
											'There are no submissions yet. They will appear here as soon as someone sends a form.',
											'formulario-mensaje'
										) }
							</p>
						</div>
					) }

					{ items.length > 0 && (
						<table className="wp-list-table widefat fixed striped fm-admin__table">
							<thead>
								<tr>
									<td className="check-column fm-admin__check">
										<CheckboxControl
											__nextHasNoMarginBottom
											aria-label={ __(
												'Select all',
												'formulario-mensaje'
											) }
											checked={ allSelected }
											onChange={ ( checked ) =>
												setSelected(
													checked
														? items.map(
																( item ) =>
																	item.id
															)
														: []
												)
											}
										/>
									</td>
									<th
										scope="col"
										className="fm-admin__col-reference"
									>
										{ __(
											'Reference',
											'formulario-mensaje'
										) }
									</th>
									<th scope="col" className="column-primary">
										{ __(
											'Content',
											'formulario-mensaje'
										) }
									</th>
									<th scope="col">
										{ __( 'Form', 'formulario-mensaje' ) }
									</th>
									<th scope="col">
										{ __( 'Date', 'formulario-mensaje' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( item ) => (
									<tr key={ item.id }>
										<th
											scope="row"
											className="check-column fm-admin__check"
										>
											<CheckboxControl
												__nextHasNoMarginBottom
												aria-label={ sprintf(
													/* translators: %s: reference number of a submission. */
													__(
														'Select submission %s',
														'formulario-mensaje'
													),
													item.reference
												) }
												checked={ selected.includes(
													item.id
												) }
												onChange={ ( checked ) =>
													toggle( item.id, checked )
												}
											/>
										</th>
										<td>
											<button
												type="button"
												className="fm-admin__row-title"
												onClick={ () =>
													onOpen( item.id )
												}
											>
												{ item.reference }
											</button>
											{ item.failed_actions > 0 && (
												<span className="fm-badge fm-badge--error">
													{ __(
														'Error',
														'formulario-mensaje'
													) }
												</span>
											) }
										</td>
										<td className="column-primary fm-admin__summary">
											{ item.summary }
										</td>
										<td>
											{ item.form_title ||
												__(
													'(deleted form)',
													'formulario-mensaje'
												) }
										</td>
										<td>
											{ dateI18n(
												dateFormat,
												item.created_at
											) }
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
								disabled={ page >= result.pages }
								onClick={ () => setPage( page + 1 ) }
							>
								{ __( 'Next', 'formulario-mensaje' ) }
							</Button>
						</div>
					) }
				</>
			) }

			{ confirming && (
				<Modal
					title={ __( 'Delete submissions?', 'formulario-mensaje' ) }
					onRequestClose={ () => setConfirming( false ) }
					size="small"
				>
					<p>
						{ __(
							'The selected submissions will be deleted and cannot be recovered.',
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

			{ exporting && (
				<ExportModal
					formId={ formId }
					onClose={ () => setExporting( false ) }
				/>
			) }
		</div>
	);
}
