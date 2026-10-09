<?php
/**
 * Tipos de fichero admitidos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Grupos cerrados de tipos de fichero entre los que elige cada campo de subida.
 *
 * No hay extensiones libres. Fuera quedan, a propósito, todo lo que un servidor o un navegador
 * puede ejecutar o interpretar: PHP y demás scripts, HTML, SVG y ejecutables.
 */
final class File_Types {

	public const GROUP_IMAGES    = 'images';
	public const GROUP_PDF       = 'pdf';
	public const GROUP_DOCUMENTS = 'documents';

	/**
	 * Tipos de cada grupo: extensiones (como expresión regular, igual que en WordPress) y su tipo MIME.
	 */
	private const GROUPS = array(
		self::GROUP_IMAGES    => array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
		),
		self::GROUP_PDF       => array(
			'pdf' => 'application/pdf',
		),
		self::GROUP_DOCUMENTS => array(
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'odt'  => 'application/vnd.oasis.opendocument.text',
			'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
			'txt'  => 'text/plain',
			'csv'  => 'text/csv',
		),
	);

	/**
	 * Grupos disponibles, con su nombre ya traducido.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			self::GROUP_IMAGES    => __( 'Images (JPG, PNG, GIF, WebP)', 'formulario-mensaje' ),
			self::GROUP_PDF       => __( 'PDF', 'formulario-mensaje' ),
			self::GROUP_DOCUMENTS => __( 'Office documents (Word, Excel, PowerPoint, OpenDocument, TXT, CSV)', 'formulario-mensaje' ),
		);
	}

	/**
	 * Tipos MIME de unos grupos, por extensión, en el formato que usa WordPress.
	 *
	 * @param string[] $groups Identificadores de grupo. Los desconocidos se ignoran.
	 * @return array<string, string>
	 */
	public function mimes( array $groups ): array {
		$mimes = array();

		foreach ( $groups as $group ) {
			if ( is_string( $group ) && isset( self::GROUPS[ $group ] ) ) {
				$mimes += self::GROUPS[ $group ];
			}
		}

		return $mimes;
	}

	/**
	 * Extensiones de unos grupos, con su punto, para el atributo `accept` y para decírselas al visitante.
	 *
	 * @param string[] $groups Identificadores de grupo.
	 * @return string[]
	 */
	public function extensions( array $groups ): array {
		$extensions = array();

		foreach ( array_keys( $this->mimes( $groups ) ) as $pattern ) {
			foreach ( explode( '|', $pattern ) as $extension ) {
				$extensions[] = '.' . $extension;
			}
		}

		return $extensions;
	}
}
