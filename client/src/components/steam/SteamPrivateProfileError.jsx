export function SteamPrivateProfileError({ help, className = '' }) {
  return (
    <div className={`rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 ${className}`}>
      <h3 className="font-semibold">Steam privacy settings need one more pass</h3>
      <p className="mt-1">
        Steam library sync needs both privacy toggles set to Public.
      </p>
      <ul className="mt-3 list-disc space-y-1 pl-5">
        <li>Profile: {help?.profile_toggle ?? 'Set "My profile" to Public.'}</li>
        <li>Game Details: {help?.game_details_toggle ?? 'Set "Game details" to Public.'}</li>
      </ul>
      <a
        className="mt-3 inline-flex text-amber-900 underline hover:no-underline"
        href={help?.url ?? 'https://steamcommunity.com/my/edit/settings'}
        target="_blank"
        rel="noreferrer"
      >
        Open Steam privacy settings
      </a>
    </div>
  )
}
