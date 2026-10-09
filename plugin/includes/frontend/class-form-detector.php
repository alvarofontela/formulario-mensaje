<?php
/**
 * Detección de formularios en la página.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Frontend;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Averigua, antes de pintar la página, si su contenido incluye un formulario.
 *
 * Sirve para encolar los estilos en la cabecera. Si el formulario llega por otra vía (una plantilla,
 * un widget), los estilos se encolan igualmente al pintarlo, aunque ya en el pie de la página.
 */
final class Form_Detector {

	/**
	 * Nombre del bloque del formulario.
	 */
	private const BLOCK_NAME = 'formulario-mensaje/form';

	/**
	 * Indica si el contenido que se está viendo (una entrada o una página) incluye un formulario.
	 *
	 * No hace consultas: el contenido ya está cargado cuando se llama.
	 */
	public function current_page_has_form(): bool {
		$post = is_singular() ? get_queried_object() : null;

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		return has_shortcode( $post->post_content, Shortcode::TAG ) || has_block( self::BLOCK_NAME, $post );
	}
}
