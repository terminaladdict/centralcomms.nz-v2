import { readFile, writeFile } from 'node:fs/promises';

const file = 'public/assets/data/updates.json';
const raw = await readFile(file, 'utf8');
const data = JSON.parse(raw);
const updates = Array.isArray(data.updates) ? data.updates : [];

let changed = false;

function normalizeYouTubeIframeAttrs(html) {
  return html.replace(/<iframe\b[^>]*>/gi, (tag) => {
    if (!/\bsrc\s*=\s*["'][^"']*youtube(?:-nocookie)?\.com\/embed\//i.test(tag)) {
      return tag;
    }

    const attrs = [];
    const matches = tag.matchAll(/\s([^\s=/>]+)(?:\s*=\s*("([^"]*)"|'([^']*)'|([^\s>]+)))?/gi);
    for (const match of matches) {
      const originalName = match[1];
      const name = originalName.toLowerCase();
      const value = match[3] ?? match[4] ?? match[5] ?? '';
      if (name === 'src' || name === 'width' || name === 'height' || name === 'allow' || name === 'title') {
        attrs.push(value === '' ? originalName : `${originalName}="${value}"`);
      } else if (name === 'allowfullscreen') {
        attrs.push('allowfullscreen');
      }
    }

    const normalized = `<iframe${attrs.length ? ` ${attrs.join(' ')}` : ''}></iframe>`;
    if (normalized !== tag) changed = true;
    return normalized;
  });
}

for (const update of updates) {
  const original = String(update.content_html ?? '');
  const normalized = normalizeYouTubeIframeAttrs(original);
  if (normalized !== original) {
    update.content_html = normalized;
    changed = true;
  }
}

if (changed) {
  await writeFile(file, `${JSON.stringify(data, null, 2)}\n`, 'utf8');
  console.log('Normalized legacy update HTML in public/assets/data/updates.json');
}
