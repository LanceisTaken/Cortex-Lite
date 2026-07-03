export function readBrowserHints() {
  const cpuCores = typeof navigator !== 'undefined' && typeof navigator.hardwareConcurrency === 'number'
    ? navigator.hardwareConcurrency
    : null
  const deviceMemoryGb = typeof navigator !== 'undefined' && typeof navigator.deviceMemory === 'number'
    ? navigator.deviceMemory
    : null

  return { cpuCores, deviceMemoryGb }
}

export async function probeWebGpu() {
  if (typeof navigator === 'undefined' || !navigator.gpu) {
    return { supported: false, adapterInfo: null }
  }

  try {
    const adapter = await navigator.gpu.requestAdapter()
    if (!adapter) return { supported: false, adapterInfo: null }

    if (typeof adapter.requestAdapterInfo === 'function') {
      const info = await adapter.requestAdapterInfo()
      const parts = [info.vendor, info.architecture, info.device].filter(Boolean)
      return { supported: true, adapterInfo: parts.length ? parts.join(' - ') : 'Adapter present (vendor-masked)' }
    }

    return { supported: true, adapterInfo: 'Adapter present (vendor-masked)' }
  } catch {
    return { supported: false, adapterInfo: null }
  }
}
