# WordPress Format

Editor documentation packaged for use **inside a WordPress site**.

| File | Use |
|---|---|
| `editors-guide.html` | Standalone HTML page - open in a browser, or paste the `<body>` contents into a WordPress page via the HTML/Code block |
| `troubleshooting.html` | Same, for the troubleshooting guide |
| `editors-docs.wxr.xml` | WordPress import file - bulk-imports both documents as published Pages |

## Option 1 - Paste into a WordPress page

1. Open `editors-guide.html` in your browser; confirm it renders correctly.
2. In WordPress, create a new Page titled **"Editor's Guide"**.
3. Add a **Custom HTML** block and paste the contents **between the `<body>` tags** of the HTML file.
4. Publish. Repeat for `troubleshooting.html`.

This is the quickest path if you only need one or two pages.

## Option 2 - Import as WordPress pages via WXR

1. In **WP Admin**, go to **Tools -> Import** and install the **WordPress** importer if it isn't already installed.
2. Click **Run Importer**, upload `editors-docs.wxr.xml`.
3. Assign the imported author to an existing user. **Do NOT** check "Download and import file attachments" (there are none).
4. Both pages are created as published pages with slugs `editors-guide` and `troubleshooting`.

## Regenerating these files

The HTML and WXR files are generated from `../EDITORS_GUIDE.md` and `../TROUBLESHOOTING.md`. See `../build-docs.mjs` for the build script.

```
npm install --no-save marked
node docs/editors/build-docs.mjs
```
