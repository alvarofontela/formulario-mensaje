<?php
/**
 * Contrato de un tipo de campo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Lo que todo tipo de campo sabe hacer: describirse para el constructor y pintarse.
 *
 * Los tipos que además recogen un dato del visitante implementan Input_Field_Type.
 */
interface Field_Type {

	/**
	 * Identificador del tipo: minúsculas, cifras y guiones bajos.
	 */
	public function get_type(): string;

	/**
	 * Nombre del tipo, ya traducido, para el constructor.
	 */
	public function get_label(): string;

	/**
	 * Grupo del constructor en el que aparece: `basic`, `choice`, `advanced` o `layout`.
	 */
	public function get_group(): string;

	/**
	 * Opciones que admite un campo de este tipo.
	 *
	 * Cada elemento describe un control: `key`, `type` (`text`, `textarea`, `html`, `toggle`, `select`,
	 * `options` o `css_class`), `label` y `default`; opcionalmente `help`, `choices` (para `select`) y
	 * `show_if` (`key` y `values`) para mostrarlo solo según el valor de otra opción. Con este esquema
	 * se sanean las opciones al guardar y se pinta el panel de ajustes del constructor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_schema(): array;

	/**
	 * Devuelve el HTML del campo, con todos los valores ya escapados.
	 *
	 * @param Field          $field   Campo que se pinta.
	 * @param Render_Context $context Formulario e instancia en los que se pinta.
	 */
	public function render( Field $field, Render_Context $context ): string;
}
