<?php
/**
 * Pasos de un formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Forms\Form_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Reparte los campos de un formulario en sus pasos.
 *
 * La definición guarda una sola lista de campos; un campo que abre sección (`Section_Field_Type`)
 * marca dónde empieza cada paso. Los campos anteriores al primero forman un paso sin título.
 */
final class Step_Layout {

	/**
	 * Constructor.
	 *
	 * @param Field_Type_Registry $field_types Tipos de campo.
	 */
	public function __construct( private readonly Field_Type_Registry $field_types ) {}

	/**
	 * Pasos del formulario, en orden.
	 *
	 * @param Form_Definition $definition Definición del formulario.
	 * @return array<int, array{marker: Field|null, fields: Field[]}> Vacío si el formulario no tiene pasos.
	 */
	public function steps( Form_Definition $definition ): array {
		$steps   = array();
		$current = null;
		$found   = false;

		foreach ( $definition->fields as $field ) {
			if ( null !== $this->field_types->get_section( $field->type ) ) {
				if ( null !== $current ) {
					$steps[] = $current;
				}

				$found   = true;
				$current = array(
					'marker' => $field,
					'fields' => array(),
				);

				continue;
			}

			$current           ??= array(
				'marker' => null,
				'fields' => array(),
			);
			$current['fields'][] = $field;
		}

		if ( null !== $current ) {
			$steps[] = $current;
		}

		return $found ? $steps : array();
	}

	/**
	 * A qué paso pertenece cada campo: identificador del campo e identificador del campo que abre
	 * su paso. Los campos del paso inicial sin título no aparecen.
	 *
	 * @param Form_Definition $definition Definición del formulario.
	 * @return array<string, string>
	 */
	public function parents( Form_Definition $definition ): array {
		$parents = array();

		foreach ( $this->steps( $definition ) as $step ) {
			if ( null === $step['marker'] ) {
				continue;
			}

			foreach ( $step['fields'] as $field ) {
				$parents[ $field->id ] = $step['marker']->id;
			}
		}

		return $parents;
	}
}
