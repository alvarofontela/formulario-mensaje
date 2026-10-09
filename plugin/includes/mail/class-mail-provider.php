<?php
/**
 * Proveedor del correo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Actions\Actions_Provider;
use Formulario_Mensaje\Antispam\Rate_Limiter;
use Formulario_Mensaje\Container;
use Formulario_Mensaje\Secrets\Secret_Vault;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Submissions\Sample_Submission_Factory;
use Formulario_Mensaje\Submissions\Tag_Replacer;

defined( 'ABSPATH' ) || exit;

/**
 * Declara los transportes de correo, la plantilla y la acción de notificación.
 */
final class Mail_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Mail_Settings_Sanitizer::class,
			static fn (): Mail_Settings_Sanitizer => new Mail_Settings_Sanitizer(
				array( Mail_Settings::TRANSPORT_WP_MAIL, Mail_Settings::TRANSPORT_SMTP, Mail_Settings::TRANSPORT_RESEND )
			)
		);

		$container->set(
			Mail_Settings_Repository::class,
			static fn ( Container $c ): Mail_Settings_Repository => new Option_Mail_Settings_Repository( $c->get( Mail_Settings_Sanitizer::class ) )
		);

		$container->set(
			Mail_Settings_Service::class,
			static fn ( Container $c ): Mail_Settings_Service => new Mail_Settings_Service(
				$c->get( Mail_Settings_Repository::class ),
				$c->get( Secret_Vault::class )
			)
		);

		$container->set(
			Wp_Mail_Transport::class,
			static fn ( Container $c ): Wp_Mail_Transport => new Wp_Mail_Transport( $c->get( Mail_Settings_Repository::class ) )
		);

		// Añadir un transporte es añadir una clase y una línea aquí: quien envía correo no cambia.
		$container->set(
			Mail_Transport::class,
			static fn ( Container $c ): Mail_Transport => new Selected_Transport(
				$c->get( Mail_Settings_Repository::class ),
				array(
					Mail_Settings::TRANSPORT_WP_MAIL => $c->get( Wp_Mail_Transport::class ),
					Mail_Settings::TRANSPORT_SMTP    => new Smtp_Transport( $c->get( Mail_Settings_Repository::class ), $c->get( Secret_Vault::class ) ),
					Mail_Settings::TRANSPORT_RESEND  => new Resend_Transport( $c->get( Mail_Settings_Repository::class ), $c->get( Secret_Vault::class ) ),
				)
			)
		);

		$container->set(
			Email_Template::class,
			static fn ( Container $c ): Email_Template => new Email_Template( $c->get( Mail_Settings_Repository::class ) )
		);

		$container->set(
			Email_Action::class,
			static fn ( Container $c ): Email_Action => new Email_Action(
				$c->get( Mail_Transport::class ),
				$c->get( Tag_Replacer::class ),
				$c->get( Email_Template::class )
			)
		);

		$container->set(
			Mail_Tester::class,
			static fn ( Container $c ): Mail_Tester => new Mail_Tester(
				$c->get( Mail_Transport::class ),
				$c->get( Email_Template::class ),
				$c->get( Email_Action::class ),
				$c->get( Sample_Submission_Factory::class ),
				$c->get( Rate_Limiter::class )
			)
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
