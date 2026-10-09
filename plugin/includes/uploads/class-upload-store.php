<?php
/**
 * Ficheros de los envíos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Mail\Attachment_Source;
use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Support\Clock;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda los ficheros de un envío en el almacén privado y los borra cuando ya no hacen falta.
 *
 * Todo fichero aceptado pasa por aquí, también los que solo se van a adjuntar a un correo: las
 * notificaciones se envían después y el fichero temporal de PHP no dura tanto. Los que su campo no
 * manda conservar, y los de un envío que no se guarda, se borran al terminar el envío.
 */
final class Upload_Store implements Attachment_Source {

	/**
	 * Horas tras las que un fichero sin envío guardado se considera un resto y se borra.
	 */
	private const ORPHAN_HOURS = 24;

	/**
	 * Constructor.
	 *
	 * @param File_Repository     $files       Registro de ficheros.
	 * @param Upload_Mover        $mover       Movimiento de ficheros subidos.
	 * @param Upload_Directory    $directory   Almacén privado.
	 * @param Field_Type_Registry $field_types Tipos de campo.
	 * @param Clock               $clock       Reloj.
	 */
	public function __construct(
		private readonly File_Repository $files,
		private readonly Upload_Mover $mover,
		private readonly Upload_Directory $directory,
		private readonly Field_Type_Registry $field_types,
		private readonly Clock $clock
	) {}

	/**
	 * Guarda los ficheros ya validados de un envío.
	 *
	 * @param Form                           $form      Formulario.
	 * @param string                         $reference Número de referencia del envío.
	 * @param array<string, Uploaded_File[]> $uploads   Ficheros recibidos, por identificador de campo.
	 * @return array<string, Stored_File[]>|WP_Error Ficheros guardados, por campo. Si alguno falla no queda guardado ninguno.
	 */
	public function persist( Form $form, string $reference, array $uploads ): array|WP_Error {
		$stored = array();

		foreach ( $form->definition->fields as $field ) {
			$type = $this->field_types->get_upload( $field->type );

			if ( null === $type || ! isset( $uploads[ $field->id ] ) ) {
				continue;
			}

			foreach ( $uploads[ $field->id ] as $upload ) {
				$file = $this->store( $form, $reference, $field, $upload, $type->allowed_mimes( $field ) );

				if ( is_wp_error( $file ) ) {
					$this->discard( $form, $reference );

					return $file;
				}

				$stored[ $field->id ][] = $file;
			}
		}

		return $stored;
	}

	/**
	 * Asocia los ficheros de un envío con el envío ya guardado.
	 *
	 * @param Form   $form          Formulario.
	 * @param string $reference     Número de referencia del envío.
	 * @param int    $submission_id Identificador del envío guardado.
	 */
	public function link( Form $form, string $reference, int $submission_id ): void {
		if ( $submission_id > 0 ) {
			$this->files->link( $form->id, $reference, $submission_id );
		}
	}

	/**
	 * Borra, al terminar un envío, los ficheros que no hay que conservar: los de un envío que no se
	 * guarda y los de los campos que solo los quieren para adjuntarlos.
	 *
	 * @param Form   $form          Formulario.
	 * @param string $reference     Número de referencia del envío.
	 * @param int    $submission_id Identificador del envío guardado, o 0 si no se guarda.
	 */
	public function cleanup( Form $form, string $reference, int $submission_id ): void {
		$keep = array();

		foreach ( $form->definition->fields as $field ) {
			$keep[ $field->id ] = $submission_id > 0 && $field->flag( 'keep' );
		}

		$this->remove(
			array_filter(
				$this->files->for_reference( $form->id, $reference ),
				static fn ( Stored_File $file ): bool => empty( $keep[ $file->field_id ] )
			)
		);
	}

	/**
	 * Borra todos los ficheros de un envío que al final no se ha aceptado.
	 *
	 * @param Form   $form      Formulario.
	 * @param string $reference Número de referencia del envío.
	 */
	public function discard( Form $form, string $reference ): void {
		$this->remove( $this->files->for_reference( $form->id, $reference ) );
	}

	/**
	 * Ficheros de un envío, se guarde o no, por su número de referencia.
	 *
	 * @param Form   $form      Formulario.
	 * @param string $reference Número de referencia del envío.
	 * @return Stored_File[]
	 */
	public function for_reference( Form $form, string $reference ): array {
		return $this->files->for_reference( $form->id, $reference );
	}

	/**
	 * Ficheros de un envío guardado.
	 *
	 * @param int $submission_id Identificador del envío.
	 * @return Stored_File[]
	 */
	public function for_submission( int $submission_id ): array {
		return $this->files->for_submissions( array( $submission_id ) );
	}

	/**
	 * Busca un fichero por su identificador aleatorio.
	 *
	 * @param string $token Identificador aleatorio: 32 caracteres hexadecimales.
	 */
	public function find( string $token ): ?Stored_File {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $token ) ? $this->files->find_by_token( $token ) : null;
	}

	/**
	 * Ruta completa de un fichero guardado.
	 *
	 * @param Stored_File $file Fichero.
	 * @return string|null Null si el fichero ya no está en el almacén.
	 */
	public function path( Stored_File $file ): ?string {
		return $this->directory->resolve( $file->path );
	}

	/**
	 * Ficheros que se adjuntan a las notificaciones de un envío: los de los campos que lo piden.
	 *
	 * @param Submission $submission Envío.
	 * @param Form       $form       Formulario.
	 * @return array<int, array{path: string, name: string}>
	 */
	public function attachments( Submission $submission, Form $form ): array {
		$attach = array();

		foreach ( $form->definition->fields as $field ) {
			if ( null !== $this->field_types->get_upload( $field->type ) && $field->flag( 'attach' ) ) {
				$attach[ $field->id ] = true;
			}
		}

		if ( array() === $attach ) {
			return array();
		}

		$attachments = array();

		foreach ( $this->files->for_reference( $form->id, $submission->reference ) as $file ) {
			$path = isset( $attach[ $file->field_id ] ) ? $this->path( $file ) : null;

			if ( null !== $path ) {
				$attachments[] = array(
					'path' => $path,
					'name' => $file->name,
				);
			}
		}

		return $attachments;
	}

	/**
	 * Borra los ficheros de unos envíos, del disco y del registro.
	 *
	 * @param int[] $submission_ids Identificadores de los envíos.
	 */
	public function delete_for_submissions( array $submission_ids ): void {
		$this->remove( $this->files->for_submissions( $submission_ids ) );
	}

	/**
	 * Borra los restos: ficheros sin envío guardado que llevan un día en el almacén.
	 *
	 * @return int Ficheros borrados.
	 */
	public function delete_orphans(): int {
		$orphans = $this->files->orphans_before( gmdate( 'Y-m-d H:i:s', $this->clock->now() - self::ORPHAN_HOURS * HOUR_IN_SECONDS ), 500 );

		$this->remove( $orphans );

		return count( $orphans );
	}

	/**
	 * Mueve un fichero al almacén y lo anota.
	 *
	 * @param Form                  $form      Formulario.
	 * @param string                $reference Número de referencia del envío.
	 * @param Field                 $field     Campo con el que se subió.
	 * @param Uploaded_File         $upload    Fichero recibido.
	 * @param array<string, string> $mimes     Tipos que admite el campo.
	 */
	private function store( Form $form, string $reference, Field $field, Uploaded_File $upload, array $mimes ): Stored_File|WP_Error {
		// Dos valores aleatorios distintos: el identificador viaja en los enlaces de descarga; la ruta en disco no sale nunca del servidor.
		$token     = bin2hex( random_bytes( 16 ) );
		$relative  = $this->directory->new_relative_path();
		$directory = $this->directory->prepare( $relative );

		if ( null === $directory ) {
			return new WP_Error( 'formulario_mensaje_upload_failed', __( 'The file could not be stored.', 'formulario-mensaje' ), array( 'status' => 500 ) );
		}

		$moved = $this->mover->move( $upload, $directory, wp_basename( $relative ), $mimes );

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$file = new Stored_File( 0, 0, $form->id, $field->id, $reference, $token, $upload->name, $moved['type'], (int) filesize( $moved['path'] ), $relative, $this->clock->now() );
		$id   = $this->files->insert( $file );

		if ( 0 === $id ) {
			wp_delete_file( $moved['path'] );

			return new WP_Error( 'formulario_mensaje_upload_failed', __( 'The file could not be stored.', 'formulario-mensaje' ), array( 'status' => 500 ) );
		}

		return $this->files->find_by_token( $token ) ?? $file;
	}

	/**
	 * Borra unos ficheros del disco y del registro.
	 *
	 * @param Stored_File[] $files Ficheros.
	 */
	private function remove( array $files ): void {
		$ids = array();

		foreach ( $files as $file ) {
			$path = $this->path( $file );

			if ( null !== $path ) {
				wp_delete_file( $path );
			}

			$ids[] = $file->id;
		}

		$this->files->delete( $ids );
	}
}
