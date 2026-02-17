<?php
/**
 * Class REST_Settings_Controller
 *
 * @package postsbridge
 */

namespace POSTS_BRIDGE;

use PBAPI;
use WP_Error;
use WP_REST_Server;
use WPCT_PLUGIN\REST_Settings_Controller as Base_Controller;
use HTTP_BRIDGE\Backend;
use HTTP_BRIDGE\Credential;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Plugin REST API controller. Handles routes registration, permissions
 * and request callbacks.
 */
class REST_Settings_Controller extends Base_Controller {

	/**
	 * Handles the current introspection request data as json.
	 *
	 * @var string|null
	 */
	private static $introspection_data = null;

	/**
	 * Inherits the parent initialized and register the post types route
	 */
	protected static function init() {
		parent::init();
		self::register_post_type_routes();
		self::register_schema_route();
		self::register_backend_routes();
	}

	/**
	 * Registers post type API routes.
	 */
	private static function register_post_type_routes() {
		$namespace = self::namespace();
		$version   = self::version();

		register_rest_route(
			"{$namespace}/v{$version}",
			'/post_types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () {
					return self::get_post_types();
				},
				'permission_callback' => array( self::class, 'permission_callback' ),
			)
		);

		register_rest_route(
			"{$namespace}/v{$version}",
			'/post_types/remotes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () {
					return self::get_remote_cpts();
				},
				'permission_callback' => array( self::class, 'permission_callback' ),
			)
		);

		register_rest_route(
			"{$namespace}/v{$version}",
			'/post_types/(?P<name>[a-zA-Z0-9-_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static function ( $request ) {
						return self::get_post_type( $request );
					},
					'permission_callback' => array( self::class, 'permission_callback' ),
					'args'                => array(
						'name' => array(
							'description' => __( 'Custom post type key', 'posts-bridge' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => static function ( $request ) {
						return self::delete_post_type( $request );
					},
					'permission_callback' => array( self::class, 'permission_callback' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => static function ( $request ) {
						return self::post_post_type( $request );
					},
					'permission_callback' => array( self::class, 'permission_callback' ),
					'args'                => array(
						'name' => array(
							'description' => __( 'Custom post type key', 'posts-bridge' ),
							'type'        => 'string',
							'required'    => true,
						),
						'args' => Custom_Post_Type::schema(),
					),
				),
			)
		);

		register_rest_route(
			"{$namespace}/v{$version}",
			'/post_types/(?P<name>[a-zA-Z0-9-_]+)/meta',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static function ( $request ) {
						return self::get_post_type_meta( $request );
					},
					'permission_callback' => array( self::class, 'permission_callback' ),
					'args'                => array(
						'name' => array(
							'description' => __( 'Custom post type key', 'posts-bridge' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Registers json schemas REST API routes.
	 */
	private static function register_schema_route() {
		foreach ( Addon::addons() as $addon ) {
			if ( ! $addon->enabled ) {
				continue;
			}

			$addon = $addon::NAME;
			register_rest_route(
				'posts-bridge/v1',
				"/{$addon}/schemas",
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static function () use ( $addon ) {
						return self::addon_schemas( $addon );
					},
					'permission_callback' => array( self::class, 'permission_callback' ),
				)
			);
		}

		register_rest_route(
			'posts-bridge/v1',
			'/http/schemas',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () {
					return self::http_schemas();
				},
				'permission_callback' => array( self::class, 'permission_callback' ),
			)
		);
	}

	/**
	 * Registers http backends REST API routes.
	 */
	private static function register_backend_routes() {
		foreach ( Addon::addons() as $addon ) {
			if ( ! $addon->enabled ) {
				continue;
			}

			$addon = $addon::NAME;

			register_rest_route(
				'posts-bridge/v1',
				"/{$addon}/backend/ping",
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => static function ( $request ) use ( $addon ) {
							return self::ping_backend( $addon, $request );
						},
						'permission_callback' => array( self::class, 'permission_callback' ),
						'args'                => array(
							'backend'    => PBAPI::get_backend_schema(),
							'credential' => PBAPI::get_credential_schema(),
						),
					),
				)
			);

			register_rest_route(
				'posts-bridge/v1',
				"/{$addon}/backend/endpoints",
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => static function ( $request ) use ( $addon ) {
							return self::get_backend_endpoints( $addon, $request );
						},
						'permission_callback' => array( self::class, 'permission_callback' ),
						'args'                => array(
							'backend' => PBAPI::get_backend_schema(),
							'method'  => array(
								'description' => __( 'HTTP method used to filter the list of endpoints', 'posts-bridge' ),
								'type'        => 'string',
							),
						),
					),
				),
			);

			register_rest_route(
				'posts-bridge/v1',
				"/{$addon}/backend/endpoint/schema",
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => static function ( $request ) use ( $addon ) {
							return self::get_endpoint_schema( $addon, $request );
						},
						'permission_callback' => array( self::class, 'permission_callback' ),
						'args'                => array(
							'backend'  => PBAPI::get_backend_schema(),
							'endpoint' => array(
								'description' => __( 'Target endpoint name', 'posts-bridge' ),
								'type'        => 'string',
								'required'    => true,
							),
							'method'   => array(
								'description' => __( 'HTTP method', 'posts-bridge' ),
								'type'        => 'string',
							),
						),
					),
				)
			);
		}
	}

	/**
	 * Callback for GET requests to the post_types endpoint.
	 *
	 * @return array|WP_Error Post type data.
	 */
	private static function get_post_types() {
		return array_keys( PBAPI::get_custom_post_types() );
	}

	/**
	 * Callback for GET requests to the post_types endpoint.
	 *
	 * @return array|WP_Error Post type data.
	 */
	private static function get_remote_cpts() {
		return PBAPI::get_remote_cpts();
	}

	/**
	 * Callback for GET requests to the post_types endpoint.
	 *
	 * @param REST_Request $request Request instance.
	 *
	 * @return array|WP_Error Post type data.
	 */
	private static function get_post_type( $request ) {
		$key  = sanitize_key( $request['name'] );
		$data = PBAPI::get_custom_post_type( $key );

		if ( ! $data ) {
			return new WP_Error(
				'not_found',
				__( 'Custom post type is unkown', 'posts-bridge' ),
				array( 'post_type' => $key )
			);
		}

		$data['name'] = $key;
		return $data;
	}

	/**
	 * Callback for the GET request to the post meta endpoint.
	 *
	 * @param REST_Request $request Request object.
	 *
	 * @return array|WP_Error
	 */
	private static function get_post_type_meta( $request ) {
		$key = sanitize_key( $request['name'] );

		global $wp_meta_keys;
		$meta = $wp_meta_keys['post'][ $key ] ?? array();

		$custom_fields = array();
		foreach ( $meta as $name => $defn ) {
			$custom_fields[] = array(
				'name'   => $name,
				'schema' => array(
					'type'    => $defn['type'],
					'default' => $defn['default'] ?? '',
				),
			);
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type = %s",
				$key,
			),
			ARRAY_A,
		);
		// phpcs:enable

		foreach ( $result as $record ) {
			if ( ! isset( $meta[ $record['meta_key'] ] ) ) {
				$custom_fields[] = array(
					'name'   => $record['meta_key'],
					'schema' => array( 'type' => 'string' ),
				);
			}
		}

		return $custom_fields;
	}

	/**
	 * Callback for POST requests to the post types endpoint.
	 *
	 * @param REST_Request $request Request instance.
	 *
	 * @return array|WP_Error Registration result.
	 */
	private static function post_post_type( $request ) {
		$key = sanitize_key( $request['name'] );

		$data         = $request->get_json_params();
		$data['name'] = $key;

		$success = Custom_Post_Type::register( $data );

		if ( ! $success ) {
			return new WP_Error(
				'internal_server_error',
				__(
					'Posts Bridge can\'t register the post type',
					'posts-bridge'
				),
				array( 'args' => $data )
			);
		}

		flush_rewrite_rules();

		$data         = PBAPI::get_custom_post_type( $key );
		$data['name'] = $key;

		return $data;
	}

	/**
	 * Callback for DELETE requests to the post types endpoint.
	 *
	 * @param REST_Request $request Request instance.
	 *
	 * @return array|WP_Error Removal result.
	 */
	private static function delete_post_type( $request ) {
		$key     = sanitize_key( $request['name'] );
		$success = Custom_Post_Type::unregister( $key );

		if ( ! $success ) {
			return new WP_Error(
				'internal_server_error',
				__(
					'Posts Bridge can\'t unregister the post type',
					'posts-bridge'
				),
				array( 'post_type' => $key )
			);
		}

		return array( 'success' => true );
	}

	/**
	 * Performs a request validation and sanitization
	 *
	 * @param string          $addon Target addon name.
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return array{0:Addon, 1:string}|WP_Error
	 */
	private static function prepare_addon_backend_request_handler(
		$addon,
		$request
	) {
		$addon = PBAPI::get_addon( $addon );
		if ( ! $addon ) {
			return self::bad_request();
		}

		$backend = wpct_plugin_sanitize_with_schema(
			$request['backend'],
			PBAPI::get_backend_schema()
		);

		if ( is_wp_error( $backend ) ) {
			return self::bad_request();
		}

		$introspection_data = array( 'backend' => $backend );

		$credential = $request['credential'];
		if ( ! empty( $credential ) ) {
			$credential = wpct_plugin_sanitize_with_schema(
				$credential,
				PBAPI::get_credential_schema( $addon )
			);

			if ( is_wp_error( $credential ) ) {
				return self::bad_request();
			}

			$backend['credential']            = $credential['name'];
			$introspection_data['backend']    = $backend;
			$introspection_data['credential'] = $credential;
		} elseif ( ! empty( $backend['credential'] ) ) {
			$credential = PBAPI::get_credential( $backend['credential'] );

			if ( $credential ) {
				$introspection_data['credential'] = $credential->data();
			}
		}

		Backend::temp_registration( $backend );
		Credential::temp_registration( $credential );

		self::$introspection_data = wp_json_encode( $introspection_data );
		return array( $addon, $backend['name'] );
	}

	/**
	 * Callback to the backend ping endpoint.
	 *
	 * @param string          $addon Addon name.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array|WP_Error
	 */
	private static function ping_backend( $addon, $request ) {
		$handler = self::prepare_addon_backend_request_handler( $addon, $request );

		if ( is_wp_error( $handler ) ) {
			return $handler;
		}

		list( $addon, $backend ) = $handler;

		$result = self::cache_lookup( $addon::NAME, $backend, 'ping' );
		if ( null !== $result ) {
			return $result;
		}

		$result = $addon->ping( $backend );

		if ( is_wp_error( $result ) ) {
			$error = self::bad_request();
			$error->add(
				$result->get_error_code(),
				$result->get_error_message(),
				$result->get_error_data()
			);

			return $error;
		}

		return self::cache_response(
			array( $addon::NAME, $backend, 'ping' ),
			array( 'success' => $result ),
			$addon->introspection_cache_expiration( 'ping' ),
		);
	}

	/**
	 * Backend endpoints route callback.
	 *
	 * @param string          $addon Addon name.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array|WP_Error
	 */
	private static function get_backend_endpoints( $addon, $request ) {
		$handler = self::prepare_addon_backend_request_handler( $addon, $request );

		if ( is_wp_error( $handler ) ) {
			return $handler;
		}

		list( $addon, $backend ) = $handler;

		$endpoints = self::cache_lookup( $addon::NAME, $backend, 'endpoints' );
		if ( null !== $endpoints ) {
			return $endpoints;
		}

		$endpoints = $addon->get_endpoints( $backend, $request['method'] );

		if ( is_wp_error( $endpoints ) ) {
			$error = self::internal_server_error();
			$error->add(
				$endpoints->get_error_code(),
				$endpoints->get_error_message(),
				$endpoints->get_error_data()
			);

			return $error;
		}

		return self::cache_response(
			array( $addon::NAME, $backend, 'endpoints' ),
			$endpoints,
			$addon->introspection_cache_expiration( 'endpoints' ),
		);
	}

	/**
	 * Backend endpoint schema route callback.
	 *
	 * @param string          $addon Addon name.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array|WP_Error
	 */
	private static function get_endpoint_schema( $addon, $request ) {
		$handler = self::prepare_addon_backend_request_handler( $addon, $request );

		if ( is_wp_error( $handler ) ) {
			return $handler;
		}

		list( $addon, $backend ) = $handler;

		$introspection_data             = json_decode( self::$introspection_data, true );
		$introspection_data['endpoint'] = $request['endpoint'];
		self::$introspection_data       = wp_json_encode( $introspection_data );

		$schema = self::cache_lookup( $addon::NAME, $backend, 'schema' );
		if ( null !== $schema ) {
			return $schema;
		}

		$schema = $addon->get_endpoint_schema( $request['endpoint'], $backend, $request['method'] );

		if ( is_wp_error( $schema ) ) {
			$error = self::internal_server_error();
			$error->add(
				$schema->get_error_code(),
				$schema->get_error_message(),
				$schema->get_error_data()
			);

			return $error;
		}

		return self::cache_response(
			array( $addon::NAME, $backend, 'schema' ),
			$schema,
			$addon->introspection_cache_expiration( 'schema' ),
		);
	}

	/**
	 * Callback of the addon schemas endpoint.
	 *
	 * @param string $name Addon name.
	 *
	 * @return array
	 */
	private static function addon_schemas( $name ) {
		$bridge = PBAPI::get_bridge_schema( $name );
		return array( 'bridge' => $bridge );
	}

	/**
	 * Callback of the http schemas endpoint.
	 *
	 * @return array
	 */
	private static function http_schemas() {
		$backend    = PBAPI::get_backend_schema();
		$credential = PBAPI::get_credential_schema();
		return array(
			'backend'    => $backend,
			'credential' => $credential,
		);
	}

	/**
	 * Lokkup for a cached introspection response.
	 *
	 * @param string[] ...$keys Introspection request keys: addon and backend names.
	 *
	 * @return array|null Cached introspection response.
	 */
	private static function cache_lookup( ...$keys ) {
		if ( Logger::is_active() ) {
			return null;
		}

		$key       = 'pb-introspection-' . sanitize_title( implode( '-', array_filter( $keys ) ) );
		$transient = get_transient( $key );
		if ( ! $transient ) {
			return null;
		}

		if ( $transient['key'] === self::$introspection_data ) {
			return $transient['data'];
		} else {
			delete_transient( $key );
		}
	}

	/**
	 * Cache an introspection response data.
	 *
	 * @param string[] $keys Introspection request keys: addon and backend names.
	 * @param array    $data Response data.
	 * @param int      $expiration Cache expiration time in seconds.
	 *
	 * @return array Cached data.
	 */
	private static function cache_response( $keys, $data, $expiration ) {
		if ( ! $expiration ) {
			return $data;
		}

		$key = 'pb-introspection-' . sanitize_title( implode( '-', array_filter( $keys ) ) );

		$transient_data = array(
			'key'  => self::$introspection_data,
			'data' => $data,
		);

		set_transient( $key, $transient_data, $expiration );
		return $data;
	}
}
