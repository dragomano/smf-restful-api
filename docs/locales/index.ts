import type { LocaleConfig } from '@markup-carve/carve-press'
import en from './en.ts'
import ru from './ru.ts'

export const locales: Record<string, LocaleConfig> = {
  '/': en,
  '/ru/': ru,
}
