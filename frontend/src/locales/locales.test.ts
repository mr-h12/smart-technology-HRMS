import { expect, test } from 'vitest'
import ar from './ar.json'
import en from './en.json'

const keys = (o: object, p = ''): string[] =>
  Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' ? keys(v, `${p}${k}.`) : [`${p}${k}`]))

test('ar and en have the same keys', () => {
  expect(keys(ar).sort()).toEqual(keys(en).sort())
})

const flat = (o: object, p = ''): [string, string][] =>
  Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' ? flat(v, `${p}${k}.`) : [[`${p}${k}`, v as string]]))

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
})
