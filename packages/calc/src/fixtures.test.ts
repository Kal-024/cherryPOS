import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { calculateSale } from './engine'
import type { SaleInput, SaleResult } from './types'

/**
 * Los mismos casos que corre PHPUnit en `apps/api`. Si estos dos conjuntos de
 * pruebas dejan de dar idéntico, el CI falla — que es el único mecanismo real
 * contra la divergencia de los dos motores.
 */

interface Fixture {
  name: string
  description: string
  input: SaleInput
  expected: SaleResult
}

interface Suite {
  suite: string
  cases: Fixture[]
}

const fixturesDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'fixtures')

const suites: Suite[] = readdirSync(fixturesDir)
  .filter((file) => file.endsWith('.json'))
  .sort()
  .map((file) => JSON.parse(readFileSync(join(fixturesDir, file), 'utf8')) as Suite)

it('encuentra las suites de fixtures', () => {
  expect(suites.length).toBeGreaterThan(0)
})

describe.each(suites)('$suite', ({ cases }) => {
  it.each(cases)('$name — $description', ({ input, expected }) => {
    expect(calculateSale(input)).toEqual(expected)
  })
})
