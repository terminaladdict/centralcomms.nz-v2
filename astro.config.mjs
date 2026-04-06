import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';
import mdx from '@astrojs/mdx';

export default defineConfig({
  site: 'https://www.centralcomms.nz',
  integrations: [sitemap(), mdx()],
  vite: {
    plugins: [tailwindcss()],
    server: {
      proxy: {
        '/assets/php': 'http://localhost:8001'
      }
    }
  },
  build: {
    format: 'file'
  },
  trailingSlash: 'never'
});
