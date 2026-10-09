<?php
/**
 * Campo de un formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con la configuración de un campo dentro de un formulario.
 */
final class Field {

	/**
	 * Formato de un identificador de campo.
	 */
	public const ID_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

	/**
	 * Constructor.
	 *
	 * @param string               $id       Identificador único dentro del formulario.
	 * @param string               $type     Tipo de campo, tal como está registrado.
	 * @param array<string, mixed> $settings Opciones del campo.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $type,
		public readonly array $settings = array()
	) {}

	/**
	 * Valor de una opción del campo.
	 *
	 * @param string $key      Clave de la opción.
	 * @param mixed  $fallback Valor si la opción no está definida.
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->settings[ $key ] ?? $fallback;
	}

	/**
	 * Valor de una opción de texto.
	 *
	 * @param string $key      Clave de la opción.
	 * @param string $fallback Valor si la opción no está definida o no es un texto.
	 */
	public function text( string $key, string $fallback = '' ): string {
		$value = $this->settings[ $key ] ?? null;

		return is_string( $value ) ? $value : $fallback;
	}

	/**
	 * Valor de una opción de sí o no.
	 *
	 * @param string $key Clave de la opción.
	 */
	public function flag( string $key ): bool {
		return ! empty( $this->settings[ $key ] );
	}

	/**
	 * Etiqueta del campo.
	 */
	public function label(): string {
		return $this->text( 'label' );
	}

	/**
	 * Indica si el campo es obligatorio.
	 */
	public function is_required(): bool {
		return $this->flag( 'required' );
	}

	/**
	 * Representación para guardar o enviar por REST.
	 *
	 * @return array{id: string, type: string, settings: array<string, mixed>}
	 */
	public function to_array(): array {
		return array(
			'id'       => $this->id,
			'type'     => $this->type,
			'settings' => $this->settings,
		);
	}
}
