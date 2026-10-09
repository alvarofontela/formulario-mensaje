<?php
/**
 * Opciones comunes de los campos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Descripciones de las opciones que comparten varios tipos de campo, para componer sus esquemas.
 */
final class Common_Settings {

	/**
	 * Etiqueta del campo.
	 *
	 * @param string $fallback Etiqueta inicial de un campo nuevo.
	 * @return array<string, mixed>
	 */
	public function label( string $fallback = '' ): array {
		return array(
			'key'     => 'label',
			'type'    => 'text',
			'label'   => __( 'Label', 'formulario-mensaje' ),
			'default' => $fallback,
		);
	}

	/**
	 * Campo obligatorio.
	 *
	 * @param bool $fallback Valor inicial.
	 * @return array<string, mixed>
	 */
	public function required( bool $fallback = false ): array {
		return array(
			'key'     => 'required',
			'type'    => 'toggle',
			'label'   => __( 'Required', 'formulario-mensaje' ),
			'default' => $fallback,
		);
	}

	/**
	 * Texto de ejemplo dentro del control.
	 *
	 * @return array<string, mixed>
	 */
	public function placeholder(): array {
		return array(
			'key'     => 'placeholder',
			'type'    => 'text',
			'label'   => __( 'Placeholder', 'formulario-mensaje' ),
			'default' => '',
		);
	}

	/**
	 * Texto de ayuda bajo el control.
	 *
	 * @return array<string, mixed>
	 */
	public function help(): array {
		return array(
			'key'     => 'help',
			'type'    => 'text',
			'label'   => __( 'Help text', 'formulario-mensaje' ),
			'default' => '',
		);
	}

	/**
	 * Clases CSS propias.
	 *
	 * @return array<string, mixed>
	 */
	public function css_class(): array {
		return array(
			'key'     => 'css_class',
			'type'    => 'css_class',
			'label'   => __( 'CSS class', 'formulario-mensaje' ),
			'default' => '',
		);
	}

	/**
	 * Atributo `autocomplete`, para que el navegador proponga el dato adecuado.
	 *
	 * @param string $fallback Valor inicial.
	 * @return array<string, mixed>
	 */
	public function autocomplete( string $fallback = '' ): array {
		return array(
			'key'     => 'autocomplete',
			'type'    => 'select',
			'label'   => __( 'Browser autocomplete', 'formulario-mensaje' ),
			'default' => $fallback,
			'choices' => array(
				$this->choice( '', __( 'Browser default', 'formulario-mensaje' ) ),
				$this->choice( 'off', __( 'Off', 'formulario-mensaje' ) ),
				$this->choice( 'name', __( 'Full name', 'formulario-mensaje' ) ),
				$this->choice( 'given-name', __( 'First name', 'formulario-mensaje' ) ),
				$this->choice( 'family-name', __( 'Last name', 'formulario-mensaje' ) ),
				$this->choice( 'email', __( 'Email', 'formulario-mensaje' ) ),
				$this->choice( 'tel', __( 'Phone', 'formulario-mensaje' ) ),
				$this->choice( 'organization', __( 'Company', 'formulario-mensaje' ) ),
				$this->choice( 'street-address', __( 'Street address', 'formulario-mensaje' ) ),
				$this->choice( 'postal-code', __( 'Postal code', 'formulario-mensaje' ) ),
				$this->choice( 'address-level2', __( 'City', 'formulario-mensaje' ) ),
				$this->choice( 'country-name', __( 'Country', 'formulario-mensaje' ) ),
				$this->choice( 'url', __( 'Website', 'formulario-mensaje' ) ),
			),
		);
	}

	/**
	 * Opciones de prellenado: origen del valor y su parámetro.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function prefill(): array {
		return array(
			array(
				'key'     => 'prefill_source',
				'type'    => 'select',
				'label'   => __( 'Prefill', 'formulario-mensaje' ),
				'default' => Prefill_Resolver::SOURCE_NONE,
				'choices' => array(
					$this->choice( Prefill_Resolver::SOURCE_NONE, __( 'None', 'formulario-mensaje' ) ),
					$this->choice( Prefill_Resolver::SOURCE_FIXED, __( 'Fixed value', 'formulario-mensaje' ) ),
					$this->choice( Prefill_Resolver::SOURCE_QUERY, __( 'URL parameter', 'formulario-mensaje' ) ),
					$this->choice( Prefill_Resolver::SOURCE_USER, __( 'Logged-in user data', 'formulario-mensaje' ) ),
				),
			),
			array(
				'key'     => 'prefill_value',
				'type'    => 'text',
				'label'   => __( 'Value or parameter name', 'formulario-mensaje' ),
				'default' => '',
				'show_if' => array(
					'key'    => 'prefill_source',
					'values' => array( Prefill_Resolver::SOURCE_FIXED, Prefill_Resolver::SOURCE_QUERY ),
				),
			),
			array(
				'key'     => 'prefill_user_field',
				'type'    => 'select',
				'label'   => __( 'User data', 'formulario-mensaje' ),
				'default' => 'display_name',
				'choices' => array(
					$this->choice( 'display_name', __( 'Display name', 'formulario-mensaje' ) ),
					$this->choice( 'first_name', __( 'First name', 'formulario-mensaje' ) ),
					$this->choice( 'last_name', __( 'Last name', 'formulario-mensaje' ) ),
					$this->choice( 'user_email', __( 'Email', 'formulario-mensaje' ) ),
				),
				'show_if' => array(
					'key'    => 'prefill_source',
					'values' => array( Prefill_Resolver::SOURCE_USER ),
				),
			),
		);
	}

	/**
	 * Una opción de un control de tipo `select`.
	 *
	 * @param string $value Valor.
	 * @param string $label Texto ya traducido.
	 * @return array{value: string, label: string}
	 */
	public function choice( string $value, string $label ): array {
		return array(
			'value' => $value,
			'label' => $label,
		);
	}
}
