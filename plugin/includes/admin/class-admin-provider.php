<?php
/**
 * Proveedor de la administración.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Admin;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Container;
use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Forms\Default_Definition;
use Formulario_Mensaje\Lifecycle\Translations;
use Formulario_Mensaje\Service_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Declara las pantallas de administración. Todo cuelga de hooks que solo se disparan en wp-admin.
 */
final class Admin_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Admin_Menu::class, static fn (): Admin_Menu => new Admin_Menu() );

		$container->set(
			Admin_Data::class,
			static fn ( Container $c ): Admin_Data => new Admin_Data(
				$c->get( Field_Type_Registry::class ),
				$c->get( Action_Type_Registry::class ),
				$c->get( Default_Definition::class )
			)
		);

		$container->set(
			Admin_Assets::class,
			static fn ( Container $c ): Admin_Assets => new Admin_Assets(
				$c->get( Admin_Menu::class ),
				$c->get( Admin_Data::class ),
				$c->get( Translations::class ),
				FORMULARIO_MENSAJE_FILE
			)
		);

		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( Admin_Menu::class )->register();
			}
		);

		add_action(
			'admin_enqueue_scripts',
			static function ( string $hook_suffix ) use ( $container ): void {
				$container->get( Admin_Assets::class )->enqueue( $hook_suffix );
			}
		);
	}
}
