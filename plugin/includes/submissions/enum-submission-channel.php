<?php
/**
 * Vías por las que llega un envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

defined( 'ABSPATH' ) || exit;

/**
 * Vía por la que ha llegado un envío. Las defensas antispam exigen cosas distintas en cada una.
 */
enum Submission_Channel: string {

	/**
	 * Envío por REST desde el script del formulario; lleva token antispam.
	 */
	case Rest = 'rest';

	/**
	 * Envío como POST normal, cuando el navegador no ejecuta JavaScript; no puede llevar token.
	 */
	case Fallback = 'fallback';
}
