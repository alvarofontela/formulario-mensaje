<?php
/**
 * Transporte de correo por SMTP.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use Formulario_Mensaje\Secrets\Secret_Vault;
use PHPMailer\PHPMailer\Exception as Mailer_Exception;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Envía los correos del plugin por un servidor SMTP propio.
 *
 * Usa su propia instancia del PHPMailer que trae WordPress, no la de `wp_mail()`: así el resto del
 * correo del sitio sigue como estaba, y otros plugins de correo no cambian este servidor.
 *
 * El servidor es el que escribe quien tiene `formulario_mensaje_manage_settings`, y puede ser interno
 * a propósito: lo habitual en muchos alojamientos es `localhost` o un relé de la red privada, así
 * que aquí no vale la lista de destinos «seguros» de la HTTP API. Lo que acota el riesgo de usarlo
 * para sondear la red es que ese permiso solo lo tiene el administrador, que la conexión solo habla
 * SMTP y que del fallo se devuelve el mensaje de PHPMailer, recortado, nunca lo que envíe el otro extremo.
 */
final class Smtp_Transport implements Mail_Transport {

	/**
	 * Segundos de espera para conectar y para cada respuesta del servidor.
	 */
	private const TIMEOUT = 15;

	/**
	 * Longitud máxima del mensaje de error que se devuelve y se anota.
	 */
	private const MAX_ERROR_LENGTH = 200;

	/**
	 * Constructor.
	 *
	 * @param Mail_Settings_Repository $settings Ajustes de correo.
	 * @param Secret_Vault             $secrets  Almacén de secretos, con la contraseña.
	 */
	public function __construct(
		private readonly Mail_Settings_Repository $settings,
		private readonly Secret_Vault $secrets
	) {}

	/**
	 * Envía un correo.
	 *
	 * @param Email_Message $message Correo ya compuesto.
	 * @return true|WP_Error True si el servidor lo aceptó; el error no contiene el cuerpo del correo.
	 */
	public function send( Email_Message $message ): bool|WP_Error {
		$settings = $this->settings->get();

		if ( '' === $settings->smtp_host ) {
			return new WP_Error( 'formulario_mensaje_smtp_not_configured', __( 'The SMTP server is not configured.', 'formulario-mensaje' ) );
		}

		$sender = $this->sender_address( $settings );

		if ( false === is_email( $sender ) ) {
			return new WP_Error( 'formulario_mensaje_smtp_no_sender', __( 'Set a sender address in the email settings: the default one of this site is not a valid email address.', 'formulario-mensaje' ) );
		}

		// Las clases de PHPMailer que trae WordPress solo se cargan cuando se envía un correo.
		if ( ! class_exists( PHPMailer::class ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}

		$mailer = new PHPMailer( true );

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Propiedades de PHPMailer.
		try {
			$mailer->isSMTP();
			$mailer->Host       = $settings->smtp_host;
			$mailer->Port       = $settings->smtp_port;
			$mailer->SMTPSecure = Mail_Settings::ENCRYPTION_NONE === $settings->smtp_encryption ? '' : $settings->smtp_encryption;
			// Sin cifrado elegido no se negocia TLS por sorpresa; con cifrado, el certificado se verifica siempre.
			$mailer->SMTPAutoTLS = Mail_Settings::ENCRYPTION_NONE !== $settings->smtp_encryption;
			$mailer->SMTPAuth    = $settings->smtp_auth;
			$mailer->Timeout     = self::TIMEOUT;
			$mailer->CharSet     = 'UTF-8';

			if ( $settings->smtp_auth ) {
				$mailer->Username = $settings->smtp_username;
				$mailer->Password = $this->secrets->get( Mail_Settings::SECRET_SMTP_PASSWORD );
			}

			$mailer->setFrom( $sender, $settings->sender_name(), false );

			foreach ( $message->safe_recipients() as $recipient ) {
				$mailer->addAddress( $recipient );
			}

			$reply_to = $message->safe_reply_to();

			if ( '' !== $reply_to ) {
				$mailer->addReplyTo( $reply_to );
			}

			foreach ( $message->safe_attachments() as $name => $path ) {
				$mailer->addAttachment( $path, $name );
			}

			$mailer->Subject = $message->safe_subject();

			if ( $message->has_html() ) {
				$mailer->isHTML( true );
				$mailer->Body    = $message->html;
				$mailer->AltBody = $message->body;
			} else {
				$mailer->Body = $message->body;
			}

			$mailer->send();
		} catch ( Mailer_Exception $exception ) {
			// El mensaje de PHPMailer describe el fallo (conexión, autenticación, rechazo), no el correo.
			return new WP_Error( 'formulario_mensaje_smtp_failed', mb_substr( sanitize_text_field( $exception->getMessage() ), 0, self::MAX_ERROR_LENGTH ) );
		} finally {
			$mailer->smtpClose();
		}
		// phpcs:enable

		return true;
	}

	/**
	 * Dirección del remitente: la configurada o, si no hay, la que usaría WordPress.
	 *
	 * @param Mail_Settings $settings Ajustes de correo.
	 */
	private function sender_address( Mail_Settings $settings ): string {
		if ( '' !== $settings->from_email ) {
			return $settings->from_email;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? (string) preg_replace( '/^www\./', '', $host ) : 'localhost';

		// El mismo filtro que aplica wp_mail(): si el sitio ya define su remitente, se respeta.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro del núcleo.
		$address = apply_filters( 'wp_mail_from', 'wordpress@' . $host );

		return is_string( $address ) ? $address : '';
	}
}
