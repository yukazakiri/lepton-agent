# Lepton documentation

Standalone Astro Starlight site. Requires Node.js >=22.22.0 and npm >=9.6.5.

```bash
cd docs
npm ci
npm run dev
npm run check
npm run build
npm run preview
```

Default path: `/lepton-agent/`. Build output: `dist/` (ignored).
For custom hosting: `DOCS_SITE=https://docs.example.com DOCS_BASE=/ npm run build`.

Content: `src/content/docs/`. Configuration: `astro.config.mjs`.
See `src/content/docs/project/documentation.md` for hosting and contributor notes.
GitHub Pages deployment is manual; hosting is not enabled by creating these files.
