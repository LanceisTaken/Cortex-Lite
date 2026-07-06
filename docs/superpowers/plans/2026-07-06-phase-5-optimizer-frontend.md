# Phase 5 Optimizer Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the already-shipped Phase 5 optimizer backend (`POST /api/recommend`, `POST /api/reverse`) to a new `/optimizer` React page so the AI optimizer is visible and demoable end-to-end.

**Architecture:** A dedicated `/optimizer` page combines a searchable game picker, the existing `HardwareAutocomplete` (GPU/CPU), a RAM input, a goal selector, and a Forward/Reverse mode toggle. Forward mode renders the recommended settings table + Gemini explanation; reverse mode adds a structured "current settings" form and renders the diff table. Hardware selection persists in `localStorage` and is shared with the existing `/hardware` page. Quota-402 responses render an upgrade CTA reusing the existing Stripe checkout helper.

**Tech Stack:** React 19 + Vite, react-router-dom 7, axios (existing `client/src/lib/api.js` Sanctum cookie client), Tailwind 4. No new npm dependencies.

## Global Constraints

- **No new npm packages.** Everything uses what's already in `client/package.json`.
- **The LLM never decides settings** — the frontend only renders `settings`, `diff`, and `explanation` fields returned by the deterministic backend. Never compute or mutate recommendations client-side.
- **Free tier gates usage volume, not catalog** — every game in the picker is optimizable; the only gate is the 402 `quota_exceeded` response, which must render an upgrade CTA, never hide games.
- **No frontend test framework exists** (no vitest/jest — `package.json` scripts are `dev`, `build`, `lint`, `preview`). Repo convention for frontend slices (see SESSION_LOG Phase 4 entries): verify with `npm run lint` (oxlint), `npm run build`, and a manual smoke pass against `make up` + `npm run dev`. Each task below ends with the lint gate; the final task runs build + full manual smoke.
- **Commit message prefix:** `[Sprint 5] ...` (repo convention). All work happens on branch `Phase-5`.
- **API base:** all calls go through `client/src/lib/api.js` (`api.get`/`api.post`) which already handles the Sanctum CSRF cookie and `withCredentials`.
- Run all `npm` commands from `client/` (e.g. `cd client && npm run lint`). PHP/backend is untouched by this plan except docs.

## Backend contract reference (already shipped — do not modify)

`POST /api/recommend` — body `{game_id, gpu_id, cpu_id, ram_gb, goal}`, `goal ∈ performance|balanced|quality`. Success `200`:

```json
{
  "data": {
    "game_id": 12,
    "goal": "balanced",
    "settings": { "resolution_scale": "100%", "upscaling": "balanced", "ray_tracing": false, "shadow_quality": "high", "texture_quality": "high", "anti_aliasing": "high", "ambient_occlusion": "high" },
    "source": "heuristic",
    "gpu_tier": "high",
    "cpu_tier": "mid",
    "ram_bucket": "16gb_plus",
    "cpu_bottleneck": false,
    "explanation": "prose string"
  }
}
```

Notes: `settings` values can be strings, booleans, or (from anchor presets) arbitrary per-game JSON values. `source` is `anchor` or `heuristic`.

`POST /api/reverse` — same body plus `current_settings` (non-empty object). Success `200`:

```json
{
  "data": {
    "game_id": 12,
    "goal": "balanced",
    "diff": [ { "setting": "shadow_quality", "current": "ultra", "recommended": "high", "label": "ultra → high" } ],
    "recommendation": { "settings": {}, "source": "heuristic", "gpu_tier": "high", "cpu_tier": "mid", "ram_bucket": "16gb_plus", "cpu_bottleneck": false },
    "explanation": "prose string"
  }
}
```

Notes: `SettingsComparator` iterates **recommended** keys, skips keys the user didn't send, matches case-insensitively, and displays booleans as `on`/`off` — so sending `"on"`/`"off"` strings for ray tracing is correct. Empty `diff` means settings already match.

Error responses both endpoints: `404` (game not owned — IDOR guard), `422` validation, `402` quota:

```json
{ "error_code": "quota_exceeded", "type": "recommend", "limit": 3, "used": 3, "window_days": 30, "message": "You've used all 3 free recommend calls in the last 30 days. Upgrade to Cortex Premium for unlimited access." }
```

Existing frontend pieces reused: `HardwareAutocomplete` (`{kind, value, onChange}`; value rows have `id, name, tier, manufacturer, released_year, g3d_mark|single_thread_mark`), `listGames` from `lib/games.js` (returns `{data, meta}` paginated), `getUsage`/`startCheckout` from `lib/usage.js`, `Button` from `components/ui/Button`.

## File structure

- Create: `client/src/lib/optimizer.js` — API calls + quota-error extractor (Task 1)
- Create: `client/src/lib/hardwareProfile.js` — localStorage persistence for `{gpu, cpu, ramGb}` (Task 1)
- Create: `client/src/components/optimizer/GameSelect.jsx` — debounced searchable game picker (Task 2)
- Create: `client/src/components/optimizer/CurrentSettingsForm.jsx` — structured reverse-mode input (Task 3)
- Create: `client/src/components/optimizer/RecommendationResult.jsx` — forward-mode result card (Task 4)
- Create: `client/src/components/optimizer/DiffResult.jsx` — reverse-mode diff card (Task 4)
- Create: `client/src/pages/Optimizer.jsx` — the page (Task 5)
- Modify: `client/src/App.jsx` — add `/optimizer` route (Task 5)
- Modify: `client/src/pages/Dashboard.jsx` — nav link + welcome copy (Task 5)
- Modify: `client/src/pages/Library.jsx` — per-game "Optimize" link (Task 5)
- Modify: `client/src/pages/Hardware.jsx` — persist selection to shared profile, retire the "Phase 5 will use this" note (Task 6)
- Modify: `README.md`, `docs/ARCHITECTURE.md`, `docs/DECISIONS.md`, `docs/cortex-lite-build-plan.md`, `SESSION_LOG.md` (Task 7)

---

### Task 1: API client + hardware profile persistence libs

**Files:**
- Create: `client/src/lib/optimizer.js`
- Create: `client/src/lib/hardwareProfile.js`

**Interfaces:**
- Consumes: `api` from `client/src/lib/api.js`.
- Produces:
  - `requestRecommendation(payload)` → resolves to the `data.data` recommendation object
  - `requestReverseDiff(payload)` → resolves to the `data.data` reverse object
  - `quotaError(error)` → `{type, limit, used, window_days, message}` or `null`
  - `loadHardwareProfile()` → `{gpu: object|null, cpu: object|null, ramGb: number|null}`
  - `saveHardwareProfile({gpu, cpu, ramGb})` → void

- [ ] **Step 1: Write `client/src/lib/optimizer.js`**

```js
import { api } from './api'

export async function requestRecommendation(payload) {
  const { data } = await api.post('/api/recommend', payload)
  return data.data
}

export async function requestReverseDiff(payload) {
  const { data } = await api.post('/api/reverse', payload)
  return data.data
}

export function quotaError(error) {
  if (error.response?.status === 402 && error.response?.data?.error_code === 'quota_exceeded') {
    return error.response.data
  }
  return null
}
```

- [ ] **Step 2: Write `client/src/lib/hardwareProfile.js`**

```js
const STORAGE_KEY = 'cortex.hardwareProfile'

export function loadHardwareProfile() {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (!raw) return { gpu: null, cpu: null, ramGb: null }
    const parsed = JSON.parse(raw)
    return {
      gpu: parsed.gpu ?? null,
      cpu: parsed.cpu ?? null,
      ramGb: typeof parsed.ramGb === 'number' ? parsed.ramGb : null,
    }
  } catch {
    return { gpu: null, cpu: null, ramGb: null }
  }
}

export function saveHardwareProfile({ gpu, cpu, ramGb }) {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ gpu, cpu, ramGb }))
  } catch {
    // Storage full or blocked (private mode) — persistence is best-effort.
  }
}
```

- [ ] **Step 3: Lint**

Run: `cd client && npm run lint`
Expected: passes (only the pre-existing `AuthContext.jsx` fast-refresh warning is acceptable).

- [ ] **Step 4: Commit**

```bash
git add client/src/lib/optimizer.js client/src/lib/hardwareProfile.js
git commit -m "[Sprint 5] add optimizer API client and hardware profile persistence"
```

---

### Task 2: GameSelect component

**Files:**
- Create: `client/src/components/optimizer/GameSelect.jsx`

**Interfaces:**
- Consumes: `listGames({ search, signal })` from `client/src/lib/games.js` (returns `{data: [{id, title, status, ...}], meta}`).
- Produces: `<GameSelect value={gameOrNull} onChange={fn} />` — `value` is a game object with at least `{id, title}`; mirrors `HardwareAutocomplete`'s debounced-dropdown pattern.

- [ ] **Step 1: Write `client/src/components/optimizer/GameSelect.jsx`**

```jsx
import { useEffect, useRef, useState } from 'react'
import { listGames } from '../../lib/games'

export function GameSelect({ value, onChange }) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState([])
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const containerRef = useRef(null)

  useEffect(() => {
    setQuery(value ? value.title : '')
  }, [value])

  useEffect(() => {
    if (!open) return undefined

    const handleClickAway = (event) => {
      if (!containerRef.current?.contains(event.target)) {
        setOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickAway)
    return () => document.removeEventListener('mousedown', handleClickAway)
  }, [open])

  useEffect(() => {
    if (!open) return undefined

    const controller = new AbortController()
    setLoading(true)
    setError(null)

    const timeout = window.setTimeout(async () => {
      try {
        const payload = await listGames({ search: query, signal: controller.signal })
        setResults(payload.data)
      } catch (fetchError) {
        if (fetchError.name !== 'CanceledError' && fetchError.name !== 'AbortError') {
          setError('Could not load your games.')
        }
      } finally {
        if (!controller.signal.aborted) {
          setLoading(false)
        }
      }
    }, 300)

    return () => {
      window.clearTimeout(timeout)
      controller.abort()
    }
  }, [open, query])

  function handleInputChange(event) {
    setQuery(event.target.value)
    if (value) onChange(null)
    setOpen(true)
  }

  return (
    <div ref={containerRef} className="relative">
      <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="optimizer-game">
        Game
      </label>
      <div className="flex gap-2">
        <input
          id="optimizer-game"
          className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-slate-900"
          onChange={handleInputChange}
          onFocus={() => setOpen(true)}
          placeholder="Search your library"
          value={query}
        />
        {value ? (
          <button
            className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
            onClick={() => { onChange(null); setQuery(''); setResults([]); setOpen(true) }}
            type="button"
          >
            Clear
          </button>
        ) : null}
      </div>

      {open ? (
        <div className="absolute z-10 mt-1 max-h-72 w-full overflow-auto rounded-md border border-slate-200 bg-white shadow-lg">
          {loading ? <div className="px-3 py-2 text-sm text-slate-500">Loading...</div> : null}
          {error ? <div className="px-3 py-2 text-sm text-rose-700">{error}</div> : null}
          {!loading && !error && results.length === 0 ? (
            <div className="px-3 py-2 text-sm text-slate-500">No games match. Sync Steam or add games from the Library.</div>
          ) : null}
          {results.map((game) => (
            <button
              className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50"
              key={game.id}
              onClick={() => { onChange(game); setQuery(game.title); setOpen(false) }}
              type="button"
            >
              <span className="truncate font-medium">{game.title}</span>
              <span className="shrink-0 text-xs uppercase text-slate-500">{game.status}</span>
            </button>
          ))}
        </div>
      ) : null}
    </div>
  )
}
```

- [ ] **Step 2: Lint**

Run: `cd client && npm run lint`
Expected: passes.

- [ ] **Step 3: Commit**

```bash
git add client/src/components/optimizer/GameSelect.jsx
git commit -m "[Sprint 5] add optimizer game picker component"
```

---

### Task 3: CurrentSettingsForm component (reverse-mode structured input)

**Files:**
- Create: `client/src/components/optimizer/CurrentSettingsForm.jsx`

**Interfaces:**
- Consumes: nothing external.
- Produces:
  - `<CurrentSettingsForm value={settingsObject} onChange={fn} />` — `value` maps setting key → chosen string; rows left "Not set" are absent from the object.
  - `SETTING_FIELDS` export — the setting vocabulary as `[{key, label, options}]` (exported so future consumers can reuse it; currently only used internally).
  - Design note: the vocabulary mirrors `HeuristicRecommender`'s output keys. `SettingsComparator` iterates *recommended* keys and skips unsent ones, so a fixed structured form can never produce silently-ignored input the way free-form keys could. Anchor presets may recommend extra per-game keys; those simply won't be diffed unless sent, which is the comparator's documented behavior.

- [ ] **Step 1: Write `client/src/components/optimizer/CurrentSettingsForm.jsx`**

```jsx
const ORDINAL = ['low', 'medium', 'high', 'ultra']

export const SETTING_FIELDS = [
  { key: 'resolution_scale', label: 'Resolution scale', options: ['50%', '67%', '75%', '90%', '100%'] },
  { key: 'upscaling', label: 'Upscaling', options: ['off', 'performance', 'balanced', 'quality'] },
  { key: 'ray_tracing', label: 'Ray tracing', options: ['off', 'on'] },
  { key: 'shadow_quality', label: 'Shadow quality', options: ORDINAL },
  { key: 'texture_quality', label: 'Texture quality', options: ORDINAL },
  { key: 'anti_aliasing', label: 'Anti-aliasing', options: ORDINAL },
  { key: 'ambient_occlusion', label: 'Ambient occlusion', options: ORDINAL },
]

const NOT_SET = ''

export function CurrentSettingsForm({ value, onChange }) {
  function handleSelect(key, selected) {
    const next = { ...value }
    if (selected === NOT_SET) {
      delete next[key]
    } else {
      next[key] = selected
    }
    onChange(next)
  }

  return (
    <fieldset className="space-y-2">
      <legend className="text-sm font-medium text-slate-700">Your current in-game settings</legend>
      <p className="text-xs text-slate-500">
        Set the ones you know — rows left as “Not set” are skipped in the comparison.
      </p>
      <div className="grid gap-2 sm:grid-cols-2">
        {SETTING_FIELDS.map((field) => (
          <label className="flex items-center justify-between gap-3 rounded-md border border-slate-200 px-3 py-2 text-sm" key={field.key}>
            <span className="text-slate-700">{field.label}</span>
            <select
              className="rounded-md border border-slate-300 px-2 py-1 text-sm outline-none focus:border-slate-900"
              onChange={(event) => handleSelect(field.key, event.target.value)}
              value={value[field.key] ?? NOT_SET}
            >
              <option value={NOT_SET}>Not set</option>
              {field.options.map((option) => (
                <option key={option} value={option}>{option}</option>
              ))}
            </select>
          </label>
        ))}
      </div>
    </fieldset>
  )
}
```

- [ ] **Step 2: Lint**

Run: `cd client && npm run lint`
Expected: passes.

- [ ] **Step 3: Commit**

```bash
git add client/src/components/optimizer/CurrentSettingsForm.jsx
git commit -m "[Sprint 5] add reverse-mode current settings form"
```

---

### Task 4: Result display components

**Files:**
- Create: `client/src/components/optimizer/RecommendationResult.jsx`
- Create: `client/src/components/optimizer/DiffResult.jsx`

**Interfaces:**
- Consumes: forward result object `{settings, source, gpu_tier, cpu_tier, ram_bucket, cpu_bottleneck, explanation, goal}` and reverse result object `{diff, recommendation, explanation, goal}` (shapes in the backend contract reference above).
- Produces:
  - `<RecommendationResult result={forwardResult} />`
  - `<DiffResult result={reverseResult} />`
  - Both render the `explanation` prose verbatim — the frontend never rewrites or derives recommendation content (LLM/engine boundary).

- [ ] **Step 1: Write `client/src/components/optimizer/RecommendationResult.jsx`**

```jsx
// Mirrors backend SettingsComparator::display() so on-screen values match
// what reverse mode would echo back.
export function displayValue(value) {
  if (typeof value === 'boolean') return value ? 'on' : 'off'
  if (Array.isArray(value) || (value !== null && typeof value === 'object')) return JSON.stringify(value)
  return String(value)
}

export function settingLabel(key) {
  const words = key.replace(/_/g, ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}

function SourceBadge({ source }) {
  const anchor = source === 'anchor'
  return (
    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${anchor ? 'bg-emerald-100 text-emerald-800' : 'bg-sky-100 text-sky-800'}`}>
      {anchor ? 'Curated preset' : 'Heuristic engine'}
    </span>
  )
}

export function TierSummary({ gpuTier, cpuTier, ramBucket }) {
  return (
    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
      <span><strong>GPU tier:</strong> {gpuTier}</span>
      <span><strong>CPU tier:</strong> {cpuTier}</span>
      <span><strong>RAM bucket:</strong> {ramBucket.replace(/_/g, ' ')}</span>
    </div>
  )
}

export function CpuBottleneckWarning() {
  return (
    <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
      Your CPU trails your GPU by two or more tiers — CPU-bound scenes may still cap your frame rate.
    </div>
  )
}

export function RecommendationResult({ result }) {
  return (
    <section className="space-y-4 rounded-md border border-slate-200 p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg font-medium">Recommended {result.goal} settings</h2>
        <SourceBadge source={result.source} />
      </div>
      <TierSummary gpuTier={result.gpu_tier} cpuTier={result.cpu_tier} ramBucket={result.ram_bucket} />
      {result.cpu_bottleneck ? <CpuBottleneckWarning /> : null}
      <dl className="divide-y divide-slate-100 rounded-md border border-slate-100">
        {Object.entries(result.settings).map(([key, value]) => (
          <div className="flex items-center justify-between gap-4 px-3 py-2 text-sm" key={key}>
            <dt className="text-slate-600">{settingLabel(key)}</dt>
            <dd className="font-medium text-slate-900">{displayValue(value)}</dd>
          </div>
        ))}
      </dl>
      <p className="rounded-md bg-slate-50 p-3 text-sm leading-relaxed text-slate-700">{result.explanation}</p>
    </section>
  )
}
```

- [ ] **Step 2: Write `client/src/components/optimizer/DiffResult.jsx`**

```jsx
import { CpuBottleneckWarning, TierSummary, settingLabel } from './RecommendationResult'

export function DiffResult({ result }) {
  const { diff, recommendation } = result

  return (
    <section className="space-y-4 rounded-md border border-slate-200 p-4">
      <h2 className="text-lg font-medium">Suggested changes for {result.goal}</h2>
      <TierSummary
        gpuTier={recommendation.gpu_tier}
        cpuTier={recommendation.cpu_tier}
        ramBucket={recommendation.ram_bucket}
      />
      {recommendation.cpu_bottleneck ? <CpuBottleneckWarning /> : null}

      {diff.length === 0 ? (
        <div className="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
          Your settings already match the recommendation. Nothing to change.
        </div>
      ) : (
        <div className="overflow-x-auto rounded-md border border-slate-100">
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">Setting</th>
                <th className="px-3 py-2 font-medium">Current</th>
                <th className="px-3 py-2 font-medium">Recommended</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {diff.map((entry) => (
                <tr key={entry.setting}>
                  <td className="px-3 py-2 text-slate-700">{settingLabel(entry.setting)}</td>
                  <td className="px-3 py-2 text-rose-700">{entry.current}</td>
                  <td className="px-3 py-2 font-medium text-emerald-700">{entry.recommended}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="rounded-md bg-slate-50 p-3 text-sm leading-relaxed text-slate-700">{result.explanation}</p>
    </section>
  )
}
```

- [ ] **Step 3: Lint**

Run: `cd client && npm run lint`
Expected: passes.

- [ ] **Step 4: Commit**

```bash
git add client/src/components/optimizer/RecommendationResult.jsx client/src/components/optimizer/DiffResult.jsx
git commit -m "[Sprint 5] add optimizer result display components"
```

---

### Task 5: Optimizer page, route, and entry points

**Files:**
- Create: `client/src/pages/Optimizer.jsx`
- Modify: `client/src/App.jsx` (imports block lines 4–13; routes block lines 35–39)
- Modify: `client/src/pages/Dashboard.jsx` (nav links lines 155–161; welcome section lines 208–220)
- Modify: `client/src/pages/Library.jsx` (row actions div lines 206–223)

**Interfaces:**
- Consumes: everything from Tasks 1–4 — `requestRecommendation`, `requestReverseDiff`, `quotaError`, `loadHardwareProfile`, `saveHardwareProfile`, `GameSelect`, `CurrentSettingsForm`, `RecommendationResult`, `DiffResult` — plus existing `HardwareAutocomplete`, `getUsage`, `startCheckout`, `Button`.
- Produces: route `/optimizer` (protected). Library rows link to it with `state={{ game }}` for preselection (router state, because `GET /api/games/{id}` does not exist — the resource excludes `show`).

- [ ] **Step 1: Write `client/src/pages/Optimizer.jsx`**

```jsx
import { useEffect, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { Button } from '../components/ui/Button'
import { HardwareAutocomplete } from '../components/hardware/HardwareAutocomplete'
import { GameSelect } from '../components/optimizer/GameSelect'
import { CurrentSettingsForm } from '../components/optimizer/CurrentSettingsForm'
import { RecommendationResult } from '../components/optimizer/RecommendationResult'
import { DiffResult } from '../components/optimizer/DiffResult'
import { useAuth } from '../context/AuthContext'
import { loadHardwareProfile, saveHardwareProfile } from '../lib/hardwareProfile'
import { quotaError, requestRecommendation, requestReverseDiff } from '../lib/optimizer'
import { getUsage, startCheckout } from '../lib/usage'

const GOALS = ['performance', 'balanced', 'quality']

export default function Optimizer() {
  const { user } = useAuth()
  const location = useLocation()
  const profile = loadHardwareProfile()

  const [game, setGame] = useState(location.state?.game ?? null)
  const [gpu, setGpu] = useState(profile.gpu)
  const [cpu, setCpu] = useState(profile.cpu)
  const [ramGb, setRamGb] = useState(profile.ramGb ?? 16)
  const [goal, setGoal] = useState('balanced')
  const [mode, setMode] = useState('forward')
  const [currentSettings, setCurrentSettings] = useState({})
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [quota, setQuota] = useState(null)
  const [usage, setUsage] = useState(null)
  const [upgrading, setUpgrading] = useState(false)

  useEffect(() => {
    saveHardwareProfile({ gpu, cpu, ramGb })
  }, [gpu, cpu, ramGb])

  useEffect(() => {
    if (user.is_premium) return undefined
    const controller = new AbortController()
    getUsage({ signal: controller.signal }).then(setUsage).catch(() => {})
    return () => controller.abort()
  }, [user.is_premium])

  const ramValid = Number.isInteger(ramGb) && ramGb >= 1 && ramGb <= 512
  const reverseReady = mode === 'forward' || Object.keys(currentSettings).length > 0
  const canSubmit = game && gpu && cpu && ramValid && reverseReady && !loading

  async function handleSubmit(event) {
    event.preventDefault()
    setLoading(true)
    setError(null)
    setQuota(null)
    setResult(null)

    const payload = {
      game_id: game.id,
      gpu_id: gpu.id,
      cpu_id: cpu.id,
      ram_gb: ramGb,
      goal,
    }

    try {
      if (mode === 'forward') {
        setResult({ mode: 'forward', data: await requestRecommendation(payload) })
      } else {
        setResult({
          mode: 'reverse',
          data: await requestReverseDiff({ ...payload, current_settings: currentSettings }),
        })
      }
      if (!user.is_premium) {
        getUsage().then(setUsage).catch(() => {})
      }
    } catch (submitError) {
      const exceeded = quotaError(submitError)
      if (exceeded) {
        setQuota(exceeded)
      } else if (submitError.response?.status === 404) {
        setError('That game is no longer in your library. Pick another one.')
      } else if (submitError.response?.status === 422) {
        setError('Check the form — some inputs were rejected.')
      } else {
        setError('The optimizer request failed. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  async function handleUpgrade() {
    setUpgrading(true)
    try {
      const url = await startCheckout()
      window.location.assign(url)
    } catch {
      setError('Could not start checkout. Please try again.')
      setUpgrading(false)
    }
  }

  const usageLine = usage ? (mode === 'forward' ? usage.recommend : usage.reverse) : null

  return (
    <div className="mx-auto w-full max-w-3xl space-y-6 px-4 py-8">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold text-slate-900">Settings Optimizer</h1>
        <Link to="/dashboard" className="text-sm text-slate-500 hover:text-slate-700">Dashboard</Link>
      </header>

      <div className="flex rounded-md border border-slate-200 p-1 text-sm" role="tablist">
        <button
          className={`flex-1 rounded px-3 py-2 ${mode === 'forward' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
          onClick={() => { setMode('forward'); setResult(null); setQuota(null); setError(null) }}
          role="tab"
          aria-selected={mode === 'forward'}
          type="button"
        >
          Recommend settings
        </button>
        <button
          className={`flex-1 rounded px-3 py-2 ${mode === 'reverse' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
          onClick={() => { setMode('reverse'); setResult(null); setQuota(null); setError(null) }}
          role="tab"
          aria-selected={mode === 'reverse'}
          type="button"
        >
          Check my settings
        </button>
      </div>

      <form className="space-y-4 rounded-md border border-slate-200 p-4" onSubmit={handleSubmit}>
        <GameSelect value={game} onChange={setGame} />
        <HardwareAutocomplete kind="gpu" value={gpu} onChange={setGpu} />
        <HardwareAutocomplete kind="cpu" value={cpu} onChange={setCpu} />

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="optimizer-ram">
              System RAM (GB)
            </label>
            <input
              id="optimizer-ram"
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-slate-900"
              inputMode="numeric"
              min="1"
              max="512"
              onChange={(event) => setRamGb(Number.parseInt(event.target.value, 10) || 0)}
              type="number"
              value={ramGb || ''}
            />
          </div>
          <div>
            <span className="mb-1 block text-sm font-medium text-slate-700">Goal</span>
            <div className="flex gap-1 rounded-md border border-slate-200 p-1">
              {GOALS.map((option) => (
                <button
                  className={`flex-1 rounded px-2 py-1.5 text-sm capitalize ${goal === option ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                  key={option}
                  onClick={() => setGoal(option)}
                  type="button"
                >
                  {option}
                </button>
              ))}
            </div>
          </div>
        </div>

        {mode === 'reverse' ? (
          <CurrentSettingsForm value={currentSettings} onChange={setCurrentSettings} />
        ) : null}

        {usageLine ? (
          <p className={`text-xs ${usageLine.remaining === 0 ? 'text-rose-700' : 'text-slate-500'}`}>
            Free tier: {usageLine.remaining} of {usageLine.limit} {mode === 'forward' ? 'recommendations' : 'reverse-mode checks'} left in the last {usage.window_days} days.
          </p>
        ) : null}

        <Button type="submit" disabled={!canSubmit}>
          {loading
            ? 'Optimizing...'
            : mode === 'forward' ? 'Get recommended settings' : 'Compare my settings'}
        </Button>
        {mode === 'reverse' && !reverseReady ? (
          <p className="text-xs text-slate-500">Set at least one current setting to compare.</p>
        ) : null}
      </form>

      {error ? (
        <div className="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">{error}</div>
      ) : null}

      {quota ? (
        <div className="space-y-3 rounded-md border border-amber-200 bg-amber-50 p-4">
          <p className="text-sm text-amber-900">{quota.message}</p>
          <Button type="button" onClick={handleUpgrade} disabled={upgrading}>
            {upgrading ? 'Starting checkout...' : 'Upgrade to Premium - $5/mo'}
          </Button>
        </div>
      ) : null}

      {result?.mode === 'forward' ? <RecommendationResult result={result.data} /> : null}
      {result?.mode === 'reverse' ? <DiffResult result={result.data} /> : null}
    </div>
  )
}
```

- [ ] **Step 2: Add the route in `client/src/App.jsx`**

Add the import after line 13 (`import Hardware from './pages/Hardware'`):

```jsx
import Optimizer from './pages/Optimizer'
```

Add the route after the `/hardware` route (line 38):

```jsx
      <Route path="/optimizer" element={<ProtectedRoute><Optimizer /></ProtectedRoute>} />
```

- [ ] **Step 3: Add entry points in `client/src/pages/Dashboard.jsx`**

In the header nav (line 156, before the Library link), add:

```jsx
          <Link to="/optimizer" className="text-slate-600 hover:underline">Optimizer</Link>
```

Replace the welcome section body (lines 210–219 — the `<p>` and the Hardware-profile `<Link>`) with:

```jsx
        <p className="mt-1 text-sm text-slate-600">
          Your account is ready. Sync your Steam library, track sessions, and get
          AI-assisted graphics settings from the optimizer.
        </p>
        <div className="mt-4 flex gap-3">
          <Link
            className="inline-flex rounded-md border border-slate-900 bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
            to="/optimizer"
          >
            Optimize a game
          </Link>
          <Link
            className="inline-flex rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            to="/hardware"
          >
            Hardware profile
          </Link>
        </div>
```

- [ ] **Step 4: Add per-game "Optimize" link in `client/src/pages/Library.jsx`**

In the row actions `<div className="flex gap-2">` (line 206), insert between the Start button and the Edit button:

```jsx
                  <Link
                    to="/optimizer"
                    state={{ game }}
                    className="rounded-md border border-sky-300 px-3 py-2 text-sm text-sky-800 hover:bg-sky-50"
                  >
                    Optimize
                  </Link>
```

(`Link` is already imported in Library.jsx at line 2.)

- [ ] **Step 5: Lint**

Run: `cd client && npm run lint`
Expected: passes.

- [ ] **Step 6: Manual smoke test (dev)**

With backend running (`make up`) and `cd client && npm run dev`:
1. Log in, open `/optimizer` from the Dashboard nav.
2. Forward mode: pick a game, GPU, CPU, RAM 16, goal balanced → submit → settings table, source badge, tier summary, and explanation prose render. Free-tier counter decrements.
3. Reverse mode: set 2–3 current settings that differ from the recommendation → submit → diff table shows `current → recommended` rows; submit again with matching values → "already match" state.
4. Library → "Optimize" on a game row → optimizer opens with that game preselected.
5. Reload `/optimizer` → GPU/CPU/RAM selections restored from localStorage.
6. (If a free account has exhausted quota) submit → amber quota panel with upgrade button, no crash.

- [ ] **Step 7: Commit**

```bash
git add client/src/pages/Optimizer.jsx client/src/App.jsx client/src/pages/Dashboard.jsx client/src/pages/Library.jsx
git commit -m "[Sprint 5] add optimizer page wiring recommend and reverse endpoints"
```

---

### Task 6: Share hardware profile with the /hardware page

**Files:**
- Modify: `client/src/pages/Hardware.jsx` (state init lines 24–25; footer note lines 86–88)

**Interfaces:**
- Consumes: `loadHardwareProfile`, `saveHardwareProfile` from `client/src/lib/hardwareProfile.js` (Task 1). Note this page has no RAM input — pass through the stored `ramGb` unchanged when saving so it isn't wiped.

- [ ] **Step 1: Persist selections in `client/src/pages/Hardware.jsx`**

Add the import after line 4 (`import { probeWebGpu, readBrowserHints } from '../lib/browserHardware'`):

```jsx
import { loadHardwareProfile, saveHardwareProfile } from '../lib/hardwareProfile'
```

Replace the state initialization (lines 24–25):

```jsx
  const [gpu, setGpu] = useState(() => loadHardwareProfile().gpu)
  const [cpu, setCpu] = useState(() => loadHardwareProfile().cpu)
```

Add a persistence effect after the existing `useEffect` (lines 29–32):

```jsx
  useEffect(() => {
    saveHardwareProfile({ gpu, cpu, ramGb: loadHardwareProfile().ramGb })
  }, [gpu, cpu])
```

- [ ] **Step 2: Update the stale footer note**

Replace lines 86–88:

```jsx
        <p className="mt-4 text-xs text-slate-400">
          Selection is not saved server-side in this phase. It will feed the recommender in Phase 5.
        </p>
```

with:

```jsx
        <p className="mt-4 text-xs text-slate-400">
          Saved in this browser and shared with the <Link className="underline" to="/optimizer">Settings Optimizer</Link>.
        </p>
```

(`Link` is already imported in Hardware.jsx at line 2.)

- [ ] **Step 3: Lint + smoke**

Run: `cd client && npm run lint`
Expected: passes.
Smoke: select a GPU/CPU on `/hardware`, open `/optimizer` → same GPU/CPU preselected; change GPU on `/optimizer`, revisit `/hardware` → updated.

- [ ] **Step 4: Commit**

```bash
git add client/src/pages/Hardware.jsx
git commit -m "[Sprint 5] share persisted hardware profile between hardware page and optimizer"
```

---

### Task 7: Docs, final verification

**Files:**
- Modify: `README.md` (sprint changelog section)
- Modify: `docs/ARCHITECTURE.md` (frontend/pages description wherever the React pages are listed)
- Modify: `docs/DECISIONS.md` (append two entries)
- Modify: `docs/cortex-lite-build-plan.md` (mark the optimizer frontend gap closed under Phase 5)
- Modify: `SESSION_LOG.md` (prepend session entry)

- [ ] **Step 1: Append to `docs/DECISIONS.md`**

```markdown
### Structured reverse-mode settings form over free-form key/value input
**Date:** 2026-07-06
**Decision:** The optimizer's reverse mode collects current settings through a fixed form built from the heuristic vocabulary (resolution_scale, upscaling, ray_tracing, shadow_quality, texture_quality, anti_aliasing, ambient_occlusion), with per-row "Not set" opt-outs.
**Rationale:** SettingsComparator iterates recommended keys and silently ignores unknown pasted keys. Free-form input would let users type keys that do nothing, which reads as a bug in a demo. A fixed vocabulary guarantees every entered value can participate in the diff.
**Alternatives considered:** Free-form key/value rows (rejected: silent-ignore footgun); paste-a-blob text parsing (rejected: fragile, out of scope).
**Consequences:** Anchor-preset keys outside the shared vocabulary are never diffed. Acceptable: the comparator already defines recommended-keys-only semantics, and the form can grow columns later.

### Client-side localStorage hardware profile over server-side persistence
**Date:** 2026-07-06
**Decision:** The selected GPU/CPU/RAM persists in `localStorage` (`cortex.hardwareProfile`), shared between `/hardware` and `/optimizer`. No server-side profile table.
**Rationale:** Phase 5 needs a smooth demo flow (pick hardware once, optimize many games) without expanding backend scope. The recommend/reverse endpoints already take hardware per-request, so no server state is required for correctness.
**Alternatives considered:** Users-table columns + profile endpoint (rejected for Phase 5: schema churn and IDOR/test surface for a value the client already holds); no persistence (rejected: tedious in the evaluator demo).
**Consequences:** Profile does not roam across browsers/devices. A server-side profile can supersede this later without breaking the API contract.
```

- [ ] **Step 2: Update `README.md` sprint changelog**

Append under the Sprint 5 changelog entries:

```markdown
- Optimizer UI: new `/optimizer` page wires the recommendation and reverse-mode endpoints — game picker, shared GPU/CPU/RAM profile (localStorage), goal selector, forward/reverse toggle, settings table, diff table, Gemini explanations, free-tier usage counters, and quota-402 upgrade flow.
```

- [ ] **Step 3: Update `docs/ARCHITECTURE.md`**

In the section describing the React client/pages, add `/optimizer` alongside the existing pages with one line: "Settings Optimizer — forward recommendations and reverse-mode diff against `POST /api/recommend` / `POST /api/reverse`; hardware selection shared with `/hardware` via localStorage."

- [ ] **Step 4: Update `docs/cortex-lite-build-plan.md`**

In the Phase 5 section, note the optimizer frontend as delivered (this plan closes the gap where the build plan omitted the frontend wiring for the optimizer backend).

- [ ] **Step 5: Prepend `SESSION_LOG.md` entry**

Follow the existing format ("Most recent first"): summarize the optimizer frontend slice, list verification results, and the commits made.

- [ ] **Step 6: Final verification**

Run: `cd client && npm run lint` → passes (pre-existing `AuthContext.jsx` fast-refresh warning acceptable).
Run: `cd client && npm run build` → passes.
Run: `make test` → all PHPUnit tests still pass (no backend code changed; confirms docs-only diffs outside `client/`).
Run: `git diff --check` → clean.

- [ ] **Step 7: Commit**

```bash
git add README.md docs/ARCHITECTURE.md docs/DECISIONS.md docs/cortex-lite-build-plan.md SESSION_LOG.md
git commit -m "[Sprint 5] document optimizer frontend slice"
```
