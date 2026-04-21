/* eslint-disable no-console */
/**
 * Parses "AADA Final Chart of accounts.xlsx" → seed data JSON.
 * Run: node backend/scripts/parse-reference-coa.cjs [path-to-xlsx]
 */
const pathMod = require('path')
const xlsxPath = pathMod.join(__dirname, '../../frontend/node_modules/xlsx')
const XLSX = require(xlsxPath)
const fs = require('fs')
const srcPath = process.argv[2] || 'c:/Users/AADA/OneDrive/Desktop/AADA Final Chart of accounts.xlsx'
const wb = XLSX.readFile(srcPath)
const ws = wb.Sheets['Chart of Accounts']
if (!ws) {
  console.error('Sheet "Chart of Accounts" not found')
  process.exit(1)
}
const data = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' })
const CODE_RE = /^\d+(\.\d+)*$/
const SEG_RE = /^(\d+(?:\.\d+)*)\s*[·.]\s*(.+)$/

function normalizeType(t) {
  const s = String(t || '')
    .toLowerCase()
    .trim()
  if (s === 'expenses' || s === 'expense') return 'expense'
  if (s === 'revenues' || s === 'revenue') return 'revenue'
  if (s === 'assets' || s === 'asset') return 'asset'
  if (s === 'liability' || s === 'liabilities') return 'liability'
  if (s === 'equity') return 'equity'
  return 'expense'
}

function normalizeNature(n) {
  const s = String(n || '')
    .toLowerCase()
    .trim()
  if (s === 'credit') return 'credit'
  if (s === 'debit') return 'debit'
  return 'debit'
}

const posting = []
const headerNames = {}

function mergePathNames(pathStr) {
  const parts = String(pathStr).split(': ').map((s) => s.trim())
  for (const p of parts) {
    const m = p.match(SEG_RE)
    if (m) {
      headerNames[m[1]] = m[2].trim()
    }
  }
}

for (let i = 2; i < data.length; i++) {
  const row = data[i]
  const a = String(row[0] || '').trim()
  const code = String(row[2] ?? '').trim()
  const name = String(row[3] || '').trim()
  if (a && !code) {
    mergePathNames(a)
  }
  if (CODE_RE.test(code) && name) {
    const gl = String(row[1] || '').trim()
    if (gl.includes(':')) {
      const glName = gl.split(':')[0].trim()
      const glCode = gl.split(':')[1].trim()
      if (glCode && !headerNames[glCode]) {
        headerNames[glCode] = glName
      }
    }
    posting.push({
      line: i + 1,
      code,
      name,
      type: normalizeType(row[4]),
      nature: normalizeNature(row[5]),
      currency: String(row[6] || '').trim().toUpperCase() || null,
      gl,
    })
  }
}

/** Mirrors App\Services\AccountCodeScheme::parentCodeForCode */
function parentCodeForScheme(code) {
  const level = levelFromCode(code)
  if (level === null || level <= 1) return null
  if (level === 2) return code.slice(0, 1)
  const lastDot = code.lastIndexOf('.')
  if (lastDot === -1) return null
  return code.slice(0, lastDot)
}

function levelFromCode(code) {
  if (!code || !isWellFormed(code)) return null
  if (!code.includes('.')) {
    return code.length === 1 ? 1 : 2
  }
  const dots = (code.match(/\./g) || []).length
  if (dots === 1) return 3
  if (dots === 2) return 4
  return null
}

function isWellFormed(code) {
  if (!code || code.length > 20) return false
  const dots = (code.match(/\./g) || []).length
  if (dots > 2) return false
  if (!code.includes('.')) {
    if (code.length === 1) return /^[1-5]$/.test(code)
    if (code.length >= 5 && /^\d+$/.test(code)) return false
    return /^[1-5]\d+$/.test(code)
  }
  return /^[1-5]\d+\.\d+(?:\.\d+)?$/.test(code)
}

function allAncestorCodes(codes) {
  const set = new Set()
  for (const c of codes) {
    let p = c
    while (p) {
      set.add(p)
      p = parentCodeForScheme(p)
    }
  }
  return [...set].sort((a, b) => {
    const la = levelFromCode(a)
    const lb = levelFromCode(b)
    if (la !== lb) return la - lb
    return a.localeCompare(b, undefined, { numeric: true })
  })
}

function isDescendantOf(code, ancestor) {
  if (!ancestor || code === ancestor) return false
  let cur = code
  while (cur) {
    const p = parentCodeForScheme(cur)
    if (p === ancestor) return true
    cur = p
  }
  return false
}

function firstPostingUnder(prefix) {
  const list = posting
    .filter((p) => p.code === prefix || isDescendantOf(p.code, prefix))
    .sort((a, b) => a.code.localeCompare(b, undefined, { numeric: true }))
  return list[0] || null
}

function typeNatureForHeader(code) {
  const fp = firstPostingUnder(code)
  if (fp) {
    return { account_type: fp.type, normal_balance: fp.nature }
  }
  return { account_type: 'expense', normal_balance: 'debit' }
}

const postingCodes = posting.map((p) => p.code)
const hierarchyCodes = allAncestorCodes(postingCodes)

const hierarchy = []
for (const code of hierarchyCodes) {
  const level = levelFromCode(code)
  const parent = parentCodeForScheme(code)
  const isPosting = postingCodes.includes(code)
  const post = posting.find((x) => x.code === code)
  let name
  if (post) {
    name = post.name
  } else {
    name = headerNames[code]
    if (!name) {
      const child = posting.find((x) => (x.code.startsWith(code + '.') || x.code === code) && x.gl && x.gl.includes(':'))
      if (child) {
        name = child.gl.split(':')[0].trim()
      }
    }
    if (!name) name = `Header ${code}`
  }
  let account_type
  let normal_balance
  let currency_code = null
  if (post) {
    account_type = post.type
    normal_balance = post.nature
    // Per workbook Currency column (AFN / USD); default AFN when blank.
    const cur = String(post.currency ?? '')
      .trim()
      .toUpperCase()
    currency_code = cur || 'AFN'
  } else {
    const tn = typeNatureForHeader(code)
    account_type = tn.account_type
    normal_balance = tn.normal_balance
  }

  hierarchy.push({
    code,
    name,
    parent_code: parent,
    level,
    is_posting: isPosting,
    account_type,
    normal_balance,
    currency_code,
  })
}

const dataDir = pathMod.join(__dirname, '../database/seeders/data')
fs.mkdirSync(dataDir, { recursive: true })
const outHier = pathMod.join(dataDir, 'reference-coa-hierarchy.json')

const sourceLabel = pathMod.basename(srcPath)
fs.writeFileSync(
  outHier,
  JSON.stringify({ source: sourceLabel, hierarchy }, null, 2),
  'utf8'
)

const outPosting = pathMod.join(dataDir, 'reference-coa-posting.json')
fs.writeFileSync(outPosting, JSON.stringify({ posting }, null, 2), 'utf8')

console.log(JSON.stringify({ postingCount: posting.length, hierarchyCount: hierarchy.length, headerNameKeys: Object.keys(headerNames).length }, null, 2))
console.log('Wrote', outHier)
console.log('Wrote', outPosting)
