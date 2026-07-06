import { displayValue, ordinalIndex, settingLabel } from './settingsDisplay'

// The results render as an in-game video-settings panel: dark surface, mono
// OSD-style labels, and notch meters for ordinal quality levels — the idiom
// players will see when they apply these values in the actual game menu.

function SourceBadge({ source }) {
  const anchor = source === 'anchor'
  return (
    <span
      className={`rounded-full border px-2.5 py-1 font-mono text-[10px] uppercase tracking-[0.15em] ${
        anchor ? 'border-emerald-400/40 text-emerald-300' : 'border-sky-400/40 text-sky-300'
      }`}
    >
      {anchor ? 'Curated preset' : 'Heuristic engine'}
    </span>
  )
}

export function LevelMeter({ value }) {
  const index = ordinalIndex(value)
  if (index === -1) return null

  return (
    <span aria-hidden="true" className="flex items-center gap-0.5">
      {[0, 1, 2, 3].map((notch) => (
        <span
          key={notch}
          className={`h-1.5 w-3 rounded-sm ${notch <= index ? 'bg-emerald-400' : 'bg-slate-700'}`}
        />
      ))}
    </span>
  )
}

export function TierSummary({ gpuTier, cpuTier, ramBucket }) {
  return (
    <div className="flex flex-wrap gap-x-5 gap-y-1 font-mono text-[11px] uppercase tracking-[0.15em] text-slate-400">
      <span>GPU <span className="text-slate-100">{gpuTier}</span></span>
      <span>CPU <span className="text-slate-100">{cpuTier}</span></span>
      <span>RAM <span className="text-slate-100">{ramBucket.replace(/_/g, ' ')}</span></span>
    </div>
  )
}

export function CpuBottleneckWarning() {
  return (
    <div className="rounded-md border border-amber-400/30 bg-amber-400/10 p-3 text-sm text-amber-200">
      Your CPU trails your GPU by two or more tiers — CPU-bound scenes may still cap your frame rate.
    </div>
  )
}

export function ExplanationFooter({ explanation }) {
  return (
    <footer className="border-t border-slate-800 bg-slate-900/60 px-5 py-4">
      <p className="font-mono text-[11px] uppercase tracking-[0.2em] text-slate-500">Why these settings</p>
      <p className="mt-2 text-sm leading-relaxed text-slate-300">{explanation}</p>
    </footer>
  )
}

export function RecommendationResult({ result }) {
  return (
    <section className="panel-reveal overflow-hidden rounded-lg border border-slate-800 bg-slate-950 text-slate-100 shadow-xl">
      <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 px-5 py-4">
        <div>
          <p className="font-mono text-[11px] uppercase tracking-[0.2em] text-slate-500">Graphics settings</p>
          <h2 className="mt-1 text-lg font-medium capitalize">{result.goal} preset</h2>
        </div>
        <SourceBadge source={result.source} />
      </header>

      <div className="space-y-4 px-5 py-4">
        <TierSummary gpuTier={result.gpu_tier} cpuTier={result.cpu_tier} ramBucket={result.ram_bucket} />
        {result.cpu_bottleneck ? <CpuBottleneckWarning /> : null}

        <dl className="divide-y divide-slate-800/70">
          {Object.entries(result.settings).map(([key, value]) => (
            <div className="flex items-center justify-between gap-4 py-2.5 text-sm" key={key}>
              <dt className="text-slate-400">{settingLabel(key)}</dt>
              <dd className="flex items-center gap-3">
                <LevelMeter value={value} />
                <span className="font-mono text-emerald-400">{displayValue(value)}</span>
              </dd>
            </div>
          ))}
        </dl>
      </div>

      <ExplanationFooter explanation={result.explanation} />
    </section>
  )
}
