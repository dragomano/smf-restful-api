import type { LocaleConfig } from '@markup-carve/carve-press'

const ru: LocaleConfig = {
  lang: 'ru-RU',
  label: 'Русский',
  title: 'SMF RESTful API',
  description:
    'RESTful JSON API для SMF 2.1 — маршрутизация, аутентификация и расширяемость через хуки.',
  themeConfig: {
    nav: [
      { text: 'Руководство', link: '/ru/guide/getting-started' },
      { text: 'Расширение', link: '/ru/guide/extending/overview' },
    ],
    sidebar: {
      '/ru/guide/': [
        {
          text: 'Руководство',
          items: [
            { text: 'Начало работы', link: '/ru/guide/getting-started' },
            { text: 'Чистые URL', link: '/ru/guide/clean-urls' },
          ],
        },
        {
          text: 'Расширение',
          items: [
            { text: 'Обзор и хуки', link: '/ru/guide/extending/overview' },
            { text: 'Добавление ресурса', link: '/ru/guide/extending/adding-resource' },
            { text: 'Query и контроллер', link: '/ru/guide/extending/query' },
            { text: 'Аутентификация', link: '/ru/guide/extending/authentication' },
            { text: 'Хуки ответа', link: '/ru/guide/extending/response-hooks' },
          ],
        },
      ],
    },
    editLink: {
      pattern: 'https://github.com/dragomano/smf-restful-api/edit/main/docs/:path',
      text: 'Редактировать страницу',
    },
    labels: {
      search: 'Поиск',
      previous: 'Назад',
      next: 'Вперёд',
      lastUpdated: 'Обновлено',
      onThisPage: 'На этой странице',
      pageNotFound: 'Страница не найдена',
      copy: 'Скопировать',
      copied: 'Скопировано',
      menu: 'Меню',
      skipToContent: 'Перейти к содержимому',
      version: 'Версия',
      versionBanner: '',
    },
    footer: {
      message: '',
      copyright: 'Лицензия MIT.',
    },
  },
}

export default ru
