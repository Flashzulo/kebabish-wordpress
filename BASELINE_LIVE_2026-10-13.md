# Baseline: live 2026-10-13

This commit makes the repository equal to what runs on kebabishoslo.no (server export 2026-10-13 12:17, catering V2 deployed and tested by Saulo).

| Repo path | Server path |
|---|---|
| `/` (repo root) | `wp-content/themes/twentytwentyfive/` |
| `plugins/kebabish-catering/` | `wp-content/plugins/kebabish-catering/` |

`plugins/` is not part of the theme. Do not upload it into the theme folder.

From now on every change goes Git -> sha256 -> upload, so the repo stays equal to live.

Notes:
- `functions.php`: the guest coming-soon function that was in the repo is not on the live server; live coming-soon is handled outside the theme. The live file is kept as-is.
- Catering plugin is V2 (occasion advisor). `KC_VERSION` stays 1.1.8 on purpose (a bump runs `maybe_upgrade()` over all catering orders).
- Not in Git: database, WooCommerce products, WPCode snippets, uploads.
