# ReleaseWP

A minimal WordPress plugin that receives changelog updates from GitHub via REST API and automatically publishes them as WordPress posts.

## What It Does

ReleaseWP creates a REST API endpoint on your WordPress site that accepts changelog content in Markdown format. When triggered (typically via GitHub Actions), it:

1. Receives a title and Markdown content via POST request
2. Converts the Markdown to HTML using Parsedown
3. Sanitizes the content for security
4. Creates a new WordPress post with the custom post type `update`
5. Returns success/error status

**Perfect for**: Automatically publishing release notes, changelogs, or version updates from your GitHub repository to your WordPress site.

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- A custom post type named `update` registered in WordPress
- WordPress user with `edit_posts` capability

## Installation

1. Upload the `releasewp` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure you have a custom post type named `update` registered (or modify line 47 in `releasewp.php` to use your preferred post type)

## GitHub Integration Setup

### Step 1: Create WordPress Application Password

1. Log into your WordPress admin dashboard
2. Go to **Users** → **Profile**
3. Scroll to **Application Passwords** section
4. Enter a name (e.g., "GitHub Actions")
5. Click **Add New Application Password**
6. Copy the generated password (you won't be able to see it again)

### Step 2: Configure GitHub Secrets

In your GitHub repository:

1. Go to **Settings** → **Secrets and variables** → **Actions**
2. Click **New repository secret**
3. Add two secrets:

   **WP_API_ENDPOINT**
   ```
   https://your-wordpress-site.com/wp-json/releasewp/v1/post-update
   ```

   **WP_API_TOKEN**
   ```
   your-username:your-application-password
   ```
   (Replace with your WordPress username and the application password from Step 1)

### Step 3: Create GitHub Action Workflow

Create `.github/workflows/update-wordpress.yml` in your repository:

```yaml
name: Update WordPress with Changelog

on:
  workflow_dispatch:
    inputs:
      version:
        description: 'Changelog Version'
        required: true
        default: '1.0.0'

jobs:
  updateWordPress:
    runs-on: ubuntu-latest
    steps:
    - name: Checkout Repository
      uses: actions/checkout@v3

    - name: Install jq
      run: sudo apt-get install jq

    - name: Extract Specific Changelog Block
      run: |
        VERSION_HEADER="## [${{ github.event.inputs.version }}]"
        VERSION_HEADER_ESCAPED=$(printf '%s\n' "$VERSION_HEADER" | sed -e 's/[]\/$*.^[]/\\&/g')
        lines_under_version_header=$(awk "/$VERSION_HEADER_ESCAPED/"' {p=1; next} p && /^## \[/ {p=0} p' CHANGELOG.md)
        echo "$lines_under_version_header" > specific-changelog.md

    - name: Convert Extracted Changelog to JSON
      run: |
        VERSION="${{ github.event.inputs.version }}"
        jq -Rs --arg version "$VERSION" '{title: ("Release " + $version), content: .}' specific-changelog.md > changelog.json

    - name: Update WordPress
      env:
        WP_API_ENDPOINT: ${{ secrets.WP_API_ENDPOINT }}
        WP_API_TOKEN: ${{ secrets.WP_API_TOKEN }}
      run: |
        echo "Updating WordPress..."
        curl -X POST $WP_API_ENDPOINT \
             -H "Authorization: Bearer $WP_API_TOKEN" \
             -H "Content-Type: application/json" \
             --data @changelog.json
```

### Step 4: Format Your CHANGELOG.md

Your `CHANGELOG.md` should follow this format:

```markdown
# Changelog

## [1.2.0] - 2024-01-15

### Added
- New feature X
- Enhancement Y

### Fixed
- Bug fix Z

## [1.1.0] - 2024-01-01

### Changed
- Updated something
```

## Usage

### Manual Trigger (Recommended)

1. Go to your GitHub repository
2. Click **Actions** tab
3. Select "Update WordPress with Changelog" workflow
4. Click **Run workflow**
5. Enter the version number (e.g., `1.2.0`)
6. Click **Run workflow**

The workflow will extract that version's changelog and post it to WordPress.

### Automatic Trigger on Release

To trigger automatically when creating a GitHub release, modify the workflow's `on:` section:

```yaml
on:
  release:
    types: [published]
  workflow_dispatch:
    inputs:
      version:
        description: 'Changelog Version'
        required: true
```

## API Endpoint Documentation

### POST `/wp-json/releasewp/v1/post-update`

**Authentication**: Bearer token (WordPress application password)

**Request Body**:
```json
{
  "title": "Release v1.2.0",
  "content": "## Changes\n- Feature added\n- Bug fixed"
}
```

**Response**:
- `200 OK`: `"Post Created"`
- `500 Internal Server Error`: `"Error creating post"`

## Testing

Test your endpoint with cURL:

```bash
curl -X POST https://your-site.com/wp-json/releasewp/v1/post-update \
     -H "Authorization: Bearer username:application_password" \
     -H "Content-Type: application/json" \
     -d '{"title":"Test Release","content":"## Test\n- Item 1\n- Item 2"}'
```

## Customization

### Change Post Type

Edit line 47 in `releasewp.php`:

```php
'post_type' => 'your_custom_type',
```

### Change Post Status

To publish as draft instead:

```php
'post_status' => 'draft',
```

### Add Custom Fields

After line 48, add:

```php
$post_id = wp_insert_post( $new_post );

if ( ! is_wp_error( $post_id ) ) {
    update_post_meta( $post_id, 'version_number', $version );
}
```

## Troubleshooting

**403 Forbidden Error**
- Check your application password is correct
- Verify the WordPress user has `edit_posts` capability

**404 Not Found**
- Ensure the plugin is activated
- Verify the endpoint URL matches: `/wp-json/releasewp/v1/post-update`

**500 Internal Server Error**
- Check if the `update` custom post type exists
- Review WordPress error logs

**Post Not Appearing**
- Verify the `update` post type is registered and visible in admin
- Check if posts are being created as drafts instead of published

## Security

- All content is sanitized using `wp_kses_post()` and `wp_strip_all_tags()`
- Requires WordPress authentication (application passwords)
- Only users with `edit_posts` capability can create posts
- Markdown is converted server-side to prevent XSS attacks

## License

This plugin is provided as-is for integration between GitHub and WordPress.

## Author

James Welbes

## Version

1.0
