<?php
/**
 * Servicio de gestión de formularios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

use Formulario_Mensaje\Submissions\Redirect_Policy;
use Formulario_Mensaje\Support\Plain_Text;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Operaciones sobre formularios: listar, leer, crear, editar, enviar a la papelera y borrar.
 *
 * Lo usan los adaptadores (REST y, más adelante, WP-CLI y abilities). No comprueba permisos: eso es
 * cosa de cada adaptador.
 *
 * La única decisión de permisos que llega hasta aquí es la del plazo de retención: fijarlo es
 * mandar borrar envíos guardados, que son datos personales, y gestionar formularios no da permiso
 * para eso. El adaptador dice si quien lo pide puede, y el servicio lo aplica en el único sitio por
 * el que pasa todo guardado.
 */
final class Form_Service {

	/**
	 * Longitud máxima del nombre de un formulario.
	 */
	private const MAX_TITLE_LENGTH = 200;

	/**
	 * Constructor.
	 *
	 * @param Form_Repository           $forms           Almacén de formularios.
	 * @param Form_Definition_Sanitizer $sanitizer       Saneado de definiciones.
	 * @param Redirect_Policy           $redirect_policy Destinos de redirección permitidos.
	 */
	public function __construct(
		private readonly Form_Repository $forms,
		private readonly Form_Definition_Sanitizer $sanitizer,
		private readonly Redirect_Policy $redirect_policy
	) {}

	/**
	 * Lista los formularios de un estado.
	 *
	 * @param string $status Form::STATUS_PUBLISHED o Form::STATUS_TRASHED.
	 * @return Form[]
	 */
	public function list( string $status = Form::STATUS_PUBLISHED ): array {
		return $this->forms->all( $status );
	}

	/**
	 * Busca un formulario.
	 *
	 * @param int $id Identificador del formulario.
	 */
	public function get( int $id ): ?Form {
		return $this->forms->find( $id );
	}

	/**
	 * Construye un formulario sin guardarlo, para previsualizarlo.
	 *
	 * @param mixed $title          Nombre sin validar.
	 * @param mixed $raw_definition Definición sin validar.
	 */
	public function build( mixed $title, mixed $raw_definition ): Form {
		return new Form( 0, $this->sanitize_title( $title ), Form::STATUS_PUBLISHED, $this->sanitizer->sanitize( $raw_definition ) );
	}

	/**
	 * Crea un formulario.
	 *
	 * @param mixed $title             Nombre sin validar.
	 * @param mixed $raw_definition    Definición sin validar.
	 * @param bool  $can_set_retention Si quien lo pide puede fijar cuánto se conservan los envíos.
	 * @return Form|WP_Error
	 */
	public function create( mixed $title, mixed $raw_definition, bool $can_set_retention = true ): Form|WP_Error {
		return $this->save( 0, $title, $raw_definition, $can_set_retention );
	}

	/**
	 * Actualiza un formulario.
	 *
	 * @param int   $id                Identificador del formulario.
	 * @param mixed $title             Nombre sin validar.
	 * @param mixed $raw_definition    Definición sin validar.
	 * @param bool  $can_set_retention Si quien lo pide puede cambiar cuánto se conservan los envíos.
	 * @return Form|WP_Error
	 */
	public function update( int $id, mixed $title = null, mixed $raw_definition = null, bool $can_set_retention = true ): Form|WP_Error {
		$current = $this->forms->find( $id );

		if ( null === $current ) {
			return $this->not_found();
		}

		// Lo que no se indica se queda como está: cambiar solo el nombre no puede vaciar los campos.
		return $this->save( $id, $title ?? $current->title, $raw_definition ?? $current->definition->to_array(), $can_set_retention );
	}

	/**
	 * Crea una copia de un formulario, con todos sus campos, ajustes y acciones.
	 *
	 * @param int $id Identificador del formulario que se copia.
	 * @return Form|WP_Error La copia.
	 */
	public function duplicate( int $id ): Form|WP_Error {
		$form = $this->forms->find( $id );

		if ( null === $form ) {
			return $this->not_found();
		}

		/* translators: %s: name of the form that was copied. */
		return $this->save( 0, sprintf( __( '%s (copy)', 'formulario-mensaje' ), $form->title ), $form->definition->to_array() );
	}

	/**
	 * Envía un formulario a la papelera.
	 *
	 * @param int $id Identificador del formulario.
	 * @return true|WP_Error
	 */
	public function trash( int $id ): bool|WP_Error {
		return $this->change( $id, $this->forms->trash( ... ) );
	}

	/**
	 * Recupera un formulario de la papelera.
	 *
	 * @param int $id Identificador del formulario.
	 * @return true|WP_Error
	 */
	public function restore( int $id ): bool|WP_Error {
		return $this->change( $id, $this->forms->restore( ... ) );
	}

	/**
	 * Borra un formulario definitivamente.
	 *
	 * @param int $id Identificador del formulario.
	 * @return true|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		return $this->change( $id, $this->forms->delete( ... ) );
	}

	/**
	 * Sanea, comprueba y guarda un formulario.
	 *
	 * @param int   $id                Identificador, o 0 para crear uno nuevo.
	 * @param mixed $title             Nombre sin validar.
	 * @param mixed $raw_definition    Definición sin validar.
	 * @param bool  $can_set_retention Si quien lo pide puede cambiar cuánto se conservan los envíos.
	 * @return Form|WP_Error
	 */
	private function save( int $id, mixed $title, mixed $raw_definition, bool $can_set_retention = true ): Form|WP_Error {
		$definition = $this->sanitizer->sanitize( $raw_definition );

		/*
		 * El plazo de retención manda borrar envíos guardados sin que nadie más intervenga. Quien no
		 * puede borrarlos tampoco puede cambiarlo, y la petición se rechaza entera: guardarla a medias
		 * le dejaría creyendo que el plazo ha cambiado.
		 */
		if ( ! $can_set_retention && (int) $definition->setting( 'retention_days', 0 ) !== $this->stored_retention( $id ) ) {
			return new WP_Error(
				'formulario_mensaje_retention_forbidden',
				__( 'Sorry, you are not allowed to change how long submissions are kept.', 'formulario-mensaje' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! empty( $definition->setting( 'redirect_enabled' ) ) && ! $this->redirect_policy->is_allowed( (string) $definition->setting( 'redirect_url', '' ) ) ) {
			return new WP_Error(
				'formulario_mensaje_redirect_not_allowed',
				__( 'The redirect address is not allowed. Use an address on this site, or add its domain to the allowed domains in the plugin settings.', 'formulario-mensaje' ),
				array( 'status' => 400 )
			);
		}

		$saved_id = $this->forms->save( $id, $this->sanitize_title( $title ), $definition );

		if ( is_wp_error( $saved_id ) ) {
			return $saved_id;
		}

		return $this->forms->find( $saved_id ) ?? $this->not_found();
	}

	/**
	 * Aplica un cambio de estado a un formulario existente.
	 *
	 * @param int                 $id        Identificador del formulario.
	 * @param callable(int): bool $operation Operación del almacén.
	 * @return true|WP_Error
	 */
	private function change( int $id, callable $operation ): bool|WP_Error {
		if ( null === $this->forms->find( $id ) ) {
			return $this->not_found();
		}

		if ( ! $operation( $id ) ) {
			return new WP_Error(
				'formulario_mensaje_form_not_changed',
				__( 'The form could not be updated.', 'formulario-mensaje' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Sanea el nombre de un formulario.
	 *
	 * @param mixed $title Nombre sin validar.
	 */
	private function sanitize_title( mixed $title ): string {
		$title = is_scalar( $title ) ? mb_substr( Plain_Text::line( (string) $title ), 0, self::MAX_TITLE_LENGTH ) : '';

		return '' === $title ? __( 'Untitled form', 'formulario-mensaje' ) : $title;
	}

	/**
	 * Días de retención que tiene guardados un formulario.
	 *
	 * @param int $id Identificador del formulario, o 0 si todavía no existe.
	 * @return int 0 si no existe: un formulario nuevo conserva sus envíos hasta que alguien los borre.
	 */
	private function stored_retention( int $id ): int {
		$form = $id > 0 ? $this->forms->find( $id ) : null;

		return null === $form ? 0 : (int) $form->definition->setting( 'retention_days', 0 );
	}

	/**
	 * Error de formulario inexistente.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'formulario_mensaje_form_not_found', __( 'The form does not exist.', 'formulario-mensaje' ), array( 'status' => 404 ) );
	}
}
