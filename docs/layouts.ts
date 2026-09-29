import type { Layout } from '@markup-carve/carve-press'
import { LAYOUTS } from '@markup-carve/carve-press'

/**
 * The theme hardcodes the site-title link to the site root, so on a localized
 * page the logo sends readers back to the default locale. Rebind it to the
 * locale prefix the page is rendered under.
 */
function withLocaleHomeLink(layout: Layout): Layout {
  return (ctx) => {
    const html = layout(ctx)
    const prefix = ctx.locale?.prefix
    if (prefix === undefined || prefix === '/') return html
    return html.replace(
      /(<a class="site-title" href=")([^"]+)(")/,
      (_match, before, href, after) => `${before}${href.replace(/\/$/, '')}${prefix}${after}`,
    )
  }
}

export const layouts: Record<string, Layout> = Object.fromEntries(
  Object.entries(LAYOUTS).map(([name, layout]) => [name, withLocaleHomeLink(layout)]),
)
