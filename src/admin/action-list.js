import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import ConditionEditor from './condition-editor';
import { getActionType } from './config';
import { createId } from './utils';

/**
 * Lista editable de las acciones de un tipo (notificaciones, webhooks) de un formulario.
 *
 * La definición guarda una sola lista con las acciones de todos los tipos; aquí se muestran y se
 * editan las de uno. Lo común a cualquier acción (nombre, interruptor, condición, duplicar y borrar)
 * vive aquí; lo propio de cada tipo lo pinta quien usa el componente.
 *
 * @param {Object}   props
 * @param {string}   props.type           Identificador del tipo de acción.
 * @param {Object}   props.definition     Definición en edición.
 * @param {Function} props.onChange       Recibe la lista de acciones nueva, con las de todos los tipos.
 * @param {Object}   props.labels         Textos `empty`, `add`, `unavailable` y `condition`, y la función `numbered`, que nombra una acción sin nombre por su posición.
 * @param {Function} props.summary        Recibe una acción y devuelve el resumen que se ve con ella plegada.
 * @param {Function} props.renderSettings Recibe `{ action, settings, set }` y pinta los ajustes del tipo.
 * @param {Function} props.renderFooter   Recibe `{ action }` y pinta lo que va tras la condición (la prueba).
 */
export default function ActionList( {
	type,
	definition,
	onChange,
	labels,
	summary,
	renderSettings,
	renderFooter,
} ) {
	const actionType = getActionType( type );
	const { actions, fields } = definition;
	// Con una sola acción, que es lo habitual, se muestra ya abierta.
	const [ openId, setOpenId ] = useState( () => {
		const own = actions.filter( ( action ) => action.type === type );

		return own.length === 1 ? own[ 0 ].id : null;
	} );

	if ( ! actionType ) {
		return <p>{ labels.unavailable }</p>;
	}

	const own = actions.filter( ( action ) => action.type === type );

	const update = ( id, patch ) =>
		onChange(
			actions.map( ( action ) =>
				action.id === id ? { ...action, ...patch } : action
			)
		);

	const add = () => {
		const action = {
			id: createId( 'a_' ),
			type,
			enabled: true,
			name: '',
			condition: { match: 'all', rules: [] },
			settings: structuredClone( actionType.defaults ),
		};

		onChange( [ ...actions, action ] );
		setOpenId( action.id );
	};

	const duplicate = ( action ) => {
		const copy = { ...structuredClone( action ), id: createId( 'a_' ) };
		const next = [ ...actions ];

		next.splice( actions.indexOf( action ) + 1, 0, copy );
		onChange( next );
		setOpenId( copy.id );
	};

	return (
		<div className="fm-fields">
			{ own.length === 0 && (
				<p className="fm-fields__empty">{ labels.empty }</p>
			) }

			<ol className="fm-fields__list">
				{ own.map( ( action, index ) => {
					const isOpen = openId === action.id;
					const settings = action.settings;
					const set = ( key, value ) =>
						update( action.id, {
							settings: { ...settings, [ key ]: value },
						} );
					const name = action.name || labels.numbered( index + 1 );
					const hasCondition = action.condition?.rules?.length > 0;

					return (
						<li
							key={ action.id }
							className={ `fm-fields__item${
								isOpen ? ' is-open' : ''
							}` }
						>
							<div className="fm-fields__row">
								<button
									type="button"
									className="fm-fields__toggle"
									aria-expanded={ isOpen }
									onClick={ () =>
										setOpenId( isOpen ? null : action.id )
									}
								>
									<span className="fm-fields__name">
										{ name }
									</span>
									<span className="fm-fields__type">
										{ [
											action.enabled
												? summary( action )
												: __(
														'Turned off',
														'formulario-mensaje'
													),
											hasCondition
												? __(
														'With condition',
														'formulario-mensaje'
													)
												: '',
										]
											.filter( Boolean )
											.join( ' · ' ) }
									</span>
								</button>
								<div className="fm-fields__tools">
									<Button
										size="small"
										icon="admin-page"
										label={ sprintf(
											/* translators: %s: name of a notification or webhook. */
											__(
												'Duplicate “%s”',
												'formulario-mensaje'
											),
											name
										) }
										onClick={ () => duplicate( action ) }
									/>
									<Button
										size="small"
										icon="trash"
										isDestructive
										label={ sprintf(
											/* translators: %s: name of a notification or webhook. */
											__(
												'Delete “%s”',
												'formulario-mensaje'
											),
											name
										) }
										onClick={ () =>
											onChange(
												actions.filter(
													( item ) =>
														item.id !== action.id
												)
											)
										}
									/>
								</div>
							</div>

							{ isOpen && (
								<div className="fm-fields__settings">
									<div className="fm-settings">
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'Name',
												'formulario-mensaje'
											) }
											help={ __(
												'Only to tell it apart from the others.',
												'formulario-mensaje'
											) }
											value={ action.name || '' }
											onChange={ ( value ) =>
												update( action.id, {
													name: value,
												} )
											}
										/>
										<ToggleControl
											__nextHasNoMarginBottom
											label={ __(
												'Turned on',
												'formulario-mensaje'
											) }
											checked={ !! action.enabled }
											onChange={ ( enabled ) =>
												update( action.id, { enabled } )
											}
										/>
										{ renderSettings( {
											action,
											settings,
											set,
										} ) }
										<ConditionEditor
											label={ labels.condition }
											condition={ action.condition }
											fields={ fields }
											onChange={ ( condition ) =>
												update( action.id, {
													condition,
												} )
											}
										/>
										{ renderFooter( { action } ) }
									</div>
								</div>
							) }
						</li>
					);
				} ) }
			</ol>

			<Button
				__next40pxDefaultSize
				variant="secondary"
				icon="plus-alt2"
				onClick={ add }
			>
				{ labels.add }
			</Button>
		</div>
	);
}
