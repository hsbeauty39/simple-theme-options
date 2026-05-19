# Topten Simple Theme Options — contributor rules

## Regression safety (do not break other features)

**Standing user policy:** plugin or sample changes must not break unrelated Theme Settings flows, theme integrations, or storefront behavior.

- **Scope** — Prefer body-class / screen-specific CSS and context checks in PHP; avoid global selector or hook renames without grep and a migration note.
- **Verify** after substantive edits: Theme Settings save + section nav, Tools → Simple Settings export/import, one theme menu (e.g. UAEBattery), packaged demo samples when demo is on.
- **`php -l`** on every edited PHP file.
- Workspace: **`.cursor/rules/regression-safety.mdc`** (always applied in this Local install).

## Deploy to GitHub (mandatory after every change)

- Work inside **`wp-content/plugins/battery-simple-theme-options/`** (this fork’s git repo; do not use the wordpress.org **`simple-theme-options`** folder name).
- **WordPress.org zip:** build from plugin root respecting **`.distignore`** (excludes **`.cursor`**, **`tools/`**, **`includes/FreemiusBootstrap.php`**, **`vendor/freemius`**, dev markdown). Run Plugin Check against that zip, not the full dev tree, before review.
- **Standing policy:** any substantive plugin change (PHP, JS, CSS, assets, README, rules, sample fields) is **not done** until it is on **`origin/main`**. Always **`git commit`** then **`git push origin main`** in the same session — including doc-only and sample-copy tweaks.
- Ritual after each change set:
  - `git status` and inspect `git diff`
  - `git add …` intentional paths  
  - `git commit -m "$(cat <<'EOF'…EOF)"` — imperative subject (~≤72 chars)
  - **`git push origin main`**
  - Confirm clean tree (`git status`) and report the commit SHA to the user when they care about deploy status.

## Canonical remote

- **`origin`:** **`git@github.com:hsbeauty39/simple-theme-options.git`**  
  Web: **https://github.com/hsbeauty39/simple-theme-options**  

Use **`git remote -v`** after a fork/changed remote.

Push requires a GitHub identity with **push** rights (maintainer **`hsbeauty39`** or a collaborator).

- **2026-05-18 — Export backup JSON:** `ajax_export` boots sample field modules + `FieldRegistrationDeferral::flush()` so the registry is populated on `admin-ajax.php`. Tools **Export scope** requires ≥1 checked menu; JS shows server error text instead of generic “Could not create the export” when possible.
- **2026-05-18 — Plugin Check / text domain:** Plugin slug / **Text Domain** = **`topten-simple-theme-options`**. **`STO_TEXT_DOMAIN`** constant matches it (used for `load_plugin_textdomain()` only). **`__()` / `_n()` / `esc_html__()`** must pass the **literal** string `'topten-simple-theme-options'` (Plugin Check rejects a constant as the domain argument). Recommended install **folder** remains **`battery-simple-theme-options`** (`STO_PLUGIN_SLUG`). **PHPCS ruleset:** `docs/phpcs.xml` (not in org zip; avoids Plugin Check `application_detected` on `*.dist`). **Cursor rules:** workspace `.cursor/rules/topten-simple-theme-options-plugin.mdc` (plugin `.cursor/` removed so Plugin Check does not flag `ai_instruction_directory`). Run Plugin Check on a **distribution zip** before review; `.gitignore` / `.distignore` may still warn on a full dev tree scan.
- **2026-05-18 — Theme Settings SPA nav:** `main.js` resolves links with **`stoAbsAdminHref()`** against **`trailingslashit( admin_url() )`** (trailing slash required — without it, `new URL('admin.php', base)` becomes `/admin.php` at site root). **`pushState`** only on the main settings screen under **`/wp-admin/`**; from Freemius Contact/Pricing, **`#adminmenu`** uses **`window.location.href`**. Broken URLs auto-redirect via **`stoRecoverBrokenThemeSettingsUrl()`**.
- **2026-05-18 — Developer guide location:** HTML integrator bundle moved from plugin root to **`wp-content/plugins/instructions/`** (`instructions.html` + `scripts/`). Not shipped in plugin ZIP; deploy separately for live docs URL.
- **2026-05-17 — Tools screen label:** **Tools → Simple Settings** (`sto-simple-backup`); first sidebar section **Settings** (`section=backup`), not “Simple Backup” / “Backup”.
- **2026-05-17 — Premium panel banner:** When Pro is off, **`PremiumFieldGate::render_panel_banner()`** outputs a full-width upgrade alert + **Purchase Premium** CTA above the panel head on Theme Settings root screens; filter **`sto_show_premium_panel_banner`**.
- **2026-05-17 — Demo Groups & panels section:** Packaged **Theme Settings** menu adds leaf **`groups-panels`** (`?section=groups-panels`) — **Group**, **Advanced repeater**, and **Accordion** demos in `includes/Admin/Sample/Fields/GroupsPanels/GroupsPanels.php` (replaces standalone **Accordion** nav).
- **2026-05-17 — Demo Responsive section:** Packaged **Theme Settings** menu adds leaf **`responsive`** (`?section=responsive`) with all `'responsive' => true` sample fields moved from **Field samples** / **Accordion** into `includes/Admin/Sample/Fields/Responsive/Responsive.php`.
- **2026-05-17 — Freemius Upgrade styling:** Non-Pro sites load **`sto-freemius-upgrade.css`** (admin-wide) for gradient **Upgrade** / **Start trial** WP submenu rows; Theme Settings sidebars append **`PremiumFieldGate::render_sidebar_upgrade_cta()`** (gradient **Upgrade to Pro** → Freemius **`pricing_url()`**; **`main.js`** skips SPA `pushState` for that link). Locked field CTAs in **`sto-premium-locked.css`** use the same horizontal purple→orange gradient.
- **2026-05-17 — Freemius Contact Us:** `StoFreemiusContact` filters `templates/contact.php` — STO hub + topic cards open the standalone Freemius form (no wp-admin iframe). CSS: **`sto-freemius-contact.css`**.
- **2026-05-17 — Freemius Plans & Pricing:** `StoFreemiusPricing` filters `templates/pricing.php` — STO panel header + purple design tokens on `#fs_pricing_app`; compact gradient **Upgrade** CTA. CSS: **`sto-freemius-pricing.css`**; body class **`sto-fs-pricing-screen`**.
- **2026-05-17 — Gradient dock color picker:** Dock portaled to **`document.body`**; **`click.stoGradOutside`** ignores the open dock. Iris **`.iris-picker-inner { position: relative }`** (in-flow, same as palette UI) so square/strips do not overlap **`.sto-gradient-dock-palette`**. **`sto-color.css`** does not style the dock.
- **2026-05-17 — Packaged demo + Freemius:** **Theme Settings** top-level menu **always** registers; demo mode only toggles sample nav/fields. Freemius defaults to **`theme-settings`** (`sto_freemius_menu_slug` filter optional).
- **2026-05-19 — Rich modern editor:** New premium field type **`rich_modern_editor`** (`RichModernEditor`) — Gutenberg block editor in Theme Settings / groups / tabs / accordions; stores serialized blocks; assets **`sto-rich-modern-editor`**; boot **`RichModernEditor::instance()`** before register. **Integrator docs:** **`wp-content/plugins/instructions/instructions.html`** § **6.14a**, PHP sample **`#php-rich_modern_editor`**.
- **2026-05-19 — Rich modern editor in advanced repeater:** **`advanced_repeater`** leaves may use **`rich_modern_editor`**; mount on row expand/add. **`sto-rich-modern-editor.js`** uses **`BlockList`** only (no extra **`DefaultBlockAppender`**). WC product-data + adv-rep CSS keep inputs/editor inside repeater cards (`min-width: 0`, `max-width: 100%`). **`.sto-rich-modern-editor__input`** stays screen-reader hidden in repeater rows (exclude from **`.sto-adv-rep__input`** width rules). Image library/upload: register **`editor.MediaUpload`** → **`wp.mediaUtils.MediaUpload`**, set **`settings.mediaUpload`**, deps **`wp-media-utils`** + **`wp_enqueue_media()`**.
- **2026-05-19 — WC product data save:** **`stoPrepareWcProductDataPanelsForSubmit()`** in **`main.js`** syncs advanced repeater JSON + enables fieldsets before **`#post`** submit; values persist to **`_sto_theme_settings_post`**. **`AdvancedRepeaterControl`** drops empty rows on save and skips them for **`html_required`** (avoids blank placeholder rows blocking Product Tabs save).
- **2026-05-19 — Instructions bundle policy:** Any new/changed STO field must update **`wp-content/plugins/instructions/`** in the same change set (see workspace **`.cursor/rules/sto-instructions-field-docs.mdc`**).
- **2026-05-16 — Premium fields:** DynamicObject, AdvancedRepeater, GoogleMap, CodeEditor, RichModernEditor, Tabs, Accordion, Group are Pro-only; check with `topten_sto()->can_use_premium_code__premium_only()` / `sto_can_use_premium_fields()`. Locked rows each show the full gradient upsell card (field name + type label + CTA) via **`PremiumFieldGate::render_controls_or_locked_placeholder( $title, $type )`**. Groups render free children when locked; Theme Settings samples use **one root group per section** and standalone premium field registration.
- **2026-05-16 — Typography Select2:** Ship **`assets/admin/vendor/select2/`** (4.0.13); without it **`jQuery.fn.select2`** is missing. Use **`stoRefreshTypographySelect2`** after visibility / fieldset enable (**`sto-typography.js`**, **`main.js`**). **`initTypographySelectsInWrap`** must not destroy/re-init an already-attached control (fixes dropdown flash-close on click); **`refreshTypographySelect2`** skips while **`.select2-container--open`**.
- **2026-05-16 — Theme Settings reset:** Footer **Reset section** / **Reset all fields** (confirm → POST). **`Menu::maybe_handle_reset_request()`** + **`ThemeSettingsDefaults`** restore registration defaults into **`sto_options`**; all-fields redirect drops **`section`** from the URL.
- **2026-05-16 — Sample field copy:** Demo registrations under **`includes/Admin/Sample/`** keep **`description`** to **≤10 words** (long API/theme docs removed).
- **2026-05-16 — Always push:** Every agent/user session that changes this plugin must end with **`git push origin main`** (see **Deploy to GitHub** above). Persisted in **`.cursor/rules/simple-theme-options.mdc`** checklist §12.

## Persist “always do …” instructions (new chats)

When the conversation defines **lasting** plugin policy (integrations, branching, forks, QA, naming, deployments):

1. Update **`.cursor/rules/simple-theme-options.mdc`** checklist **§§12–13** and related sections (+ **README**, `wp-content/plugins/instructions/` when checklist §2 says so — see rule file §2 **Docs** block).
2. Update **this file** (`docs/RULES.md`) with a dated one-line bullet if it helps scanning.
3. **Commit + push** with the implementation or immediately after — **fresh Cursor chats** must read obligations from Git, not memory.

Full binding detail: **`.cursor/rules/simple-theme-options.mdc`** (Mandatory agent checklist, **Architecture**, **Git workflow**).
