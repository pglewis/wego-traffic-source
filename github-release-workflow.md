# GitHub Auto-Updates Release Process

## Implementation Status

✅ **Core auto-update system complete** - `class-wego-plugin-updater.php`

- Updates check via GitHub API (12-hour cache)
- Plugin details popup with release notes
- System info section for debugging

## Future Enhancements (Optional)

- Branch-specific updates
- Private repo support (auth tokens)
- Beta channel
- Rollback capability

## GitHub Release Workflow

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
