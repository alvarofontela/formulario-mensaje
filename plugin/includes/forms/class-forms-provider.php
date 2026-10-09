<?php
/**
 * Proveedor de los formularios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Container;
use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Fields\Settings_Schema_Sanitizer;
use Formulario_Mensaje\Rules\Condition_Sanitizer;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Submissions\Redirect_Policy;
use Formulario_Mensaje\Support\Inline_Html;

defined( 'ABSPATH' ) || exit;

/**
 * Declara el modelo de formularios y registra su tipo de contenido.
 */
final class Forms_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Form_Post_Type::class, static fn (): Form_Post_Type => new Form_Post_Type() );
		$container->set( Form_Repository::class, static fn (): Form_Repository => new Post_Form_Repository() );

		$container->set(
			Form_Definition_Sanitizer::class,
			static fn ( Container $c ): Form_Definition_Sanitizer => new Form_Definition_Sanitizer(
				$c->get( Field_Type_Registry::class ),
				$c->get( Action_Type_Registry::class ),
				$c->get( Settings_Schema_Sanitizer::class ),
				$c->get( Inline_Html::class ),
				$c->get( Condition_Sanitizer::class )
			)
		);

		$container->set(
			Form_Templates::class,
			static fn ( Container $c ): Form_Templates => new Form_Templates(
				$c->get( Form_Definition_Sanitizer::class ),
				$c->get( Action_Type_Registry::class )
			)
		);

		$container->set(
			Form_Porter::class,
			static fn ( Container $c ): Form_Porter => new Form_Porter(
				$c->get( Form_Service::class ),
				$c->get( Form_Definition_Sanitizer::class ),
				$c->get( Action_Type_Registry::class ),
				$c->get( Redirect_Policy::class ),
				FORMULARIO_MENSAJE_VERSION
			)
		);

		$container->set(
			Form_Service::class,
			static fn ( Container $c ): Form_Service => new Form_Service(
				$c->get( Form_Repository::class ),
				$c->get( Form_Definition_Sanitizer::class ),
				$c->get( Redirect_Policy::class )
			)
		);

		add_action(
			'init',
			static function () use ( $container ): void {
				$container->get( Form_Post_Type::class )->register();
			}
		);
	}
}
