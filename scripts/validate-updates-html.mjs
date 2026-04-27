import { readFile } from 'node:fs/promises';

const data = JSON.parse(await readFile('public/assets/data/updates.json', 'utf8'));
const updates = Array.isArray(data.updates) ? data.updates : [];

const dangerousPatterns = [
  /<\s*(script|style|template|object|embed)\b/i,
  /\son[a-z]+\s*=/i,
  /javascript\s*:/i,
  /srcdoc\s*=/i,
  /data\s*:\s*text\/html/i,
];

const allowedIframeHosts = new Set([
  'www.youtube.com',
  'youtube.com',
  'www.youtube-nocookie.com',
  'youtube-nocookie.com',
]);
const allowedIframeAttrs = new Set(['src', 'width', 'height', 'allow', 'allowfullscreen', 'title']);

const failures = [];

function attrValue(tag, attr) {
  const match = tag.match(new RegExp(`\\s${attr}\\s*=\\s*("([^"]*)"|'([^']*)'|([^\\s>]+))`, 'i'));
  return match?.[2] ?? match?.[3] ?? match?.[4] ?? '';
}

for (const update of updates) {
  const slug = update.slug ?? '(missing slug)';
  const html = String(update.content_html ?? '');

  for (const pattern of dangerousPatterns) {
    if (pattern.test(html)) {
      failures.push(`${slug}: content_html matched unsafe pattern ${pattern}`);
    }
  }

  for (const match of html.matchAll(/<iframe\b[^>]*>/gi)) {
    const tag = match[0];
    const src = attrValue(tag, 'src');
    try {
      const url = new URL(src);
      if (url.protocol !== 'https:' || !allowedIframeHosts.has(url.hostname) || !url.pathname.startsWith('/embed/')) {
        failures.push(`${slug}: iframe src is not an allowed YouTube embed URL: ${src}`);
      }
    } catch {
      failures.push(`${slug}: iframe src is not a valid absolute URL: ${src}`);
    }

    for (const attrMatch of tag.matchAll(/\s([^\s=/>]+)(?:\s*=\s*("([^"]*)"|'([^']*)'|([^\s>]+)))?/gi)) {
      const name = attrMatch[1].toLowerCase();
      const value = attrMatch[3] ?? attrMatch[4] ?? attrMatch[5] ?? '';
      if (!allowedIframeAttrs.has(name)) {
        failures.push(`${slug}: iframe contains unsupported attribute ${name}`);
      }
      if (name === 'allowfullscreen' && value && value.toLowerCase() !== 'allowfullscreen') {
        failures.push(`${slug}: iframe allowfullscreen should be a boolean attribute, found value ${JSON.stringify(value)}`);
      }
    }
  }

  for (const match of html.matchAll(/<img\b[^>]*>/gi)) {
    const tag = match[0];
    const src = attrValue(tag, 'src');
    if (src.startsWith('//') || src.toLowerCase().startsWith('javascript:')) {
      failures.push(`${slug}: unsafe image src: ${src}`);
    }
  }
}

if (failures.length) {
  console.error('Unsafe update HTML detected:');
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
