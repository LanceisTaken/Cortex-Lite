import { CpuBottleneckWarning, ExplanationFooter, TierSummary } from './RecommendationResult'
import { settingLabel } from './settingsDisplay'

export function DiffResult({ result }) {
  const { diff, recommendation } = result

  return (
    <section className="panel-reveal overflow-hidden rounded-lg border border-slate-800 bg-slate-950 text-slate-100 shadow-xl">
      <header className="border-b border-slate-800 px-5 py-4">
        <p className="font-mono text-[11px] uppercase tracking-[0.2em] text-slate-500">Settings check</p>
        <h2 className="mt-1 text-lg font-medium capitalize">{result.goal} target</h2>
      </header>

      <div className="space-y-4 px-5 py-4">
        <TierSummary
          gpuTier={recommendation.gpu_tier}
          cpuTier={recommendation.cpu_tier}
          ramBucket={recommendation.ram_bucket}
        />
        {recommendation.cpu_bottleneck ? <CpuBottleneckWarning /> : null}

        {diff.length === 0 ? (
          <div className="rounded-md border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-200">
            Your settings already match the recommendation. Nothing to change.
          </div>
        ) : (
          <ul className="divide-y divide-slate-800/70">
            {diff.map((entry) => (
              <li
                className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2.5 text-sm"
                key={entry.setting}
              >
                <span className="text-slate-400">{settingLabel(entry.setting)}</span>
                <span className="flex items-center gap-2 font-mono">
                  <span className="text-rose-400 line-through decoration-rose-400/60">{entry.current}</span>
                  <span aria-hidden="true" className="text-slate-600">→</span>
                  <span className="text-emerald-400">{entry.recommended}</span>
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>

      <ExplanationFooter explanation={result.explanation} />
    </section>
  )
}
