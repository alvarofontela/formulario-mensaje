<?php
/**
 * Recogida de los ficheros de una petición.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Forms\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte lo que PHP deja en `$_FILES` en la lista de ficheros de cada campo de subida.
 *
 * Solo se leen los ficheros de los campos de subida que el formulario tiene: lo que llegue con otro
 * nombre se ignora y PHP lo borra al terminar la petición.
 */
final class Upload_Collector {

	/**
	 * Ficheros que se leen, como mucho, de un campo. El límite real lo pone cada campo; este solo
	 * evita recorrer una lista desmedida.
	 */
	private const MAX_PER_FIELD = 20;

	/**
	 * Constructor.
	 *
	 * @param Field_Type_Registry $field_types Tipos de campo.
	 */
	public function __construct( private readonly Field_Type_Registry $field_types ) {}

	/**
	 * Ficheros recibidos para cada campo de subida del formulario.
	 *
	 * @param Form  $form Formulario.
	 * @param mixed $raw  Elemento de `$_FILES` con los ficheros del formulario, sin validar.
	 * @return array<string, Uploaded_File[]> Por identificador de campo. Los campos sin ficheros no aparecen.
	 */
	public function collect( Form $form, mixed $raw ): array {
		$raw     = is_array( $raw ) ? $raw : array();
		$uploads = array();

		foreach ( $form->definition->fields as $field ) {
			if ( null === $this->field_types->get_upload( $field->type ) ) {
				continue;
			}

			$files = $this->files( $raw, $field->id );

			if ( array() !== $files ) {
				$uploads[ $field->id ] = $files;
			}
		}

		return $uploads;
	}

	/**
	 * Ficheros de un campo.
	 *
	 * @param array<string, mixed> $raw      Elemento de `$_FILES`: un array por dato (`name`, `tmp_name`…), cada uno por campo.
	 * @param string               $field_id Identificador del campo.
	 * @return Uploaded_File[]
	 */
	private function files( array $raw, string $field_id ): array {
		$names = $raw['name'][ $field_id ] ?? null;

		// Un campo con un solo fichero llega como texto; con varios, como lista.
		$names = is_array( $names ) ? $names : ( is_string( $names ) ? array( $names ) : array() );
		$files = array();

		foreach ( array_slice( array_keys( $names ), 0, self::MAX_PER_FIELD ) as $index ) {
			$error = $this->value( $raw, 'error', $field_id, $index );

			// Un hueco vacío del selector de ficheros no es un fichero.
			if ( UPLOAD_ERR_NO_FILE === (int) $error || ! is_string( $names[ $index ] ) ) {
				continue;
			}

			$files[] = new Uploaded_File(
				mb_substr( sanitize_file_name( wp_basename( $names[ $index ] ) ), 0, 200 ),
				(string) $this->value( $raw, 'tmp_name', $field_id, $index ),
				(int) $this->value( $raw, 'size', $field_id, $index ),
				(int) $error
			);
		}

		return $files;
	}

	/**
	 * Lee un dato de un fichero de la estructura de `$_FILES`.
	 *
	 * @param array<string, mixed> $raw      Elemento de `$_FILES`.
	 * @param string               $key      Dato: `tmp_name`, `size` o `error`.
	 * @param string               $field_id Identificador del campo.
	 * @param int|string           $index    Posición del fichero dentro del campo.
	 */
	private function value( array $raw, string $key, string $field_id, int|string $index ): string|int {
		$values = $raw[ $key ][ $field_id ] ?? null;
		$value  = is_array( $values ) ? ( $values[ $index ] ?? '' ) : $values;

		return is_scalar( $value ) ? ( is_int( $value ) ? $value : (string) $value ) : '';
	}
}
