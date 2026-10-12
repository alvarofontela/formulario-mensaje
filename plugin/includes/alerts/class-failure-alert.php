<?php
/**
 * Aviso de entregas fallidas.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Alerts;

use Formulario_Mensaje\Mail\Email_Message;
use Formulario_Mensaje\Mail\Mail_Transport;
use Formulario_Mensaje\Settings\Settings_Repository;
use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Avisa por correo al administrador de que hay acciones que han fallado y nadie ha visto.
 *
 * Los fallos se agrupan: se envía un aviso como mucho cada cierto tiempo, con el número de fallos
 * acumulados desde el anterior. El aviso no lleva datos de ningún envío, solo el recuento y el
 * enlace a la pantalla donde revisarlos.
 */
final class Failure_Alert {

	/**
	 * Opción con los fallos pendientes de avisar y la fecha del último aviso.
	 */
	public const OPTION = 'formulario_mensaje_failure_alert';

	/**
	 * Segundos mínimos entre dos avisos.
	 */
	private const INTERVAL = 12 * HOUR_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $settings  Ajustes del plugin.
	 * @param Mail_Transport      $transport Transporte de correo elegido en los ajustes.
	 * @param Mail_Transport      $fallback  Transporte de reserva: el correo de WordPress.
	 * @param Clock               $clock     Reloj.
	 */
	public function __construct(
		private readonly Settings_Repository $settings,
		private readonly Mail_Transport $transport,
		private readonly Mail_Transport $fallback,
		private readonly Clock $clock
	) {}

	/**
	 * Anota un fallo nuevo y, si toca, envía el aviso.
	 */
	public function report(): void {
		$state = $this->state();

		++$state['pending'];

		$this->save( $state );
		$this->flush();
	}

	/**
	 * Envía el aviso si hay fallos pendientes de avisar y ha pasado el tiempo mínimo desde el anterior.
	 *
	 * Se llama también desde el mantenimiento diario, para los fallos que llegaron con el aviso anterior reciente.
	 */
	public function flush(): void {
		$state    = $this->state();
		$settings = $this->settings->get();

		if ( $state['pending'] <= 0 ) {
			return;
		}

		// Con los avisos desactivados no se acumula nada: al activarlos no llega un aviso de fallos viejos.
		if ( ! $settings->failure_alerts ) {
			$this->save(
				array(
					'pending'   => 0,
					'last_sent' => $state['last_sent'],
				)
			);

			return;
		}

		/**
		 * Filtra los segundos mínimos entre dos avisos de entregas fallidas.
		 *
		 * @since 0.4.0
		 *
		 * @param int $interval Segundos.
		 */
		$interval = max( 0, (int) apply_filters( 'formulario_mensaje_failure_alert_interval', self::INTERVAL ) );
		$now      = $this->clock->now();

		if ( $now - $state['last_sent'] < $interval ) {
			return;
		}

		// Se da por enviado antes de enviar: si el correo falla, no se reintenta en cada fallo nuevo.
		$this->save(
			array(
				'pending'   => 0,
				'last_sent' => $now,
			)
		);

		$recipient = '' !== $settings->failure_alert_email ? $settings->failure_alert_email : (string) get_option( 'admin_email' );
		$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$message   = new Email_Message(
			array( $recipient ),
			sprintf(
				/* translators: 1: name of the site, 2: number of failed actions. */
				_n( '[%1$s] %2$d form action has failed', '[%1$s] %2$d form actions have failed', $state['pending'], 'formulario-mensaje' ),
				$site,
				$state['pending']
			),
			sprintf(
				/* translators: 1: number of failed actions, 2: name of the site, 3: address of the screen with the failed actions. */
				_n(
					"%1\$d form action on %2\$s (a notification or a webhook) could not be completed.\n\nReview it and retry it here:\n%3\$s",
					"%1\$d form actions on %2\$s (notifications or webhooks) could not be completed.\n\nReview them and retry them here:\n%3\$s",
					$state['pending'],
					'formulario-mensaje'
				),
				$state['pending'],
				$site,
				admin_url( 'admin.php?page=formulario-mensaje-submissions' )
			)
		);

		// Si lo que falla es el propio transporte del plugin, el aviso tiene que poder salir por otro lado.
		if ( is_wp_error( $this->transport->send( $message ) ) ) {
			$this->fallback->send( $message );
		}
	}

	/**
	 * Estado guardado.
	 *
	 * @return array{pending: int, last_sent: int}
	 */
	private function state(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'pending'   => max( 0, (int) ( $state['pending'] ?? 0 ) ),
			'last_sent' => max( 0, (int) ( $state['last_sent'] ?? 0 ) ),
		);
	}

	/**
	 * Guarda el estado. No se carga con el resto de opciones: solo se lee cuando hay un fallo.
	 *
	 * @param array{pending: int, last_sent: int} $state Estado.
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
