<?php
/**
 * Envío del formulario sin JavaScript.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Frontend;

use Formulario_Mensaje\Antispam\Honeypot_Check;
use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Settings\Settings_Repository;
use Formulario_Mensaje\Submissions\Redirect_Policy;
use Formulario_Mensaje\Submissions\Submission_Channel;
use Formulario_Mensaje\Submissions\Submission_Handler;
use Formulario_Mensaje\Submissions\Submission_Request;
use Formulario_Mensaje\Submissions\Submission_Request_Factory;
use Formulario_Mensaje\Submissions\Submission_Result;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador del envío como POST normal a `admin-post.php`, para navegadores sin JavaScript.
 *
 * Solo traduce la petición y la respuesta: la lógica es la misma que la de la ruta REST.
 */
final class Fallback_Controller {

	/**
	 * Constructor.
	 *
	 * @param Submission_Handler         $handler  Servicio de envío.
	 * @param Submission_Request_Factory $requests Construcción de peticiones de envío.
	 * @param Form_Repository            $forms    Almacén de formularios.
	 * @param Redirect_Policy            $policy   Destinos de redirección permitidos.
	 * @param Settings_Repository        $settings Ajustes del plugin.
	 */
	public function __construct(
		private readonly Submission_Handler $handler,
		private readonly Submission_Request_Factory $requests,
		private readonly Form_Repository $forms,
		private readonly Redirect_Policy $policy,
		private readonly Settings_Repository $settings
	) {}

	/**
	 * Atiende el envío y termina la petición con una redirección o con una página de error.
	 */
	public function handle(): void {
		// Esta vía no puede traer token antispam: quien no quiera aceptarla la desactiva en los ajustes.
		if ( ! $this->settings->get()->accept_no_js ) {
			wp_die(
				esc_html__( 'This form needs JavaScript to be sent. Enable it in your browser and try again.', 'formulario-mensaje' ),
				esc_html__( 'The form could not be sent', 'formulario-mensaje' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$request = $this->read_request();
		$result  = $this->handler->handle( $request );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result, $request );
		}

		$hosts_filter = fn ( array $hosts ): array => array_merge( $hosts, $this->policy->allowed_hosts() );

		// La dirección ya está validada contra la misma lista; wp_safe_redirect() la comprueba otra vez.
		add_filter( 'allowed_redirect_hosts', $hosts_filter );
		wp_safe_redirect( $this->destination( $result, $request ), 303 );
		remove_filter( 'allowed_redirect_hosts', $hosts_filter );
		exit;
	}

	/**
	 * Lee de la petición los datos del envío.
	 */
	private function read_request(): Submission_Request {
		/*
		 * Sin nonce ni capability a propósito: es el envío público del formulario, que debe funcionar
		 * para visitantes anónimos y en páginas servidas desde caché, donde un nonce estaría caducado.
		 * Un nonce sería además el mismo para todos los anónimos y no detendría a un bot. La defensa
		 * es la del servicio de envío: campo trampa y límite por IP.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$form_id  = isset( $_POST[ Form_Renderer::FORM_ID_FIELD ] ) ? absint( wp_unslash( $_POST[ Form_Renderer::FORM_ID_FIELD ] ) ) : 0;
		$honeypot = isset( $_POST[ Honeypot_Check::FIELD_NAME ] ) && is_string( $_POST[ Honeypot_Check::FIELD_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ Honeypot_Check::FIELD_NAME ] ) )
			: '';

		/*
		 * Los valores de los campos se entregan sin sanear: de ellos solo se leen las claves de los
		 * campos del formulario, y cada valor lo sanea y valida el tipo de campo que le corresponde.
		 */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$values = isset( $_POST[ Render_Context::VALUES_PARAM ] ) && is_array( $_POST[ Render_Context::VALUES_PARAM ] ) ? wp_unslash( $_POST[ Render_Context::VALUES_PARAM ] ) : array();

		// Con los ficheros pasa lo mismo: solo se leen los de los campos de subida del formulario, y cada uno se comprueba antes de moverlo.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$files = isset( $_FILES[ Render_Context::VALUES_PARAM ] ) && is_array( $_FILES[ Render_Context::VALUES_PARAM ] ) ? $_FILES[ Render_Context::VALUES_PARAM ] : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $this->requests->create( $form_id, $values, $honeypot, '', Submission_Channel::Fallback, '', $files );
	}

	/**
	 * Dirección a la que vuelve el visitante tras un envío correcto.
	 *
	 * @param Submission_Result  $result  Resultado del envío.
	 * @param Submission_Request $request Envío.
	 */
	private function destination( Submission_Result $result, Submission_Request $request ): string {
		if ( '' !== $result->redirect_url ) {
			return $result->redirect_url;
		}

		// De vuelta a la página del formulario, que muestra el mensaje de éxito. Sin datos del envío en la URL.
		$page = '' !== $request->page_url ? $request->page_url : home_url( '/' );
		$page = remove_query_arg( array( Form_Embed::SENT_PARAM, Form_Embed::REFERENCE_PARAM ), strtok( $page, '#' ) );

		return esc_url_raw(
			add_query_arg(
				array(
					Form_Embed::SENT_PARAM      => $result->form_id,
					Form_Embed::REFERENCE_PARAM => rawurlencode( $result->reference ),
				),
				$page
			)
		) . '#formulario-mensaje-' . $result->form_id . '-1';
	}

	/**
	 * Termina la petición con una página que explica por qué no se aceptó el envío.
	 *
	 * El enlace para volver conserva en el navegador lo que el visitante había escrito.
	 *
	 * @param WP_Error           $error   Error del servicio de envío.
	 * @param Submission_Request $request Envío.
	 */
	private function fail( WP_Error $error, Submission_Request $request ): never {
		$data    = $error->get_error_data();
		$status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		$errors  = is_array( $data ) && is_array( $data['errors'] ?? null ) ? $data['errors'] : array();
		$form    = array() === $errors ? null : $this->forms->find( $request->form_id );
		$message = '<p>' . esc_html( $error->get_error_message() ) . '</p>';

		if ( null !== $form ) {
			$message .= '<ul>';

			foreach ( $errors as $field_id => $field_error ) {
				$field    = $form->definition->get_field( (string) $field_id );
				$message .= sprintf( '<li><strong>%s:</strong> %s</li>', esc_html( null === $field ? (string) $field_id : $field->label() ), esc_html( (string) $field_error ) );
			}

			$message .= '</ul>';
		}

		wp_die(
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML compuesto arriba con cada valor escapado.
			$message,
			esc_html__( 'The form could not be sent', 'formulario-mensaje' ),
			array(
				'response'  => absint( $status ),
				'back_link' => true,
			)
		);
	}
}
