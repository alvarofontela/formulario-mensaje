<?php
/**
 * Proveedor del motor de reglas.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rules;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Service_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Declara el motor de reglas y el saneado de condiciones.
 */
final class Rules_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Rule_Evaluator::class, static fn (): Rule_Evaluator => new Rule_Evaluator() );
		$container->set( Condition_Sanitizer::class, static fn (): Condition_Sanitizer => new Condition_Sanitizer() );
		$container->set( Visibility_Resolver::class, static fn ( Container $c ): Visibility_Resolver => new Visibility_Resolver( $c->get( Rule_Evaluator::class ) ) );
	}
}
