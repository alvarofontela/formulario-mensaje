<?php
/**
 * Exportación e importación de formularios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Submissions\Redirect_Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lleva un formulario de una web a otra como un documento JSON.
 *
 * Viaja todo menos los secretos. Un documento importado es entrada no fiable: pasa por el mismo
 * saneado que un formulario guardado desde el constructor, y sus acciones quedan desactivadas hasta
 * que alguien las revise, porque escriben a direcciones y servicios que eligió otra persona.
 */
final class Form_Porter {

	/**
	 * Marca que identifica el documento.
	 */
	public const FORMAT = 'formulario-mensaje-form';

	/**
	 * Versión del documento que se exporta. Se importan esta y las anteriores.
	 */
	public const VERSION = 1;

	/**
	 * Constructor.
	 *
	 * @param Form_Service              $forms           Servicio de formularios.
	 * @param Form_Definition_Sanitizer $sanitizer       Saneado de definiciones.
	 * @param Action_Type_Registry      $action_types    Tipos de acción.
	 * @param Redirect_Policy           $redirect_policy Destinos de redirección permitidos.
	 * @param string                    $plugin_version  Versión del plugin, que se anota en el documento.
	 */
	public function __construct(
		private readonly Form_Service $forms,
		private readonly Form_Definition_Sanitizer $sanitizer,
		private readonly Action_Type_Registry $action_types,
		private readonly Redirect_Policy $redirect_policy,
		private readonly string $plugin_version
	) {}

	/**
	 * Documento de exportación de un formulario, sin secretos.
	 *
	 * @param Form $form Formulario.
	 * @return array<string, mixed>
	 */
	public function export( Form $form ): array {
		return array(
			'format'     => self::FORMAT,
			'version'    => self::VERSION,
			'plugin'     => $this->plugin_version,
			'title'      => $form->title,
			'definition' => $this->portable( $form->definition->to_array(), true ),
		);
	}

	/**
	 * Crea un formulario a partir de un documento de exportación.
	 *
	 * @param mixed $document Documento ya decodificado, sin validar.
	 * @return Form|WP_Error El formulario creado.
	 */
	public function import( mixed $document ): Form|WP_Error {
		if ( ! is_array( $document ) || self::FORMAT !== ( $document['format'] ?? null ) || ! is_array( $document['definition'] ?? null ) ) {
			return new WP_Error( 'formulario_mensaje_import_invalid', __( 'The file is not a form exported from Formulario Mensaje.', 'formulario-mensaje' ), array( 'status' => 400 ) );
		}

		if ( (int) ( $document['version'] ?? 0 ) > self::VERSION ) {
			return new WP_Error( 'formulario_mensaje_import_newer', __( 'The file was exported from a newer version of Formulario Mensaje. Update the plugin and try again.', 'formulario-mensaje' ), array( 'status' => 400 ) );
		}

		$definition = $this->portable( $this->sanitizer->sanitize( $document['definition'] )->to_array(), false );

		// Una redirección a un dominio que esta web no permite impediría guardar: se desactiva y se conserva la dirección.
		if ( ! empty( $definition['settings']['redirect_enabled'] ) && ! $this->redirect_policy->is_allowed( (string) ( $definition['settings']['redirect_url'] ?? '' ) ) ) {
			$definition['settings']['redirect_enabled'] = false;
		}

		return $this->forms->create( $document['title'] ?? '', $definition );
	}

	/**
	 * Quita los secretos de las acciones de una definición y, al importar, las desactiva.
	 *
	 * @param array<string, mixed> $definition   Definición ya saneada.
	 * @param bool                 $keep_enabled Si las acciones conservan su interruptor.
	 * @return array<string, mixed>
	 */
	private function portable( array $definition, bool $keep_enabled ): array {
		foreach ( $definition['actions'] as $index => $action ) {
			$type = $this->action_types->get( $action['type'] );

			if ( null === $type ) {
				unset( $definition['actions'][ $index ] );
				continue;
			}

			$definition['actions'][ $index ]['settings'] = $type->portable_settings( $action['settings'] );
			$definition['actions'][ $index ]['enabled']  = $keep_enabled && $action['enabled'];
		}

		$definition['actions'] = array_values( $definition['actions'] );

		return $definition;
	}
}
