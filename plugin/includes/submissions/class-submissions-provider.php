<?php
/**
 * Proveedor de los envíos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

use Formulario_Mensaje\Actions\Action_Runner;
use Formulario_Mensaje\Antispam\Client_Ip_Resolver;
use Formulario_Mensaje\Antispam\Spam_Guard;
use Formulario_Mensaje\Container;
use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Settings\Settings_Repository;
use Formulario_Mensaje\Storage\Submission_Journal;
use Formulario_Mensaje\Support\Clock;
use Formulario_Mensaje\Support\Inline_Html;
use Formulario_Mensaje\Support\Server_Vars;

defined( 'ABSPATH' ) || exit;

/**
 * Declara el servicio de envío y sus colaboradores.
 */
final class Submissions_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Tag_Replacer::class, static fn (): Tag_Replacer => new Tag_Replacer() );
		$container->set( Reference_Generator::class, static fn (): Reference_Generator => new Reference_Generator() );

		$container->set(
			Redirect_Policy::class,
			static fn ( Container $c ): Redirect_Policy => new Redirect_Policy( $c->get( Settings_Repository::class ) )
		);

		$container->set(
			Redirect_Resolver::class,
			static fn ( Container $c ): Redirect_Resolver => new Redirect_Resolver( $c->get( Redirect_Policy::class ) )
		);

		$container->set(
			Submission_Validator::class,
			static fn ( Container $c ): Submission_Validator => new Submission_Validator( $c->get( Field_Type_Registry::class ) )
		);

		$container->set(
			Confirmation_Builder::class,
			static fn ( Container $c ): Confirmation_Builder => new Confirmation_Builder(
				$c->get( Tag_Replacer::class ),
				$c->get( Redirect_Resolver::class ),
				$c->get( Inline_Html::class )
			)
		);

		$container->set(
			Sample_Submission_Factory::class,
			static fn ( Container $c ): Sample_Submission_Factory => new Sample_Submission_Factory(
				$c->get( Field_Type_Registry::class ),
				$c->get( Reference_Generator::class ),
				$c->get( Clock::class )
			)
		);

		$container->set(
			Submission_Request_Factory::class,
			static fn ( Container $c ): Submission_Request_Factory => new Submission_Request_Factory(
				$c->get( Client_Ip_Resolver::class ),
				$c->get( Server_Vars::class )
			)
		);

		$container->set(
			Submission_Handler::class,
			static fn ( Container $c ): Submission_Handler => new Submission_Handler(
				$c->get( Form_Repository::class ),
				$c->get( Spam_Guard::class ),
				$c->get( Submission_Validator::class ),
				$c->get( Submission_Journal::class ),
				$c->get( Action_Runner::class ),
				$c->get( Confirmation_Builder::class ),
				$c->get( Reference_Generator::class ),
				$c->get( Clock::class )
			)
		);
	}
}
