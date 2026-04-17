#!/usr/bin/env node
// Regenerates the editor documentation in three formats from the markdown sources:
//   * HTML (docs/editors/wordpress/*.html) - standalone, styled, paste-ready for WordPress
//   * WXR (docs/editors/wordpress/editors-docs.wxr.xml) - WordPress Tools > Import payload
//   * PDF (docs/editors/pdf/*.pdf) - rendered via headless Chrome
//
// Run from the plugin root or this directory:
//   node docs/editors/build-docs.mjs

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';

const __dirname = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);

const SOURCES = [
  { md: 'EDITORS_GUIDE.md',   slug: 'editors-guide',   title: "Editor's Guide - Multilanguage Features" },
  { md: 'TROUBLESHOOTING.md', slug: 'troubleshooting', title: 'Troubleshooting - Multilanguage Features' },
];

const WP_DIR  = join(__dirname, 'wordpress');
const PDF_DIR = join(__dirname, 'pdf');
for (const d of [WP_DIR, PDF_DIR]) if (!existsSync(d)) mkdirSync(d, { recursive: true });

let marked;
try {
  ({ marked } = require('marked'));
} catch {
  console.error('marked is not installed. Run: npm install --no-save marked');
  process.exit(1);
}

const CSS = `
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         line-height: 1.6; color: #23282d; max-width: 860px; margin: 2em auto; padding: 0 1em; }
  h1, h2, h3, h4 { color: #1d2327; margin-top: 1.6em; }
  h1 { border-bottom: 2px solid #2271b1; padding-bottom: .3em; }
  h2 { border-bottom: 1px solid #dcdcde; padding-bottom: .2em; }
  code { background: #f0f0f1; padding: .1em .35em; border-radius: 3px; font-size: 92%; }
  pre { background: #f6f7f7; border: 1px solid #dcdcde; padding: 1em; overflow-x: auto; border-radius: 4px; }
  pre code { background: transparent; padding: 0; }
  blockquote { border-left: 4px solid #2271b1; background: #f0f6fc; margin: 1em 0;
               padding: .6em 1em; color: #1d2327; }
  table { border-collapse: collapse; margin: 1em 0; width: 100%; }
  th, td { border: 1px solid #dcdcde; padding: .5em .75em; text-align: left; vertical-align: top; }
  th { background: #f6f7f7; }
  a { color: #2271b1; }
  hr { border: 0; border-top: 1px solid #dcdcde; margin: 2em 0; }
`;

function renderHtml(mdText, title) {
  const body = marked.parse(mdText, { gfm: true, breaks: false });
  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>${escapeHtml(title)}</title>
<style>${CSS}</style>
</head>
<body>
${body}
</body>
</html>`;
}

function escapeHtml(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Render each doc to HTML
const rendered = SOURCES.map(src => {
  const mdPath = join(__dirname, src.md);
  const md = readFileSync(mdPath, 'utf8');
  const html = renderHtml(md, src.title);
  const htmlPath = join(WP_DIR, `${src.slug}.html`);
  writeFileSync(htmlPath, html, 'utf8');
  console.log('wrote', htmlPath);
  return { ...src, html, htmlPath, bodyHtml: marked.parse(md, { gfm: true, breaks: false }) };
});

// Render WXR import file (both docs as WordPress pages)
const pubDate = new Date().toUTCString();
const isoDate = new Date().toISOString().replace('T', ' ').replace(/\..*$/, '');
const items = rendered.map((d, i) => `
  <item>
    <title>${escapeHtml(d.title)}</title>
    <link>https://example.com/${d.slug}/</link>
    <pubDate>${pubDate}</pubDate>
    <dc:creator><![CDATA[admin]]></dc:creator>
    <guid isPermaLink="false">https://example.com/?page_id=${9000 + i}</guid>
    <description></description>
    <content:encoded><![CDATA[${d.bodyHtml}]]></content:encoded>
    <excerpt:encoded><![CDATA[]]></excerpt:encoded>
    <wp:post_id>${9000 + i}</wp:post_id>
    <wp:post_date><![CDATA[${isoDate}]]></wp:post_date>
    <wp:post_date_gmt><![CDATA[${isoDate}]]></wp:post_date_gmt>
    <wp:comment_status><![CDATA[closed]]></wp:comment_status>
    <wp:ping_status><![CDATA[closed]]></wp:ping_status>
    <wp:post_name><![CDATA[${d.slug}]]></wp:post_name>
    <wp:status><![CDATA[publish]]></wp:status>
    <wp:post_parent>0</wp:post_parent>
    <wp:menu_order>${i}</wp:menu_order>
    <wp:post_type><![CDATA[page]]></wp:post_type>
    <wp:post_password><![CDATA[]]></wp:post_password>
    <wp:is_sticky>0</wp:is_sticky>
  </item>`).join('\n');

const wxr = `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wfw="http://wellformedweb.org/CommentAPI/"
  xmlns:dc="http://purl.org/dc/elements/1.1/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
  <title>Simple Translation Manager - Editor Documentation</title>
  <link>https://example.com</link>
  <description>Editor-facing documentation for the Simple Translation Manager plugin.</description>
  <pubDate>${pubDate}</pubDate>
  <language>en-US</language>
  <wp:wxr_version>1.2</wp:wxr_version>
  <wp:base_site_url>https://example.com</wp:base_site_url>
  <wp:base_blog_url>https://example.com</wp:base_blog_url>
  <wp:author>
    <wp:author_id>1</wp:author_id>
    <wp:author_login><![CDATA[admin]]></wp:author_login>
    <wp:author_email><![CDATA[admin@example.com]]></wp:author_email>
    <wp:author_display_name><![CDATA[admin]]></wp:author_display_name>
  </wp:author>
${items}
</channel>
</rss>
`;
const wxrPath = join(WP_DIR, 'editors-docs.wxr.xml');
writeFileSync(wxrPath, wxr, 'utf8');
console.log('wrote', wxrPath);

// PDF generation via headless Chrome
const chromeCandidates = [
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
];
const chrome = chromeCandidates.find(p => existsSync(p));
if (!chrome) {
  console.warn('Chrome/Edge not found - skipping PDF generation.');
  process.exit(0);
}

for (const d of rendered) {
  const pdfPath = join(PDF_DIR, `${d.slug}.pdf`);
  const htmlUrl = pathToFileURL(resolve(d.htmlPath)).href;
  try {
    execFileSync(chrome, [
      '--headless=new',
      '--disable-gpu',
      '--no-sandbox',
      `--print-to-pdf=${pdfPath}`,
      '--no-pdf-header-footer',
      htmlUrl,
    ], { stdio: 'inherit' });
    console.log('wrote', pdfPath);
  } catch (e) {
    console.warn(`PDF generation failed for ${d.slug}:`, e.message);
  }
}
