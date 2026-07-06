const ORDINAL = ['low', 'medium', 'high', 'ultra']

const SETTING_FIELDS = [
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
