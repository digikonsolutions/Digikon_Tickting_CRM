// Capacity comes from the API (the `capacity` field on assignments and /work-items/mine, driven by
// backend config/scheduling.php). This is only the fallback for mock mode or a missing payload.
export const DEFAULT_CAPACITY = Object.freeze({ daily_hours: 6, weekly_hours: 36 })

// Calendar labels for the working days Monday to Saturday.
export const WORKING_WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
// A project due within this many days (and not yet overdue) is "at risk".
export const AT_RISK_DAYS = 2
