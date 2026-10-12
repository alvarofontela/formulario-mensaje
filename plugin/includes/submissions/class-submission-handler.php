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
use Formulario_Mensaje\Queue\Queue_Dispatcher;
use Formulario_Mensaje\Storage\Submission_Journal;
use Formulario_Mensaje\Support\Clock;
use Formulario_Mensaje\Uploads\Submission_Uploads;
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
	 * @param Queue_Dispatcher     $queue        Entrada de las acciones en segundo plano en la cola.
	 * @param Submission_Uploads   $uploads      Ficheros de los envíos.
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
		private readonly Queue_Dispatcher $queue,
		private readonly Submission_Uploads $uploads,
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

		if ( $request->truncated ) {
			return new WP_Error(
				'formulario_mensaje_request_too_large',
				__( 'The files are too large to be sent. Please choose smaller files and try again.', 'formulario-mensaje' ),
				array( 'status' => 413 )
			);
		}

		$rejection = $this->spam_guard->inspect( $request, $form );

		if ( null !== $rejection ) {
			return $rejection;
		}

		$uploads   = $this->uploads->collect( $form, $request->files );
		$validated = $this->validator->validate( $form, $request->raw_values, $uploads );

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

		$reference = $this->references->generate();

		// Los ficheros, ya validados, pasan al almacén privado: las acciones se ejecutan después.
		$entries = $this->uploads->attach( $form, $reference, $validated['entries'], $uploads );

		if ( is_wp_error( $entries ) ) {
			$this->spam_guard->release( $request, $form );

			return new WP_Error(
				'formulario_mensaje_upload_failed',
				__( 'The files could not be stored. Please try again later.', 'formulario-mensaje' ),
				array( 'status' => 500 )
			);
		}

		$submission = new Submission(
			$form->id,
			$reference,
			$this->clock->now(),
			$request->page_url,
			$entries,
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

		$this->uploads->link( $form, $reference, $stored_id );

		// Las acciones reciben el envío con los enlaces de descarga de sus ficheros, que no se guardan con él.
		$for_actions = $submission->with_entries( $this->uploads->with_links( $form, $reference, $stored_id, $entries ) );
		$results     = $this->actions->run( $form, $for_actions );

		$this->journal->record_results( $form, $stored_id, $results );

		// Las acciones en segundo plano quedan en la cola, que tiene su propia copia del envío si no se guarda.
		$queued = $this->queue->dispatch( $form, $for_actions, $stored_id, $results );

		if ( 0 === $stored_id && 0 === $queued && $this->nothing_succeeded( $form, $results ) ) {
			$this->spam_guard->release( $request, $form );
			$this->uploads->discard( $form, $reference );

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

		// Hechas las acciones, los ficheros que solo hacían falta para ellas se borran; si alguna ha fallado, cuando se resuelva.
		$this->uploads->finish( $form, $reference, $stored_id, $this->has_failures( $results ) );

		return $this->confirmation->build( $form, $submission );
	}

	/**
	 * Indica si alguna acción ejecutada ha fallado. Las que van por la cola no cuentan: aún no se han intentado.
	 *
	 * @param Action_Result[] $results Resultados de las acciones.
	 */
	private function has_failures( array $results ): bool {
		foreach ( $results as $result ) {
			if ( ! $result->success && ! $result->deferred ) {
				return true;
			}
		}

		return false;
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
