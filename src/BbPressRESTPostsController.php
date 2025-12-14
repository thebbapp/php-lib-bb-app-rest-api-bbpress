<?php

declare(strict_types=1);

namespace BbApp\RestAPI\BbPress;

use WP_REST_Posts_Controller, WP_Error;

/**
 * Custom posts controller adding bbPress-specific query and creation logic.
 */
class BbPressRESTPostsController extends WP_REST_Posts_Controller
{
	/**
	 * Add bbPress meta query parameters to collection routes.
	 */
	function get_collection_params(): array
	{
		$params = parent::get_collection_params();

		$params['meta_key'] = [
			'description' => __('Limit results to posts with a specific meta key.', 'bb-app'),
			'type' => 'string',
			'required' => false,
		];

		$params['meta_value'] = [
			'description' => __('Limit results to posts where meta_key has this value.', 'bb-app'),
			'type' => ['string', 'number', 'boolean', 'array'],
			'required' => false,
		];

		$params['meta_compare'] = [
			'description' => __('Comparison operator for meta_value.', 'bb-app'),
			'type' => 'string',
			'enum' => ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS', 'REGEXP', 'NOT REGEXP', 'RLIKE'],
			'required' => false,
		];

		$params['meta_type'] = [
			'description' => __('Data type of meta_value for sorting and comparison.', 'bb-app'),
			'type' => 'string',
			'enum' => ['NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'UNSIGNED', 'TIME'],
			'required' => false,
		];

		$params['meta_query'] = [
			'description' => __('Advanced meta query. Accepts JSON string or array using meta_query[0][key]=... syntax. Follows WP_Query meta_query format, with optional relation.', 'bb-app'),
			'type' => 'array',
			'required' => false,
		];

		return $params;
	}

	/**
	 * Prepare WP_Query args with bbPress parent/meta filters.
	 */
	function prepare_items_query($prepared_args = array(), $request = null): array
	{
		$query_args = parent::prepare_items_query($prepared_args, $request);

		$parent = $request->get_param('parent');

		if ($parent !== null && $parent !== '') {
			if (is_array($parent)) {
				$ids = array_values(array_filter(array_map('intval', $parent), function ($v) { return $v > 0; }));

				if (!empty($ids)) {
					$query_args['post_parent__in'] = $ids;
				}
			} else {
				$parent_id = (int) $parent;

				if ($parent_id > 0) {
					$query_args['post_parent'] = $parent_id;
				}
			}
		}

		$meta_query = $request->get_param('meta_query');

		if (!empty($meta_query)) {
			$normalized = $this->normalize_meta_query($meta_query);

			if (!empty($normalized)) {
				$query_args['meta_query'] = $normalized;
			}
		} else {
			$meta_key = $request->get_param('meta_key') ?? '';

			if ($meta_key !== '') {
				$meta_value = $request->get_param('meta_value');
				$meta_compare = strtoupper($request->get_param('meta_compare') ?? '=');
				$meta_type = $request->get_param('meta_type');

				$clause = [
					'key' => $meta_key,
					'compare' => $meta_compare,
				];

				if ($meta_value !== null && $meta_value !== '') {
					$clause['value'] = $meta_value;
				}

				if (is_string($meta_type) && $meta_type !== '') {
					$clause['type'] = strtoupper($meta_type);
				}

				$query_args['meta_query'] = [$clause];
			}
		}

		return $query_args;
	}

	/**
	 * Create a topic or reply via bbPress handlers.
	 */
	function create_item($request)
	{
		if (!empty($request['id'])) {
			return new WP_Error(
				'rest_post_exists',
				__('Cannot create existing post.'),
				['status' => 400]
			);
		}

		$this->add_filter_permalinks();

		$_POST = $request->get_params();

		switch ($this->post_type) {
			case 'topic':
				if (current_user_can('unfiltered_html')) {
					$_POST['_bbp_unfiltered_html_topic'] = wp_create_nonce('bbp-unfiltered-html-topic_new');
				}

				$_REQUEST['_wpnonce'] = wp_create_nonce('bbp-new-topic');
				bbp_new_topic_handler('bbp-new-topic');
				break;
			case 'reply':
				if (current_user_can('unfiltered_html')) {
					$_POST['_bbp_unfiltered_html_reply'] = wp_create_nonce('bbp-unfiltered-html-reply_' . $request['id']);
				}

				$_REQUEST['_wpnonce'] = wp_create_nonce('bbp-new-reply');
				bbp_new_reply_handler('bbp-new-reply');
				break;
		}

		if (bbp_has_errors()) {
			return new WP_Error(
				bbpress()->errors->get_error_code(),
				bbpress()->errors->get_error_message(),
				['status' => 403]
			);
		}

		return new WP_Error(
			'control_structure_unreachable',
			__('Unreachable control structure reached.', 'bb-app'),
			['status' => 500]
		);
	}

	/**
	 * Update a topic or reply via bbPress handlers.
	 */
	function update_item($request)
	{
		$this->add_filter_permalinks();

		$post = $this->get_post($request['id']);

		if (is_wp_error($post)) {
			return $post;
		}

		$bbp_forum_id = get_post_meta($post->ID, '_bbp_forum_id', true);

		if (is_wp_error($bbp_forum_id)) {
			$bbp_forum_id = null;
		}

		$_POST = $request->get_params() + compact('bbp_forum_id');

		switch ($this->post_type) {
			case 'topic':
				if (current_user_can('unfiltered_html')) {
					$_POST['_bbp_unfiltered_html_topic'] = wp_create_nonce('bbp-unfiltered-html-topic_' . $post->ID);
				}

				$_REQUEST['_wpnonce'] = wp_create_nonce('bbp-edit-topic_' . $request['id']);
				bbp_edit_topic_handler('bbp-edit-topic');
				break;
			case 'reply':
				if (current_user_can('unfiltered_html')) {
					$_POST['_bbp_unfiltered_html_reply'] = wp_create_nonce('bbp-unfiltered-html-reply_' . $post->ID);
				}

				$_REQUEST['_wpnonce'] = wp_create_nonce('bbp-edit-reply_' . $request['id']);
				bbp_edit_reply_handler('bbp-edit-reply');
				break;
		}

		if (bbp_has_errors()) {
			return new WP_Error(
				bbpress()->errors->get_error_code(),
				bbpress()->errors->get_error_message(),
				['status' => 403]
			);
		}

		return new WP_Error(
			'control_structure_unreachable',
			__('Unreachable control structure reached.', 'bb-app'),
			['status' => 500]
		);
	}

	/**
	 * Force bbPress permalinks to REST endpoints for created posts.
	 */
	private function add_filter_permalinks(): void
	{
		add_filter('bbp_get_topic_permalink', function(string $topic_permalink, int $topic_id) {
			return rest_url("wp/v2/topics/{$topic_id}");
		}, 100, 2);

		add_filter('bbp_get_reply_url', function(string $reply_url, int $reply_id, string $redirect_to) {
			return rest_url("wp/v2/replies/{$reply_id}");
		}, 100, 3);
	}

	/**
	 * Normalize meta_query request param into WP_Query format.
	 */
	private function normalize_meta_query($raw): array
	{
		if (is_string($raw)) {
			$decoded = json_decode($raw, true);

			if (json_last_error() === JSON_ERROR_NONE) {
				$raw = $decoded;
			}
		}

		if (!is_array($raw)) {
			return [];
		}

		$relation = 'AND';

		if (!empty($raw['relation']) && is_string($raw['relation'])) {
			$upper = strtoupper($raw['relation']);
			if (in_array($upper, ['AND', 'OR'], true)) {
				$relation = $upper;
			}
		}

		$clauses = [];

		foreach ($raw as $key => $value) {
			if ($key === 'relation') {
				continue;
			}

			if (is_array($value)) {
				$clause = [];

				if (!empty($value['key'])) {
					$clause['key'] = (string) $value['key'];
				} elseif (!empty($value['meta_key'])) {
					$clause['key'] = (string) $value['meta_key'];
				}

				if (array_key_exists('value', $value)) {
					$clause['value'] = $value['value'];
				} elseif (array_key_exists('meta_value', $value)) {
					$clause['value'] = $value['meta_value'];
				}

				$this->set_compare_type_key_clauses($value, $clause, $clauses);
			}
		}

		if (empty($clauses)) {
			$single = [];

			if (!empty($raw['key']) || !empty($raw['meta_key'])) {
				$single['key'] = (string) ($raw['key'] ?? $raw['meta_key']);
			}

			if (array_key_exists('value', $raw) || array_key_exists('meta_value', $raw)) {
				$single['value'] = $raw['value'] ?? $raw['meta_value'];
			}

			$this->set_compare_type_key_clauses($raw, $single, $clauses);
		}

		if (empty($clauses)) {
			return [];
		}

		if (count($clauses) === 1) {
			return $clauses;
		}

		return array_merge(compact('relation'), $clauses);
	}

	/**
	 * @param array $value
	 * @param array $clause
	 * @param array $clauses
	 * @return void
	 */
	private function set_compare_type_key_clauses(
		array $value,
		array &$clause,
		array &$clauses
	): void {
		if (!empty($value['compare'])) {
			$clause['compare'] = strtoupper((string)$value['compare']);
		}

		if (!empty($value['type'])) {
			$clause['type'] = strtoupper((string)$value['type']);
		}

		if (!empty($clause['key'])) {
			$clauses[] = $clause;
		}
	}
}
