<?php
/**
 * Inserción de un formulario en una página.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Frontend;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Submissions\Confirmation_Builder;
use Formulario_Mensaje\Submissions\Reference_Generator;
use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Submissions\Submission_Result;
use Formulario_Mensaje\Support\Clock;
use Formulario_Mensaje\Support\Request_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Pinta un formulario guardado y encola lo que necesita. Lo usan el shortcode, el bloque y el widget.
 */
final class Form_Embed {

	/**
	 * Parámetro de la URL que indica el formulario recién enviado sin JavaScript.
	 */
	public const SENT_PARAM = 'formulario_mensaje_sent';

	/**
	 * Parámetro de la URL con la referencia del envío recién completado sin JavaScript.
	 */
	public const REFERENCE_PARAM = 'formulario_mensaje_ref';

	/**
	 * Constructor.
	 *
	 * @param Form_Repository      $forms        Almacén de formularios.
	 * @param Form_Renderer        $renderer     HTML del formulario.
	 * @param Frontend_Assets      $assets       Estilos y script.
	 * @param Confirmation_Builder $confirmation Mensaje de éxito.
	 * @param Request_Query        $query        Parámetros de la URL.
	 * @param Clock                $clock        Reloj.
	 */
	public function __construct(
		private readonly Form_Repository $forms,
		private readonly Form_Renderer $renderer,
		private readonly Frontend_Assets $assets,
		private readonly Confirmation_Builder $confirmation,
		private readonly Request_Query $query,
		private readonly Clock $clock
	) {}

	/**
	 * Devuelve el HTML de un formulario guardado.
	 *
	 * @param int $form_id Identificador del formulario.
	 * @return string Cadena vacía si el formulario no existe o no acepta envíos.
	 */
	public function render( int $form_id ): string {
		$form = $this->forms->find( $form_id );

		if ( null === $form || ! $form->is_published() ) {
			// A quien gestiona formularios se le explica por qué no ve nada; al resto, nada.
			return current_user_can( Capabilities::MANAGE_FORMS )
				? sprintf( '<p class="fm-unavailable">%s</p>', esc_html__( 'This form does not exist or is in the trash.', 'formulario-mensaje' ) )
				: '';
		}

		$this->assets->enqueue();

		$confirmation = $this->fallback_confirmation( $form );

		return $this->renderer->render(
			$form,
			$confirmation,
			'' === $confirmation || Submission_Result::AFTER_RESET === $form->definition->setting( 'after_submit' )
		);
	}

	/**
	 * Mensaje de éxito tras un envío sin JavaScript, que vuelve a la página con dos parámetros en la URL.
	 *
	 * Los datos del envío no viajan en la URL: las etiquetas de campos quedan vacías en este mensaje.
	 *
	 * @param Form $form Formulario.
	 * @return string HTML del mensaje, o cadena vacía si no se acaba de enviar este formulario.
	 */
	private function fallback_confirmation( Form $form ): string {
		if ( $this->query->get( self::SENT_PARAM ) !== (string) $form->id ) {
			return '';
		}

		$reference = $this->query->get( self::REFERENCE_PARAM );

		if ( 1 !== preg_match( Reference_Generator::PATTERN, $reference ) ) {
			$reference = '';
		}

		return $this->confirmation->message(
			$form,
			new Submission(
				$form->id,
				$reference,
				$this->clock->now(),
				'',
				array()
			)
		);
	}
}
