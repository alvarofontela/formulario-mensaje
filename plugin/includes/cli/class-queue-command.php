<?php
/**
 * Comandos de WP-CLI para la cola.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Cli;

use Formulario_Mensaje\Queue\Queue_Processor;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/*
 * El docblock de la clase y los de sus métodos son la ayuda que muestra WP-CLI (`wp help …`): por eso
 * van en inglés, como el resto de la interfaz, y las notas de diseño van en este comentario.
 *
 * Es la vía manual de la tarea que normalmente lanza WP-Cron: sirve cuando el cron del sitio está
 * parado o para no esperar al siguiente pase.
 */

/**
 * Runs the background queue of Formulario Mensaje.
 */
final class Queue_Command {

	/**
	 * Pasadas que da, como mucho, una sola ejecución del comando.
	 */
	private const MAX_PASSES = 50;

	/**
	 * Constructor.
	 *
	 * @param Queue_Processor $processor Procesado de la cola.
	 */
	public function __construct( private readonly Queue_Processor $processor ) {}

	/**
	 * Processes the pending deliveries (webhooks) that are due.
	 *
	 * Deliveries waiting for a later retry are not forced: each one keeps its schedule. Running the command twice does no harm.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp formulario-mensaje queue process
	 *     Success: 3 deliveries processed.
	 */
	public function process(): void {
		$total = 0;

		// Cada pasada procesa un lote; se repite hasta vaciar lo que toca ahora.
		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$processed = $this->processor->process();
			$total    += $processed;

			if ( 0 === $processed ) {
				break;
			}
		}

		WP_CLI::success(
			sprintf(
				/* translators: %d: number of deliveries. */
				_n( '%d delivery processed.', '%d deliveries processed.', $total, 'formulario-mensaje' ),
				$total
			)
		);
	}
}
