# thebbapp/rest-api-bbpress

BBPress REST API implementation for BbApp. The library can be used independently of the full BbApp framework stack, making it reusable for any WordPress site that needs BBPress REST API functionality.

## Simplified Dependency Tree

```
thebbapp/rest-api-bbpress
└── thebbapp/rest-api-wordpress-base
    └── thebbapp/rest-api
```

## Installation

Install via Composer:

```bash
composer require thebbapp/rest-api-bbpress
```

## Requirements

- PHP >= 7.2.24
- WordPress with BBPress plugin installed and activated
- `thebbapp/rest-api-wordpress-base` >= 0.1.0

## Usage

This library extends the WordPress REST API to provide comprehensive BBPress forum, topic, and reply functionality. It adds REST endpoints, custom fields, meta queries, and anonymous posting support for BBPress.

### Basic Setup

Initialize and register the BBPress REST API in your WordPress plugin or theme:

```php
<?php

use BbApp\RestAPI\BbPress\BbPressRESTAPI;

// Create an instance
$bbpress_rest_api = new BbPressRESTAPI();

// Register hooks and filters (call on 'rest_api_init' hook)
add_action('rest_api_init', function() use ($bbpress_rest_api) {
    $bbpress_rest_api->register();
});

// Initialize post types and meta fields (call on 'init' hook or during plugin init)
$bbpress_rest_api->init();
```

### What This Library Provides

#### 1. REST API Endpoints for BBPress

The library automatically enables REST API support for BBPress post types:

- **Forums**: `/wp/v2/forums`
- **Topics**: `/wp/v2/topics`
- **Replies**: `/wp/v2/replies`

All endpoints support standard WordPress REST API operations (GET, POST, PUT, DELETE) with batch support.

#### 2. Custom REST Fields

The library adds several computed and meta fields to BBPress resources:

**For Forums:**
- `menu_order` - Menu order

**For Forums, Topics:**
- `_bbp_last_active_time` - UNIX timestamp of last activity
- `parent` - Parent post ID

**For Topics:**
- `_bbp_reply_count` - Number of replies in the topic
- `_bbp_forum_id` - The forum ID (in meta)

**For Replies:**
- `_bbp_reply_count` - Number of replies to this reply (nested replies)
- `_bbp_reply_to` - Parent reply ID

**For Anonymous Posts (when `bbp_allow_anonymous()` returns true):**
- `_bbp_anonymous_name` - Anonymous author name

#### 3. Enhanced Query Parameters

The library extends collection endpoints with BBPress-specific query parameters:

**Parent Filtering:**
```
// Get topics in forum 123
GET /wp/v2/topics?parent=123

// Get topics in multiple forums
GET /wp/v2/topics?parent[]=123&parent[]=456
```

**Meta Queries:**
```
// Simple meta query
GET /wp/v2/topics?meta_key=_bbp_forum_id&meta_value=123&meta_compare==

// Advanced meta query (JSON)
GET /wp/v2/replies?meta_query[0][key]=_bbp_reply_to&meta_query[0][value]=456&meta_query[0][compare]==
```

**Ordering by BBPress Fields:**
```
// Order forums by menu order
GET /wp/v2/forums?orderby=menu_order

// Order topics by last active time
GET /wp/v2/topics?orderby=_bbp_last_active_time
```

#### 4. Anonymous Posting Support

When BBPress anonymous posting is enabled (`bbp_allow_anonymous()` returns true), the library:

- Allows unauthenticated POST requests to `/wp/v2/topics` and `/wp/v2/replies`
- Accepts anonymous author name and email via meta fields
- Allows anonymous comments on topics and replies

#### 5. BBPress Handler Integration

The library uses BBPress's native handlers for creating and updating topics and replies, ensuring:

- Proper validation and sanitization
- BBPress hooks and filters are triggered
- Forum statistics are updated correctly
- Subscriptions and notifications work as expected

## Advanced Usage

### Extending the Library

You can extend `BbPressRESTAPI` to add additional functionality:

```php
<?php

use BbApp\RestAPI\BbPress\BbPressRESTAPI;

class MyCustomBbPressRESTAPI extends BbPressRESTAPI {
    public function register(): void {
        parent::register();

        // Add your custom REST fields, routes, or filters here
        register_rest_field('forum', 'my_custom_field', [
            'get_callback' => function($post) {
                return get_post_meta($post['id'], 'my_custom_meta', true);
            },
            'schema' => [
                'type' => 'string',
                'context' => ['view', 'edit']
            ]
        ]);
    }
}
```

## To keep BbApp development going, [donate here](https://thebbapp.com/donate)
