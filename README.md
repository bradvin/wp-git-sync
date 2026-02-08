# WP Git Sync

Contributors: bradvin, foobender
Tags: git, github, sync
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WordPress content and metadata to GitHub.

## What it does

- Configures a GitHub repo (`owner` + `repo`) + branch (default: `main`)
- Exports posts/pages to deterministic content/data/meta files
- Maintains a mapping file at `wp-git-sync/mapping.json` in the repo
- Generates/updates a deterministic repo-root `README.md` index in the repo
- Writes changes via the GitHub **Git Data API** in a single commit per export
- Adds a per-post metabox with sync status + “Sync this post now”

## Deterministic file layout

Folder naming rule: **folder name MUST always equal `post_type`**.

- Mapping file:
  - `wp-git-sync/mapping.json`
- Per-post files (all in the same post-type folder):
  - `<post_type>/<post_id>.md` (raw `post_content`)
  - `<post_type>/<post_id>.json` (post table row data, excluding `post_content`)
  - `<post_type>/<post_id>.meta.json` (post meta only)

## Repo root README index

On every export, the plugin regenerates the repo-root `README.md` (fully deterministic) listing synced posts.
Format:

- Section per type (Pages, Posts, Other)
- Bullet: `[Title](permalink) — [file](relative/path.md)`

## Auth

### Fine-grained PAT

wp-config.php mode:

```php
define( 'WPGS_GITHUB_PAT', 'github_pat_...' );
```

Settings mode:
- In **Settings → WP Git Sync**, paste the token into **GitHub PAT token**
- Save settings
- After PAT is saved, the **GitHub repo** field appears as a dropdown populated from your accessible repos

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
   - GitHub PAT token (or set `WPGS_GITHUB_PAT` in `wp-config.php`)
   - GitHub repo (from dropdown)
   - Branch
4. Go to **Tools → WP Git Sync** and click **Export all posts/pages now**.

## Notes

- Exported content is raw `post_content` (no block serialization transforms).
- Post data export is sourced from `wp_posts` fields (excluding `post_content`).
- Meta export is sourced from `get_post_meta( $post_id )` only.
- Meta blacklist defaults to excluding `_edit_lock`.
  - Additional excluded keys can be provided via the `wpgs_export_postmeta_blacklist` filter.