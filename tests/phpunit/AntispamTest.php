<?php
/**
 * Defensas antispam: IP del visitante, token, límite por IP y campo trampa.
 */

use Formulario_Mensaje\Antispam\Client_Ip_Resolver;
use Formulario_Mensaje\Antispam\Cloudflare_Ranges;
use Formulario_Mensaje\Antispam\Ip_Matcher;
use Formulario_Mensaje\Antispam\Rate_Limit_Check;
use Formulario_Mensaje\Antispam\Token_Service;
use Formulario_Mensaje\Antispam\Transient_Rate_Limiter;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Definition;
use Formulario_Mensaje\Settings\Settings;
use Formulario_Mensaje\Settings\Trusted_Proxy_Mode;
use Formulario_Mensaje\Submissions\Submission_Channel;
use Formulario_Mensaje\Submissions\Submission_Request;

class AntispamTest extends Caso_Formulario {

	public function test_los_rangos_de_ip_se_comparan_bien_en_ipv4_e_ipv6() {
		$matcher = new Ip_Matcher();

		$this->assertTrue( $matcher->matches( '10.1.2.3', array( '10.0.0.0/8' ) ) );
		$this->assertFalse( $matcher->matches( '11.1.2.3', array( '10.0.0.0/8' ) ) );
		$this->assertTrue( $matcher->matches( '192.168.1.130', array( '192.168.1.128/25' ) ) );
		$this->assertFalse( $matcher->matches( '192.168.1.127', array( '192.168.1.128/25' ) ) );
		$this->assertTrue( $matcher->matches( '203.0.113.7', array( '203.0.113.7' ) ) );
		$this->assertTrue( $matcher->matches( '2001:db8:abcd::1', array( '2001:db8::/32' ) ) );
		$this->assertFalse( $matcher->matches( '2001:db9::1', array( '2001:db8::/32' ) ) );
		$this->assertFalse( $matcher->matches( '10.0.0.1', array( '2001:db8::/32' ) ), 'Una IPv4 no pertenece a un rango IPv6.' );
		$this->assertFalse( $matcher->matches( 'no-es-una-ip', array( '0.0.0.0/0' ) ) );
	}

	public function test_los_rangos_mal_formados_se_descartan() {
		$matcher = new Ip_Matcher();

		$this->assertSame( '10.0.0.0/8', $matcher->normalize_range( ' 10.0.0.0/8 ' ) );
		$this->assertSame( '', $matcher->normalize_range( '10.0.0.0/33' ) );
		$this->assertSame( '', $matcher->normalize_range( '10.0.0.0/8/2' ) );
		$this->assertSame( '', $matcher->normalize_range( 'ejemplo.com' ) );
	}

	private function resolver( Settings $ajustes, array $servidor ): Client_Ip_Resolver {
		return new Client_Ip_Resolver( new Ajustes_En_Memoria( $ajustes ), new Servidor_Falso( $servidor ), new Ip_Matcher(), new Cloudflare_Ranges() );
	}

	public function test_sin_proxy_de_confianza_las_cabeceras_falsificables_se_ignoran() {
		$ip = $this->resolver(
			new Settings(),
			array(
				'REMOTE_ADDR'           => '198.51.100.20',
				'HTTP_X_FORWARDED_FOR'  => '1.2.3.4',
				'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
			)
		)->resolve();

		$this->assertSame( '198.51.100.20', $ip );
	}

	public function test_la_cabecera_de_cloudflare_solo_vale_si_la_conexion_viene_de_cloudflare() {
		$ajustes = new Settings( Trusted_Proxy_Mode::Cloudflare );

		$desde_cloudflare = $this->resolver( $ajustes, array( 'REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9' ) )->resolve();
		$directo          = $this->resolver( $ajustes, array( 'REMOTE_ADDR' => '198.51.100.20', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9' ) )->resolve();

		$this->assertSame( '203.0.113.9', $desde_cloudflare );
		$this->assertSame( '198.51.100.20', $directo, 'Un atacante que evita Cloudflare no puede fingir su IP.' );
	}

	public function test_con_proxy_propio_se_toma_la_primera_ip_no_fiable_empezando_por_la_derecha() {
		$ajustes = new Settings( Trusted_Proxy_Mode::Custom, array( '10.0.0.0/8' ) );

		$ip = $this->resolver(
			$ajustes,
			// El visitante puede escribir lo que quiera a la izquierda; el proxy añade por la derecha.
			array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.50, 10.0.0.9' )
		)->resolve();

		$this->assertSame( '203.0.113.50', $ip );

		$no_es_el_proxy = $this->resolver( $ajustes, array( 'REMOTE_ADDR' => '198.51.100.20', 'HTTP_X_FORWARDED_FOR' => '203.0.113.50' ) )->resolve();
		$this->assertSame( '198.51.100.20', $no_es_el_proxy );
	}

	public function test_el_token_es_valido_para_su_formulario_tras_el_tiempo_minimo() {
		remove_filter( 'formulario_mensaje_min_fill_seconds', '__return_zero' );

		$reloj   = new Reloj_Falso();
		$tokens  = new Token_Service( $reloj );
		$token   = $tokens->issue( 12 );
		$demasiado_pronto = $tokens->verify( $token, 12 );

		$this->assertSame( 'formulario_mensaje_too_fast', $demasiado_pronto->get_error_code() );

		$reloj->avanzar( $tokens->min_seconds() );

		$this->assertNull( $tokens->verify( $token, 12 ) );
		$this->assertSame( 'formulario_mensaje_token_invalid', $tokens->verify( $token, 13 )->get_error_code(), 'No sirve para otro formulario.' );
	}

	public function test_un_token_manipulado_o_caducado_se_rechaza() {
		$reloj  = new Reloj_Falso();
		$tokens = new Token_Service( $reloj );
		$token  = $tokens->issue( 12 );

		list( $marca, $firma ) = explode( '.', $token );

		$this->assertSame( 'formulario_mensaje_token_invalid', $tokens->verify( ( (int) $marca - 100 ) . '.' . $firma, 12 )->get_error_code() );
		$this->assertSame( 'formulario_mensaje_token_invalid', $tokens->verify( '', 12 )->get_error_code() );
		$this->assertSame( 'formulario_mensaje_token_invalid', $tokens->verify( 'abc', 12 )->get_error_code() );

		$reloj->avanzar( DAY_IN_SECONDS + 1 );

		$this->assertSame( 'formulario_mensaje_token_expired', $tokens->verify( $token, 12 )->get_error_code() );
	}

	public function test_el_limite_por_ip_bloquea_al_superarlo_y_se_levanta_al_pasar_la_ventana() {
		$reloj      = new Reloj_Falso();
		$limite     = new Rate_Limit_Check( new Transient_Rate_Limiter( $reloj ) );
		$formulario = new Form( 7, 'Contacto', Form::STATUS_PUBLISHED, new Form_Definition() );
		$peticion   = new Submission_Request( 7, array(), '', '', Submission_Channel::Rest, '203.0.113.9' );
		$otra_ip    = new Submission_Request( 7, array(), '', '', Submission_Channel::Rest, '203.0.113.10' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertNull( $limite->check( $peticion, $formulario ), "Envío $i" );
			$limite->record( $peticion, $formulario );
		}

		$error = $limite->check( $peticion, $formulario );

		$this->assertSame( 'formulario_mensaje_rate_limited', $error->get_error_code() );
		$this->assertSame( 429, $error->get_error_data()['status'] );
		$this->assertNull( $limite->check( $otra_ip, $formulario ), 'El límite es por IP.' );

		$reloj->avanzar( 601 );

		$this->assertNull( $limite->check( $peticion, $formulario ) );
	}

	public function test_el_contador_del_limite_no_guarda_la_ip() {
		global $wpdb;

		$limite     = new Rate_Limit_Check( new Transient_Rate_Limiter( new Reloj_Falso( time() ) ) );
		$formulario = new Form( 7, 'Contacto', Form::STATUS_PUBLISHED, new Form_Definition() );

		$limite->record( new Submission_Request( 7, array(), '', '', Submission_Channel::Rest, '203.0.113.77' ), $formulario );

		$guardado = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE '%203.0.113.77%' OR option_value LIKE '%203.0.113.77%'" );

		$this->assertSame( '0', $guardado );
	}

	public function test_un_envio_con_el_campo_trampa_relleno_se_rechaza_sin_enviar_correo() {
		$formulario = $this->crear_formulario();

		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Bot', 'correo' => 'bot@example.org' ), array( 'fm_website' => 'http://spam.example' ) );

		$this->assertSame( 400, $respuesta->get_status() );
		$this->assertSame( 'formulario_mensaje_spam', $respuesta->get_data()['code'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_un_envio_por_rest_sin_token_se_rechaza() {
		$formulario = $this->crear_formulario();

		$respuesta = $this->rest( 'POST', "/forms/$formulario/submissions", array( 'fm' => array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) ) );

		$this->assertSame( 400, $respuesta->get_status() );
		$this->assertSame( 'formulario_mensaje_token_invalid', $respuesta->get_data()['code'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_un_envio_por_rest_antes_del_tiempo_minimo_se_rechaza() {
		remove_filter( 'formulario_mensaje_min_fill_seconds', '__return_zero' );

		$formulario = $this->crear_formulario();
		$respuesta  = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );

		$this->assertSame( 400, $respuesta->get_status() );
		$this->assertSame( 'formulario_mensaje_too_fast', $respuesta->get_data()['code'] );
	}

	public function test_la_ruta_del_token_indica_cuanto_esperar_y_no_existe_para_formularios_en_la_papelera() {
		$formulario = $this->crear_formulario();
		$respuesta  = $this->rest( 'POST', "/forms/$formulario/token" );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertMatchesRegularExpression( '/^\d+\.[a-f0-9]{64}$/', $respuesta->get_data()['token'] );
		$this->assertSame( 0, $respuesta->get_data()['wait'] );
		$this->assertSame( 'no-store', $respuesta->get_headers()['Cache-Control'] );

		wp_trash_post( $formulario );

		$this->assertSame( 404, $this->rest( 'POST', "/forms/$formulario/token" )->get_status() );
	}
}
