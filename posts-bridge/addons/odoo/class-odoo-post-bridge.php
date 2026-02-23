<?php
/**
 * Class Odoo_Post_Bridge
 *
 * @package postsbridge
 */

namespace POSTS_BRIDGE;

use HTTP_BRIDGE\Http_Client;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Odoo post bridge.
 */
class Odoo_Post_Bridge extends Post_Bridge {

	/**
	 * Handles the Odoo JSON-RPC well known endpoint.
	 *
	 * @var string
	 */
	public const ENDPOINT = '/jsonrpc';

	/**
	 * Handle active rpc session data.
	 *
	 * @var array Tuple with session and user ids.
	 */
	private static $session;

	/**
	 * Handles the current HTTP request of the bridge.
	 *
	 * @var array
	 */
	private static $request;

	/**
	 * RPC payload decorator.
	 *
	 * @param int    $session_id RPC session ID.
	 * @param string $service RPC service name.
	 * @param string $method RPC method name.
	 * @param array  $args RPC request arguments.
	 * @param array  $more_args RPC additional arguments.
	 *
	 * @return array JSON-RPC conformant payload.
	 */
	private static function rpc_payload(
		$session_id,
		$service,
		$method,
		$args,
		$more_args = array()
	) {
		if ( ! empty( $more_args ) ) {
			$args[] = $more_args;
		}

		return array(
			'jsonrpc' => '2.0',
			'method'  => 'call',
			'id'      => $session_id,
			'params'  => array(
				'service' => $service,
				'method'  => $method,
				'args'    => $args,
			),
		);
	}

	/**
	 * Handle RPC responses and catch errors on the application layer.
	 *
	 * @param array $res Request response.
	 *
	 * @return mixed|WP_Error Request result.
	 */
	private static function rpc_response( $res ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( empty( $res['data'] ) ) {
			$content_type = Http_Client::get_content_type( $res['headers'] ) ?? 'undefined';

			return new WP_Error(
				'unkown_content_type',
				sprintf(
					/* translators: %s: Content-Type header value */
					__( 'Unkown HTTP response content type %s', 'posts-bridge' ),
					sanitize_text_field( $content_type )
				),
				$res
			);
		}

		if ( isset( $res['data']['error'] ) ) {
			$error = new WP_Error(
				'response_code_' . $res['data']['error']['code'],
				$res['data']['error']['message'],
				$res['data']['error']['data']
			);

			$error_data = array( 'response' => $res );
			if ( self::$request ) {
				$error_data['request'] = self::$request;
			}

			$error->add_data( $error_data );
			return $error;
		}

		$data = $res['data'];

		if ( empty( $data['result'] ) ) {
			$error = new WP_Error( 'not_found' );

			$error_data = array( 'response' => $res );
			if ( self::$request ) {
				$error_data['request'] = self::$request;
			}

			$error->add_data( $error_data );
			return $error;
		}

		return $data['result'];
	}

	/**
	 * JSON RPC login request.
	 *
	 * @param array   $login RPC login credentials.
	 * @param Backend $backend Backend object.
	 *
	 * @return array|WP_Error Tuple with RPC session id and user id.
	 */
	private static function rpc_login( $login, $backend ) {
		$session_id = 'posts-bridge-' . time();

		$payload = self::rpc_payload( $session_id, 'common', 'login', $login );

		$response = $backend->post( self::ENDPOINT, $payload );

		$user_id = self::rpc_response( $response );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		self::$session = array( $session_id, $user_id );
		return self::$session;
	}

	/**
	 * Bridge constructor.
	 *
	 * @param array $data Bridge data.
	 */
	public function __construct( $data ) {
		parent::__construct( $data, 'odoo' );

		if ( 'read' !== $data['method'] ) {
			$this->data['method'] = $data['method'];
		}
	}

	/**
	 * Performs a login call and sets up hooks to handle RPC calls over HTTP requests .
	 *
	 * @return array{0:string, 1:integer, 2:string}|WP_Error
	 */
	public function login() {
		if ( self::$session ) {
			return self::$session;
		}

		if ( ! $this->is_valid ) {
			return new WP_Error( 'invalid_bridge', 'Bridge is invalid', (array) $this->data );
		}

		$backend = $this->backend();
		if ( ! $backend ) {
			return new WP_Error(
				'invalid_backend',
				'The bridge does not have a valid backend',
				$this->data,
			);
		}

		$credential = $backend->credential;
		if ( ! $credential ) {
			return new WP_Error(
				'invalid_credential',
				'The bridge does not have a valid credential',
				$backend->data(),
			);
		}

		$login = $credential->authorization();

		if ( ! self::$session ) {
			add_filter(
				'http_bridge_request',
				static function ( $request ) {
					self::$request = $request;
					return $request;
				},
				10,
				1
			);

			$backend_name = $backend->name;

			add_filter(
				'http_bridge_backend_headers',
				static function ( $headers, $backend ) use ( $backend_name ) {
					if ( $backend->name !== $backend_name ) {
						return $headers;
					}

					$locale = get_locale();
					if ( ! $locale ) {
						return $headers;
					}

					if ( 'ca' === $locale ) {
						$locale = 'ca_ES';
					}

					$headers['Accept-Language'] = $locale;
					return $headers;
				},
				20,
				2
			);
		}

		$session = self::rpc_login( $login, $backend );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$login[1] = $session[1];
		return $login;
	}

	/**
	 * Performs an RPC call to the Odoo API.
	 *
	 * @param string    $model Model name.
	 * @param array|int $params RPC args.
	 * @param array     $headers HTTP headers.
	 *
	 * @return array|WP_Error
	 */
	public function request( $model, $params = array(), $headers = array() ) {
		$login = self::login();

		if ( is_wp_error( $login ) ) {
			return $login;
		}

		$foreigns = array_keys( $this->mappers() );

		$fields = array();
		foreach ( $foreigns as $foreign ) {
			$parts = JSON_Finger::parse( $foreign );
			if ( count( $parts ) ) {
				$fields[] = $parts[0];
			}
		}

		$args = array_merge( $login, array( $model, $this->method ) );

		if ( false !== strpos( $this->method, 'read' ) ) {
			if ( is_scalar( $params ) ) {
				$params = (int) $params;
			}
		} else {
			$fields = null;
		}

		$args[]  = $params;
		$payload = self::rpc_payload( self::$session[0], 'object', 'execute', $args, $fields );

		$response = $this->backend()->post( self::ENDPOINT, $payload, $headers );

		$result = self::rpc_response( $response );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'read' === $this->method ) {
			$response['data'] = $response['data']['result'][0];
		}

		return $response;
	}

	/**
	 * Performs an RPC read call for the bridge model by foreign id.
	 *
	 * @param int|string $foreign_id Foreig key value.
	 * @param array      $params Ignored.
	 * @param array      $headers HTTP headers.
	 *
	 * @return array|WP_Error Remote data for the given id.
	 */
	public function fetch_one( $foreign_id, $params = array(), $headers = array() ) {
		$response = $this->patch( array( 'method' => 'read' ) )
			->request( $this->endpoint, $foreign_id, $headers );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['data'];
	}

	/**
	 * Performs an RPC search call for the bridge model.
	 *
	 * @param array $params Ignored.
	 * @param array $headers HTTP headers.
	 *
	 * @return array|WP_Error List of remote model IDs.
	 */
	public function fetch_all( $params = array(), $headers = array() ) {
		$response = $this->patch(
			array(
				'method'        => 'search',
				'field_mappers' => array(),
				'tax_mappers'   => array(),
			)
		)->request( $this->endpoint, $params, $headers );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array_map(
			function ( $id ) {
				return array( 'id' => $id );
			},
			$response['data']['result']
		);
	}
}
