// Mock data shaped like the planned REST responses (see developer handoff, sections 4 and 11).
// Replace the calls in src/services/dashboardService.js with real /api/v1 requests later;
// the components only depend on these shapes.

export const MOCK_TICKETS = [
  { id: 'TK-1048', title: 'Fix calendar booking workflow not triggering SMS', service: 'GHL / CRM Automation', agency: 'Northwind Digital', status: 'In Progress', priority: 'Urgent', sla: 'At Risk', assignee: 'Aisha Khan', due: 'Today, 5:00 PM', updated: '12 min ago', description: 'The appointment-booked trigger fires, but the confirmation SMS step never runs.' },
  { id: 'TK-1047', title: 'Landing page: update hero copy and CTA colour', service: 'Marketing fulfillment', agency: 'Brightside Media', status: 'New', priority: 'Normal', sla: 'On Track', assignee: null, due: 'Tomorrow', updated: '34 min ago', description: 'New campaign copy attached. CTA should match brand guidelines.' },
  { id: 'TK-1046', title: 'Checkout page broken on Safari iOS', service: 'Web development', agency: 'Northwind Digital', status: 'QA Review', priority: 'High', sla: 'On Track', assignee: 'Omar Siddiqui', due: 'Today, 8:00 PM', updated: '1 hr ago', description: 'Payment button unresponsive on Safari 17 / iOS 17.' },
  { id: 'TK-1045', title: 'Add webhook for new-lead sync to CRM', service: 'Software development', agency: 'Peak Growth Co', status: 'Assigned', priority: 'High', sla: 'On Track', assignee: 'Sara Ahmed', due: 'Fri, Nov 8', updated: '2 hr ago', description: 'Push new leads to the agency CRM via signed webhook.' },
  { id: 'TK-1044', title: 'Need access credentials for client funnel', service: 'GHL / CRM Automation', agency: 'Brightside Media', status: 'Waiting for Agency', priority: 'Normal', sla: 'Paused', assignee: 'Aisha Khan', due: 'Paused', updated: '3 hr ago', description: 'Waiting on the agency to share the sub-account login.' },
  { id: 'TK-1043', title: 'Regression test for v2.4 release', service: 'QA / testing', agency: 'Peak Growth Co', status: 'QA Review', priority: 'Normal', sla: 'On Track', assignee: 'Hira Tariq', due: 'Mon, Nov 11', updated: 'Yesterday', description: 'Run full regression checklist before the production release.' },
  { id: 'TK-1042', title: 'Server downtime alert: API latency spike', service: 'IT / DevOps', agency: 'Northwind Digital', status: 'Resolved', priority: 'Urgent', sla: 'On Track', assignee: 'Omar Siddiqui', due: 'Done', updated: 'Yesterday', description: 'Root cause was a stuck queue worker. Restarted and monitored.' },
  { id: 'TK-1041', title: 'Email nurture sequence setup (5 emails)', service: 'Marketing fulfillment', agency: 'Brightside Media', status: 'In Progress', priority: 'Low', sla: 'Breached', assignee: 'Sara Ahmed', due: 'Overdue', updated: '2 days ago', description: 'Five-step welcome sequence with merge fields.' },
]

export const MOCK_ACTIVITY = [
  { id: 1, tone: 'success', text: 'TK-1042 was resolved by Omar Siddiqui', time: '2 hr ago' },
  { id: 2, tone: 'info', text: 'New comment on TK-1048 from Northwind Digital', time: '3 hr ago' },
  { id: 3, tone: 'warning', text: 'TK-1041 breached its resolution SLA', time: 'Yesterday' },
  { id: 4, tone: 'primary', text: 'TK-1045 assigned to Sara Ahmed', time: 'Yesterday' },
  { id: 5, tone: 'info', text: 'TK-1047 submitted by Brightside Media', time: '2 days ago' },
]

export const MOCK_STATS = {
  admin: [
    { id: 'open', label: 'Open tickets', value: 24, hint: '6 unassigned', icon: 'ticket', tone: 'primary' },
    { id: 'sla', label: 'SLA compliance', value: '94%', hint: '+2% vs last month', icon: 'shield', tone: 'success' },
    { id: 'overdue', label: 'Overdue', value: 3, hint: 'Needs attention', icon: 'alert', tone: 'danger' },
    { id: 'agencies', label: 'Active agencies', value: 12, hint: '2 added this month', icon: 'users', tone: 'info' },
  ],
  team: [
    { id: 'queue', label: 'In team queue', value: 9, hint: '4 unassigned', icon: 'queue', tone: 'primary' },
    { id: 'progress', label: 'In progress', value: 11, hint: 'Across 6 members', icon: 'clock', tone: 'info' },
    { id: 'risk', label: 'SLA at risk', value: 2, hint: 'Due within 4 hours', icon: 'alert', tone: 'warning' },
    { id: 'done', label: 'Resolved this week', value: 18, hint: '+5 vs last week', icon: 'check', tone: 'success' },
  ],
  agency: [
    { id: 'open', label: 'Open requests', value: 5, hint: '2 in progress', icon: 'ticket', tone: 'primary' },
    { id: 'waiting', label: 'Waiting on you', value: 1, hint: 'Reply to continue', icon: 'clock', tone: 'warning' },
    { id: 'review', label: 'Ready for review', value: 2, hint: 'Approve or request changes', icon: 'check', tone: 'info' },
    { id: 'done', label: 'Completed', value: 31, hint: 'Last 90 days', icon: 'shield', tone: 'success' },
  ],
}
