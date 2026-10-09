<?php
/**
 * Tipo de contenido de los formularios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

use Formulario_Mensaje\Lifecycle\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registra el tipo de contenido privado donde se guardan los formularios.
 */
final class Form_Post_Type {

	/**
	 * Nombre del tipo de contenido (WordPress admite 20 caracteres como máximo).
	 */
	public const NAME = 'formulario_mensaje';

	/**
	 * Registra el tipo de contenido.
	 *
	 * No tiene interfaz propia de WordPress ni rutas REST del núcleo: se gestiona desde la pantalla del
	 * plugin. Todas sus capabilities primitivas se resuelven en la de gestionar formularios.
	 */
	public function register(): void {
		$capability = Capabilities::MANAGE_FORMS;

		register_post_type(
			self::NAME,
			array(
				// Sin traducir a propósito: el tipo no tiene interfaz y así no se cargan traducciones en cada petición.
				'label'               => 'Formulario Mensaje',
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => true,
				'capabilities'        => array(
					'create_posts'           => $capability,
					'edit_posts'             => $capability,
					'edit_others_posts'      => $capability,
					'edit_private_posts'     => $capability,
					'edit_published_posts'   => $capability,
					'publish_posts'          => $capability,
					'read_private_posts'     => $capability,
					'delete_posts'           => $capability,
					'delete_others_posts'    => $capability,
					'delete_private_posts'   => $capability,
					'delete_published_posts' => $capability,
				),
			)
		);
	}
}
