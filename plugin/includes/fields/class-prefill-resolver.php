<?php
/**
 * Prellenado de campos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Support\Request_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Calcula el valor inicial de un campo: fijo, de un parámetro de la URL o del usuario conectado.
 */
final class Prefill_Resolver {

	public const SOURCE_NONE  = 'none';
	public const SOURCE_FIXED = 'fixed';
	public const SOURCE_QUERY = 'query';
	public const SOURCE_USER  = 'user';

	/**
	 * Datos del usuario que se pueden usar para prellenar.
	 */
	private const USER_FIELDS = array( 'display_name', 'first_name', 'last_name', 'user_email' );

	/**
	 * Constructor.
	 *
	 * @param Request_Query $query Parámetros de la URL.
	 */
	public function __construct( private readonly Request_Query $query ) {}

	/**
	 * Valor inicial del campo, sin escapar.
	 *
	 * @param Field $field Campo.
	 */
	public function resolve( Field $field ): string {
		return match ( $field->text( 'prefill_source', self::SOURCE_NONE ) ) {
			self::SOURCE_FIXED => $field->text( 'prefill_value' ),
			self::SOURCE_QUERY => $this->query->get( $this->query_param( $field ) ),
			self::SOURCE_USER  => $this->user_value( $field->text( 'prefill_user_field', 'display_name' ) ),
			default            => '',
		};
	}

	/**
	 * Nombre del parámetro de la URL del que se prellena el campo, si ese es su origen.
	 *
	 * El script del formulario lo usa para rellenar el campo cuando la página se sirve desde una caché.
	 *
	 * @param Field $field Campo.
	 * @return string Cadena vacía si el campo no se prellena desde la URL.
	 */
	public function query_param( Field $field ): string {
		if ( self::SOURCE_QUERY !== $field->text( 'prefill_source' ) ) {
			return '';
		}

		return sanitize_key( $field->text( 'prefill_value' ) );
	}

	/**
	 * Dato del usuario conectado.
	 *
	 * @param string $user_field Nombre del dato.
	 */
	private function user_value( string $user_field ): string {
		if ( ! in_array( $user_field, self::USER_FIELDS, true ) || ! is_user_logged_in() ) {
			return '';
		}

		$value = wp_get_current_user()->get( $user_field );

		return is_string( $value ) ? $value : '';
	}
}
