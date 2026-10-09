<?php
/**
 * Bloque del formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Blocks;

use Formulario_Mensaje\Frontend\Form_Embed;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador del bloque que inserta un formulario ya creado. Es dinámico: el HTML lo genera el servidor.
 */
final class Form_Block {

	/**
	 * Constructor.
	 *
	 * @param Form_Embed $embed     Inserción de formularios.
	 * @param string     $block_dir Carpeta del bloque compilado, la que contiene `block.json`.
	 */
	public function __construct(
		private readonly Form_Embed $embed,
		private readonly string $block_dir
	) {}

	/**
	 * Registra el bloque a partir de su `block.json`.
	 */
	public function register(): void {
		// Sin compilar (un clon recién hecho) no hay bloque que registrar.
		if ( ! is_file( $this->block_dir . '/block.json' ) ) {
			return;
		}

		register_block_type(
			$this->block_dir,
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Devuelve el HTML del bloque.
	 *
	 * @param array<string, mixed> $attributes Atributos del bloque.
	 */
	public function render( array $attributes ): string {
		$form = $this->embed->render(
			absint( $attributes['formId'] ?? 0 ),
			// Los controles de estilo del bloque: se sanean al pintar, como el estilo del formulario.
			is_array( $attributes['formStyle'] ?? null ) ? $attributes['formStyle'] : array()
		);

		if ( '' === $form ) {
			return '';
		}

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $form );
	}
}
