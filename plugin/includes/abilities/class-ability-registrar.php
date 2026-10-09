<?php
/**
 * Registro de las abilities en WordPress.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Da de alta en la Abilities API de WordPress la categoría y las abilities del plugin.
 */
final class Ability_Registrar {

	/**
	 * Categoría en la que se agrupan las abilities del plugin.
	 */
	public const CATEGORY = 'formulario-mensaje';

	/**
	 * Constructor.
	 *
	 * @param Ability[] $abilities Abilities del plugin.
	 */
	public function __construct( private readonly array $abilities ) {}

	/**
	 * Registra la categoría. Se llama en `wp_abilities_api_categories_init`.
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Formulario Mensaje', 'formulario-mensaje' ),
				'description' => __( 'Forms of the site and the submissions they have received.', 'formulario-mensaje' ),
			)
		);
	}

	/**
	 * Registra las abilities. Se llama en `wp_abilities_api_init`.
	 */
	public function register_abilities(): void {
		foreach ( $this->abilities as $ability ) {
			/**
			 * Filtra si una ability del plugin se ofrece a los clientes MCP.
			 *
			 * Por defecto se ofrecen todas menos las que devuelven texto escrito por visitantes
			 * anónimos: un agente que lo lea podría tomar por instrucciones lo que alguien haya
			 * escrito en un formulario. Activarlas es decisión de quien administra el sitio.
			 *
			 * @since 0.11.0
			 *
			 * @param bool   $public Si la ability se ofrece a los clientes MCP.
			 * @param string $name   Nombre de la ability, como `formulario-mensaje/get-submissions`.
			 */
			$mcp_public = true === apply_filters( 'formulario_mensaje_ability_mcp_public', ! $ability->returns_visitor_content(), $ability->get_name() );

			wp_register_ability(
				$ability->get_name(),
				array(
					'label'               => $ability->get_label(),
					'description'         => $ability->get_description(),
					'category'            => self::CATEGORY,
					'input_schema'        => $ability->get_input_schema(),
					'output_schema'       => $ability->get_output_schema(),
					'permission_callback' => static fn (): bool => $ability->is_allowed(),
					'execute_callback'    => static fn ( mixed $input = null ): array|WP_Error => $ability->execute( is_array( $input ) ? $input : array() ),
					'meta'                => array(
						// Todas consultan: ninguna cambia nada, y repetirlas no tiene efecto.
						'annotations'  => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
						// Visibles para los clientes de la REST API de abilities. El permiso se comprueba en cada ejecución.
						'show_in_rest' => true,
						'mcp'          => array( 'public' => $mcp_public ),
					),
				)
			);
		}
	}
}
