import { useCallback, useRef, useState } from '@wordpress/element';

/**
 * Permite pedir desde un manejador de eventos que un listado se vuelva a cargar.
 *
 * El manejador no llama a la función de carga que tenía a mano cuando se creó, que puede ser de un
 * filtro anterior: cambia un contador del que depende el efecto, y el efecto usa la carga actual.
 *
 * @return {[number, Function]} Contador para las dependencias del efecto y función que lo incrementa.
 */
export function useReload() {
	const [ version, setVersion ] = useState( 0 );
	const reload = useCallback(
		() => setVersion( ( current ) => current + 1 ),
		[]
	);

	return [ version, reload ];
}

/**
 * Evita que la respuesta de una petición antigua pise la de una más reciente.
 *
 * Un listado que se recarga al cambiar un filtro puede tener dos peticiones en vuelo; si la primera
 * llega la última, mostraría datos que no corresponden al filtro elegido. La función que devuelve
 * envuelve una promesa y solo la resuelve si, al llegar, sigue siendo la última que se lanzó.
 *
 * @return {Function} Recibe una promesa y devuelve otra que ignora los resultados obsoletos.
 */
export function useLatest() {
	const counter = useRef( 0 );

	return useCallback( ( promise ) => {
		const id = ++counter.current;

		return new Promise( ( resolve, reject ) => {
			promise.then(
				( value ) => id === counter.current && resolve( value ),
				( error ) => id === counter.current && reject( error )
			);
		} );
	}, [] );
}
