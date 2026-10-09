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
	 * @return string[] Nombres de las credenciales que se han descartado sin que nadie lo pidiera.
	 */
	public function update( Mail_Settings $settings, array $secrets, array $clear ): array {
		$previous  = $this->settings->get();
		$discarded = array();

		$this->settings->save( $settings );

		foreach ( self::SECRETS as $name ) {
			$value = is_string( $secrets[ $name ] ?? null ) ? $secrets[ $name ] : '';

			if ( '' !== $value ) {
				$this->secrets->set( $name, $value );
			} elseif ( in_array( $name, $clear, true ) ) {
				$this->secrets->set( $name, '' );
			}
		}

		$host_changed = strtolower( $previous->smtp_host ) !== strtolower( $settings->smtp_host );
		$new_password = is_string( $secrets[ Mail_Settings::SECRET_SMTP_PASSWORD ] ?? null ) && '' !== $secrets[ Mail_Settings::SECRET_SMTP_PASSWORD ];

		/*
		 * Una contraseña definida como constante no se puede descartar: quien la escribe en
		 * `wp-config.php` controla el servidor y decide a quién da el permiso de cambiar estos ajustes.
		 */
		if ( $host_changed && ! $new_password && Secret_Vault::SOURCE_STORED === $this->secrets->source( Mail_Settings::SECRET_SMTP_PASSWORD ) ) {
			$this->secrets->set( Mail_Settings::SECRET_SMTP_PASSWORD, '' );
			$discarded[] = Mail_Settings::SECRET_SMTP_PASSWORD;
		}

		return $discarded;
	}
}
