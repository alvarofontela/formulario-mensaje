import { __ } from '@wordpress/i18n';

import { getFieldType } from './config';
import { fieldTitle } from './utils';

const GENERAL_TAGS = [
	{
		tag: '{all_fields}',
		label: __( 'All the fields that were filled in', 'formulario-mensaje' ),
	},
	{
		tag: '{reference}',
		label: __( 'Reference number of the submission', 'formulario-mensaje' ),
	},
	{
		tag: '{form_title}',
		label: __( 'Name of the form', 'formulario-mensaje' ),
	},
	{
		tag: '{site_name}',
		label: __( 'Name of the site', 'formulario-mensaje' ),
	},
	{
		tag: '{page_url}',
		label: __( 'Page the form was sent from', 'formulario-mensaje' ),
	},
	{
		tag: '{date}',
		label: __( 'Date and time of the submission', 'formulario-mensaje' ),
	},
];

/**
 * Lista de etiquetas que se pueden usar en un texto. Al pulsar una, se añade al texto.
 *
 * @param {Object}   props
 * @param {Array}    props.fields   Campos del formulario.
 * @param {Function} props.onInsert Recibe la etiqueta elegida.
 */
export default function TagsHelp( { fields, onInsert } ) {
	const fieldTags = fields
		.filter( ( field ) => getFieldType( field.type )?.isInput )
		.map( ( field ) => ( {
			tag: `{field:${ field.id }}`,
			label: fieldTitle( field ),
		} ) );

	return (
		<details className="fm-tags">
			<summary>{ __( 'Available tags', 'formulario-mensaje' ) }</summary>
			<p>
				{ __(
					'Tags are replaced by data of each submission. Click one to add it at the end of the text.',
					'formulario-mensaje'
				) }
			</p>
			<ul>
				{ [ ...GENERAL_TAGS, ...fieldTags ].map( ( { tag, label } ) => (
					<li key={ tag }>
						<button
							type="button"
							className="fm-tags__tag"
							onClick={ () => onInsert( tag ) }
						>
							<code>{ tag }</code>
						</button>
						<span>{ label }</span>
					</li>
				) ) }
			</ul>
		</details>
	);
}
