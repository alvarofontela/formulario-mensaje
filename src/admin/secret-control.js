import { Button, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Campo para una credencial. Nunca muestra la guardada: el servidor no la devuelve.
 *
 * @param {Object}   props
 * @param {string}   props.label    Etiqueta del campo.
 * @param {Object}   props.status   Estado de la credencial: `{ source, constant }`.
 * @param {string}   props.value    Valor nuevo que se está escribiendo.
 * @param {Function} props.onChange Recibe el valor nuevo.
 * @param {boolean}  props.cleared  Si se ha pedido borrar la credencial guardada.
 * @param {Function} props.onClear  Pide borrar, o deja de pedirlo, la credencial guardada.
 */
export default function SecretControl( {
	label,
	status,
	value,
	onChange,
	cleared,
	onClear,
} ) {
	if ( status?.source === 'constant' ) {
		return (
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				help={ sprintf(
					/* translators: %s: name of a PHP constant. */
					__(
						'Defined in wp-config.php with the constant %s. It cannot be changed here.',
						'formulario-mensaje'
					),
					status.constant
				) }
				value="••••••••"
				readOnly
				disabled
				onChange={ () => {} }
			/>
		);
	}

	const stored = status?.source === 'stored' && ! cleared;

	return (
		<div className="fm-secret">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="password"
				autoComplete="new-password"
				label={ label }
				placeholder={
					stored
						? __(
								'Stored. Write to replace it',
								'formulario-mensaje'
							)
						: ''
				}
				help={
					stored
						? __(
								'It is stored encrypted and is never shown again.',
								'formulario-mensaje'
							)
						: __(
								'It will be stored encrypted.',
								'formulario-mensaje'
							)
				}
				value={ value }
				onChange={ onChange }
			/>
			{ status?.source === 'stored' && (
				<Button variant="link" isDestructive onClick={ onClear }>
					{ cleared
						? __( 'Keep the stored one', 'formulario-mensaje' )
						: __( 'Delete the stored one', 'formulario-mensaje' ) }
				</Button>
			) }
		</div>
	);
}
