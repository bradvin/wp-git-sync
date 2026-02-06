# WP Git Sync

WP Git Sync is a WordPress plugin that syncs WordPress post content + meta into a GitHub repository branch using deterministic file paths.

> Status: early scaffold. The repo currently contains a **temporary shell git adapter**; Brad has approved a pivot to **GitHub API-only** (no `proc_open`, no `git` CLI) using **Device Flow OAuth** and/or a **fine-grained PAT**.

## What it does today

- Configures a repo remote URL + branch (default: `wp-content-sync`)
- Exports posts/pages to deterministic files
- Maintains a mapping file at `wp-git-sync/mapping.json`
- Generates/updates a deterministic repo-root `README.md` index
- Commits + pushes changes to the configured branch
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

## Auth (planned / approved direction)

### Option A: GitHub OAuth Device Flow (recommended UX)
Planned settings fields:
- `auth_mode=device_oauth`
- Store `device_token`, optional `device_refresh_token`, `token_expires_at`, `refresh_expires_at`
- Buttons: **Connect GitHub** (device flow) and **Disconnect**

Scopes:
- Public repos: `public_repo`
- Private repos: `repo`

### Option C: Fine-grained PAT
Planned settings fields:
- `auth_mode=pat`
- `pat_storage=options|wp_config`

Preferred storage (wp-config):

```php
define( 'WPGS_GITHUB_PAT', 'ghp_...' );
```

Repo permissions required:
- Contents: Read and write
- Metadata: Read-only

## Assumptions / requirements

Current scaffold (temporary):
- The WordPress server can run `git` via `proc_open()`.
- The server can authenticate to your Git remote via SSH or HTTPS.

Approved target architecture:
- WordPress server can make outbound HTTPS requests to `api.github.com`.
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
