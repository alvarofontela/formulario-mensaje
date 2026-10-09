<?php
/**
 * Capabilities propias del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Define las capabilities del plugin y las concede o retira de los roles.
 */
final class Capabilities {

	/**
	 * Crear, editar y borrar formularios.
	 */
	public const MANAGE_FORMS = 'formulario_mensaje_manage_forms';

	/**
	 * Ver, exportar y borrar los envíos recibidos.
	 */
	public const VIEW_SUBMISSIONS = 'formulario_mensaje_view_submissions';

	/**
	 * Cambiar los ajustes generales del plugin.
	 */
	public const MANAGE_SETTINGS = 'formulario_mensaje_manage_settings';

	/**
	 * Todas las capabilities del plugin.
	 *
	 * @return string[]
	 */
	public function all(): array {
		return array( self::MANAGE_FORMS, self::VIEW_SUBMISSIONS, self::MANAGE_SETTINGS );
	}

	/**
	 * Concede todas las capabilities al rol de administrador.
	 */
	public function grant_to_administrator(): void {
		$role = get_role( 'administrator' );

		if ( null === $role ) {
			return;
		}

		foreach ( $this->all() as $capability ) {
			$role->add_cap( $capability );
		}
	}

	/**
	 * Retira las capabilities del plugin de todos los roles.
	 */
	public function remove_from_all_roles(): void {
		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( $this->all() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}
}
