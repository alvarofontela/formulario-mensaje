<?php
/**
 * Confirmación de un envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Support\Inline_Html;

defined( 'ABSPATH' ) || exit;

/**
 * Compone lo que ocurre tras un envío correcto según los ajustes del formulario.
 */
final class Confirmation_Builder {

	/**
	 * Constructor.
	 *
	 * @param Tag_Replacer      $tags        Etiquetas de los textos.
	 * @param Redirect_Resolver $redirects   Redirección tras el envío.
	 * @param Inline_Html       $inline_html Filtro del HTML permitido en los textos.
	 */
	public function __construct(
		private readonly Tag_Replacer $tags,
		private readonly Redirect_Resolver $redirects,
		private readonly Inline_Html $inline_html
	) {}

	/**
	 * Compone el resultado de un envío correcto.
	 *
	 * @param Form       $form       Formulario.
	 * @param Submission $submission Envío.
	 */
	public function build( Form $form, Submission $submission ): Submission_Result {
		$definition = $form->definition;

		return new Submission_Result(
			$form->id,
			$submission->reference,
			$this->message( $form, $submission ),
			Submission_Result::AFTER_RESET === $definition->setting( 'after_submit' ) ? Submission_Result::AFTER_RESET : Submission_Result::AFTER_HIDE,
			$this->redirects->resolve( $form, $submission ),
			! empty( $definition->setting( 'tracking_enabled', true ) )
		);
	}

	/**
	 * Mensaje de éxito en HTML, con las etiquetas sustituidas.
	 *
	 * @param Form       $form       Formulario.
	 * @param Submission $submission Envío.
	 */
	public function message( Form $form, Submission $submission ): string {
		$template = $form->definition->setting( 'success_message' );

		if ( ! is_string( $template ) || '' === trim( $template ) ) {
			$template = __( 'Thank you. Your message has been sent.', 'formulario-mensaje' );
		}

		// La plantilla se filtra antes de sustituir: los valores del envío entran después, ya escapados.
		return $this->tags->to_html( nl2br( $this->inline_html->filter( $template ) ), $submission, $form );
	}
}
