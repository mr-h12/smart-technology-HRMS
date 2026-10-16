import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import ar from './locales/ar.json'
import en from './locales/en.json'

const read = () => {
  try {
    return localStorage.getItem('hrms-lang') === 'en' ? 'en' : 'ar'
  } catch {
    return 'ar'
  }
}

i18n.use(initReactI18next).init({
  resources: { ar: { translation: ar }, en: { translation: en } },
  lng: read(),
  fallbackLng: 'ar',
  interpolation: { escapeValue: false },
})

/** Keeps <html lang/dir> and the stored choice in sync with the active language. */
const apply = (lng: string) => {
  document.documentElement.lang = lng
  document.documentElement.dir = lng === 'ar' ? 'rtl' : 'ltr'
  try {
    localStorage.setItem('hrms-lang', lng)
  } catch {
    /* storage unavailable: language still applies for this session */
  }
}
apply(i18n.language)
i18n.on('languageChanged', apply)

export default i18n
