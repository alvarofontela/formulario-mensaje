<?php
/**
 * Shortcode del formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador del shortcode `[formulario_mensaje id="123"]`.
 */
final class Shortcode {

	/**
	 * Nombre del shortcode.
	 */
	public const TAG = 'formulario_mensaje';

	/**
	 * Constructor.
	 *
	 * @param Form_Embed $embed Inserción de formularios.
	 */
	public function __construct( private readonly Form_Embed $embed ) {}

	/**
	 * Devuelve el HTML del formulario indicado en el atributo `id`.
	 *
	 * @param array<string, mixed>|string $attributes Atributos del shortcode.
	 */
	public function render( array|string $attributes ): string {
		$attributes = shortcode_atts( array( 'id' => 0 ), is_array( $attributes ) ? $attributes : array(), self::TAG );

		return $this->embed->render( absint( $attributes['id'] ) );
	}
}
