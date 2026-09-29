import type { LocaleConfig } from '@markup-carve/carve-press'

const en: LocaleConfig = {
  lang: 'en-US',
  label: 'English',
  title: 'SMF RESTful API',
  description:
    'RESTful JSON API for SMF 2.1 — routing, authentication, and extensibility via hooks.',
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/guide/getting-started' },
      { text: 'Extending', link: '/guide/extending/overview' },
    ],
    sidebar: {
      '/guide/': [
        {
          text: 'Guide',
          items: [
            { text: 'Getting Started', link: '/guide/getting-started' },
            { text: 'Clean URLs', link: '/guide/clean-urls' },
          ],
        },
        {
          text: 'Extending',
          items: [
            { text: 'Overview & Hooks', link: '/guide/extending/overview' },
            { text: 'Adding a Resource', link: '/guide/extending/adding-resource' },
            { text: 'Query & Controller', link: '/guide/extending/query' },
            { text: 'Authentication', link: '/guide/extending/authentication' },
            { text: 'Response Hooks', link: '/guide/extending/response-hooks' },
          ],
        },
      ],
    },
    editLink: {
      pattern: 'https://github.com/dragomano/smf-restful-api/edit/main/docs/:path',
      text: 'Edit this page',
    },
    labels: {
      search: 'Search',
      previous: 'Previous',
      next: 'Next',
      lastUpdated: 'Last updated',
      onThisPage: 'On this page',
      pageNotFound: 'Page not found',
      copy: 'Copy',
      copied: 'Copied',
      menu: 'Menu',
      skipToContent: 'Skip to content',
      version: 'Version',
      versionBanner: '',
    },
    footer: {
      message: '',
      copyright: 'Released under the MIT License.',
    },
  },
}

export default en
