import { rm } from 'node:fs/promises';
import { join } from 'node:path';

const configFiles = [
  'contact-config.php',
  'notifications-auth-config.php',
  'updates-auth-config.php',
];

await Promise.all(configFiles.map((file) =>
  rm(join('dist', 'assets', 'php', file), { force: true })
));
