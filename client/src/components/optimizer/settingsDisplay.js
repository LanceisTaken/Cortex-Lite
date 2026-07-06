const ORDINAL_LEVELS = ['low', 'medium', 'high', 'ultra']

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

// Index into low → ultra for notch meters; -1 when the value is not ordinal.
export function ordinalIndex(value) {
  if (typeof value !== 'string') return -1
  return ORDINAL_LEVELS.indexOf(value.toLowerCase())
}
