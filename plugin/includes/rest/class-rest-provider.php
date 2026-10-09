<?php
/**
 * Proveedor de la REST API.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rest;

use Formulario_Mensaje\Antispam\Client_Ip_Resolver;
use Formulario_Mensaje\Antispam\Token_Service;
use Formulario_Mensaje\Container;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Frontend\Form_Renderer;
use Formulario_Mensaje\Frontend\Frontend_Assets;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Settings\Settings_Repository;
use Formulario_Mensaje\Settings\Settings_Sanitizer;
use Formulario_Mensaje\Submissions\Submission_Handler;
use Formulario_Mensaje\Submissions\Submission_Request_Factory;
use Formulario_Mensaje\Support\Server_Vars;

defined( 'ABSPATH' ) || exit;

/**
 * Declara los controladores REST y registra sus rutas.
 */
final class Rest_Provider implements Service_Provider {

	/**
	 * Namespace de las rutas del plugin.
	 */
	public const ROUTE_NAMESPACE = 'formulario-mensaje/v1';

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Submissions_Controller::class,
			static fn ( Container $c ): Submissions_Controller => new Submissions_Controller(
				$c->get( Submission_Handler::class ),
				$c->get( Submission_Request_Factory::class ),
				$c->get( Token_Service::class ),
				$c->get( Form_Repository::class )
			)
		);

		$container->set(
			Forms_Controller::class,
			static fn ( Container $c ): Forms_Controller => new Forms_Controller(
				$c->get( Form_Service::class ),
				$c->get( Form_Renderer::class ),
				$c->get( Frontend_Assets::class )
			)
		);

		$container->set(
			Settings_Controller::class,
			static fn ( Container $c ): Settings_Controller => new Settings_Controller(
				$c->get( Settings_Repository::class ),
				$c->get( Settings_Sanitizer::class ),
				$c->get( Client_Ip_Resolver::class ),
				$c->get( Server_Vars::class )
			)
		);

		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( Submissions_Controller::class )->register_routes();
				$container->get( Forms_Controller::class )->register_routes();
				$container->get( Settings_Controller::class )->register_routes();
			}
		);
	}
}
