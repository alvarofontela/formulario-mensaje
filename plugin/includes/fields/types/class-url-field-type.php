<?php
/**
 * Tipo de campo: dirección web.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Campo de dirección web.
 */
final class Url_Field_Type extends Abstract_Text_Field_Type {

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'url';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Website', 'formulario-mensaje' );
	}

	/**
	 * Grupo del constructor en el que aparece.
	 */
	public function get_group(): string {
		return 'advanced';
	}

	/**
	 * Atributo `type` del `<input>`.
	 *
	 * Es `text` y no `url` para admitir direcciones sin esquema (`ejemplo.com`), que el navegador
	 * rechazaría antes de que el servidor pueda completarlas.
	 */
	protected function input_type(): string {
		return 'text';
	}

	/**
	 * Longitud máxima del valor.
	 */
	protected function max_length(): int {
		return 2000;
	}

	/**
	 * Valor inicial de la opción de autocompletado para un campo nuevo.
	 */
	protected function default_autocomplete(): string {
		return 'url';
	}

	/**
	 * Sanea el valor recibido en un envío; si no trae esquema, se asume `https`.
	 *
	 * @param Field $field Campo al que pertenece el valor.
	 * @param mixed $raw   Valor tal como llegó en la petición.
	 * @return string|string[]
	 */
	public function sanitize_value( Field $field, mixed $raw ): string|array {
		$value = parent::sanitize_value( $field, $raw );

		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		if ( 1 !== preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $value ) ) {
			$value = 'https://' . $value;
		}

		return $value;
	}

	/**
	 * Valida el formato de un valor saneado y no vacío.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function validate( Field $field, string|array $value ): ?string {
		$valid = is_string( $value )
			&& in_array( strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true )
			&& false !== filter_var( $value, FILTER_VALIDATE_URL )
			&& esc_url_raw( $value, array( 'http', 'https' ) ) === $value;

		return $valid ? null : __( 'Enter a valid web address.', 'formulario-mensaje' );
	}

	/**
	 * Valor de ejemplo para probar notificaciones y otras acciones.
	 *
	 * @param Field $field Campo.
	 * @return string|string[]
	 */
	public function sample_value( Field $field ): string|array {
		return 'https://example.com/';
	}
}
