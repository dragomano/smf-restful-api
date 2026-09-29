import { defineConfig, sitemap, llmsTxt } from '@markup-carve/carve-press'
import { locales } from './locales/index.ts'
import { layouts } from './layouts.ts'

export default defineConfig({
  title: 'SMF RESTful API',
  hostname: 'https://dragomano.github.io',
  base: '/smf-restful-api/',
  srcDir: 'src',
  outDir: 'dist',
  publicDir: 'public',
  routeManifest: false,
  theme: {
    extraCss: ['./extra.css'],
  },
  shiki: {
    langs: ['apache', 'nginx', 'php', 'bash', 'yaml', 'json'],
  },
  themeConfig: {
    socialLinks: [
      { icon: 'github', link: 'https://github.com/dragomano/smf-restful-api' },
    ],
    lastUpdated: true,
    outline: { level: [2, 4] },
  },
  locales,
  layouts,
  extensions: [
    sitemap({ hostname: 'https://dragomano.github.io' }),
    llmsTxt({
      title: 'SMF RESTful API',
      summary: 'Documentation for SMF RESTful API — RESTful JSON API for SMF 2.1.',
    }),
  ],
})
