/**
 * Bloque que inserta un formulario ya creado. Es dinámico: el HTML lo genera el servidor.
 */
import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
