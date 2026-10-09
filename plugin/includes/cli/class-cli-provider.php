<?php
/**
 * Proveedor de los comandos de WP-CLI.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Cli;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Forms\Form_Porter;
use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Queue\Queue_Processor;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Storage\Csv_Exporter;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Declara los comandos `wp formulario-mensaje …` y los registra cuando se ejecuta WP-CLI.
 */
final class Cli_Provider implements Service_Provider {

	/**
	 * Nombre del comando del plugin, del que cuelgan todos los demás.
	 */
	public const COMMAND = 'formulario-mensaje';

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( File_Access::class, static fn (): File_Access => new File_Access() );

		$container->set(
			Form_Command::class,
			static fn ( Container $c ): Form_Command => new Form_Command(
				$c->get( Form_Service::class ),
				$c->get( Form_Porter::class ),
				$c->get( File_Access::class )
			)
		);

		$container->set(
			Submission_Command::class,
			static fn ( Container $c ): Submission_Command => new Submission_Command(
				$c->get( Form_Service::class ),
				$c->get( Csv_Exporter::class ),
				$c->get( File_Access::class )
			)
		);

		$container->set(
			Queue_Command::class,
			static fn ( Container $c ): Queue_Command => new Queue_Command( $c->get( Queue_Processor::class ) )
		);

		// `cli_init` solo se dispara bajo WP-CLI: en una petición web nada de esto se carga ni se construye.
		add_action(
			'cli_init',
			static function () use ( $container ): void {
				WP_CLI::add_command( self::COMMAND, Root_Command::class );
				WP_CLI::add_command( self::COMMAND . ' form', $container->get( Form_Command::class ) );
				WP_CLI::add_command( self::COMMAND . ' submission', $container->get( Submission_Command::class ) );
				WP_CLI::add_command( self::COMMAND . ' queue', $container->get( Queue_Command::class ) );
			}
		);
	}
}
