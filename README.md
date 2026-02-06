# WP Git Sync

WP Git Sync is a WordPress plugin that syncs post content + meta to a GitHub branch.

> Status: early scaffold (v0.2). **In-memory GitHub API only** (no local filesystem writes, no `git` CLI).

## What it does (P0)

- Configures a GitHub repo + branch (default: `wp-content-sync`)
- Exports posts/pages to deterministic paths:
  - `<post_type>/<post_id>-<slug>.md`
  - `meta/<post_type>/<post_id>-<slug>.json`
- Commits changes via the GitHub Git Data API:
  - create blobs → create tree → create commit → update branch ref
- Stores per-post sync state in **post meta** (no mapping file):
  - repo, branch, paths, hashes, last commit sha, last synced time
- Adds a per-post metabox with sync status + “Sync this post now”

## Assumptions / requirements

- GitHub is `github.com` (no Enterprise support yet).
- Authentication:
  - PAT (supported)
  - OAuth connect flow (UI scaffold only in v0; wiring up redirect next)
- The plugin stores settings in `wp_options` (`wpgs_settings`).
  - Prefer defining `WPGS_GITHUB_TOKEN` in `wp-config.php` in production.

## Setup

1. Install the plugin (copy into `wp-content/plugins/wp-git-sync/`).
2. Activate it in WordPress.
3. Go to **Settings → WP Git Sync** and set:
   - Repo (`owner/repo` or `https://github.com/owner/repo`)
   - Branch
   - PAT token (or `WPGS_GITHUB_TOKEN` constant)
4. Go to **Tools → WP Git Sync** and click **Export all posts/pages now**.

## Notes

- Exported content is currently raw `post_content`.
- Meta export uses `get_post_meta( $post_id )` (all meta for now) and excludes internal `_wpgs_*` keys.
- Meta export is filterable via `wpgs_export_postmeta`.

## Roadmap

Next:
- Wire up GitHub OAuth redirect flow (“Connect to GitHub”)
- Add rate-limit/backoff handling + batching for large initial exports
- Add diff preview in the metabox
