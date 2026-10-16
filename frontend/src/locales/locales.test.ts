import { expect, test } from 'vitest'
import ar from './ar.json'
import en from './en.json'

const keys = (o: object, p = ''): string[] =>
  Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' ? keys(v, `${p}${k}.`) : [`${p}${k}`]))

test('ar and en have the same keys', () => {
  expect(keys(ar).sort()).toEqual(keys(en).sort())
})
