/**
 * Cabecera de una pantalla: el título y, a su derecha, las acciones principales.
 *
 * @param {Object}  props
 * @param {string}  props.title    Título de la pantalla.
 * @param {?Object} props.children Acciones.
 */
export default function PageHeader( { title, children } ) {
	return (
		<div className="fm-page__header">
			<h1 className="fm-page__title">{ title }</h1>
			{ children && <div className="fm-page__actions">{ children }</div> }
		</div>
	);
}
