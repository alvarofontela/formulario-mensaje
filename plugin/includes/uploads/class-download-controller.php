<?php
/**
 * Descarga de ficheros.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

use Formulario_Mensaje\Lifecycle\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Sirve los ficheros de los envíos. No hay otra forma de llegar a ellos: el almacén no se sirve por URL.
 *
 * Se entra de dos maneras: con sesión y el permiso de ver envíos (más un nonce, para que el enlace
 * no valga pegado en otro sitio), o con un enlace firmado y sin caducar de una notificación. El
 * fichero se entrega siempre como descarga, nunca para que el navegador lo muestre.
 */
final class Download_Controller {

	/**
	 * Constructor.
	 *
	 * @param Upload_Store   $store Ficheros de los envíos.
	 * @param Download_Links $links Enlaces de descarga.
	 */
	public function __construct(
		private readonly Upload_Store $store,
		private readonly Download_Links $links
	) {}

	/**
	 * Atiende una petición de descarga.
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- El acceso se comprueba abajo: firma del enlace, o capability y nonce.
		$token     = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : '';
		$signature = isset( $_GET['sig'] ) ? sanitize_key( wp_unslash( $_GET['sig'] ) ) : '';
		$expires   = isset( $_GET['expires'] ) ? absint( wp_unslash( $_GET['expires'] ) ) : 0;
		$nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$signed  = '' !== $signature && $this->links->is_valid_signature( $token, $expires, $signature );
		$allowed = $signed || ( current_user_can( Capabilities::VIEW_SUBMISSIONS ) && false !== wp_verify_nonce( $nonce, $this->links->nonce_action( $token ) ) );

		if ( ! $allowed ) {
			// El mismo aviso para un enlace caducado, una firma falsa o un fichero que no existe: no se da pistas.
			$this->deny();
		}

		$file = $this->store->find( $token );
		$path = null === $file ? null : $this->store->path( $file );

		if ( null === $file || null === $path ) {
			$this->deny();
		}

		$this->send( $file, $path );
	}

	/**
	 * Entrega un fichero como descarga y termina la petición.
	 *
	 * @param Stored_File $file Fichero.
	 * @param string      $path Ruta completa en el servidor.
	 */
	private function send( Stored_File $file, string $path ): never {
		// En la cabecera, el nombre va sin nada que pueda romperla; el nombre completo, en su forma codificada.
		$ascii = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '_', remove_accents( $file->name ) );

		nocache_headers();
		// Siempre como descarga y con un tipo genérico: el navegador no interpreta lo que subió un desconocido.
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'; sandbox' );
		header( sprintf( 'Content-Disposition: attachment; filename="%s"; filename*=UTF-8\'\'%s', '' === $ascii ? 'file' : $ascii, rawurlencode( $file->name ) ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		// Sin búfer de salida, para no cargar el fichero entero en memoria.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Envío directo de un fichero del almacén propio, con la ruta ya comprobada.
		readfile( $path );
		exit;
	}

	/**
	 * Rechaza la petición.
	 */
	private function deny(): never {
		wp_die(
			esc_html__( 'This download link is not valid or has expired.', 'formulario-mensaje' ),
			esc_html__( 'File not available', 'formulario-mensaje' ),
			array( 'response' => 403 )
		);
	}
}
