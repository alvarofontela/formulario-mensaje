<?php
/**
 * Dirección a la que se conecta de verdad un webhook.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Webhooks;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decide a qué dirección IP se conecta la petición de un webhook y comprueba que no sea interna.
 *
 * La variante segura de la HTTP API valida la dirección resolviendo el nombre una vez, pero la
 * conexión lo resuelve otra vez por su cuenta: quien controle el DNS del destino podría responder con
 * una dirección pública a la primera consulta y con una interna a la segunda. Aquí el nombre se
 * resuelve una sola vez y se comprueba esa dirección; quien hace la petición obliga a la conexión a
 * ir a ella, sin volver a preguntar.
 */
final class Destination_Guard {

	/**
	 * Constructor.
	 *
	 * @param Host_Resolver $resolver Resolución de nombres de servidor.
	 */
	public function __construct( private readonly Host_Resolver $resolver ) {}

	/**
	 * Dirección IP a la que tiene que ir la conexión de una petición.
	 *
	 * @param string $url Dirección del webhook.
	 * @return string|WP_Error Dirección IPv4 ya comprobada; cadena vacía si no hay nombre que resolver
	 *                         (la dirección ya es una IP, que valida el núcleo); un error si el nombre
	 *                         no se resuelve o apunta a una dirección interna.
	 */
	public function address( string $url ): string|WP_Error {
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) && is_string( $parts['host'] ?? null ) ? strtolower( $parts['host'] ) : '';

		if ( '' === $host || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$ip  = $this->resolver->resolve( $host );
		$own = $this->is_own_host( $host );

		if ( '' === $ip ) {
			// Al propio sitio se llega como llegue el servidor; a cualquier otro, sin dirección no hay petición.
			return $own ? '' : new WP_Error( 'formulario_mensaje_webhook_unresolved', __( 'The webhook address could not be resolved.', 'formulario-mensaje' ) );
		}

		if ( ! $own && ! $this->is_external( $ip, $host, $url ) ) {
			return new WP_Error( 'formulario_mensaje_webhook_internal', __( 'The webhook address points to an internal network address.', 'formulario-mensaje' ) );
		}

		return $ip;
	}

	/**
	 * Indica si un nombre es el del propio sitio, al que el núcleo siempre deja hacer peticiones.
	 *
	 * @param string $host Nombre de servidor, en minúsculas.
	 */
	private function is_own_host( string $host ): bool {
		return strtolower( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) ) === $host;
	}

	/**
	 * Indica si una dirección ya resuelta es externa.
	 *
	 * La lista de rangos internos es la del núcleo, que se consulta con la propia dirección para no
	 * mantener una copia aquí.
	 *
	 * @param string $ip   Dirección IPv4 a la que apunta el nombre.
	 * @param string $host Nombre de servidor.
	 * @param string $url  Dirección del webhook.
	 */
	private function is_external( string $ip, string $host, string $url ): bool {
		if ( false !== wp_http_validate_url( 'http://' . $ip . '/' ) ) {
			return true;
		}

		/*
		 * Un sitio puede permitir un servidor de su red interna por su nombre con este filtro del núcleo
		 * (un n8n propio, por ejemplo). Se respeta esa decisión, y la conexión queda fijada igualmente a
		 * la dirección que se ha comprobado.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro del núcleo.
		return (bool) apply_filters( 'http_request_host_is_external', false, $host, $url );
	}
}
