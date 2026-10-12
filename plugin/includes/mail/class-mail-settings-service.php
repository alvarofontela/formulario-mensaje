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
 *
 * El orden importa: la contraseña guardada se descarta antes de guardar un destino distinto, y el
 * destino solo cambia si de verdad se ha descartado. Así no hay ningún momento, ni siquiera si la
 * petición se corta a medias o una escritura falla, en el que el destino nuevo conviva con la
 * contraseña del anterior.
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
		$previous = $this->settings->get();
		$password = Mail_Settings::SECRET_SMTP_PASSWORD;
		$locked   = array();
		$dropped  = false;

		/*
		 * Con la contraseña en una constante, el destino de la conexión se queda como está, sea cual
		 * sea: otro puerto del mismo servidor puede ser de otro, y sin cifrado la contraseña viaja en
		 * claro. Tampoco se deja escribir el primer servidor, o quien llegase antes elegiría el destino.
		 */
		if ( Secret_Vault::SOURCE_CONSTANT === $this->secrets->source( $password ) ) {
			$locked   = $settings->smtp_destination_changes( $previous );
			$settings = $settings->with_smtp_destination_of( $previous );
		}

		/*
		 * Con una contraseña guardada y un destino distinto, primero se descarta la contraseña. Si no se
		 * puede (la base de datos no acepta el cambio), el destino se queda como estaba: lo contrario
		 * sería guardar el destino nuevo junto a una contraseña que no es la suya.
		 */
		if ( array() !== $settings->smtp_destination_changes( $previous ) && Secret_Vault::SOURCE_STORED === $this->secrets->source( $password ) ) {
			if ( $this->secrets->set( $password, '' ) && Secret_Vault::SOURCE_STORED !== $this->secrets->source( $password ) ) {
				$dropped = true;
			} else {
				$locked   = $settings->smtp_destination_changes( $previous );
				$settings = $settings->with_smtp_destination_of( $previous );
			}
		}

		$this->settings->save( $settings );

		// Si una de estas escrituras falla, lo guardado sigue siendo coherente: la contraseña anterior ya no
		// está si el destino ha cambiado, y sigue con su destino si no ha cambiado.
		foreach ( self::SECRETS as $name ) {
			$value = is_string( $secrets[ $name ] ?? null ) ? $secrets[ $name ] : '';

			if ( '' !== $value ) {
				$this->secrets->set( $name, $value );
			} elseif ( in_array( $name, $clear, true ) ) {
				$this->secrets->set( $name, '' );
			}
		}

		// Se avisa del descarte cuando nadie lo pidió y al final no ha quedado ninguna contraseña: lo
		// que cuenta es lo guardado, no lo que traía la petición.
		$discarded = $dropped && ! in_array( $password, $clear, true ) && Secret_Vault::SOURCE_NONE === $this->secrets->source( $password );

		return new Mail_Settings_Update( $settings, $discarded ? array( $password ) : array(), $locked );
	}
}
