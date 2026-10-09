<?php
/**
 * Contrato de un tipo de campo que abre una sección.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Tipo de campo que no recoge datos sino que parte el formulario: los campos que le siguen, hasta el
 * siguiente de su clase, forman una sección que se rellena por separado (un paso).
 */
interface Section_Field_Type extends Field_Type {

	/**
	 * Título de la sección que abre el campo, sin escapar.
	 *
	 * @param Field $field Campo que abre la sección.
	 */
	public function section_title( Field $field ): string;
}
