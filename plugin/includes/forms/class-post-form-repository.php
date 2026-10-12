<?php
/**
 * Formularios guardados como tipo de contenido.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Almacén de formularios sobre un tipo de contenido privado, con la definición en JSON en un metadato.
 */
final class Post_Form_Repository implements Form_Repository {

	/**
	 * Metadato que guarda la definición en JSON.
	 */
	public const DEFINITION_META = '_formulario_mensaje_definition';

	/**
	 * Máximo de formularios que devuelve un listado.
	 */
	private const LIST_LIMIT = 500;

	/**
	 * Busca un formulario por su identificador, en cualquier estado.
	 *
	 * @param int $id Identificador del formulario.
	 */
	public function find( int $id ): ?Form {
		if ( $id <= 0 ) {
			return null;
		}

		$post = get_post( $id );

		if ( ! $post instanceof WP_Post || Form_Post_Type::NAME !== $post->post_type ) {
			return null;
		}

		return $this->to_form( $post );
	}

	/**
	 * Lista los formularios de un estado, del más reciente al más antiguo.
	 *
	 * @param string $status Form::STATUS_PUBLISHED o Form::STATUS_TRASHED.
	 * @return Form[]
	 */
	public function all( string $status ): array {
		$posts = get_posts(
			array(
				'post_type'      => Form_Post_Type::NAME,
				'post_status'    => Form::STATUS_TRASHED === $status ? Form::STATUS_TRASHED : Form::STATUS_PUBLISHED,
				'posts_per_page' => self::LIST_LIMIT,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		return array_map( array( $this, 'to_form' ), $posts );
	}

	/**
	 * Crea o actualiza un formulario.
	 *
	 * @param int             $id         Identificador, o 0 para crear uno nuevo.
	 * @param string          $title      Nombre del formulario.
	 * @param Form_Definition $definition Definición ya saneada.
	 * @return int|WP_Error Identificador del formulario guardado.
	 */
	public function save( int $id, string $title, Form_Definition $definition ): int|WP_Error {
		$data = array(
			'post_type'  => Form_Post_Type::NAME,
			'post_title' => $title,
		);

		if ( $id > 0 ) {
			if ( null === $this->find( $id ) ) {
				return new WP_Error( 'formulario_mensaje_form_not_found', __( 'The form does not exist.', 'formulario-mensaje' ), array( 'status' => 404 ) );
			}

			$data['ID'] = $id;
			$result     = wp_update_post( wp_slash( $data ), true );
		} else {
			$data['post_status'] = Form::STATUS_PUBLISHED;
			$result              = wp_insert_post( wp_slash( $data ), true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// El metadato pasa por wp_unslash() al guardarse: sin wp_slash() se perderían las barras del JSON.
		update_post_meta( $result, self::DEFINITION_META, wp_slash( (string) wp_json_encode( $definition->to_array() ) ) );

		return $result;
	}

	/**
	 * Envía un formulario a la papelera.
	 *
	 * @param int $id Identificador del formulario.
	 */
	public function trash( int $id ): bool {
		$form = $this->find( $id );

		if ( null === $form ) {
			return false;
		}

		if ( ! $form->is_published() ) {
			return true;
		}

		/*
		 * No se usa wp_trash_post(): un formulario se queda en la papelera hasta que alguien lo borra, porque
		 * borrarlo deja sin dueño a sus envíos, y con la función de WordPress no sería así. Con su papelera
		 * desactivada (EMPTY_TRASH_DAYS a 0) lo borraría en el acto, y con ella activada lo borraría por su
		 * cuenta pasados unos días: wp_scheduled_delete() busca la marca de tiempo que esa función deja.
		 * Aquí solo se guarda el estado anterior, que es lo que necesita wp_untrash_post() para restaurarlo.
		 */
		update_post_meta( $id, '_wp_trash_meta_status', Form::STATUS_PUBLISHED );

		$updated = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => Form::STATUS_TRASHED,
			),
			true
		);

		return ! is_wp_error( $updated );
	}

	/**
	 * Recupera un formulario de la papelera.
	 *
	 * @param int $id Identificador del formulario.
	 */
	public function restore( int $id ): bool {
		if ( null === $this->find( $id ) || false === wp_untrash_post( $id ) ) {
			return false;
		}

		// WordPress recupera los contenidos como borrador; un formulario no tiene ese estado.
		$updated = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => Form::STATUS_PUBLISHED,
			),
			true
		);

		return ! is_wp_error( $updated );
	}

	/**
	 * Borra un formulario definitivamente.
	 *
	 * @param int $id Identificador del formulario.
	 */
	public function delete( int $id ): bool {
		return null !== $this->find( $id ) && false !== wp_delete_post( $id, true );
	}

	/**
	 * Convierte un contenido de WordPress en un formulario.
	 *
	 * @param WP_Post $post Contenido del tipo de los formularios.
	 */
	private function to_form( WP_Post $post ): Form {
		$json = get_post_meta( $post->ID, self::DEFINITION_META, true );

		return new Form(
			$post->ID,
			$post->post_title,
			$post->post_status,
			Form_Definition::from_array( is_string( $json ) && '' !== $json ? json_decode( $json, true ) : array() ),
			$post->post_modified_gmt
		);
	}
}
