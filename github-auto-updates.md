# DIY Plugin Update System from GitHub

## Implementation Phases

### Phase 1: Core Update Checking (2-3 hours)

1. Create `includes/class-plugin-updater.php`
2. Hook `pre_set_site_transient_update_plugins`
3. Fetch latest release from GitHub API: `https://api.github.com/repos/username/repo/releases/latest`
4. Compare versions, add to transient if newer
5. Test update flow

### Phase 2: Caching (1 hour)

1. Cache API responses in transients (12-24 hour expiry)
2. Error handling for failed requests
3. Manual check option (flush cache)

**Rate limit:** 60 requests/hour unauthenticated

### Phase 3: Plugin Details Popup (2-3 hours)

1. Hook `plugins_api` filter
2. Parse release notes from GitHub
3. Format for WP plugin info display

### Phase 4: Optional Advanced Features

- Branch-specific updates
- Private repo support (auth tokens)
- Beta channel
- Rollback capability

## Basic Code Structure

```php
// Main plugin file
require_once plugin_dir_path(__FILE__) . 'includes/class-plugin-updater.php';

if (is_admin()) {
    new Your_Plugin_Updater(__FILE__, 'username', 'repo');
}
```

## GitHub Release Workflow

### Understanding Tags

Tags are Git pointers to specific commits - they mark release points in your history. They're immutable (unlike branches).

### Creating Your First Tag

```bash
# Check current version in your plugin header
# Let's say it's 2.1.0

# Tag the current commit
git tag 2.1.0

# Or tag with a message (annotated tag - recommended)
git tag -a 2.1.0 -m "Version 2.1.0 - Initial release"

# Push the tag to GitHub
git push origin 2.1.0

# Or push all tags at once
git push --tags
```

### Starting with No Existing Tags

No problem - just tag your current state as your first release:

```bash
# Tag current commit as your starting version
git tag -a 2.1.0 -m "Initial tagged release"
git push origin 2.1.0
```

### Creating Subsequent Releases

```bash
# Make your changes, commit them
git add .
git commit -m "Fix bug in tracking"

# Tag the new version
git tag -a 2.1.1 -m "Version 2.1.1 - Bug fixes"
git push origin 2.1.1
```

### Tag Format

**Use straight semver with no prefix: `1.0.0`**

This matches your plugin header exactly (`Version: 1.0.0`) and requires no string manipulation in your updater code. Simpler and less fragile.

### Viewing Tags

```bash
# List all tags
git tag

# Show tag details
git show 2.1.0

# List tags on GitHub (remote)
git ls-remote --tags origin
```

### GitHub Releases (Optional but Recommended)

After pushing a tag, create a release on GitHub:

1. Go to repo → Releases → "Create a new release"
2. Select your tag
3. Add release notes/changelog
4. Publish

This gives you a nice UI and the API returns release notes your updater can display.

### Common Mistakes to Avoid

- Forgetting to push tags (`git push` doesn't push tags by default)
- Mismatched version between tag and plugin header
- Adding a `v` prefix to tags (stick with plain semver)

## Testing Checklist

- [ ] Update notification appears
- [ ] Version displays correctly
- [ ] Download/install works
- [ ] No PHP errors
- [ ] Caching works
- [ ] Graceful failure when GitHub down

## Make Reusable

Build as standalone class with configurable:
- Plugin file path
- GitHub username
- Repo name
- Optional: branch, auth token

Drop into any plugin, initialize with repo details.

## Key WordPress APIs

- `pre_set_site_transient_update_plugins` - inject update info
- `plugins_api` - plugin details popup
- `wp_remote_get()` - API requests
- `set_transient()` / `get_transient()` - caching
- `version_compare()` - version checking