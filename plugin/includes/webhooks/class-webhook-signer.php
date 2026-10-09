<?php
/**
 * Firma de webhooks.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Firma el cuerpo de un webhook con un secreto compartido, para que el destino pueda comprobar que
 * la petición viene de este sitio y que nadie la ha cambiado por el camino.
 *
 * La firma es un HMAC-SHA256 de «marca de tiempo, punto, cuerpo». Incluir la marca de tiempo deja al
 * destino rechazar la repetición de una petición antigua capturada.
 */
final class Webhook_Signer {

	public const HEADER_SIGNATURE = 'X-Formulario-Mensaje-Signature';
	public const HEADER_TIMESTAMP = 'X-Formulario-Mensaje-Timestamp';

	/**
	 * Cabeceras de firma de una petición.
	 *
	 * @param string $body      Cuerpo de la petición, tal como se envía.
	 * @param string $secret    Secreto compartido con el destino.
	 * @param int    $timestamp Marca de tiempo Unix del envío.
	 * @return array<string, string>
	 */
	public function headers( string $body, string $secret, int $timestamp ): array {
		return array(
			self::HEADER_TIMESTAMP => (string) $timestamp,
			self::HEADER_SIGNATURE => 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ),
		);
	}
}
