import { Button, Dropdown, MenuGroup, MenuItem } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { fieldGroups, fieldTypes, getFieldType } from './config';
import FieldSettings from './field-settings';
import { createField, createId, fieldTitle, moveItem } from './utils';

/**
 * Menú para añadir un campo, con los tipos registrados agrupados.
 *
 * @param {Object}   props
 * @param {Function} props.onAdd Recibe la descripción del tipo elegido.
 */
function AddFieldMenu( { onAdd } ) {
	return (
		<Dropdown
			className="fm-fields__add"
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					__next40pxDefaultSize
					variant="secondary"
					icon="plus-alt2"
					aria-expanded={ isOpen }
					onClick={ onToggle }
				>
					{ __( 'Add field', 'formulario-mensaje' ) }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="fm-fields__menu">
					{ Object.entries( fieldGroups ).map(
						( [ group, label ] ) => {
							const types = fieldTypes.filter(
								( type ) => type.group === group
							);

							return types.length ? (
								<MenuGroup key={ group } label={ label }>
									{ types.map( ( type ) => (
										<MenuItem
											key={ type.type }
											onClick={ () => {
												onAdd( type );
												onClose();
											} }
										>
											{ type.label }
										</MenuItem>
									) ) }
								</MenuGroup>
							) : null;
						}
					) }
				</div>
			) }
		/>
	);
}

/**
 * Pestaña de campos: lista ordenable, con los ajustes de cada campo desplegables.
 *
 * Los campos se reordenan arrastrando o con los botones de subir y bajar, que funcionan con teclado.
 *
 * @param {Object}   props
 * @param {Array}    props.fields   Campos de la definición.
 * @param {Function} props.onChange Recibe la lista de campos nueva.
 */
export default function FieldsPanel( { fields, onChange } ) {
	const [ openId, setOpenId ] = useState( null );
	const [ dragIndex, setDragIndex ] = useState( null );
	const [ overIndex, setOverIndex ] = useState( null );

	const add = ( type ) => {
		const field = createField( type );

		onChange( [ ...fields, field ] );
		setOpenId( field.id );
	};

	const update = ( index, settings ) =>
		onChange(
			fields.map( ( field, position ) =>
				position === index ? { ...field, settings } : field
			)
		);

	const remove = ( index ) =>
		onChange( fields.filter( ( field, position ) => position !== index ) );

	const duplicate = ( index ) => {
		const copy = {
			...structuredClone( fields[ index ] ),
			id: createId( 'f_' ),
		};
		const next = [ ...fields ];

		next.splice( index + 1, 0, copy );
		onChange( next );
		setOpenId( copy.id );
	};

	const drop = ( index ) => {
		if ( dragIndex !== null ) {
			onChange( moveItem( fields, dragIndex, index ) );
		}

		setDragIndex( null );
		setOverIndex( null );
	};

	return (
		<div className="fm-fields">
			{ fields.length === 0 && (
				<p className="fm-fields__empty">
					{ __(
						'This form has no fields yet.',
						'formulario-mensaje'
					) }
				</p>
			) }

			<ol className="fm-fields__list">
				{ fields.map( ( field, index ) => {
					const type = getFieldType( field.type );
					const isOpen = openId === field.id;
					const name = fieldTitle( field );

					return (
						<li
							key={ field.id }
							className={ [
								'fm-fields__item',
								isOpen ? 'is-open' : '',
								dragIndex === index ? 'is-dragging' : '',
								overIndex === index && dragIndex !== index
									? 'is-over'
									: '',
							].join( ' ' ) }
							onDragOver={ ( event ) => {
								if ( dragIndex !== null ) {
									event.preventDefault();
									setOverIndex( index );
								}
							} }
							onDrop={ ( event ) => {
								event.preventDefault();
								drop( index );
							} }
						>
							<div className="fm-fields__row">
								<span
									className="fm-fields__handle dashicons dashicons-menu"
									draggable
									title={ __(
										'Drag to reorder',
										'formulario-mensaje'
									) }
									aria-hidden="true"
									onDragStart={ ( event ) => {
										event.dataTransfer.effectAllowed =
											'move';
										event.dataTransfer.setData(
											'text/plain',
											field.id
										);
										setDragIndex( index );
									} }
									onDragEnd={ () => {
										setDragIndex( null );
										setOverIndex( null );
									} }
								/>
								<button
									type="button"
									className="fm-fields__toggle"
									aria-expanded={ isOpen }
									onClick={ () =>
										setOpenId( isOpen ? null : field.id )
									}
								>
									<span className="fm-fields__name">
										{ name }
									</span>
									<span className="fm-fields__type">
										{ type
											? type.label
											: __(
													'Unknown type',
													'formulario-mensaje'
												) }
										{ field.settings?.required
											? ` · ${ __( 'Required', 'formulario-mensaje' ) }`
											: '' }
									</span>
								</button>
								<div className="fm-fields__tools">
									<Button
										size="small"
										icon="arrow-up-alt2"
										disabled={ index === 0 }
										label={ sprintf(
											/* translators: %s: name of a field. */
											__(
												'Move “%s” up',
												'formulario-mensaje'
											),
											name
										) }
										onClick={ () =>
											onChange(
												moveItem(
													fields,
													index,
													index - 1
												)
											)
										}
									/>
									<Button
										size="small"
										icon="arrow-down-alt2"
										disabled={ index === fields.length - 1 }
										label={ sprintf(
											/* translators: %s: name of a field. */
											__(
												'Move “%s” down',
												'formulario-mensaje'
											),
											name
										) }
										onClick={ () =>
											onChange(
												moveItem(
													fields,
													index,
													index + 1
												)
											)
										}
									/>
									<Button
										size="small"
										icon="admin-page"
										label={ sprintf(
											/* translators: %s: name of a field. */
											__(
												'Duplicate “%s”',
												'formulario-mensaje'
											),
											name
										) }
										onClick={ () => duplicate( index ) }
									/>
									<Button
										size="small"
										icon="trash"
										isDestructive
										label={ sprintf(
											/* translators: %s: name of a field. */
											__(
												'Delete “%s”',
												'formulario-mensaje'
											),
											name
										) }
										onClick={ () => remove( index ) }
									/>
								</div>
							</div>

							{ isOpen && type && (
								<div className="fm-fields__settings">
									<FieldSettings
										schema={ type.schema }
										settings={ field.settings }
										onChange={ ( settings ) =>
											update( index, settings )
										}
									/>
									<p className="fm-fields__id">
										{ __(
											'Tag for emails and messages:',
											'formulario-mensaje'
										) }{ ' ' }
										<code>{ `{field:${ field.id }}` }</code>
									</p>
								</div>
							) }
						</li>
					);
				} ) }
			</ol>

			<AddFieldMenu onAdd={ add } />
		</div>
	);
}
