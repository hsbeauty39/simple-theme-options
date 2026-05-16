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

## Persist “always do …” instructions (new chats)

When the conversation defines **lasting** plugin policy (integrations, branching, forks, QA, naming, deployments):

1. Update **`.cursor/rules/simple-theme-options.mdc`** checklist **§§12–13** and related sections (+ **README**, `docs/simple-theme-options/` when checklist §2 says so — see rule file §2 **Docs** block).
2. Update **this file** (`RULES.md`) with a dated one-line bullet if it helps scanning.
3. **Commit + push** with the implementation or immediately after — **fresh Cursor chats** must read obligations from Git, not memory.

Full binding detail: **`.cursor/rules/simple-theme-options.mdc`** (Mandatory agent checklist, **Architecture**, **Git workflow**).
