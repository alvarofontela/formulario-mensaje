<?php
/**
 * Contrato de un tipo de campo que recoge datos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Tipo de campo que recoge un dato del visitante: lo sanea, lo valida y lo muestra.
 */
interface Input_Field_Type extends Field_Type {

	/**
	 * Sanea el valor recibido en un envío.
	 *
	 * @param Field $field Campo al que pertenece el valor.
	 * @param mixed $raw   Valor tal como llegó en la petición, ya sin barras de escape; null si no llegó.
	 * @return string|string[] Valor saneado; cadena o lista vacía si no hay dato.
	 */
	public function sanitize_value( Field $field, mixed $raw ): string|array;

	/**
	 * Valida el formato de un valor saneado y no vacío.
	 *
	 * La obligatoriedad no se comprueba aquí: es común a todos los tipos.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 * @return string|null Mensaje de error ya traducido, o null si el valor es válido.
	 */
	public function validate( Field $field, string|array $value ): ?string;

	/**
	 * Convierte un valor saneado en un texto legible, sin escapar, para correos y listados.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function format_value( Field $field, string|array $value ): string;
}
