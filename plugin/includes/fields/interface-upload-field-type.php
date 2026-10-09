<?php
/**
 * Contrato de un tipo de campo que recibe ficheros.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Uploads\Uploaded_File;

defined( 'ABSPATH' ) || exit;

/**
 * Tipo de campo cuyo dato no viaja con los valores del formulario sino como ficheros.
 *
 * Su valor, para el resto del plugin, es la lista de nombres de los ficheros.
 */
interface Upload_Field_Type extends Input_Field_Type {

	/**
	 * Valida los ficheros recibidos para un campo: cuántos son, cuánto ocupan y qué son de verdad.
	 *
	 * @param Field           $field Campo.
	 * @param Uploaded_File[] $files Ficheros recibidos; al menos uno.
	 * @return string|null Mensaje de error ya traducido, o null si son válidos.
	 */
	public function validate_files( Field $field, array $files ): ?string;

	/**
	 * Tipos de fichero que admite un campo, por extensión, en el formato que usa WordPress.
	 *
	 * @param Field $field Campo.
	 * @return array<string, string>
	 */
	public function allowed_mimes( Field $field ): array;
}
