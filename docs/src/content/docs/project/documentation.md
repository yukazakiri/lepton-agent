---
title: Documentation development and deployment
description: Build the standalone Astro Starlight site and publish the static artifact under the correct base path.
sidebar:
  order: 3
---

Documentation source lives in repository `docs/`; generated static site lives in
`docs/dist/` and is ignored. PHP consumers do not need documentation dependencies.
Site uses Astro Starlight for navigation, search, syntax highlighting, responsive
layout, theme switching, and edit links.

Dependency lockfile is committed for reproducible builds. A scoped npm override
updates `postcss-nested`'s `postcss-selector-parser` to the patched 7.1.6+ line
for GHSA-rj75-hqrm-r3gf; review/remove it when upstream Expressive Code updates
its dependency. Sharp uses 0.35.5+ for patched image-processing dependencies.

## Local workflow

Node.js 22.22+ and npm 9.6.5+ required. From repository root:

```bash
cd docs
npm ci
npm run dev
```

Default local URL includes `/lepton-agent/` base path. Follow URL printed by
Astro. Before opening a pull request:

```bash
npm run check
npm run build
npm run preview
```

`check` runs Astro/TypeScript diagnostics; `build` produces static HTML and
Pagefind search index. `preview` serves built artifact, not production hosting.
If Sharp installation tries to compile against incompatible global libvips,
retry installation with `SHARP_IGNORE_GLOBAL_LIBVIPS=1 npm ci`.

## Hosting settings

Default configuration targets GitHub project Pages:

- Site origin: `https://yukazakiri.github.io`
- Base path: `/lepton-agent`
- Output: `docs/dist/`

Build environment can override `DOCS_SITE` and `DOCS_BASE`. For a custom domain
hosted at root:

```bash
DOCS_SITE=https://docs.example.com DOCS_BASE=/ npm run build
```

For a subpath, set `DOCS_BASE=/your-project`. Upload the **contents of `dist/`**
to host serving that base. Do not publish source, node_modules, or application
`.env` files. No server runtime or credentials needed for static artifact.

## GitHub Pages

Repository includes docs validation workflow for pull requests and a separate
**manual** Pages deployment workflow. A maintainer must enable Pages with
**GitHub Actions** source in repository settings, then run “Deploy documentation”
from Actions on `main`. Workflow builds, uploads artifact, and deploys through
`github-pages` environment. Apply environment approval rules if required.

Workflow's default URL is a deployment target, not evidence the site is already
published. Actual publication requires repository permissions and a successful
workflow run. No automatic deploy occurs merely by opening a pull request.

## Edit content

Pages live in `src/content/docs/` as Markdown or MDX. Content loader and schema
live in `src/content.config.ts`; navigation and hosting settings in
`astro.config.mjs`. Use relative links between pages so custom base paths work.
Keep source examples synchronized with SDK behavior and keep live validation
limits visible.
