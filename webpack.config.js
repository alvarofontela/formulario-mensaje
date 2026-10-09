/**
 * Compilación del JavaScript del plugin con @wordpress/scripts.
 *
 * Los fuentes están en `src/` y el resultado va a `plugin/build/`, que es lo que se distribuye.
 * Los bloques se detectan por su `block.json`; la aplicación de administración se añade aquí.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const defaultEntry = typeof defaultConfig.entry === 'function' ? defaultConfig.entry() : defaultConfig.entry;

module.exports = {
	...defaultConfig,
	entry: {
		...defaultEntry,
		'admin/index': path.resolve( __dirname, 'src/admin/index.js' ),
	},
};
