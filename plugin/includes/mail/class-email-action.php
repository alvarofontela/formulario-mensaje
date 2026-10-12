<?php
/**
 * Acción: notificación por correo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use Formulario_Mensaje\Actions\Action_Type;
use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Submissions\Tag_Replacer;
use Formulario_Mensaje\Support\Plain_Text;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Envía por correo los datos de un envío a los destinatarios configurados en el formulario.
 *
 * Los destinatarios salen siempre de la configuración. La dirección que escribe el visitante solo se
 * usa, como mucho, en `Reply-To`.
 */
final class Email_Action implements Action_Type {

	/**
	 * Identificador del tipo de acción.
	 */
	public const TYPE = 'email';

	public const FORMAT_HTML = 'html';
	public const FORMAT_TEXT = 'text';

	/**
	 * Máximo de destinatarios de una notificación.
	 */
	private const MAX_RECIPIENTS = 20;

	/**
	 * Tamaño máximo, en bytes, de los adjuntos de un correo: por encima, muchos servidores lo rechazan.
	 */
	private const MAX_ATTACHMENT_BYTES = 20 * MB_IN_BYTES;

	/**
	 * Constructor.
	 *
	 * @param Mail_Transport    $transport Transporte de correo.
	 * @param Tag_Replacer      $tags      Etiquetas de los textos.
	 * @param Email_Template    $template    Plantilla HTML con la marca.
	 * @param Attachment_Source $attachments Ficheros del envío que se adjuntan.
	 */
	public function __construct(
		private readonly Mail_Transport $transport,
		private readonly Tag_Replacer $tags,
		private readonly Email_Template $template,
		private readonly Attachment_Source $attachments
	) {}

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Email notification', 'formulario-mensaje' );
	}

	/**
	 * El correo se envía mientras el visitante espera: si falla y el envío no se guarda, tiene que saberlo.
	 */
	public function is_deferred(): bool {
		return false;
	}

	/**
	 * Una notificación no guarda secretos: sus ajustes viajan tal cual.
	 *
	 * @param array<string, mixed> $settings Ajustes guardados de la acción.
	 * @return array<string, mixed>
	 */
	public function portable_settings( array $settings ): array {
		return $settings;
	}

	/**
	 * Ajustes iniciales de una notificación nueva.
	 *
	 * @return array<string, mixed>
	 */
	public function default_settings(): array {
		return array(
			'to'             => array( (string) get_option( 'admin_email' ) ),
			/* translators: {form_title} is a tag replaced by the name of the form; keep it as is. */
			'subject'        => __( 'New submission: {form_title}', 'formulario-mensaje' ),
			/* translators: {all_fields}, {reference}, {page_url} and {date} are tags replaced by data of the submission; keep them as they are. */
			'body'           => __( "{all_fields}\n\n--\nReference: {reference}\nPage: {page_url}\nDate: {date}", 'formulario-mensaje' ),
			'format'         => self::FORMAT_HTML,
			'reply_to_field' => '',
		);
	}

	/**
	 * Sanea los ajustes de una notificación antes de guardarlos.
	 *
	 * @param mixed $raw Ajustes sin validar.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( mixed $raw ): array {
		$raw      = is_array( $raw ) ? $raw : array();
		$defaults = $this->default_settings();
		$reply_to = is_string( $raw['reply_to_field'] ?? null ) ? $raw['reply_to_field'] : '';

		return array(
			'to'             => $this->sanitize_recipients( $raw['to'] ?? array() ),
			'subject'        => is_scalar( $raw['subject'] ?? null ) ? mb_substr( Plain_Text::line( (string) $raw['subject'] ), 0, 300 ) : $defaults['subject'],
			'body'           => is_scalar( $raw['body'] ?? null ) ? mb_substr( Plain_Text::block( (string) $raw['body'] ), 0, 20000 ) : $defaults['body'],
			'format'         => self::FORMAT_TEXT === ( $raw['format'] ?? null ) ? self::FORMAT_TEXT : self::FORMAT_HTML,
			'reply_to_field' => 1 === preg_match( Field::ID_PATTERN, $reply_to ) ? $reply_to : '',
		);
	}

	/**
	 * Envía la notificación de un envío.
	 *
	 * @param array<string, mixed> $settings   Ajustes guardados de la acción.
	 * @param Submission           $submission Envío ya validado.
	 * @param Form                 $form       Formulario.
	 * @return true|WP_Error True si el transporte aceptó el correo.
	 */
	public function execute( array $settings, Submission $submission, Form $form ): bool|WP_Error {
		$recipients = $this->sanitize_recipients( $settings['to'] ?? array() );

		if ( array() === $recipients ) {
			return new WP_Error( 'formulario_mensaje_no_recipients', __( 'The notification has no valid recipients.', 'formulario-mensaje' ) );
		}

		$body    = is_string( $settings['body'] ?? null ) ? $settings['body'] : '';
		$subject = $this->tags->to_text( is_string( $settings['subject'] ?? null ) ? $settings['subject'] : '', $submission, $form );
		$html    = '';

		if ( self::FORMAT_TEXT !== ( $settings['format'] ?? self::FORMAT_HTML ) ) {
			// La plantilla se escapa antes de sustituir las etiquetas: los datos del envío entran después, ya escapados.
			$html = $this->template->render( $this->tags->to_email_html( nl2br( esc_html( $body ) ), $submission, $form ), $subject );
		}

		$message = new Email_Message(
			$recipients,
			$subject,
			$this->tags->to_text( $body, $submission, $form ),
			$this->reply_to( $settings, $submission ),
			$html,
			$this->attachments( $submission, $form )
		);

		/**
		 * Filtra el correo de notificación antes de enviarlo.
		 *
		 * @since 0.1.0
		 *
		 * @param Email_Message $message    Correo compuesto. Para cambiarlo, devuelve otro Email_Message.
		 * @param Submission    $submission Envío.
		 * @param Form          $form       Formulario.
		 */
		$filtered = apply_filters( 'formulario_mensaje_email_message', $message, $submission, $form );

		return $this->transport->send( $filtered instanceof Email_Message ? $filtered : $message );
	}

	/**
	 * Ficheros que se adjuntan, hasta el tope de tamaño de un correo. Los que no caben se quedan
	 * fuera: siguen estando en el envío guardado, si el formulario los conserva.
	 *
	 * @param Submission $submission Envío.
	 * @param Form       $form       Formulario.
	 * @return array<int, array{path: string, name: string}>
	 */
	private function attachments( Submission $submission, Form $form ): array {
		$attachments = array();
		$total       = 0;

		foreach ( $this->attachments->attachments( $submission, $form ) as $attachment ) {
			$total += (int) filesize( $attachment['path'] );

			if ( $total > self::MAX_ATTACHMENT_BYTES ) {
				break;
			}

			$attachments[] = $attachment;
		}

		return $attachments;
	}

	/**
	 * Dirección de respuesta: la que el visitante escribió en el campo elegido.
	 *
	 * Su validación como dirección de correo la hace el mensaje justo antes de usarla.
	 *
	 * @param array<string, mixed> $settings   Ajustes de la acción.
	 * @param Submission           $submission Envío.
	 */
	private function reply_to( array $settings, Submission $submission ): string {
		$field_id = is_string( $settings['reply_to_field'] ?? null ) ? $settings['reply_to_field'] : '';
		$entry    = '' === $field_id ? null : $submission->entry( $field_id );

		return null !== $entry && is_string( $entry->value ) ? $entry->value : '';
	}

	/**
	 * Sanea una lista de destinatarios: solo direcciones válidas, sin repetir.
	 *
	 * @param mixed $raw Lista de direcciones, o texto con las direcciones separadas por comas o saltos de línea.
	 * @return string[]
	 */
	private function sanitize_recipients( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		}

		$recipients = array();

		foreach ( is_array( $raw ) ? $raw : array() as $address ) {
			$address = is_string( $address ) ? sanitize_email( $address ) : '';

			if ( '' !== $address && false !== is_email( $address ) ) {
				$recipients[ strtolower( $address ) ] ??= $address;
			}
		}

		return array_slice( array_values( $recipients ), 0, self::MAX_RECIPIENTS );
	}
}
