<?php
/**
 * Contrato de una ability del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Operación de solo lectura que el plugin ofrece a agentes y otros clientes por la Abilities API.
 *
 * Es un adaptador más sobre los servicios del plugin, como una ruta REST o un comando de WP-CLI:
 * comprueba el permiso, lee la entrada y delega. Todas las del plugin consultan y ninguna modifica
 * nada; si algún día hace falta una que escriba, este contrato tendrá que decir cuál es cuál.
 */
interface Ability {

	/**
	 * Nombre con el que se registra, con el prefijo del plugin: `formulario-mensaje/list-forms`.
	 */
	public function get_name(): string;

	/**
	 * Nombre legible, ya traducido.
	 */
	public function get_label(): string;

	/**
	 * Qué hace y cuándo usarla, ya traducido. Es lo que lee un agente para elegirla.
	 */
	public function get_description(): string;

	/**
	 * Esquema JSON de la entrada.
	 *
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array;

	/**
	 * Esquema JSON del resultado.
	 *
	 * @return array<string, mixed>
	 */
	public function get_output_schema(): array;

	/**
	 * Indica si el usuario actual puede ejecutarla.
	 */
	public function is_allowed(): bool;

	/**
	 * Ejecuta la operación.
	 *
	 * @param array<string, mixed> $input Entrada ya validada contra el esquema. Los valores por defecto del esquema no vienen puestos.
	 * @return array<string|int, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error;
}
