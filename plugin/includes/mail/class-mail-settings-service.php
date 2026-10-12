<?php
/**
 * Guardado de los ajustes de correo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use Formulario_Mensaje\Secrets\Secret_Vault;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda los ajustes de correo junto con sus credenciales.
 *
 * La contraseña de SMTP pertenece al servidor para el que se escribió: si el servidor cambia y no
 * llega una contraseña nueva, la guardada se descarta. Sin esta regla, bastaría apuntar los ajustes
 * a otro servidor para que el plugin le entregase una contraseña que nadie puede leer.
 *
 * Una contraseña definida como constante en `wp-config.php` no se puede descartar, así que lo que
 * queda fijo es el servidor: mientras exista la constante, el servidor configurado no se cambia
 * desde aquí. Quien la define suele hacerlo justo para que no la vea quien administra el sitio
 * desde el panel (un cliente, el administrador de un sitio de una red), y el permiso de cambiar
 * estos ajustes lo recibe todo administrador al activar el plugin.
 */
final class Mail_Settings_Service {

	/**
	 * Credenciales que acompañan a los ajustes de correo.
	 */
	public const SECRETS = array( Mail_Settings::SECRET_SMTP_PASSWORD, Mail_Settings::SECRET_RESEND_API_KEY );

	/**
	 * Constructor.
	 *
	 * @param Mail_Settings_Repository $settings Almacén de ajustes de correo.
	 * @param Secret_Vault             $secrets  Almacén de secretos.
	 */
	public function __construct(
		private readonly Mail_Settings_Repository $settings,
		private readonly Secret_Vault $secrets
	) {}

	/**
	 * Guarda los ajustes y aplica los cambios pedidos sobre las credenciales.
	 *
	 * @param Mail_Settings         $settings Ajustes ya saneados.
	 * @param array<string, string> $secrets  Valores nuevos por nombre; uno vacío conserva el guardado.
	 * @param string[]              $clear    Nombres de las credenciales guardadas que se borran.
	 * @return Mail_Settings_Update Lo que ha quedado guardado, lo descartado y lo que no se ha podido cambiar.
	 */
	public function update( Mail_Settings $settings, array $secrets, array $clear ): Mail_Settings_Update {
		$previous     = $this->settings->get();
		$discarded    = array();
		$locked       = array();
		$host_changed = strtolower( $previous->smtp_host ) !== strtolower( $settings->smtp_host );

		/*
		 * Con la contraseña en una constante, el servidor ya configurado se queda como está: vaciarlo
		 * también es cambiarlo, o bastaría hacerlo en dos pasos. Si todavía no hay servidor, se puede
		 * escribir el primero.
		 */
		if ( $host_changed && '' !== $previous->smtp_host && Secret_Vault::SOURCE_CONSTANT === $this->secrets->source( Mail_Settings::SECRET_SMTP_PASSWORD ) ) {
			$settings     = $settings->with_smtp_host( $previous->smtp_host );
			$locked[]     = 'smtp_host';
			$host_changed = false;
		}

		$this->settings->save( $settings );

		foreach ( self::SECRETS as $name ) {
			$value = is_string( $secrets[ $name ] ?? null ) ? $secrets[ $name ] : '';

			if ( '' !== $value ) {
				$this->secrets->set( $name, $value );
			} elseif ( in_array( $name, $clear, true ) ) {
				$this->secrets->set( $name, '' );
			}
		}

		$new_password = is_string( $secrets[ Mail_Settings::SECRET_SMTP_PASSWORD ] ?? null ) && '' !== $secrets[ Mail_Settings::SECRET_SMTP_PASSWORD ];

		if ( $host_changed && ! $new_password && Secret_Vault::SOURCE_STORED === $this->secrets->source( Mail_Settings::SECRET_SMTP_PASSWORD ) ) {
			$this->secrets->set( Mail_Settings::SECRET_SMTP_PASSWORD, '' );
			$discarded[] = Mail_Settings::SECRET_SMTP_PASSWORD;
		}

		return new Mail_Settings_Update( $settings, $discarded, $locked );
	}
}
