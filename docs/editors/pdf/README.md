# PDF Format

Offline-friendly PDF versions of the editor docs, for distribution to staff, printing, or inclusion in training handouts.

| File | Source |
|---|---|
| `editors-guide.pdf` | Built from `../EDITORS_GUIDE.md` |
| `troubleshooting.pdf` | Built from `../TROUBLESHOOTING.md` |

## Regenerating the PDFs

The PDFs are built from the markdown sources via the same script that produces the WordPress HTML. Chrome (or Edge) is used as the headless PDF renderer - no extra dependencies beyond Node and a local Chromium-based browser.

```
npm install --no-save marked
node docs/editors/build-docs.mjs
```

The script writes to `wordpress/` and `pdf/` and is safe to re-run - it overwrites existing outputs.
