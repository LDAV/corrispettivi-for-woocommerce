# corrispettivi-for-woocommerce

## Release workflow (GitHub + WordPress.org SVN)

1. Update version in both files:
- `corrispettivi-for-woocommerce.php` (`Version:` header)
- `readme.txt` (`Stable tag:`)

2. Commit and tag in Git:
```bash
git add corrispettivi-for-woocommerce.php readme.txt
git commit -m "Release 0.8.1"
git tag v0.8.1
git push origin main --tags
```

3. Deploy to WordPress.org SVN:
```bash
chmod +x scripts/deploy-wp-org-svn.sh
scripts/deploy-wp-org-svn.sh --slug corrispettivi-for-woocommerce --commit --message "Release 0.8.1"
```

### Notes
- The script validates that plugin header `Version`, `Stable tag`, and deploy version match.
- Distribution excludes are read from `.distignore`.
- Use `--dry-run` to preview SVN operations.
- SVN credentials can be provided using environment variables (recommended for CI):
```bash
export WPORG_SVN_USERNAME="your-username"
export WPORG_SVN_PASSWORD="your-app-password-or-password"
scripts/deploy-wp-org-svn.sh --slug corrispettivi-for-woocommerce --commit
```
- You can also pass credentials as flags (`--svn-username`, `--svn-password`), but env vars are safer than shell history.
