import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { HardwareAutocomplete } from '../components/hardware/HardwareAutocomplete'
import { probeWebGpu, readBrowserHints } from '../lib/browserHardware'

function TierBadge({ tier }) {
  if (!tier) return null

  const colors = {
    low: 'bg-slate-200 text-slate-700',
    mid: 'bg-sky-200 text-sky-800',
    high: 'bg-emerald-200 text-emerald-800',
    enthusiast: 'bg-fuchsia-200 text-fuchsia-800',
  }

  return (
    <span className={`inline-block rounded px-2 py-0.5 text-xs uppercase ${colors[tier] ?? 'bg-slate-200 text-slate-700'}`}>
      {tier}
    </span>
  )
}

export default function Hardware() {
  const [gpu, setGpu] = useState(null)
  const [cpu, setCpu] = useState(null)
  const [hints, setHints] = useState({ cpuCores: null, deviceMemoryGb: null })
  const [webgpu, setWebgpu] = useState({ supported: false, adapterInfo: null })

  useEffect(() => {
    setHints(readBrowserHints())
    probeWebGpu().then(setWebgpu)
  }, [])

  return (
    <div className="mx-auto w-full max-w-3xl space-y-6 px-4 py-8">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold text-slate-900">Hardware profile</h1>
        <Link to="/dashboard" className="text-sm text-slate-500 hover:text-slate-700">Dashboard</Link>
      </header>

      <section className="rounded-md border border-slate-200 bg-slate-50 p-4">
        <h2 className="mb-2 text-sm font-semibold text-slate-700">Browser detected</h2>
        <ul className="mb-3 space-y-1 text-sm text-slate-700">
          <li><strong>CPU cores:</strong> {hints.cpuCores ?? 'Not exposed by this browser'}</li>
          <li><strong>Device memory (GB, rounded):</strong> {hints.deviceMemoryGb ?? 'Not exposed by this browser'}</li>
          <li><strong>WebGPU:</strong> {webgpu.supported ? webgpu.adapterInfo : 'Not supported / disabled'}</li>
        </ul>
        <p className="text-xs text-slate-500">
          The browser cannot reliably identify your specific GPU model for privacy reasons. Please select it manually below.
        </p>
      </section>

      <section className="space-y-4 rounded-md border border-slate-200 p-4">
        <HardwareAutocomplete kind="gpu" value={gpu} onChange={setGpu} />
        <HardwareAutocomplete kind="cpu" value={cpu} onChange={setCpu} />
      </section>

      <section className="rounded-md border border-slate-200 bg-white p-4">
        <h2 className="mb-3 text-sm font-semibold text-slate-700">Selected</h2>
        <div className="grid gap-4 text-sm sm:grid-cols-2">
          <div>
            <div className="text-slate-500">GPU</div>
            {gpu ? (
              <div>
                <div className="font-medium">{gpu.name}</div>
                <div className="mt-1 flex items-center gap-2 text-xs text-slate-600">
                  <TierBadge tier={gpu.tier} />
                  <span>{gpu.g3d_mark.toLocaleString()} G3D</span>
                </div>
              </div>
            ) : <div className="text-slate-400">Not selected</div>}
          </div>
          <div>
            <div className="text-slate-500">CPU</div>
            {cpu ? (
              <div>
                <div className="font-medium">{cpu.name}</div>
                <div className="mt-1 flex items-center gap-2 text-xs text-slate-600">
                  <TierBadge tier={cpu.tier} />
                  <span>{cpu.single_thread_mark.toLocaleString()} ST</span>
                </div>
              </div>
            ) : <div className="text-slate-400">Not selected</div>}
          </div>
        </div>
        <p className="mt-4 text-xs text-slate-400">
          Selection is not saved server-side in this phase. It will feed the recommender in Phase 5.
        </p>
      </section>
    </div>
  )
}
