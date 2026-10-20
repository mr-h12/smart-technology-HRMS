import { expect, test } from 'vitest'
import ar from './ar.json'
import en from './en.json'

const flat = (o: object, p = ''): [string, string][] =>
  Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' ? flat(v, `${p}${k}.`) : [[`${p}${k}`, v as string]]))
const keys = (o: object) => flat(o).map(([k]) => k)
// i18next's plural suffixes (Intl.PluralRules categories); `_zero` is also i18next's own count-of-0 form in any language
const PLURAL = /_(zero|one|two|few|many|other)$/
const bases = (o: object) => [...new Set(keys(o).map((k) => k.replace(PLURAL, '')))].sort()

test('ar and en have the same keys, a counted key once whatever its plural forms', () => {
  expect(bases(ar)).toEqual(bases(en))
})

test('every counted key has each plural form its language needs', () => {
  for (const [lng, o] of [['ar', ar], ['en', en]] as const) {
    const all = keys(o)
    const needed = new Intl.PluralRules(lng).resolvedOptions().pluralCategories
    for (const base of new Set(all.filter((k) => PLURAL.test(k)).map((k) => k.replace(PLURAL, '')))) {
      expect(needed.filter((form) => !all.includes(`${base}_${form}`)), `${lng} ${base}`).toEqual([])
    }
  }
})

test('Arabic copy has no Latin letters outside placeholders', () => {
  const allowed = ['console.ip', 'attendance.source.imported', 'attendance.sheet.paste.hint', 'attendance.tab.import', 'attendance.import.file', 'attendance.import.badFile'] // product names and file types: IP, Excel, WhatsApp, .xlsx
  const latin = flat(ar).filter(([k, v]) => !allowed.includes(k) && /[A-Za-z]/.test(v.replace(/\{\{\w+\}\}/g, '')))
  expect(latin).toEqual([])
})

test('the owner wording rulings are in place', () => {
  const a = Object.fromEntries(flat(ar))
  expect(a['employee.pay_method']).toBe('طريقة صرف المرتب')
  expect(a['nav.group.admin']).toBe('إدارة النظام')
  expect(a['app.name']).toBe('سمارت تكنولوجي لإدارة الموارد البشرية')
  expect(a['enum.pay_method.bank']).toBe('تحويل بنكي')
  expect(a['settings.tab.leaveTypes']).toBe('أنواع الإجازات')
  expect(a['employee.code']).toBe('الرقم الوظيفي')
  expect(a['attendance.sheet.paste.hint']).toMatch(/Excel.*WhatsApp/) // P25: product names stay Latin
})

test('the review wording rulings are in place', () => {
  const e = Object.fromEntries(flat(en))
  expect(e['enum.allowance_mode.share_of_wage']).toBe('Share of daily wage')
  expect(e['console.inactive']).toBe('Deactivated')
})
