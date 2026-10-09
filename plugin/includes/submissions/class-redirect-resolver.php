<?php
/**
 * Redirección tras un envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

use Formulario_Mensaje\Forms\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Calcula la dirección a la que se redirige al visitante tras un envío correcto.
 *
 * El destino sale siempre de la configuración guardada del formulario, nunca de la petición, y se
 * comprueba de nuevo en cada envío: la lista de dominios permitidos puede haber cambiado.
 */
final class Redirect_Resolver {

	/**
	 * Constructor.
	 *
	 * @param Redirect_Policy $policy Destinos permitidos.
	 */
	public function __construct( private readonly Redirect_Policy $policy ) {}

	/**
	 * Dirección de redirección para un envío.
	 *
	 * @param Form       $form       Formulario.
	 * @param Submission $submission Envío.
	 * @return string Dirección completa, o cadena vacía si el formulario no redirige o el destino no está permitido.
	 */
	public function resolve( Form $form, Submission $submission ): string {
		$url = $form->definition->setting( 'redirect_url', '' );

		if ( empty( $form->definition->setting( 'redirect_enabled' ) ) || ! is_string( $url ) || ! $this->policy->is_allowed( $url ) ) {
			return '';
		}

		$args = array();

		// Solo viajan en la URL los campos que quien configuró el formulario eligió expresamente.
		foreach ( (array) $form->definition->setting( 'redirect_fields', array() ) as $mapping ) {
			if ( ! is_array( $mapping ) || ! is_string( $mapping['field'] ?? null ) || ! is_string( $mapping['param'] ?? null ) || '' === $mapping['param'] ) {
				continue;
			}

			$value = $submission->formatted( $mapping['field'] );

			if ( '' !== $value ) {
				// add_query_arg() no codifica los valores.
				$args[ $mapping['param'] ] = rawurlencode( $value );
			}
		}

		// Las rutas del propio sitio se convierten en direcciones absolutas.
		$url = str_starts_with( $url, '/' ) ? home_url( $url ) : $url;

		return esc_url_raw( add_query_arg( $args, $url ), array( 'http', 'https' ) );
	}
}
