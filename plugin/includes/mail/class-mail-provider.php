<?php
/**
 * Proveedor del correo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Actions\Actions_Provider;
use Formulario_Mensaje\Container;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Submissions\Tag_Replacer;

defined( 'ABSPATH' ) || exit;

/**
 * Declara el transporte de correo y registra la acción de notificación.
 */
final class Mail_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Mail_Transport::class, static fn (): Mail_Transport => new Wp_Mail_Transport() );

		$container->set(
			Email_Action::class,
			static fn ( Container $c ): Email_Action => new Email_Action( $c->get( Mail_Transport::class ), $c->get( Tag_Replacer::class ) )
		);

		add_action(
			Actions_Provider::REGISTER_ACTION,
			static function ( Action_Type_Registry $registry ) use ( $container ): void {
				$registry->register( $container->get( Email_Action::class ) );
			},
			5
		);
	}
}
