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
	 * Anchos que puede ocupar un campo en su fila, en tanto por ciento. Varios campos seguidos que
	 * sumen 100 comparten fila; en pantallas estrechas cada uno ocupa la suya.
	 */
	public const WIDTHS = array( 100, 75, 66, 50, 33, 25 );

	/**
	 * Constructor.
	 *
	 * @param string               $id       Identificador único dentro del formulario.
	 * @param string               $type     Tipo de campo, tal como está registrado.
	 * @param array<string, mixed> $settings Opciones del campo.
	 * @param int                  $width    Ancho que ocupa en su fila, en tanto por ciento: uno de WIDTHS.
	 * @param array<string, mixed> $condition Condición para mostrarlo (`match` y `rules`); sin reglas se muestra siempre.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $type,
		public readonly array $settings = array(),
		public readonly int $width = 100,
		public readonly array $condition = array()
	) {}

	/**
	 * Indica si el campo solo se muestra cuando se cumple una condición.
	 */
	public function is_conditional(): bool {
		return ! empty( $this->condition['rules'] );
	}

	/**
	 * Convierte un valor sin validar en uno de los anchos admitidos; ante la duda, todo el ancho.
	 *
	 * @param mixed $width Ancho sin validar.
	 */
	public static function normalize_width( mixed $width ): int {
		return is_numeric( $width ) && in_array( (int) $width, self::WIDTHS, true ) ? (int) $width : 100;
	}

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
			'id'        => $this->id,
			'type'      => $this->type,
			'settings'  => $this->settings,
			'width'     => $this->width,
			'condition' => $this->condition,
		);
	}
}
