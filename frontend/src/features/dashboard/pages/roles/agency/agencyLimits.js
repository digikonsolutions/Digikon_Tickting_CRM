// Pure rules for the agency dashboard: which actions a limit blocks, and the warnings to show.
// No contract means every limit is null, and a null limit is never reached.

/** What the limits mean for the buttons: which actions are blocked, and the sentence that says why. */
export function limitState(dashboard) {
  const { limits, role } = dashboard
  const isOwner = role === 'agency_owner'
  const hoursReached = Boolean(limits.hours?.reached)
  const projectsReached = Boolean(limits.open_projects?.reached)
  const seatsReached = isOwner && Boolean(limits.employees?.reached)
  return {
    isOwner,
    createDisabled: projectsReached || hoursReached,
    createReason: projectsReached
      ? `You have ${limits.open_projects.used} of ${limits.open_projects.allowed} open projects. Finish or cancel one to create another.`
      : hoursReached ? 'All included hours for this period are used. Request more hours to create a project.' : null,
    holdDisabled: seatsReached,
    holdReason: seatsReached ? `You are holding ${limits.employees.used} of ${limits.employees.allowed} employees. Release one or request an upgrade to hold another.` : null,
  }
}

export function limitWarnings(dashboard) {
  const { limits, role } = dashboard
  const isOwner = role === 'agency_owner'
  const share = (limit) => Math.round((limit.used / limit.allowed) * 100)
  const out = []
  const check = (key, noun, reachedText, canUpgrade) => {
    const limit = limits[key]
    if (!limit || limit.allowed == null) return
    if (limit.reached) out.push({ key, variant: 'error', title: `${noun} limit reached`, text: reachedText, upgrade: canUpgrade })
    else if (limit.nearly) out.push({ key, variant: 'warning', title: `${share(limit)}% of ${noun.toLowerCase()} used`, text: `${limit.used} of ${limit.allowed} used.`, upgrade: canUpgrade })
  }
  if (isOwner) {
    check('employees', 'Seat', 'Release an employee or request an upgrade to hold another.', true)
    check('hours', 'Hours', 'All included hours for this period are used. Request more hours to keep scheduling work.', true)
    check('open_projects', 'Open project', 'Finish or cancel a project before creating another.', false)
    check('client_accounts', 'Client account', 'Remove a client account or request an upgrade to add another.', true)
  } else {
    check('open_projects', 'Open project', 'Finish or cancel one of your projects to create another.', false)
    if (limits.hours?.reached) out.push({ key: 'hours', variant: 'error', title: 'Hours used up', text: 'All included hours for this period are used.', upgrade: true })
  }
  return out
}
