<?php
/**
 * Contrato de un tipo de acción.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Actions;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Algo que un formulario puede hacer con cada envío: notificar por correo, guardarlo, llamar a un webhook.
 */
interface Action_Type {

	/**
	 * Identificador del tipo: minúsculas, cifras y guiones bajos.
	 */
	public function get_type(): string;

	/**
	 * Nombre del tipo, ya traducido, para el constructor.
	 */
	public function get_label(): string;

	/**
	 * Ajustes iniciales de una acción nueva de este tipo.
	 *
	 * @return array<string, mixed>
	 */
	public function default_settings(): array;

	/**
	 * Sanea los ajustes de una acción antes de guardarlos.
	 *
	 * @param mixed $raw Ajustes sin validar.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( mixed $raw ): array;

	/**
	 * Ejecuta la acción sobre un envío.
	 *
	 * @param array<string, mixed> $settings   Ajustes guardados de la acción.
	 * @param Submission           $submission Envío ya validado.
	 * @param Form                 $form       Formulario.
	 * @return true|WP_Error True si se completó; el error no debe contener datos del envío.
	 */
	public function execute( array $settings, Submission $submission, Form $form ): bool|WP_Error;

	/**
	 * Indica si la acción se ejecuta en segundo plano, por la cola y con reintentos, en vez de
	 * mientras el visitante espera. Es lo propio de las que dependen de un servicio ajeno.
	 */
	public function is_deferred(): bool;
}
