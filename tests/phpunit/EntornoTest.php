<?php
/**
 * Comprueba que el entorno de tests está operativo. Sirve de plantilla para los tests del plugin.
 */
class EntornoTest extends WP_UnitTestCase {

	public function test_wordpress_escribe_y_lee_en_la_base_de_datos() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Prueba del entorno' ) );

		$this->assertSame( 'Prueba del entorno', get_post( $post_id )->post_title );
	}

	public function test_los_correos_se_pueden_inspeccionar_sin_enviarse() {
		wp_mail( 'cliente@example.org', 'Asunto de prueba', 'Cuerpo' );

		$sent = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertSame( 'Asunto de prueba', $sent->subject );
	}
}
