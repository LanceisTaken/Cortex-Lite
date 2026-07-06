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
