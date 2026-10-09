<?php
/**
 * Servicio de envío de formularios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

use Formulario_Mensaje\Actions\Action_Result;
use Formulario_Mensaje\Actions\Action_Runner;
use Formulario_Mensaje\Antispam\Spam_Guard;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Storage\Submission_Journal;
use Formulario_Mensaje\Support\Clock;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Procesa un envío de principio a fin: antispam, validación, guardado, acciones y confirmación.
 *
 * Es el único punto por el que entra un envío. La ruta REST y el POST sin JavaScript son adaptadores
 * que construyen la petición y delegan aquí.
 */
final class Submission_Handler {

	/**
	 * Constructor.
	 *
	 * @param Form_Repository      $forms        Almacén de formularios.
	 * @param Spam_Guard           $spam_guard   Defensas antispam.
	 * @param Submission_Validator $validator    Validación de los datos.
	 * @param Submission_Journal   $journal      Guardado del envío y registro de sus acciones.
	 * @param Action_Runner        $actions      Ejecución de las acciones.
	 * @param Confirmation_Builder $confirmation Confirmación para el visitante.
	 * @param Reference_Generator  $references   Números de referencia.
	 * @param Clock                $clock        Reloj.
	 */
	public function __construct(
		private readonly Form_Repository $forms,
		private readonly Spam_Guard $spam_guard,
		private readonly Submission_Validator $validator,
		private readonly Submission_Journal $journal,
		private readonly Action_Runner $actions,
		private readonly Confirmation_Builder $confirmation,
		private readonly Reference_Generator $references,
		private readonly Clock $clock
	) {}

	/**
	 * Procesa un envío.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @return Submission_Result|WP_Error El error lleva `status` en sus datos y, si hay campos no válidos,
	 *                                    `errors` con un mensaje por identificador de campo.
	 */
	public function handle( Submission_Request $request ): Submission_Result|WP_Error {
		$form = $this->forms->find( $request->form_id );

		// Un formulario en la papelera deja de aceptar envíos.
		if ( null === $form || ! $form->is_published() ) {
			return new WP_Error(
				'formulario_mensaje_form_unavailable',
				__( 'This form is no longer available.', 'formulario-mensaje' ),
				array( 'status' => 404 )
			);
		}

		$rejection = $this->spam_guard->inspect( $request, $form );

		if ( null !== $rejection ) {
			return $rejection;
		}

		$validated = $this->validator->validate( $form, $request->raw_values );

		if ( array() !== $validated['errors'] ) {
			// El envío no se acepta: no ocupa hueco en el límite por IP.
			$this->spam_guard->release( $request, $form );

			return new WP_Error(
				'formulario_mensaje_invalid',
				__( 'Please review the highlighted fields.', 'formulario-mensaje' ),
				array(
					'status' => 422,
					'errors' => $validated['errors'],
				)
			);
		}

		$submission = new Submission(
			$form->id,
			$this->references->generate(),
			$this->clock->now(),
			$request->page_url,
			$validated['entries'],
			$request->client_ip,
			$request->user_agent
		);

		/**
		 * Se dispara cuando un envío ha superado el antispam y la validación, antes de ejecutar las acciones.
		 *
		 * @since 0.1.0
		 *
		 * @param Submission $submission Envío validado.
		 * @param Form       $form       Formulario.
		 */
		do_action( 'formulario_mensaje_before_actions', $submission, $form );

		// Primero se guarda: si una acción falla después, el envío ya está a salvo y se puede reintentar.
		$stored_id = $this->journal->record_submission( $form, $submission );
		$results   = $this->actions->run( $form, $submission );

		$this->journal->record_results( $form, $stored_id, $results );

		if ( 0 === $stored_id && $this->nothing_succeeded( $form, $results ) ) {
			$this->spam_guard->release( $request, $form );

			return new WP_Error(
				'formulario_mensaje_delivery_failed',
				__( 'Your message could not be sent. Please try again later.', 'formulario-mensaje' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Se dispara cuando un envío se ha completado.
		 *
		 * @since 0.1.0
		 *
		 * @param Submission      $submission Envío.
		 * @param Form            $form       Formulario.
		 * @param Action_Result[] $results    Resultado de cada acción ejecutada.
		 */
		do_action( 'formulario_mensaje_submission_completed', $submission, $form, $results );

		return $this->confirmation->build( $form, $submission );
	}

	/**
	 * Indica si el envío se habría perdido: no se ha guardado y ninguna acción lo ha entregado.
	 *
	 * Un formulario que ni guarda sus envíos ni tiene acciones no espera nada: se acepta sin más.
	 *
	 * @param Form            $form    Formulario.
	 * @param Action_Result[] $results Resultados de las acciones.
	 */
	private function nothing_succeeded( Form $form, array $results ): bool {
		foreach ( $results as $result ) {
			if ( $result->success ) {
				return false;
			}
		}

		return array() !== $results || $this->journal->stores( $form );
	}
}
