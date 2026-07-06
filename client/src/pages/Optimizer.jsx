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

  const [game, setGame] = useState(location.state?.game ?? null)
  const [gpu, setGpu] = useState(() => loadHardwareProfile().gpu)
  const [cpu, setCpu] = useState(() => loadHardwareProfile().cpu)
  const [ramGb, setRamGb] = useState(() => loadHardwareProfile().ramGb ?? 16)
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

  function switchMode(nextMode) {
    setMode(nextMode)
    setResult(null)
    setQuota(null)
    setError(null)
  }

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
          onClick={() => switchMode('forward')}
          role="tab"
          aria-selected={mode === 'forward'}
          type="button"
        >
          Recommend settings
        </button>
        <button
          className={`flex-1 rounded px-3 py-2 ${mode === 'reverse' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
          onClick={() => switchMode('reverse')}
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
