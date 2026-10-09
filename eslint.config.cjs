/**
 * ESLint para el JavaScript del plugin (`npm run lint:js`): reglas y formato de WordPress.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		rules: {
			// Los paquetes @wordpress/* no se instalan: los aporta WordPress y la compilación los deja como externos.
			'import/no-unresolved': [ 'error', { ignore: [ '^@wordpress/' ] } ],
			'import/no-extraneous-dependencies': 'off',
		},
	},
];
