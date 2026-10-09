<?php
/**
 * Proveedor de los bloques.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Blocks;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Frontend\Form_Embed;
use Formulario_Mensaje\Service_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Declara y registra el bloque del formulario.
 */
final class Blocks_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Form_Block::class,
			static fn ( Container $c ): Form_Block => new Form_Block( $c->get( Form_Embed::class ), FORMULARIO_MENSAJE_DIR . '/build/blocks/form' )
		);

		add_action(
			'init',
			static function () use ( $container ): void {
				$container->get( Form_Block::class )->register();
			}
		);
	}
}
