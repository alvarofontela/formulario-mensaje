<?php
/**
 * Proveedor del frontend.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Frontend;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Fields\Field_Markup;
use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Submissions\Confirmation_Builder;
use Formulario_Mensaje\Submissions\Redirect_Policy;
use Formulario_Mensaje\Submissions\Submission_Handler;
use Formulario_Mensaje\Submissions\Submission_Request_Factory;
use Formulario_Mensaje\Support\Clock;
use Formulario_Mensaje\Support\Request_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Declara el render del formulario y registra el shortcode, los ficheros del frontend y el envío sin JavaScript.
 */
final class Frontend_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Frontend_Assets::class,
			static fn (): Frontend_Assets => new Frontend_Assets( FORMULARIO_MENSAJE_FILE, FORMULARIO_MENSAJE_VERSION )
		);

		$container->set( Form_Detector::class, static fn (): Form_Detector => new Form_Detector() );

		$container->set(
			Form_Renderer::class,
			static fn ( Container $c ): Form_Renderer => new Form_Renderer( $c->get( Field_Type_Registry::class ), $c->get( Field_Markup::class ) )
		);

		$container->set(
			Form_Embed::class,
			static fn ( Container $c ): Form_Embed => new Form_Embed(
				$c->get( Form_Repository::class ),
				$c->get( Form_Renderer::class ),
				$c->get( Frontend_Assets::class ),
				$c->get( Confirmation_Builder::class ),
				$c->get( Request_Query::class ),
				$c->get( Clock::class )
			)
		);

		$container->set( Shortcode::class, static fn ( Container $c ): Shortcode => new Shortcode( $c->get( Form_Embed::class ) ) );

		$container->set(
			Fallback_Controller::class,
			static fn ( Container $c ): Fallback_Controller => new Fallback_Controller(
				$c->get( Submission_Handler::class ),
				$c->get( Submission_Request_Factory::class ),
				$c->get( Form_Repository::class ),
				$c->get( Redirect_Policy::class )
			)
		);

		// Solo se registran: se encolan al pintar un formulario. El bloque necesita el handle desde `init`.
		add_action(
			'init',
			static function () use ( $container ): void {
				$container->get( Frontend_Assets::class )->register();
			},
			5
		);

		// Si el contenido de la página lleva un formulario, sus estilos van en la cabecera.
		add_action(
			'wp_enqueue_scripts',
			static function () use ( $container ): void {
				if ( $container->get( Form_Detector::class )->current_page_has_form() ) {
					$container->get( Frontend_Assets::class )->enqueue();
				}
			}
		);

		add_shortcode(
			Shortcode::TAG,
			static fn ( array|string $attributes ): string => $container->get( Shortcode::class )->render( $attributes )
		);

		/*
		 * Envío público del formulario sin JavaScript: se atiende igual con sesión iniciada que sin ella.
		 * No lleva nonce ni capability porque es la única acción abierta a visitantes anónimos; la
		 * protegen el campo trampa y el límite por IP del servicio de envío (ver Fallback_Controller).
		 */
		$fallback = static function () use ( $container ): void {
			$container->get( Fallback_Controller::class )->handle();
		};

		add_action( 'admin_post_nopriv_' . Form_Renderer::FALLBACK_ACTION, $fallback );
		add_action( 'admin_post_' . Form_Renderer::FALLBACK_ACTION, $fallback );
	}
}
