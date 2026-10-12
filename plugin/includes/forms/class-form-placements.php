<?php
/**
 * Páginas en las que está puesto cada formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

use Formulario_Mensaje\Frontend\Shortcode;
use WP_Post;
use wpdb;

defined( 'ABSPATH' ) || exit;

/*
 * Buscar un texto dentro del contenido de las entradas no tiene API en WordPress: son dos consultas
 * directas, solo de lectura, cuyo resultado se guarda en un transient. Todas pasan por prepare(), con
 * %i para el nombre de la tabla.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Averigua en qué páginas publicadas se ha insertado cada formulario, por cualquiera de las tres
 * vías: el bloque, el shortcode o el widget de Elementor.
 *
 * Sirve para decirle a quien gestiona los formularios si uno está recibiendo mensajes o todavía no
 * se ha colocado en ningún sitio. Solo ve el contenido de entradas y páginas: un formulario puesto
 * en una plantilla del tema o en un widget no aparece, y por eso quien lo muestra dice «no se ha
 * encontrado», no «no está».
 */
final class Form_Placements {

	/**
	 * Transient que guarda el resultado de la búsqueda.
	 */
	public const CACHE_KEY = 'formulario_mensaje_placements';

	/**
	 * Nombre del bloque del formulario.
	 */
	private const BLOCK = 'formulario-mensaje/form';

	/**
	 * Nombre del widget de Elementor y meta en la que Elementor guarda el contenido de una página.
	 */
	private const ELEMENTOR_WIDGET = 'formulario-mensaje';
	private const ELEMENTOR_META   = '_elementor_data';

	/**
	 * Páginas que se revisan como mucho en cada búsqueda, por cada vía.
	 */
	private const LIMIT = 300;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión de WordPress a la base de datos.
	 */
	public function __construct( private readonly wpdb $wpdb ) {}

	/**
	 * Páginas publicadas en las que está cada formulario.
	 *
	 * @return array<int, array<int, array{id: int, title: string, url: string}>> Por identificador de formulario.
	 */
	public function all(): array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$found = array();

		foreach ( $this->forms_by_post() as $post_id => $form_ids ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
				continue;
			}

			$page = array(
				'id'    => $post->ID,
				'title' => wp_strip_all_tags( $post->post_title ),
				'url'   => (string) get_permalink( $post ),
			);

			foreach ( array_unique( $form_ids ) as $form_id ) {
				$found[ $form_id ][] = $page;
			}
		}

		// Caduca sola al cabo de una hora por si algo cambia el contenido sin pasar por los hooks de guardado.
		set_transient( self::CACHE_KEY, $found, HOUR_IN_SECONDS );

		return $found;
	}

	/**
	 * Olvida el resultado guardado. Se llama cuando se guarda o se borra cualquier contenido.
	 */
	public function forget(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Formularios que aparecen en cada entrada, sin comprobar todavía si está publicada.
	 *
	 * @return array<int, int[]> Identificadores de formulario, por identificador de entrada.
	 */
	private function forms_by_post(): array {
		$wpdb  = $this->wpdb;
		$found = array();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ID, post_content FROM %i WHERE post_status = %s AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID DESC LIMIT %d',
				$wpdb->posts,
				'publish',
				'%' . $wpdb->esc_like( '<!-- wp:' . self::BLOCK ) . '%',
				'%' . $wpdb->esc_like( '[' . Shortcode::TAG ) . '%',
				self::LIMIT
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$ids = array( ...$this->in_blocks( (string) $row['post_content'] ), ...$this->in_shortcodes( (string) $row['post_content'] ) );

			if ( array() !== $ids ) {
				$found[ (int) $row['ID'] ] = $ids;
			}
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_id, meta_value FROM %i WHERE meta_key = %s AND meta_value LIKE %s ORDER BY post_id DESC LIMIT %d',
				$wpdb->postmeta,
				self::ELEMENTOR_META,
				'%' . $wpdb->esc_like( '"widgetType":"' . self::ELEMENTOR_WIDGET . '"' ) . '%',
				self::LIMIT
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$ids = $this->in_elementor( json_decode( (string) $row['meta_value'], true ) );

			if ( array() !== $ids ) {
				$post_id           = (int) $row['post_id'];
				$found[ $post_id ] = array( ...( $found[ $post_id ] ?? array() ), ...$ids );
			}
		}

		return $found;
	}

	/**
	 * Formularios insertados con el bloque.
	 *
	 * Se lee el comentario que delimita el bloque en vez de analizar todo el contenido: así se
	 * encuentran también los que están dentro de columnas o grupos.
	 *
	 * @param string $content Contenido de la entrada.
	 * @return int[]
	 */
	private function in_blocks( string $content ): array {
		if ( 1 > preg_match_all( '#<!--\s+wp:' . preg_quote( self::BLOCK, '#' ) . '\s+(\{.*?\})\s+/?-->#s', $content, $matches ) ) {
			return array();
		}

		$ids = array();

		foreach ( $matches[1] as $json ) {
			$attributes = json_decode( $json, true );
			$ids[]      = absint( is_array( $attributes ) ? ( $attributes['formId'] ?? 0 ) : 0 );
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Formularios insertados con el shortcode.
	 *
	 * @param string $content Contenido de la entrada.
	 * @return int[]
	 */
	private function in_shortcodes( string $content ): array {
		if ( 1 > preg_match_all( '/' . get_shortcode_regex( array( Shortcode::TAG ) ) . '/s', $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$ids = array();

		foreach ( $matches as $match ) {
			// Con doble corchete, [[así]], el shortcode está escrito para enseñarlo, no para ejecutarlo.
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue;
			}

			$attributes = shortcode_parse_atts( $match[3] );
			$ids[]      = absint( is_array( $attributes ) ? ( $attributes['id'] ?? 0 ) : 0 );
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Formularios insertados con el widget de Elementor, a cualquier profundidad.
	 *
	 * @param mixed $elements Elementos de una página de Elementor, ya decodificados.
	 * @return int[]
	 */
	private function in_elementor( mixed $elements ): array {
		$ids = array();

		foreach ( is_array( $elements ) ? $elements : array() as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( self::ELEMENTOR_WIDGET === ( $element['widgetType'] ?? '' ) ) {
				$ids[] = absint( $element['settings']['form_id'] ?? 0 );
			}

			$ids = array( ...$ids, ...$this->in_elementor( $element['elements'] ?? array() ) );
		}

		return array_values( array_filter( $ids ) );
	}
}
