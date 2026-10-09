<?php
/**
 * Proveedor de las abilities.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Abilities;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Forms\Form_Porter;
use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Storage\Submission_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Declara las abilities del plugin y las registra cuando WordPress prepara su Abilities API.
 */
final class Abilities_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Ability_Registrar::class,
			static fn ( Container $c ): Ability_Registrar => new Ability_Registrar(
				array(
					new List_Forms_Ability( $c->get( Form_Service::class ) ),
					new Get_Form_Ability( $c->get( Form_Service::class ), $c->get( Form_Porter::class ) ),
					new Get_Submissions_Ability( $c->get( Submission_Service::class ) ),
					new Get_Submission_Counts_Ability( $c->get( Submission_Service::class ), $c->get( Form_Service::class ) ),
				)
			)
		);

		// WordPress dispara estos hooks la primera vez que alguien pide las abilities, no en cada petición.
		add_action(
			'wp_abilities_api_categories_init',
			static function () use ( $container ): void {
				$container->get( Ability_Registrar::class )->register_category();
			}
		);

		add_action(
			'wp_abilities_api_init',
			static function () use ( $container ): void {
				$container->get( Ability_Registrar::class )->register_abilities();
			}
		);
	}
}
