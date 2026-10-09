<?php
/**
 * Saneado de la definición de un formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Fields\Settings_Schema_Sanitizer;
use Formulario_Mensaje\Rules\Condition_Sanitizer;
use Formulario_Mensaje\Storage\Submission_Journal;
use Formulario_Mensaje\Submissions\Submission_Result;
use Formulario_Mensaje\Support\Inline_Html;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte la definición que llega del constructor (o de una importación) en una definición válida.
 *
 * Las opciones de cada campo las sanea su tipo a través del esquema que declara, y los ajustes de
 * cada acción, su tipo de acción: aquí no hay nada específico de ningún tipo.
 */
final class Form_Definition_Sanitizer {

	/**
	 * Máximo de campos de un formulario.
	 */
	private const MAX_FIELDS = 200;

	/**
	 * Máximo de acciones de un formulario.
	 */
	private const MAX_ACTIONS = 50;

	/**
	 * Constructor.
	 *
	 * @param Field_Type_Registry       $field_types      Tipos de campo.
	 * @param Action_Type_Registry      $action_types     Tipos de acción.
	 * @param Settings_Schema_Sanitizer $schema_sanitizer Saneado de opciones según esquema.
	 * @param Inline_Html               $inline_html      Filtro del HTML permitido en los textos.
	 * @param Condition_Sanitizer       $conditions       Saneado de condiciones.
	 */
	public function __construct(
		private readonly Field_Type_Registry $field_types,
		private readonly Action_Type_Registry $action_types,
		private readonly Settings_Schema_Sanitizer $schema_sanitizer,
		private readonly Inline_Html $inline_html,
		private readonly Condition_Sanitizer $conditions
	) {}

	/**
	 * Sanea una definición.
	 *
	 * @param mixed $raw Definición sin validar.
	 */
	public function sanitize( mixed $raw ): Form_Definition {
		$raw    = is_array( $raw ) ? $raw : array();
		$fields = $this->sanitize_fields( $raw['fields'] ?? array() );

		return new Form_Definition(
			$fields,
			$this->sanitize_settings( $raw['settings'] ?? array(), $fields ),
			$this->sanitize_actions( $raw['actions'] ?? array() )
		);
	}

	/**
	 * Sanea la lista de campos. Se descartan los de tipos no registrados.
	 *
	 * @param mixed $raw Lista de campos sin validar.
	 * @return Field[]
	 */
	private function sanitize_fields( mixed $raw ): array {
		$fields = array();
		$ids    = array();

		foreach ( is_array( $raw ) ? array_slice( $raw, 0, self::MAX_FIELDS ) : array() as $item ) {
			$type = is_array( $item ) && is_string( $item['type'] ?? null ) ? $this->field_types->get( $item['type'] ) : null;

			if ( null === $type ) {
				continue;
			}

			$id       = $this->unique_id( $item['id'] ?? null, 'f_', $ids );
			$fields[] = new Field( $id, $type->get_type(), $this->schema_sanitizer->sanitize( $type->get_settings_schema(), $item['settings'] ?? array() ) );
		}

		return $fields;
	}

	/**
	 * Sanea los ajustes del formulario.
	 *
	 * @param mixed   $raw    Ajustes sin validar.
	 * @param Field[] $fields Campos ya saneados.
	 * @return array<string, mixed>
	 */
	private function sanitize_settings( mixed $raw, array $fields ): array {
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'submit_label'     => $this->text( $raw['submit_label'] ?? null, __( 'Send', 'formulario-mensaje' ), 100 ),
			'success_message'  => is_scalar( $raw['success_message'] ?? null ) && '' !== trim( (string) $raw['success_message'] )
				? mb_substr( $this->inline_html->filter( (string) $raw['success_message'] ), 0, 5000 )
				: __( 'Thank you. Your message has been sent.', 'formulario-mensaje' ),
			'after_submit'     => Submission_Result::AFTER_RESET === ( $raw['after_submit'] ?? null ) ? Submission_Result::AFTER_RESET : Submission_Result::AFTER_HIDE,
			'redirect_enabled' => ! empty( $raw['redirect_enabled'] ),
			'redirect_url'     => is_scalar( $raw['redirect_url'] ?? null ) ? $this->sanitize_redirect_url( (string) $raw['redirect_url'] ) : '',
			'redirect_fields'  => $this->sanitize_redirect_fields( $raw['redirect_fields'] ?? array(), $fields ),
			'tracking_enabled' => ! array_key_exists( 'tracking_enabled', $raw ) || ! empty( $raw['tracking_enabled'] ),
			// Los envíos se guardan salvo que se desactive expresamente; IP y navegador, solo si se piden.
			'store_enabled'    => ! array_key_exists( 'store_enabled', $raw ) || ! empty( $raw['store_enabled'] ),
			// Activado salvo renuncia expresa: si el sitio configura un captcha, protege todos sus formularios.
			'captcha_enabled'  => ! array_key_exists( 'captcha_enabled', $raw ) || ! empty( $raw['captcha_enabled'] ),
			'store_ip'         => in_array( $raw['store_ip'] ?? null, array( Submission_Journal::IP_ANONYMIZED, Submission_Journal::IP_FULL ), true ) ? $raw['store_ip'] : Submission_Journal::IP_NONE,
			'store_user_agent' => ! empty( $raw['store_user_agent'] ),
			'retention_days'   => is_numeric( $raw['retention_days'] ?? null ) ? max( 0, min( 3650, (int) $raw['retention_days'] ) ) : 0,
		);
	}

	/**
	 * Sanea la dirección de redirección: absoluta (http o https) o ruta del propio sitio.
	 *
	 * Que el dominio esté permitido lo decide Redirect_Policy al guardar y en cada envío.
	 *
	 * @param string $url Dirección sin validar.
	 */
	private function sanitize_redirect_url( string $url ): string {
		// esc_url_raw() conserva las rutas que empiezan por `/` y descarta cualquier otro esquema.
		return mb_substr( esc_url_raw( trim( $url ), array( 'http', 'https' ) ), 0, 2000 );
	}

	/**
	 * Sanea la lista de campos que se añaden a la dirección de redirección.
	 *
	 * @param mixed   $raw    Lista sin validar de pares campo y parámetro.
	 * @param Field[] $fields Campos ya saneados.
	 * @return array<int, array{field: string, param: string}>
	 */
	private function sanitize_redirect_fields( mixed $raw, array $fields ): array {
		$known = array();

		foreach ( $fields as $field ) {
			$known[ $field->id ] = true;
		}

		$mappings = array();

		foreach ( is_array( $raw ) ? $raw : array() as $mapping ) {
			$field_id = is_array( $mapping ) && is_string( $mapping['field'] ?? null ) ? $mapping['field'] : '';
			$param    = is_array( $mapping ) && is_string( $mapping['param'] ?? null ) ? sanitize_key( $mapping['param'] ) : '';

			if ( isset( $known[ $field_id ] ) && '' !== $param ) {
				$mappings[ $param ] = array(
					'field' => $field_id,
					'param' => $param,
				);
			}
		}

		return array_values( $mappings );
	}

	/**
	 * Sanea la lista de acciones. Se descartan las de tipos no registrados.
	 *
	 * @param mixed $raw Lista de acciones sin validar.
	 * @return array<int, array{id: string, type: string, enabled: bool, name: string, condition: array<string, mixed>, settings: array<string, mixed>}>
	 */
	private function sanitize_actions( mixed $raw ): array {
		$actions = array();
		$ids     = array();

		foreach ( is_array( $raw ) ? array_slice( $raw, 0, self::MAX_ACTIONS ) : array() as $item ) {
			$type = is_array( $item ) && is_string( $item['type'] ?? null ) ? $this->action_types->get( $item['type'] ) : null;

			if ( null === $type ) {
				continue;
			}

			$actions[] = array(
				'id'        => $this->unique_id( $item['id'] ?? null, 'a_', $ids ),
				'type'      => $type->get_type(),
				'enabled'   => ! empty( $item['enabled'] ),
				'name'      => is_scalar( $item['name'] ?? null ) ? mb_substr( sanitize_text_field( (string) $item['name'] ), 0, 100 ) : '',
				'condition' => $this->conditions->sanitize( $item['condition'] ?? array() ),
				'settings'  => $type->sanitize_settings( $item['settings'] ?? array() ),
			);
		}

		return $actions;
	}

	/**
	 * Devuelve un identificador válido y no repetido, generando uno si el recibido no sirve.
	 *
	 * @param mixed               $candidate Identificador recibido.
	 * @param string              $prefix    Prefijo de los identificadores generados.
	 * @param array<string, true> $used      Identificadores ya usados; se actualiza.
	 */
	private function unique_id( mixed $candidate, string $prefix, array &$used ): string {
		$id = is_string( $candidate ) && 1 === preg_match( Field::ID_PATTERN, $candidate ) ? $candidate : '';

		while ( '' === $id || isset( $used[ $id ] ) ) {
			$id = $prefix . strtolower( wp_generate_password( 8, false ) );
		}

		$used[ $id ] = true;

		return $id;
	}

	/**
	 * Sanea un texto de una línea, con un valor por defecto si llega vacío.
	 *
	 * @param mixed  $value      Texto sin validar.
	 * @param string $fallback   Valor por defecto.
	 * @param int    $max_length Longitud máxima.
	 */
	private function text( mixed $value, string $fallback, int $max_length ): string {
		$text = is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $max_length ) : '';

		return '' === $text ? $fallback : $text;
	}
}
