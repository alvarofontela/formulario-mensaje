<?php
/**
 * Programación del mantenimiento diario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Programa en WP-Cron la tarea diaria de mantenimiento.
 *
 * La tarea es una acción a la que cada módulo engancha lo suyo (retención de envíos, limpieza de
 * contadores). Todo lo que hace se puede ejecutar tarde o varias veces sin daño, y también a mano
 * desde los ajustes del plugin.
 */
final class Maintenance_Schedule {

	/**
	 * Acción que se dispara una vez al día.
	 */
	public const HOOK = 'formulario_mensaje_daily_maintenance';

	/**
	 * Programa la tarea si no lo está ya.
	 */
	public function ensure(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Quita la tarea de la programación.
	 */
	public function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
