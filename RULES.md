# Simple Theme Options — contributor rules

## Deploy to GitHub

- Work inside **`wp-content/plugins/simple-theme-options/`** (the plugin is its own Git repo).
- After **any** code, asset, README, Cursor rule, or integrator-docs change:
  - `git status` and inspect `git diff`
  - `git add …` intentional paths  
  - `git commit -m "$(cat <<'EOF'…EOF)"` — imperative subject (~≤72 chars)
  - **`git push origin main`**

## Canonical remote

- **`origin`:** **`git@github.com:hsbeauty39/simple-theme-options.git`**  
  Web: **https://github.com/hsbeauty39/simple-theme-options**  

Use **`git remote -v`** after a fork/changed remote.

Push requires a GitHub identity with **push** rights (maintainer **`hsbeauty39`** or a collaborator).

- **2026-05-16 — Premium fields:** DynamicObject, AdvancedRepeater, GoogleMap, CodeEditor, Tabs, Accordion, Group are Pro-only; check with `topten_sto()->can_use_premium_code__premium_only()` / `sto_can_use_premium_fields()`. Locked rows each show the full gradient upsell card (field name + type label + CTA) via **`PremiumFieldGate::render_controls_or_locked_placeholder( $title, $type )`**. Groups render free children when locked; Theme Settings samples use **one root group per section** and standalone premium field registration.
- **2026-05-16 — Typography Select2:** Ship **`assets/admin/vendor/select2/`** (4.0.13); without it **`jQuery.fn.select2`** is missing. Use **`stoRefreshTypographySelect2`** after visibility / fieldset enable (**`sto-typography.js`**, **`main.js`**). **`initTypographySelectsInWrap`** must not destroy/re-init an already-attached control (fixes dropdown flash-close on click); **`refreshTypographySelect2`** skips while **`.select2-container--open`**.
- **2026-05-16 — Theme Settings reset:** Footer **Reset section** / **Reset all fields** (confirm → POST). **`Menu::maybe_handle_reset_request()`** + **`ThemeSettingsDefaults`** restore registration defaults into **`sto_options`**; all-fields redirect drops **`section`** from the URL.

## Persist “always do …” instructions (new chats)

When the conversation defines **lasting** plugin policy (integrations, branching, forks, QA, naming, deployments):

1. Update **`.cursor/rules/simple-theme-options.mdc`** checklist **§§12–13** and related sections (+ **README**, `docs/simple-theme-options/` when checklist §2 says so — see rule file §2 **Docs** block).
2. Update **this file** (`RULES.md`) with a dated one-line bullet if it helps scanning.
3. **Commit + push** with the implementation or immediately after — **fresh Cursor chats** must read obligations from Git, not memory.

Full binding detail: **`.cursor/rules/simple-theme-options.mdc`** (Mandatory agent checklist, **Architecture**, **Git workflow**).
