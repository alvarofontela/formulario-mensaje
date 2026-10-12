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
 * La contraseña de SMTP pertenece al destino para el que se escribió (servidor, puerto y cifrado):
 * si cambia alguno y no llega una contraseña nueva, la guardada se descarta. Sin esta regla,
 * bastaría apuntar los ajustes a otro servidor, a otro puerto de la misma máquina o quitar el
 * cifrado para que el plugin entregase una contraseña que nadie puede leer.
 *
 * Una contraseña definida como constante en `wp-config.php` no se puede descartar, así que lo que
 * queda fijo es su destino: mientras exista la constante, ni el servidor, ni el puerto ni el
 * cifrado se cambian desde aquí, tampoco si aún no hay servidor. Quien la define suele hacerlo
 * justo para que no la vea quien administra el sitio desde el panel (un cliente, el administrador
 * de un sitio de una red), y el permiso de cambiar estos ajustes lo recibe todo administrador al
 * activar el plugin. Para configurar o cambiar el destino hay que retirar antes la constante.
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
		$previous  = $this->settings->get();
		$discarded = array();
		$locked    = array();

		/*
		 * Con la contraseña en una constante, el destino de la conexión se queda como está, sea cual
		 * sea: otro puerto del mismo servidor puede ser de otro, y sin cifrado la contraseña viaja en
		 * claro. Tampoco se deja escribir el primer servidor, o quien llegase antes elegiría el destino.
		 */
		if ( Secret_Vault::SOURCE_CONSTANT === $this->secrets->source( Mail_Settings::SECRET_SMTP_PASSWORD ) ) {
			$locked   = $settings->smtp_destination_changes( $previous );
			$settings = $settings->with_smtp_destination_of( $previous );
		}

		// Tras aplicar el bloqueo: con una constante ya no queda ningún cambio de destino.
		$destination_changed = array() !== $settings->smtp_destination_changes( $previous );

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

		if ( $destination_changed && ! $new_password && Secret_Vault::SOURCE_STORED === $this->secrets->source( Mail_Settings::SECRET_SMTP_PASSWORD ) ) {
			$this->secrets->set( Mail_Settings::SECRET_SMTP_PASSWORD, '' );
			$discarded[] = Mail_Settings::SECRET_SMTP_PASSWORD;
		}

		return new Mail_Settings_Update( $settings, $discarded, $locked );
	}
}
