<?php

declare(strict_types=1);

namespace BbApp\RestAPI\BbPress;

use BbApp\RestAPI\WordPressBase\WordPressBaseRESTAPI;
use WP_REST_Request;

/**
 * REST API extensions for bbPress-specific resources and behaviors.
 */
class BbPressRESTAPI extends WordPressBaseRESTAPI
{
	/**
	 * Register computed last active UNIX timestamp field for a post type.
	 */
	public function register_last_active_time_field(
		string $post_type
	): void {
		register_rest_field($post_type, '_bbp_last_active_time', [
			'get_callback' => function ($post) {
				$last_active_time = get_post_meta($post['id'], '_bbp_last_active_time', true);

				if (empty($last_active_time)) {
					$last_active_time = $post['date'] ?? get_post_field('post_date', $post['id']);
				}

				if (empty($last_active_time)) {
					$last_active_time = date('c', 0);
				}

				return strtotime($last_active_time);
			},

			'schema' => [
				'type' => 'integer',
				'context' => ['view', 'embed']
			]
		]);
	}

	/**
	 * Register the parent ID field for a given post type.
	 */
	public function register_parent_field(string $post_type): void
	{
		register_rest_field($post_type, 'parent', [
			'get_callback' => function ($post) {
				return $post['parent'];
			},

			'schema' => [
				'type' => 'integer',
				'context' => ['view', 'embed']
			]
		]);
	}

	/**
	 * Extend topic schema with bbPress metadata.
	 */
	public function rest_topic_item_schema(array $schema): array
	{
		$schema['properties']['meta']['_bbp_forum_id'] = [
			'type' => 'integer',
			'description' => __('The forum id of the topic', 'bb-app'),
			'context' => ['edit']
		];

		return $this->rest_post_item_schema_anonymous_author($schema);
	}

	/**
	 * Extend reply schema with bbPress metadata.
	 */
	public function rest_reply_item_schema(array $schema): array
	{
		return $this->rest_post_item_schema_anonymous_author($schema);
	}

	/**
	 * Add bbPress-specific collection params for forums.
	 */
	public function rest_forum_collection_params(array $value): array
	{
		$value['orderby']['enum'][] = 'menu_order';
		$value['orderby']['enum'][] = '_bbp_last_active_time';
		return $this->rest_post_collection_params($value);
	}

	/**
	 * Add bbPress-specific collection params for topics.
	 */
	public function rest_topic_collection_params(array $value): array
	{
		$value['orderby']['enum'][] = '_bbp_last_active_time';
		return $this->rest_post_collection_params($value);
	}

	/**
	 * Add bbPress-specific collection params for replies.
	 */
	public function rest_reply_collection_params(array $value): array
	{
		return $this->rest_post_collection_params($value);
	}

	/**
	 * Shared collection params for post-like resources.
	 */
	public function rest_post_collection_params(array $value): array
	{
		$value['properties']['parent'] = [
			'description' => __('The ID for the parent of the post.'),
			'type' => 'integer',
			'context' => ['view', 'edit']
		];

		return $value;
	}

	/**
	 * Register bbPress meta fields when bb-app context is present.
	 */
	public function rest_request_before_callbacks(
		$response,
		array $handler,
		WP_REST_Request $request
	): void {
		register_rest_field('forum', 'menu_order', [
			'get_callback' => function ($post) {
				return get_post_field('menu_order', $post['id']);
			},

			'schema' => [
				'description' => __('Menu order'),
				'type' => 'integer',
				'context' => ['view', 'embed']
			]
		]);

		register_rest_field('reply', '_bbp_reply_count', [
			'get_callback' => function ($reply) {
				global $wpdb;

				return (int) $wpdb->get_var($wpdb->prepare(
					'SELECT COUNT(*) FROM %1$s WHERE meta_key = "%2$s" AND meta_value = %3$d',
					$wpdb->prefix . 'postmeta',
					'_bbp_reply_to',
					$reply['id']
				));
			},

			'schema' => [
				'type' => 'integer',
				'context' => ['view', 'embed']
			]
		]);

		$this->register_parent_field('forum');
		$this->register_parent_field('topic');
		$this->register_parent_field('reply');

		$this->register_last_active_time_field('forum');
		$this->register_last_active_time_field('topic');

		register_post_meta('topic', '_bbp_reply_count', [
			'type' => 'integer',
			'single' => true,
			'show_in_rest' => true,
			'auth_callback' => '__return_true'
		]);

		register_post_meta('reply', '_bbp_reply_to', [
			'type' => 'integer',
			'single' => true,
			'show_in_rest' => true,
			'auth_callback' => '__return_true'
		]);

		if (!is_user_logged_in() && bbp_allow_anonymous()) {
			foreach (['topic', 'reply'] as $subtype) {
				register_post_meta($subtype, '_bbp_anonymous_name', [
					'type' => 'string',
					'single' => true,
					'show_in_rest' => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback' => '__return_true'
				]);

				register_post_meta($subtype, '_bbp_anonymous_email', [
					'show_in_rest' => [
						'schema' => [
							'type' => 'string',
							'context' => ['edit']
						]
					],

					'type' => 'string',
					'single' => true,
					'sanitize_callback' => 'sanitize_email',
					'auth_callback' => '__return_true',
				]);
			}
		}

		add_filter('rest_forum_collection_params', [$this, 'rest_forum_collection_params'], 10, 2);
		add_filter('rest_topic_collection_params', [$this, 'rest_topic_collection_params'], 10, 2);
		add_filter('rest_reply_collection_params', [$this, 'rest_reply_collection_params'], 10, 2);
	}

	/**
	 * Allow anonymous POST routes for bbPress endpoints when configured.
	 */
	public function rest_endpoints_allow_anonymous_post(
		string $route,
		array &$endpoints
	): array {
		if (!empty($endpoints[$route]) && is_array($endpoints[$route])) {
			foreach ($endpoints[$route] as &$endpoint) {
				if (!empty($endpoint['methods'])) {
					$methods = $endpoint['methods'];

					$is_post = ($methods === 'POST') ||
						(is_array($methods) && in_array('POST', $methods, true));

					if ($is_post) {
						$endpoint['permission_callback'] = function (WP_REST_Request $request) {
							return is_user_logged_in() || bbp_allow_anonymous();
						};
					}
				}
			}
		}

		return $endpoints;
	}

	/**
	 * Add anonymous author fields to post schema when allowed.
	 */
	protected function rest_post_item_schema_anonymous_author(array $schema): array
	{
		if (!is_user_logged_in() && bbp_allow_anonymous()) {
			$schema['properties']['meta']['_bbp_anonymous_name'] = [
				'type' => 'string',
				'description' => __('The name of the author of the post.', 'bb-app'),
				'context' => ['edit'],
				'sanitize_callback' => 'sanitize_user'
			];

			$schema['properties']['meta']['_bbp_anonymous_email'] = [
				'type' => 'string',
				'description' => __('The email of the author of the post.', 'bb-app'),
				'context' => ['edit'],
				'sanitize_callback' => 'sanitize_email'
			];
		}

		return $schema;
	}

	/**
	 * Register hooks and filters for bbPress REST integration.
	 */
	public function register(): void
	{
		parent::register();

		add_filter('rest_allow_anonymous_comments', function($value, WP_REST_Request $request) {
			if (!bbp_allow_anonymous()) {
				return $value;
			}

			return true;
		}, 10, 2);

		add_filter('bbp_new_topic_redirect_to', function(string $topic_url, string $redirect_to, int $topic_id) {
			return rest_url("wp/v2/topics/{$topic_id}");
		}, 100, 3);

		add_filter('bbp_new_reply_redirect_to', function(string $reply_url, string $redirect_to, int $reply_id) {
			return rest_url("wp/v2/replies/{$reply_id}");
		}, 100, 3);

		add_filter('rest_topic_item_schema', [$this, 'rest_topic_item_schema']);
		add_filter('rest_reply_item_schema', [$this, 'rest_reply_item_schema']);

		add_action('rest_request_before_callbacks', [$this, 'rest_request_before_callbacks'], 10, 3);
	}

	/**
	 * Initialize bbPress-specific REST setup and post meta.
	 */
	public function init(): void
	{
		parent::init();

		register_post_meta('forum', '_bbp_last_active_time', [
			'type' => 'string',
			'single' => true,
			'show_in_rest' => true,
			'auth_callback' => '__return_true'
		]);

		register_post_meta('forum', '_bbp_forum_type', [
			'type' => 'string',
			'single' => true,
			'show_in_rest' => true,
			'auth_callback' => '__return_true'
		]);

		register_post_meta('topic', '_bbp_last_active_time', [
			'type' => 'string',
			'single' => true,
			'show_in_rest' => true,
			'auth_callback' => '__return_true'
		]);

		add_filter('bbp_register_forum_post_type', function (array $value) {
			$value['supports'][] = 'author';
			$value['supports'][] = 'custom-fields';
			return $value + ['show_in_rest' => true, 'rest_base' => 'forums', 'allow_batch' => ['v1' => true]];
		}, 100, 2);

		add_filter('bbp_register_topic_post_type', function (array $value) {
			$value['supports'][] = 'author';
			$value['supports'][] = 'custom-fields';
			$value['rest_controller_class'] = BbPressRESTPostsController::class;
			return $value + ['show_in_rest' => true, 'rest_base' => 'topics', 'allow_batch' => ['v1' => true]];
		}, 100, 3);

		add_filter('bbp_register_reply_post_type', function (array $value) {
			$value['supports'][] = 'author';
			$value['supports'][] = 'custom-fields';
			$value['rest_controller_class'] = BbPressRESTPostsController::class;
			return $value + ['show_in_rest' => true, 'rest_base' => 'replies', 'allow_batch' => ['v1' => true]];
		}, 100, 3);

		add_filter('rest_endpoints', function ($endpoints) {
			$this->rest_endpoints_allow_anonymous_post('/wp/v2/topics', $endpoints);
			$this->rest_endpoints_allow_anonymous_post('/wp/v2/replies', $endpoints);

			return $endpoints;
		}, 110);
	}
}
