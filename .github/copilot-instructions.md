# ReleaseWP - AI Coding Instructions

## Project Overview
ReleaseWP is a minimal WordPress plugin that receives GitHub changelog updates via REST API and publishes them as WordPress posts. It's designed for webhook integration from GitHub releases/updates.

**Core Purpose**: Bridge GitHub changelog content to WordPress custom post types via authenticated REST endpoints.

## Architecture

### Single Entry Point Pattern
- `releasewp.php` - Main plugin file containing all business logic (60 lines total)
- Uses WordPress plugin namespace: `ReleaseWP`
- No classes, functions are namespaced within `ReleaseWP\` namespace

### REST API Endpoint
```
POST /wp-json/releasewp/v1/post-update
```

**Authentication**: Requires `edit_posts` capability (WordPress user must be logged in)

**Expected payload**:
```json
{
  "title": "Release v1.2.3",
  "content": "## Changes\n- Feature added\n- Bug fixed"
}
```

### Data Flow
1. REST request received at `/post-update` endpoint
2. Markdown content extracted from `content` parameter
3. Parsedown library converts markdown to HTML
4. `wp_kses_post()` sanitizes HTML output
5. Post created with type `'update'` (custom post type, must exist in WordPress)
6. Returns 200 on success, 500 on error

## Key Dependencies

### Parsedown Library
- Vendored copy in `Parsedown.php` (v1.7.4)
- Standalone markdown parser, no external dependencies
- Used in global namespace (not namespaced)
- Instantiated as: `new \Parsedown()`

**Important**: The plugin requires the `update` custom post type to be registered elsewhere in WordPress. It will NOT auto-create this post type.

## WordPress Integration Points

### Hooks Used
- `rest_api_init` - Registers the REST route on WordPress initialization

### WordPress Functions Called
- `register_rest_route()` - REST API registration
- `current_user_can('edit_posts')` - Permission check
- `wp_strip_all_tags()` - Sanitizes title
- `wp_kses_post()` - Sanitizes HTML content
- `wp_insert_post()` - Creates post in database
- `is_wp_error()` - Error checking
- `plugin_dir_path(__FILE__)` - Gets plugin directory path

## Development Conventions

### Code Style
- WordPress coding standards (tabs for indentation, Yoda conditions)
- Array syntax: Use `array()` not `[]` (PHP 5.x compatibility)
- Anonymous functions used for hooks
- Namespace separator: Use `\\` in string contexts (`__NAMESPACE__ . '\\handle_changelog_update'`)

### Security Patterns
1. **Always sanitize**: Use `wp_strip_all_tags()` for text, `wp_kses_post()` for HTML
2. **Permission checks**: Use `current_user_can()` in REST permission callbacks
3. **Escape output**: Parsedown output is passed through `wp_kses_post()` before database insertion

### Error Handling
- Functions return `WP_REST_Response` objects with HTTP status codes
- Check `is_wp_error()` after WordPress operations
- Minimal error messages (no detailed debugging info exposed via API)

## Extending the Plugin

### Adding New Endpoints
Follow the pattern in `rest_api_init` hook:
```php
register_rest_route(
    'releasewp/v1',
    '/your-endpoint',
    array(
        'methods'              => 'POST',
        'callback'             => __NAMESPACE__ . '\\your_function',
        'permissions_callback' => function () {
            return current_user_can( 'edit_posts' );
        },
    )
);
```

### Modifying Post Type
Change line 47: `'post_type' => 'your_custom_type'` - ensure the type is registered in WordPress first.

### Content Transformation
To modify markdown processing, adjust between lines 37-38:
```php
$Parsedown = new \Parsedown();
$content   = wp_kses_post( $Parsedown->text( $markdown_content ) );
```

## Testing Considerations

- **Environment**: Plugin is in Lando WordPress development environment (`/wp-content/plugins/releasewp`)
- **No unit tests**: Plugin lacks automated tests; test manually via REST API
- **Test endpoint**: Use tools like cURL, Postman, or WP-CLI to POST to `/wp-json/releasewp/v1/post-update`
- **Prerequisites**: Ensure `update` custom post type exists before testing

Example cURL test:
```bash
curl -X POST https://your-site.com/wp-json/releasewp/v1/post-update \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"title":"Test Release","content":"## Test\n- Item 1"}'
```

## Common Modification Scenarios

1. **Change authentication**: Modify `permissions_callback` closure
2. **Add metadata**: Use `update_post_meta()` after `wp_insert_post()`
3. **Support different formats**: Replace Parsedown with alternative parser
4. **Add webhoo verification**: Implement signature validation in callback function
5. **Custom post status**: Change `'post_status' => 'draft'` instead of `'publish'`
