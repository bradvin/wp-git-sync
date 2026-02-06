# WP Git Sync

WP Git Sync is a WordPress plugin that syncs WordPress post content + meta into a GitHub repository branch using deterministic file paths.

> Status: active development. As of Feb 2026 the plugin is **GitHub API-only** (no `proc_open`, no `git` CLI) and supports **Device Flow OAuth** and **fine-grained PATs**.

## What it does

- Configures a GitHub repo (`owner` + `repo`) + branch (default: `wp-content-sync`)
- Exports posts/pages to deterministic files
- Maintains a mapping file at `wp-git-sync/mapping.json` in the repo
- Generates/updates a deterministic repo-root `README.md` index in the repo
- Writes changes via the GitHub **Git Data API** in a single commit per export
- Adds a per-post metabox with sync status + “Sync this post now”

## Deterministic file layout (final)

Folder naming rule: **folder name MUST always equal `post_type`**.

- Mapping file:
  - `wp-git-sync/mapping.json`
- Content files:
  - `posts/<post_type>/<post_id>-<slug>.md`
- Meta files:
  - `meta/<post_type>/<post_id>-<slug>.json`

## Repo root README index

On every export, the plugin regenerates the repo-root `README.md` (fully deterministic) listing synced posts.
Format:

- Section per type (Pages, Posts, Other)
- Bullet: `[Title](permalink) — [file](relative/path.md)`

## Auth

### Device Flow OAuth

- Define your GitHub OAuth App client id in `wp-config.php`:

```php
define( 'WPGS_GITHUB_CLIENT_ID', 'Iv1.1234567890abcdef' );
```

- In **Settings → WP Git Sync** select **Device Flow OAuth** and click **Connect GitHub**.
- The settings page will show a user code + verification URL.
- After authorizing, click **Complete connection (poll)**.

Scopes:
- Public repos: `public_repo`
- Private repos: `repo`

### Fine-grained PAT

Modes:
- `pat_storage=wp_config` (preferred)
- `pat_storage=options`

wp-config.php mode:

```php
define( 'WPGS_GITHUB_PAT', 'github_pat_...' );
```

Required permissions for the selected repo:
- Contents: Read and write
- Metadata: Read-only

## Assumptions / requirements

- WordPress server can make outbound HTTPS requests to `api.github.com` and `github.com`.
- The configured token has permission to read/write repo contents.
- No shell execution required.

## Setup (current scaffold)

1. Install the plugin (copy into `wp-content/plugins/wp-git-sync/`).
2. Activate it in WordPress.
3. Go to **Settings → WP Git Sync** and set:
   - Repo URL
   - Branch
   - Local clone path
4. Go to **Tools → WP Git Sync** and click **Export all posts/pages now**.

## Notes

- Exported content is currently raw `post_content` (no block serialization transforms yet).
- Meta export uses `get_post_meta( $post_id )` and includes a small post header.

## Roadmap

Next:
- Replace git-shell adapter with a GitHub API adapter (Git Data API for batch commits)
- Device Flow OAuth + PAT (wp-config constant supported)
- Per-post remote fetch + diff + apply
- On-save auto-push if previously synced
