<?php
/**
 * Resultado de un envío correcto.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con lo que el visitante ve y lo que ocurre tras un envío correcto.
 */
final class Submission_Result {

	/**
	 * El formulario se oculta y queda solo el mensaje.
	 */
	public const AFTER_HIDE = 'hide';

	/**
	 * El formulario se vacía para permitir otro envío.
	 */
	public const AFTER_RESET = 'reset';

	/**
	 * Constructor.
	 *
	 * @param int    $form_id      Identificador del formulario.
	 * @param string $reference    Número de referencia del envío.
	 * @param string $message      Mensaje de éxito en HTML, ya filtrado y con los datos del envío escapados.
	 * @param string $after_submit Qué pasa con el formulario: AFTER_HIDE o AFTER_RESET.
	 * @param string $redirect_url Dirección a la que redirigir, ya validada, o cadena vacía.
	 * @param bool   $tracking     Si se avisa del envío con un evento de JavaScript y en `dataLayer`.
	 */
	public function __construct(
		public readonly int $form_id,
		public readonly string $reference,
		public readonly string $message,
		public readonly string $after_submit,
		public readonly string $redirect_url,
		public readonly bool $tracking
	) {}

	/**
	 * Representación para la respuesta REST. No incluye ningún dato personal del envío fuera del mensaje.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'success'      => true,
			'form_id'      => $this->form_id,
			'reference'    => $this->reference,
			'message'      => $this->message,
			'after_submit' => $this->after_submit,
			'redirect'     => $this->redirect_url,
			'tracking'     => $this->tracking,
		);
	}
}
