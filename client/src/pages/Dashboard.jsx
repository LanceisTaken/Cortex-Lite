import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { VerifiedBanner } from '../components/VerifiedBanner'
import { Button } from '../components/ui/Button'
import { api } from '../lib/api'
import { SteamPrivateProfileError } from '../components/steam/SteamPrivateProfileError'

export default function Dashboard() {
  const { user, logout, refresh } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const [vanity, setVanity] = useState('')
  const [syncing, setSyncing] = useState(false)
  const [connectingVanity, setConnectingVanity] = useState(false)
  const [notice, setNotice] = useState(() => {
    if (searchParams.get('steam_connected') === '1') {
      return { type: 'success', message: 'Steam connected. You can sync your library now.' }
    }

    const errorCode = searchParams.get('steam_error')

    if (errorCode === 'steam_id_already_linked') {
      return { type: 'error', message: 'That Steam account is already linked to another Cortex Lite account.' }
    }

    if (errorCode === 'steam_openid_verification_failed') {
      return { type: 'error', message: 'Steam could not verify the OpenID callback. Try connecting again or use the vanity fallback below.' }
    }

    return null
  })
  const [privateProfileHelp, setPrivateProfileHelp] = useState(null)

  useEffect(() => {
    if (searchParams.has('steam_connected') || searchParams.has('steam_error')) {
      const next = new URLSearchParams(searchParams)
      next.delete('steam_connected')
      next.delete('steam_error')
      setSearchParams(next, { replace: true })
    }
  }, [searchParams, setSearchParams])

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

  async function handleVanityConnect(event) {
    event.preventDefault()
    setConnectingVanity(true)
    setNotice(null)

    try {
      await api.post('/api/steam/connect-vanity', { vanity })
      await refresh()
      setVanity('')
      setPrivateProfileHelp(null)
      setNotice({
        type: 'success',
        message: 'Steam account connected from vanity URL. You can sync your library now.',
      })
    } catch (error) {
      if (error.response?.status === 422) {
        setNotice({
          type: 'error',
          message: error.response.data.message ?? 'Steam vanity lookup failed.',
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
      setConnectingVanity(false)
    }
  }

  function connectWithSteam() {
    window.location.assign('/api/steam/login')
  }

  return (
    <div className="mx-auto mt-10 w-full max-w-2xl space-y-6 px-4">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Cortex Lite</h1>
        <div className="flex items-center gap-3 text-sm">
          <Link to="/library" className="text-slate-600 hover:underline">Library</Link>
          <Link to="/account" className="text-slate-600 hover:underline">Account</Link>
          <button onClick={logout} className="text-slate-600 hover:underline">Log out</button>
        </div>
      </header>
      <VerifiedBanner user={user} />
      <section className="rounded-md border border-slate-200 p-6">
        <h2 className="text-lg font-medium">Welcome, {user.name}.</h2>
        <p className="mt-1 text-sm text-slate-600">
          Your account is ready. Steam connection and library sync now live here,
          while session tracking and the AI settings optimizer land in later phases.
        </p>
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
          <form className="mt-6 space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4" onSubmit={handleVanityConnect}>
            <div>
              <h3 className="font-medium text-slate-900">Manual fallback</h3>
              <p className="mt-1 text-sm text-slate-600">
                Paste a Steam vanity URL or handle if the OpenID redirect is awkward during the demo.
              </p>
            </div>
            <label className="block text-sm font-medium text-slate-700" htmlFor="steam-vanity">
              Vanity URL or handle
            </label>
            <input
              id="steam-vanity"
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-slate-900"
              onChange={(event) => setVanity(event.target.value)}
              placeholder="https://steamcommunity.com/id/your-handle/"
              value={vanity}
            />
            <Button type="submit" disabled={connectingVanity || vanity.trim() === ''}>
              {connectingVanity ? 'Connecting…' : 'Connect With Vanity'}
            </Button>
          </form>
        ) : null}
      </section>
    </div>
  )
}
