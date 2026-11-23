// What each role may do in the interface. The API enforces the same rules; this only decides what is drawn.
// Scheduling is the admin's: agency owners and clients send a request with an optional estimate, and our team plans the days.
const ABILITIES = Object.freeze({
  'schedule.set': ['admin'],
})

export function can(user, ability) {
  return Boolean(user?.role && (ABILITIES[ability] ?? []).includes(user.role))
}
