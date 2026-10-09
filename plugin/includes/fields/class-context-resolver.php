<?php
/**
 * Valores de contexto de los campos ocultos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

use Formulario_Mensaje\Support\Request_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Calcula el valor de un campo oculto de contexto: página de origen, parámetros UTM o de la URL.
 *
 * Lo que se calcula aquí es el valor que lleva el HTML. El script del formulario lo vuelve a calcular
 * en el navegador, porque una página servida desde caché no conoce la URL ni los parámetros reales.
 */
final class Context_Resolver {

	public const SOURCE_FIXED      = 'fixed';
	public const SOURCE_PAGE_URL   = 'page_url';
	public const SOURCE_PAGE_TITLE = 'page_title';
	public const SOURCE_REFERRER   = 'referrer';
	public const SOURCE_QUERY      = 'query';

	/**
	 * Parámetros UTM que se ofrecen como origen directo.
	 */
	public const UTM_PARAMS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );

	/**
	 * Constructor.
	 *
	 * @param Request_Query $query Parámetros de la URL.
	 */
	public function __construct( private readonly Request_Query $query ) {}

	/**
	 * Clave que el script usa para recalcular el valor en el navegador.
	 *
	 * @param string $source Origen configurado en el campo.
	 * @param string $param  Nombre del parámetro de la URL, si el origen es un parámetro a medida.
	 * @return string `page_url`, `page_title`, `referrer`, `query:<parámetro>` o cadena vacía.
	 */
	public function client_key( string $source, string $param ): string {
		if ( in_array( $source, array( self::SOURCE_PAGE_URL, self::SOURCE_PAGE_TITLE, self::SOURCE_REFERRER ), true ) ) {
			return $source;
		}

		$param = $this->query_param( $source, $param );

		return '' === $param ? '' : 'query:' . $param;
	}

	/**
	 * Valor que se conoce en el servidor al pintar el formulario, sin escapar.
	 *
	 * @param string $source Origen configurado en el campo.
	 * @param string $param  Nombre del parámetro de la URL, si el origen es un parámetro a medida.
	 */
	public function resolve( string $source, string $param ): string {
		if ( self::SOURCE_PAGE_URL === $source ) {
			$id = is_singular() ? get_queried_object_id() : 0;

			return $id > 0 ? (string) get_permalink( $id ) : home_url( '/' );
		}

		if ( self::SOURCE_PAGE_TITLE === $source ) {
			return is_singular() ? wp_strip_all_tags( get_the_title( get_queried_object_id() ) ) : '';
		}

		$param = $this->query_param( $source, $param );

		return '' === $param ? '' : $this->query->get( $param );
	}

	/**
	 * Nombre del parámetro de la URL que corresponde a un origen.
	 *
	 * @param string $source Origen configurado en el campo.
	 * @param string $param  Nombre del parámetro, si el origen es un parámetro a medida.
	 */
	private function query_param( string $source, string $param ): string {
		if ( in_array( $source, self::UTM_PARAMS, true ) ) {
			return $source;
		}

		return self::SOURCE_QUERY === $source ? sanitize_key( $param ) : '';
	}
}
