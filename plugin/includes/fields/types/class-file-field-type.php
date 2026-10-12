<?php
/**
 * Tipo de campo: subida de ficheros.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Fields\Common_Settings;
use Formulario_Mensaje\Fields\Field_Markup;
use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Fields\Upload_Field_Type;
use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Uploads\File_Types;
use Formulario_Mensaje\Uploads\Uploaded_File;

defined( 'ABSPATH' ) || exit;

/**
 * Campo para adjuntar ficheros al envío.
 *
 * Los tipos permitidos se eligen entre grupos cerrados y lo que cuenta es el contenido real del
 * fichero, no su extensión ni el tipo que declare el navegador.
 */
final class File_Field_Type implements Upload_Field_Type {

	private const MAX_FILES_CHOICES = array( 1, 2, 3, 5, 10 );
	private const MAX_SIZE_CHOICES  = array( 1, 2, 5, 10, 20 );
	private const LINK_DAYS_CHOICES = array( 1, 7, 30 );

	/**
	 * Constructor.
	 *
	 * @param Field_Markup    $markup HTML común de los campos.
	 * @param Common_Settings $common Opciones comunes de los campos.
	 * @param File_Types      $types  Tipos de fichero admitidos.
	 */
	public function __construct(
		private readonly Field_Markup $markup,
		private readonly Common_Settings $common,
		private readonly File_Types $types
	) {}

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'file';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'File upload', 'formulario-mensaje' );
	}

	/**
	 * Grupo del constructor en el que aparece.
	 */
	public function get_group(): string {
		return 'advanced';
	}

	/**
	 * Opciones que admite un campo de este tipo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_schema(): array {
		$groups = array();

		foreach ( $this->types->groups() as $group => $label ) {
			$groups[] = $this->common->choice( $group, $label );
		}

		return array(
			$this->common->label( $this->get_label() ),
			$this->common->required(),
			$this->common->help(),
			array(
				'key'     => 'file_types',
				'type'    => 'checklist',
				'label'   => __( 'Allowed file types', 'formulario-mensaje' ),
				'help'    => __( 'If none are selected, images and PDFs are accepted.', 'formulario-mensaje' ),
				'default' => array( File_Types::GROUP_IMAGES, File_Types::GROUP_PDF ),
				'choices' => $groups,
			),
			array(
				'key'     => 'max_files',
				'type'    => 'select',
				'label'   => __( 'Maximum number of files', 'formulario-mensaje' ),
				'default' => '1',
				'choices' => array_map( fn ( int $count ): array => $this->common->choice( (string) $count, (string) $count ), self::MAX_FILES_CHOICES ),
			),
			array(
				'key'     => 'max_size',
				'type'    => 'select',
				'label'   => __( 'Maximum total size', 'formulario-mensaje' ),
				'default' => '5',
				'choices' => array_map(
					/* translators: %d: number of megabytes. */
					fn ( int $megabytes ): array => $this->common->choice( (string) $megabytes, sprintf( __( '%d MB', 'formulario-mensaje' ), $megabytes ) ),
					self::MAX_SIZE_CHOICES
				),
			),
			array(
				'key'     => 'attach',
				'type'    => 'toggle',
				'label'   => __( 'Attach the files to the email notifications', 'formulario-mensaje' ),
				'default' => true,
			),
			array(
				'key'     => 'keep',
				'type'    => 'toggle',
				'label'   => __( 'Keep the files with the stored submission', 'formulario-mensaje' ),
				'help'    => __( 'They are downloaded from the Messages screen and are deleted with the submission. If the form does not store its submissions, the files are not kept either.', 'formulario-mensaje' ),
				'default' => true,
			),
			array(
				'key'     => 'signed_link',
				'type'    => 'toggle',
				'label'   => __( 'Include a download link in the notifications', 'formulario-mensaje' ),
				'help'    => __( 'Anyone with the link can download the file until it expires, without logging in.', 'formulario-mensaje' ),
				'default' => false,
				'show_if' => array(
					'key'    => 'keep',
					'values' => array( true ),
				),
			),
			array(
				'key'     => 'link_days',
				'type'    => 'select',
				'label'   => __( 'The link expires after', 'formulario-mensaje' ),
				'default' => '7',
				'choices' => array_map(
					/* translators: %d: number of days. */
					fn ( int $days ): array => $this->common->choice( (string) $days, sprintf( _n( '%d day', '%d days', $days, 'formulario-mensaje' ), $days ) ),
					self::LINK_DAYS_CHOICES
				),
				'show_if' => array(
					'key'    => 'signed_link',
					'values' => array( true ),
				),
			),
			$this->common->css_class(),
		);
	}

	/**
	 * Devuelve el HTML del campo, con todos los valores ya escapados.
	 *
	 * @param Field          $field   Campo que se pinta.
	 * @param Render_Context $context Formulario e instancia en los que se pinta.
	 */
	public function render( Field $field, Render_Context $context ): string {
		$id        = $context->control_id( $field );
		$max_files = $this->max_files( $field );

		$control = sprintf(
			'<input%1$s><p class="fm-help fm-file-hint" id="%2$s">%3$s</p>',
			$this->markup->attributes(
				array(
					'class'                 => 'fm-control fm-control--file',
					'type'                  => 'file',
					'id'                    => $id,
					// Siempre como lista: el servidor recibe igual uno que varios ficheros.
					'name'                  => $context->control_name( $field ) . '[]',
					'accept'                => implode( ',', $this->types->extensions( $this->groups( $field ) ) ),
					'multiple'              => $max_files > 1,
					'required'              => $field->is_required(),
					'aria-describedby'      => trim( $id . '-hint ' . $this->markup->described_by( $field, $id ) ),
					// Para que el navegador avise antes de subir lo que el servidor va a rechazar. El servidor lo comprueba igual.
					'data-fm-max-files'     => $max_files,
					'data-fm-max-bytes'     => $this->max_bytes( $field ),
					'data-fm-count-message' => $this->too_many( $field ),
					'data-fm-size-message'  => $this->too_large( $field ),
				)
			),
			esc_attr( $id . '-hint' ),
			esc_html( $this->hint( $field ) )
		);

		return $this->markup->field( $field, $context, $control );
	}

	/**
	 * Nombres de los ficheros recibidos. Cualquier otra cosa que llegue en su lugar se descarta.
	 *
	 * @param Field $field Campo.
	 * @param mixed $raw   Ficheros recibidos para el campo.
	 * @return string[]
	 */
	public function sanitize_value( Field $field, mixed $raw ): string|array {
		$names = array();

		foreach ( is_array( $raw ) ? $raw : array() as $file ) {
			if ( $file instanceof Uploaded_File ) {
				$names[] = $file->name;
			}
		}

		return $names;
	}

	/**
	 * La validación de un campo de ficheros se hace sobre los ficheros, no sobre sus nombres.
	 *
	 * @param Field           $field Campo.
	 * @param string|string[] $value Nombres de los ficheros.
	 */
	public function validate( Field $field, string|array $value ): ?string {
		return null;
	}

	/**
	 * Valida los ficheros recibidos: cuántos son, cuánto ocupan y qué son de verdad.
	 *
	 * @param Field           $field Campo.
	 * @param Uploaded_File[] $files Ficheros recibidos.
	 */
	public function validate_files( Field $field, array $files ): ?string {
		if ( count( $files ) > $this->max_files( $field ) ) {
			return $this->too_many( $field );
		}

		if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$mimes = $this->allowed_mimes( $field );
		$total = 0;

		foreach ( $files as $file ) {
			// El límite del servidor es por fichero y puede ser menor que el del campo.
			if ( UPLOAD_ERR_INI_SIZE === $file->error || UPLOAD_ERR_FORM_SIZE === $file->error ) {
				return sprintf(
					/* translators: %s: maximum file size, for example "2 MB". */
					__( 'The file is too large. This site accepts files up to %s.', 'formulario-mensaje' ),
					(string) size_format( wp_max_upload_size() )
				);
			}

			if ( UPLOAD_ERR_OK !== $file->error || $file->size <= 0 || '' === $file->tmp_name || ! is_readable( $file->tmp_name ) ) {
				return __( 'The file could not be uploaded. Please try again.', 'formulario-mensaje' );
			}

			// El tamaño se mide en el servidor: el que declara la petición no cuenta.
			$total += (int) filesize( $file->tmp_name );

			// Se mira el contenido real del fichero y que coincida con su extensión; el tipo que diga el navegador no cuenta.
			$checked = wp_check_filetype_and_ext( $file->tmp_name, $file->name, $mimes );

			if ( empty( $checked['ext'] ) || empty( $checked['type'] ) || ! in_array( $checked['type'], $mimes, true ) ) {
				return sprintf(
					/* translators: %s: list of allowed file extensions. */
					__( 'This type of file is not allowed. Allowed: %s.', 'formulario-mensaje' ),
					$this->extensions_text( $field )
				);
			}
		}

		return $total > $this->max_bytes( $field ) ? $this->too_large( $field ) : null;
	}

	/**
	 * Tipos de fichero que admite el campo.
	 *
	 * @param Field $field Campo.
	 * @return array<string, string>
	 */
	public function allowed_mimes( Field $field ): array {
		return $this->types->mimes( $this->groups( $field ) );
	}

	/**
	 * Texto legible del valor: los nombres de los ficheros.
	 *
	 * @param Field           $field Campo.
	 * @param string|string[] $value Nombres de los ficheros.
	 */
	public function format_value( Field $field, string|array $value ): string {
		return implode( ', ', (array) $value );
	}

	/**
	 * Valor de ejemplo para probar notificaciones y otras acciones.
	 *
	 * @param Field $field Campo.
	 * @return string[]
	 */
	public function sample_value( Field $field ): string|array {
		return array( __( 'sample-document.pdf', 'formulario-mensaje' ) );
	}

	/**
	 * Grupos de tipos elegidos en el campo. Sin ninguno válido, los de partida.
	 *
	 * @param Field $field Campo.
	 * @return string[]
	 */
	private function groups( Field $field ): array {
		$groups = array_values( array_intersect( array_keys( $this->types->groups() ), (array) $field->get( 'file_types', array() ) ) );

		return array() === $groups ? array( File_Types::GROUP_IMAGES, File_Types::GROUP_PDF ) : $groups;
	}

	/**
	 * Máximo de ficheros del campo.
	 *
	 * @param Field $field Campo.
	 */
	private function max_files( Field $field ): int {
		$value = (int) $field->text( 'max_files', '1' );

		return in_array( $value, self::MAX_FILES_CHOICES, true ) ? $value : 1;
	}

	/**
	 * Tamaño máximo, en bytes, de todos los ficheros del campo juntos.
	 *
	 * @param Field $field Campo.
	 */
	private function max_bytes( Field $field ): int {
		$megabytes = (int) $field->text( 'max_size', '5' );

		return ( in_array( $megabytes, self::MAX_SIZE_CHOICES, true ) ? $megabytes : 5 ) * MB_IN_BYTES;
	}

	/**
	 * Extensiones admitidas, como texto.
	 *
	 * @param Field $field Campo.
	 */
	private function extensions_text( Field $field ): string {
		return strtoupper( str_replace( '.', '', implode( ', ', $this->types->extensions( $this->groups( $field ) ) ) ) );
	}

	/**
	 * Aviso, bajo el control, de qué se puede subir.
	 *
	 * @param Field $field Campo.
	 */
	private function hint( Field $field ): string {
		$max_files = $this->max_files( $field );

		return sprintf(
			/* translators: 1: list of allowed file extensions, 2: maximum number of files, 3: maximum total size in megabytes. */
			_n(
				'Allowed: %1$s. %2$d file, up to %3$d MB.',
				'Allowed: %1$s. Up to %2$d files, %3$d MB in total.',
				$max_files,
				'formulario-mensaje'
			),
			$this->extensions_text( $field ),
			$max_files,
			(int) ( $this->max_bytes( $field ) / MB_IN_BYTES )
		);
	}

	/**
	 * Mensaje de error por número de ficheros.
	 *
	 * @param Field $field Campo.
	 */
	private function too_many( Field $field ): string {
		$max_files = $this->max_files( $field );

		return sprintf(
			/* translators: %d: maximum number of files. */
			_n( 'You can upload %d file at most.', 'You can upload %d files at most.', $max_files, 'formulario-mensaje' ),
			$max_files
		);
	}

	/**
	 * Mensaje de error por tamaño.
	 *
	 * @param Field $field Campo.
	 */
	private function too_large( Field $field ): string {
		return sprintf(
			/* translators: %d: maximum size in megabytes. */
			__( 'The files are too large. The maximum is %d MB in total.', 'formulario-mensaje' ),
			(int) ( $this->max_bytes( $field ) / MB_IN_BYTES )
		);
	}
}
