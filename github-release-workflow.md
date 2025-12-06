# GitHub Auto-Updates Release Process

## GitHub Release Workflow

The plugin includes a built-in auto-update system that checks for updates on GitHub releases published from the default branch `master`.

### Release Steps

1. **Make sure master is clean with no changes**

2. **Checkout the release branch**
    - `git checkout release/x.y.z`

3. **Verify the version in the plugin header**
    - Check that the version in `wego-traffic-source.php` is correct

4. **Combine all commits into a single commit**
    - `git reset --soft master`
    - Stage all changes and commit

5. **Merge changes to master**
    - Merge the release branch with master

6. **Checkout master and delete the release branch**
    - `git checkout master`
    - `git branch -d release/x.y.z`

7. **Push upstream**

8. **Create a tag for the new release version**
   - Create an annotated tag: `git tag -a x.y.z -m "Release version x.y.z"`
   - Note: Use the exact MAJOR.MINOR.PATCH from the plugin header, without a `v` prefix

9. **Push tags**
   - Push all tags: `git push --tags`

10. **Create a GitHub release based on the new tag**
    - Go to the repository on GitHub
    - Navigate to **Releases** → **"Draft a new release"**
    - Select the tag you just pushed
    - Add a title (e.g., "Release x.y.z")
    - Add release notes/changelog describing the changes
    - **Important:** Make sure **"Set as the latest release"** is checked
    - Click **"Publish release"**

### Post-release

1. **Create release branch for the next version**
    - `git checkout -b release/x.y.x`

2. **Bump the plugin version**
    - Edit `wego-traffic-source.php` and set the version to the next release
    - Commit with "Version bump" message

### Common Mistakes to Avoid

- Forgetting to push tags (`git push` doesn't push tags by default - use `git push --tags`)
- Mismatched version between tag and plugin header
- Adding a `v` prefix to tags (stick with plain semver)
- Not bumping the version before tagging
