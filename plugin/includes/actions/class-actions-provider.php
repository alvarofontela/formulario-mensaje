<?php
/**
 * Proveedor de las acciones.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Actions;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Service_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Declara el registro de tipos de acción y quien las ejecuta.
 */
final class Actions_Provider implements Service_Provider {

	/**
	 * Acción en la que el plugin y terceros registran sus tipos de acción.
	 */
	public const REGISTER_ACTION = 'formulario_mensaje_register_action_types';

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Action_Type_Registry::class,
			static function (): Action_Type_Registry {
				$registry = new Action_Type_Registry();

				/**
				 * Registra los tipos de acción disponibles.
				 *
				 * Los tipos del plugin se registran en esta misma acción con prioridad 5.
				 *
				 * @since 0.1.0
				 *
				 * @param Action_Type_Registry $registry Registro al que añadir los tipos con `register()`.
				 */
				do_action( 'formulario_mensaje_register_action_types', $registry );

				return $registry;
			}
		);

		$container->set(
			Action_Runner::class,
			static fn ( Container $c ): Action_Runner => new Action_Runner( $c->get( Action_Type_Registry::class ) )
		);
	}
}
