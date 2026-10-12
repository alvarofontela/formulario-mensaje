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
	 * Comentario con el que el editor de bloques abre el bloque del formulario.
	 */
	private const BLOCK_OPENING = '<!-- wp:' . self::BLOCK;

	/**
	 * Páginas que se revisan como mucho en cada búsqueda, por cada vía.
	 */
	private const LIMIT = 300;

	/**
	 * Páginas que se leen de la base de datos en cada tanda.
	 */
	private const BATCH = 25;

	/**
	 * Longitud máxima de los atributos de un bloque o de un shortcode: lo que pase de ahí no lo es.
	 */
	private const MAX_INNER = 2000;

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
		$found = array();

		foreach ( $this->posts_with_form() as $post_id => $content ) {
			$ids = array( ...$this->in_blocks( $content ), ...$this->in_shortcodes( $content ) );

			if ( array() !== $ids ) {
				$found[ $post_id ] = $ids;
			}
		}

		foreach ( $this->elementor_pages_with_form() as $post_id => $data ) {
			$ids = $this->in_elementor( json_decode( $data, true ) );

			if ( array() !== $ids ) {
				$found[ $post_id ] = array( ...( $found[ $post_id ] ?? array() ), ...$ids );
			}
		}

		return $found;
	}

	/**
	 * Contenido de las entradas publicadas que mencionan el bloque o el shortcode, de las más nuevas a
	 * las más antiguas.
	 *
	 * Se leen por tandas, y no todas a la vez, para que la memoria no dependa de lo que pese el
	 * contenido que hayan escrito los autores del sitio.
	 *
	 * @return \Generator<int, string> Contenido, por identificador de entrada.
	 */
	private function posts_with_form(): \Generator {
		$wpdb = $this->wpdb;
		$last = PHP_INT_MAX;

		for ( $read = 0; $read < self::LIMIT; $read += self::BATCH ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ID, post_content FROM %i WHERE post_status = %s AND ID < %d AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID DESC LIMIT %d',
					$wpdb->posts,
					'publish',
					$last,
					'%' . $wpdb->esc_like( self::BLOCK_OPENING ) . '%',
					'%' . $wpdb->esc_like( '[' . Shortcode::TAG ) . '%',
					min( self::BATCH, self::LIMIT - $read )
				),
				ARRAY_A
			);

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$last = (int) $row['ID'];

				yield $last => (string) $row['post_content'];
			}

			if ( ! is_array( $rows ) || count( $rows ) < self::BATCH ) {
				return;
			}
		}
	}

	/**
	 * Contenido de Elementor de las páginas que llevan el widget, por tandas como posts_with_form().
	 *
	 * @return \Generator<int, string> JSON de Elementor, por identificador de entrada.
	 */
	private function elementor_pages_with_form(): \Generator {
		$wpdb = $this->wpdb;
		$last = PHP_INT_MAX;

		for ( $read = 0; $read < self::LIMIT; $read += self::BATCH ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT meta_id, post_id, meta_value FROM %i WHERE meta_key = %s AND meta_id < %d AND meta_value LIKE %s ORDER BY meta_id DESC LIMIT %d',
					$wpdb->postmeta,
					self::ELEMENTOR_META,
					$last,
					'%' . $wpdb->esc_like( '"widgetType":"' . self::ELEMENTOR_WIDGET . '"' ) . '%',
					min( self::BATCH, self::LIMIT - $read )
				),
				ARRAY_A
			);

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$last = (int) $row['meta_id'];

				yield (int) $row['post_id'] => (string) $row['meta_value'];
			}

			if ( ! is_array( $rows ) || count( $rows ) < self::BATCH ) {
				return;
			}
		}
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
		$ids = array();

		foreach ( $this->between( $content, self::BLOCK_OPENING, '-->' ) as $inner ) {
			// Tras el nombre del bloque va un espacio: si no, es el de otro bloque que empieza igual.
			if ( '' === $inner || ! ctype_space( $inner[0] ) ) {
				continue;
			}

			$attributes = json_decode( trim( rtrim( trim( $inner ), '/' ) ), true );
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
		$ids = array();

		foreach ( $this->between( $content, '[' . Shortcode::TAG, ']' ) as $start => $inner ) {
			// Tras el nombre va un espacio, la barra de cierre o nada: si no, es otro shortcode.
			if ( '' !== $inner && '/' !== $inner[0] && ! ctype_space( $inner[0] ) ) {
				continue;
			}

			$after = $start + strlen( '[' . Shortcode::TAG ) + strlen( $inner ) + 1;

			// Con doble corchete, [[así]], el shortcode está escrito para enseñarlo, no para ejecutarlo.
			if ( $start > 0 && '[' === $content[ $start - 1 ] && ']' === ( $content[ $after ] ?? '' ) ) {
				continue;
			}

			$attributes = shortcode_parse_atts( trim( rtrim( trim( $inner ), '/' ) ) );
			$ids[]      = absint( is_array( $attributes ) ? ( $attributes['id'] ?? 0 ) : 0 );
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Trozos del contenido que van entre una apertura y el primer cierre que la sigue.
	 *
	 * Recorre el texto una sola vez, sin expresiones regulares: el contenido lo escriben los autores
	 * del sitio, y una búsqueda que volviera a empezar desde cada apertura tardaría más cuanto más
	 * aperturas sin cerrar hubiera, dentro de la petición de quien gestiona los formularios.
	 *
	 * @param string $content Contenido de la entrada.
	 * @param string $opening Texto con el que empieza lo que se busca.
	 * @param string $closing Texto con el que termina.
	 * @return \Generator<int, string> Lo que hay entre los dos, por la posición de la apertura.
	 */
	private function between( string $content, string $opening, string $closing ): \Generator {
		$start = strpos( $content, $opening );

		while ( false !== $start ) {
			$inner = $start + strlen( $opening );
			$end   = strpos( $content, $closing, $inner );

			// Sin cierre de aquí al final, tampoco lo tiene ninguna apertura posterior.
			if ( false === $end ) {
				return;
			}

			if ( $end - $inner <= self::MAX_INNER ) {
				yield $start => substr( $content, $inner, $end - $inner );
			}

			$start = strpos( $content, $opening, $end + strlen( $closing ) );
		}
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
