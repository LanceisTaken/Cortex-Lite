import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { VerifiedBanner } from '../components/VerifiedBanner'
import { ActiveSessionBanner } from '../components/sessions/ActiveSessionBanner'
import { Button } from '../components/ui/Button'
import { api } from '../lib/api'
import { getUsage, startCheckout } from '../lib/usage'
import { SteamPrivateProfileError } from '../components/steam/SteamPrivateProfileError'

export default function Dashboard() {
  const { user, logout, refresh } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const [steamIdInput, setSteamIdInput] = useState('')
  const [syncing, setSyncing] = useState(false)
  const [connectingSteamId, setConnectingSteamId] = useState(false)
  const [usage, setUsage] = useState(null)
  const [upgrading, setUpgrading] = useState(false)
  const [notice, setNotice] = useState(() => {
    if (searchParams.get('steam_connected') === '1') {
      return { type: 'success', message: 'Steam connected. You can sync your library now.' }
    }

    const errorCode = searchParams.get('steam_error')

    if (errorCode === 'steam_id_already_linked') {
      return { type: 'error', message: 'That Steam account is already linked to another Cortex Lite account.' }
    }

    if (errorCode === 'steam_openid_verification_failed') {
      return { type: 'error', message: 'Steam could not verify the OpenID callback. Try connecting again or enter your SteamID64 below.' }
    }

    return null
  })
  const [checkoutNotice, setCheckoutNotice] = useState(() => {
    const status = searchParams.get('checkout')
    if (status === 'success') {
      return { type: 'success', message: 'Thanks for subscribing. Premium unlocks shortly after Stripe confirms payment.' }
    }
    if (status === 'cancelled') {
      return { type: 'error', message: 'Checkout cancelled. No charge was made.' }
    }
    return null
  })
  const [privateProfileHelp, setPrivateProfileHelp] = useState(null)

  useEffect(() => {
    if (searchParams.has('steam_connected') || searchParams.has('steam_error') || searchParams.has('checkout')) {
      const next = new URLSearchParams(searchParams)
      next.delete('steam_connected')
      next.delete('steam_error')
      next.delete('checkout')
      setSearchParams(next, { replace: true })
    }
  }, [searchParams, setSearchParams])

  useEffect(() => {
    const controller = new AbortController()
    getUsage({ signal: controller.signal })
      .then(setUsage)
      .catch(() => {})
    return () => controller.abort()
  }, [])

  async function handleSync() {
    setSyncing(true)
    setNotice(null)

    try {
      const { data } = await api.post('/api/steam/sync')
      setPrivateProfileHelp(null)
      setNotice({
        type: 'success',
        message: `Steam sync finished. Imported ${data.imported} game(s) and updated ${data.updated}.`,
      })
    } catch (error) {
      if (error.response?.status === 422 && error.response?.data?.error_code === 'steam_profile_private') {
        setPrivateProfileHelp(error.response.data.help)
        setNotice({
          type: 'error',
          message: 'Steam sync is blocked by privacy settings.',
        })
      } else if (error.response?.status === 409 && error.response?.data?.error_code === 'steam_not_connected') {
        setNotice({
          type: 'error',
          message: 'Connect Steam before trying a sync.',
        })
      } else {
        setNotice({
          type: 'error',
          message: 'Steam sync failed. Please try again.',
        })
      }
    } finally {
      setSyncing(false)
    }
  }

  async function handleSteamIdConnect(event) {
    event.preventDefault()
    setConnectingSteamId(true)
    setNotice(null)

    try {
      await api.post('/api/steam/connect-id', { steam_id: steamIdInput.trim() })
      await refresh()
      setSteamIdInput('')
      setPrivateProfileHelp(null)
      setNotice({
        type: 'success',
        message: 'Steam account connected from SteamID64. You can sync your library now.',
      })
    } catch (error) {
      if (error.response?.status === 422) {
        setNotice({
          type: 'error',
          message: error.response?.data?.message ?? 'Enter a valid SteamID64 to connect your Steam account.',
        })
      } else if (error.response?.status === 409) {
        setNotice({
          type: 'error',
          message: 'That Steam account is already linked to another Cortex Lite account.',
        })
      } else {
        setNotice({
          type: 'error',
          message: 'Steam connection failed. Please try again.',
        })
      }
    } finally {
      setConnectingSteamId(false)
    }
  }

  function connectWithSteam() {
    window.location.assign('/api/steam/login')
  }

  async function handleUpgrade() {
    setUpgrading(true)
    try {
      const url = await startCheckout()
      window.location.assign(url)
    } catch {
      setCheckoutNotice({ type: 'error', message: 'Could not start checkout. Please try again.' })
      setUpgrading(false)
    }
  }

  return (
    <div className="mx-auto mt-10 w-full max-w-2xl space-y-6 px-4">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Cortex Lite</h1>
        <div className="flex items-center gap-3 text-sm">
          <Link to="/optimizer" className="text-slate-600 hover:underline">Optimizer</Link>
          <Link to="/library" className="text-slate-600 hover:underline">Library</Link>
          <Link to="/history" className="text-slate-600 hover:underline">History</Link>
          <Link to="/hardware" className="text-slate-600 hover:underline">Hardware</Link>
          <Link to="/account" className="text-slate-600 hover:underline">Account</Link>
          <button onClick={logout} className="text-slate-600 hover:underline">Log out</button>
        </div>
      </header>
      <ActiveSessionBanner />
      <VerifiedBanner user={user} />
      <section className="rounded-md border border-slate-200 p-6">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-medium">Cortex Premium</h2>
          {user.is_premium ? (
            <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">
              Premium
            </span>
          ) : null}
        </div>

        {checkoutNotice ? (
          <div
            className={`mt-3 rounded-md border p-3 text-sm ${
              checkoutNotice.type === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                : 'border-rose-200 bg-rose-50 text-rose-900'
            }`}
          >
            {checkoutNotice.message}
          </div>
        ) : null}

        {user.is_premium ? (
          <p className="mt-2 text-sm text-slate-600">
            You have unlimited recommendations and reverse-mode calls. Thanks for supporting Cortex Lite.
          </p>
        ) : usage ? (
          <div className="mt-3 space-y-3">
            <UsageMeter label="Recommendations" line={usage.recommend} windowDays={usage.window_days} />
            <UsageMeter label="Reverse-mode calls" line={usage.reverse} windowDays={usage.window_days} />
            {usage.recommend.remaining === 0 || usage.reverse.remaining === 0 ? (
              <p className="text-sm text-rose-700">
                You have hit a free-tier cap. Upgrade for unlimited access.
              </p>
            ) : null}
            <Button type="button" onClick={handleUpgrade} disabled={upgrading}>
              {upgrading ? 'Starting checkout...' : 'Upgrade to Premium - $5/mo'}
            </Button>
          </div>
        ) : (
          <p className="mt-2 text-sm text-slate-500">Loading usage...</p>
        )}
      </section>
      <section className="rounded-md border border-slate-200 p-6">
        <h2 className="text-lg font-medium">Welcome, {user.name}.</h2>
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
      </section>
      <section className="rounded-md border border-slate-200 p-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h2 className="text-lg font-medium">Steam Connection</h2>
            <p className="mt-1 text-sm text-slate-600">
              Connect your Steam account to import your library and keep playtime in sync.
            </p>
            <p className="mt-2 text-sm text-slate-700">
              {user.steam_id
                ? `Connected SteamID64: ${user.steam_id}`
                : 'No Steam account connected yet.'}
            </p>
          </div>
          <div className="w-full max-w-xs space-y-3">
            {user.steam_id ? (
              <Button type="button" onClick={handleSync} disabled={syncing}>
                {syncing ? 'Syncing…' : 'Sync Now'}
              </Button>
            ) : (
              <Button type="button" onClick={connectWithSteam}>
                Connect Steam
              </Button>
            )}
          </div>
        </div>

        {notice ? (
          <div
            className={`mt-4 rounded-md border p-3 text-sm ${
              notice.type === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                : 'border-rose-200 bg-rose-50 text-rose-900'
            }`}
          >
            {notice.message}
          </div>
        ) : null}

        {privateProfileHelp ? (
          <SteamPrivateProfileError className="mt-4" help={privateProfileHelp} />
        ) : null}

        {!user.steam_id ? (
          <form className="mt-6 space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4" onSubmit={handleSteamIdConnect}>
            <div>
              <h3 className="font-medium text-slate-900">Manual fallback</h3>
              <p className="mt-1 text-sm text-slate-600">
                Enter your SteamID64 if the OpenID redirect is awkward during the demo.
              </p>
            </div>
            <label className="block text-sm font-medium text-slate-700" htmlFor="steam-id">
              SteamID64
            </label>
            <input
              id="steam-id"
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-slate-900"
              inputMode="numeric"
              onChange={(event) => setSteamIdInput(event.target.value)}
              placeholder="76561198000000000"
              value={steamIdInput}
            />
            <Button type="submit" disabled={connectingSteamId || steamIdInput.trim() === ''}>
              {connectingSteamId ? 'Connecting…' : 'Connect With SteamID64'}
            </Button>
          </form>
        ) : null}
      </section>
    </div>
  )
}

function UsageMeter({ label, line, windowDays }) {
  const atCap = line.remaining === 0

  return (
    <div>
      <div className="flex justify-between gap-4 text-sm">
        <span className="font-medium text-slate-700">{label}</span>
        <span className={atCap ? 'text-rose-700' : 'text-slate-600'}>
          {line.used} / {line.limit} used in the last {windowDays} days
        </span>
      </div>
      <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-100">
        <div
          className={`h-full ${atCap ? 'bg-rose-500' : 'bg-slate-700'}`}
          style={{ width: `${Math.min(100, (line.used / line.limit) * 100)}%` }}
        />
      </div>
    </div>
  )
}
