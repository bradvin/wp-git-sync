# WP Git Sync

WP Git Sync is a WordPress plugin that exports post content + meta into a Git repository branch, using deterministic file paths.

> Status: early scaffold (v0.1). Shell `git` adapter first.

## What it does (P0)

- Configures a repo + branch (default: `wp-content-sync`)
- Exports all posts/pages to:
  - `posts/<post_type>/<post_id>-<slug>.md`
  - `meta/<post_type>/<post_id>-<slug>.json`
- Maintains a mapping file at `wp-git-sync/mapping.json`
- Commits + pushes changes to the configured branch
- Adds a per-post metabox with sync status + “Sync this post now”

## Assumptions / requirements

- The WordPress server can run `git` via `proc_open()`.
- The server can authenticate to your Git remote via SSH (preferred) or HTTPS.
- The plugin stores settings in `wp_options` (`wpgs_settings`).
  - **Note:** HTTPS token storage in `wp_options` is not ideal for production. Prefer a constant or environment variable.

## Setup

1. Install the plugin (copy into `wp-content/plugins/wp-git-sync/`).
2. Activate it in WordPress.
3. Go to **Settings → WP Git Sync** and set:
   - Repo URL (SSH URL recommended: `git@github.com:org/repo.git`)
   - Branch (defaults to `wp-content-sync`)
   - Local clone path (defaults to `wp-content/wpgs-repo`)
4. Go to **Tools → WP Git Sync** and click **Export all posts/pages now**.

## Notes

- Exported content is currently raw `post_content` (no block serialization transforms yet).
- Meta export uses `get_post_meta( $post_id )` and includes a small post header.

## Roadmap

Next:
- Fetch remote version per-post
- Show diff
- Apply changes with capability + nonce
- On-save auto-push if the post was previously synced
