/**
 * Formulario Mensaje: motor de reglas en el navegador.
 *
 * Es la misma lógica que `Rule_Evaluator` y `Visibility_Resolver` en PHP, y se comprueba contra los
 * mismos casos (`tests/fixtures/reglas.json`). Aquí sirve para mostrar y ocultar campos mientras se
 * rellena el formulario; lo que decide el servidor al recibir el envío es lo que vale.
 */

const normalize = ( value ) =>
	typeof value === 'string' ||
	typeof value === 'number' ||
	typeof value === 'boolean'
		? String( value ).trim().toLowerCase()
		: '';

/**
 * Valores de un campo como lista de textos normalizados y no vacíos.
 *
 * @param {*} value Valor del campo: un texto, una lista de textos o nada.
 * @return {string[]} Valores.
 */
const actualValues = ( value ) => {
	let list = [];

	if ( Array.isArray( value ) ) {
		list = value;
	} else if ( value !== undefined && value !== null ) {
		list = [ value ];
	}

	return list.map( normalize ).filter( ( item ) => item !== '' );
};

const NUMBER = /^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/;

/**
 * Compara como números el primer valor del campo y el de la regla.
 *
 * @param {string[]} actual   Valores del campo.
 * @param {string}   expected Valor de la regla.
 * @return {number} Negativo, cero o positivo; cero también si alguno de los dos no es un número.
 */
function compare( actual, expected ) {
	const left = ( actual[ 0 ] ?? '' ).replace( ',', '.' );
	const right = expected.replace( ',', '.' );

	if ( ! NUMBER.test( left ) || ! NUMBER.test( right ) ) {
		return 0;
	}

	return Math.sign( Number( left ) - Number( right ) );
}

/**
 * Indica si una regla se cumple.
 *
 * @param {Object} rule   Regla: `field`, `operator` y `value`.
 * @param {Object} values Valores, por identificador de campo.
 * @return {boolean} Si se cumple. Un operador desconocido no se cumple nunca.
 */
function rulePasses( rule, values ) {
	if ( ! rule || typeof rule !== 'object' ) {
		return false;
	}

	// Un campo que no está entre los valores cuenta como vacío.
	const actual = actualValues( values[ rule.field ] );

	if ( rule.operator === 'is_empty' ) {
		return actual.length === 0;
	}

	if ( rule.operator === 'is_not_empty' ) {
		return actual.length > 0;
	}

	const expected = normalize( rule.value );
	const contains =
		expected !== '' &&
		actual.some( ( value ) => value.includes( expected ) );

	switch ( rule.operator ) {
		case 'equals':
			return actual.includes( expected );
		case 'not_equals':
			return ! actual.includes( expected );
		case 'contains':
			return contains;
		case 'not_contains':
			return ! contains;
		case 'greater_than':
			return compare( actual, expected ) > 0;
		case 'less_than':
			return compare( actual, expected ) < 0;
		default:
			return false;
	}
}

/**
 * Indica si una condición se cumple.
 *
 * @param {?Object} condition Condición: `match` (`all` o `any`) y `rules`.
 * @param {Object}  values    Valores, por identificador de campo.
 * @return {boolean} True si se cumple o si la condición no tiene reglas.
 */
export function passes( condition, values ) {
	const rules = Array.isArray( condition?.rules ) ? condition.rules : [];

	if ( rules.length === 0 ) {
		return true;
	}

	return condition.match === 'any'
		? rules.some( ( rule ) => rulePasses( rule, values ) )
		: rules.every( ( rule ) => rulePasses( rule, values ) );
}

/**
 * Calcula qué elementos con condición se muestran.
 *
 * Un elemento oculto no aporta su valor, así que se calcula por pasadas hasta que deja de cambiar,
 * con el mismo tope y el mismo orden que en el servidor.
 *
 * @param {Array<{id: string, condition: Object}>} items  Elementos con su condición. El `id` de un campo es su identificador.
 * @param {Object}                                 values Valores, por identificador de campo.
 * @return {boolean[]} Si se muestra cada elemento, en el mismo orden.
 */
export function resolveVisibility( items, values ) {
	let visible = items.map( () => true );

	for ( let pass = 0; pass < items.length; pass++ ) {
		const current = { ...values };

		items.forEach( ( item, index ) => {
			if ( ! visible[ index ] ) {
				delete current[ item.id ];
			}
		} );

		const next = items.map( ( item ) => passes( item.condition, current ) );

		if ( next.every( ( shown, index ) => shown === visible[ index ] ) ) {
			break;
		}

		visible = next;
	}

	return visible;
}
