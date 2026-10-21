import { expect, test } from 'vitest'
import ar from './ar.json'
import en from './en.json'

const flat = (o: object, p = ''): [string, string][] =>
  Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' ? flat(v, `${p}${k}.`) : [[`${p}${k}`, v as string]]))
const keys = (o: object) => flat(o).map(([k]) => k)

test('ar and en have the same keys', () => {
  expect(keys(ar).sort()).toEqual(keys(en).sort())
})

test('Arabic copy has no Latin letters outside placeholders', () => {
  const allowed = ['console.ip']
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
})

test('the review wording rulings are in place', () => {
  const e = Object.fromEntries(flat(en))
  expect(e['enum.allowance_mode.share_of_wage']).toBe('Share of daily wage')
  expect(e['console.inactive']).toBe('Deactivated')
})
