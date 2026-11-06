import { useCallback, useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Input,
  Loader,
  Modal,
  PageHeader,
  Select,
  Spinner,
  Table,
  Textarea,
  useToast,
} from '../../components/ui'

const TABLE_COLUMNS = [
  { key: 'name', label: 'Name' },
  { key: 'status', label: 'Status', render: (status) => <Badge preset={status}>{status}</Badge> },
  { key: 'priority', label: 'Priority', render: (priority) => <Badge preset={priority}>{priority}</Badge> },
]

const TABLE_ROWS = [
  { id: 1, name: 'Agency onboarding', status: 'In Progress', priority: 'High' },
  { id: 2, name: 'Password access', status: 'Waiting for Agency', priority: 'Normal' },
]

const BADGES = [
  'New', 'Assigned', 'In Progress', 'Waiting for Agency', 'Blocked', 'QA Review',
  'Resolved', 'Closed', 'Reopened', 'Low', 'Normal', 'High', 'Urgent',
  'On Track', 'At Risk', 'Breached', 'Paused',
]

export function UiShowcasePage() {
  const { showToast } = useToast()
  const [isModalOpen, setIsModalOpen] = useState(false)
  const closeModal = useCallback(() => setIsModalOpen(false), [])

  return (
    <main className="ui-showcase">
      <PageHeader
        eyebrow="Developer preview"
        title="ClientHub UI"
        subtitle="Reusable components and states using the current login-page visual language."
      />

      <div className="ui-showcase-grid">
        <Card title="Buttons" description="Variants, sizes, loading and disabled states.">
          <div className="ui-demo-row">
            <Button>Primary</Button>
            <Button variant="secondary">Secondary</Button>
            <Button variant="ghost">Ghost</Button>
            <Button variant="danger">Danger</Button>
          </div>
          <div className="ui-demo-row">
            <Button size="sm">Small</Button>
            <Button size="lg">Large</Button>
            <Button loading>Loading</Button>
            <Button disabled>Disabled</Button>
          </div>
        </Card>

        <Card title="Fields" description="Labels, helper text, required markers and validation.">
          <div className="ui-demo-fields">
            <Input label="Email address" type="email" placeholder="you@example.com" required helperText="We’ll only use this for account access." />
            <Input label="Invalid field" defaultValue="not-an-email" error="Enter a valid email address." />
            <Select label="Role" defaultValue="admin">
              <option value="admin">Administrator</option>
              <option value="agency_owner">Agency owner</option>
            </Select>
            <Textarea label="Message" placeholder="Write a short note…" rows={3} />
          </div>
        </Card>

        <Card title="Alerts" description="Clear semantic status that does not rely on color alone.">
          <div className="ui-demo-stack">
            <Alert variant="success" title="Invite sent">An invitation has been sent.</Alert>
            <Alert variant="error" title="Could not save">Check the highlighted fields and try again.</Alert>
            <Alert variant="warning" title="Review needed">Some details may need attention.</Alert>
            <Alert variant="info" title="Helpful information">The database assigns agency IDs automatically.</Alert>
          </div>
        </Card>

        <Card title="Badges" description="Ticket status, priority, SLA and account roles.">
          <div className="ui-demo-row">
            {BADGES.map((preset) => <Badge key={preset} preset={preset} />)}
            <Badge variant="primary">Administrator</Badge>
            <Badge variant="slate">Agency member</Badge>
          </div>
        </Card>

        <Card title="Page header and empty state">
          <PageHeader eyebrow="Requests" title="No requests yet" subtitle="New requests will appear here." />
          <EmptyState icon="○" title="Nothing here yet">
            When you create a request, it will show up in this list.
          </EmptyState>
        </Card>

        <Card title="Loading and table states">
          <div className="ui-demo-row ui-demo-loading">
            <Spinner label="Loading component preview" />
            <span>Small inline loader</span>
          </div>
          <div className="ui-loader-preview"><Loader label="Loading your workspace…" /></div>
          <Table columns={TABLE_COLUMNS} rows={TABLE_ROWS} />
          <div className="ui-demo-table-states">
            <Table columns={TABLE_COLUMNS} loading />
            <Table columns={TABLE_COLUMNS} error="Could not load these rows. Try again." />
            <Table columns={TABLE_COLUMNS} rows={[]} emptyTitle="No requests" emptyText="There are no requests to show." />
          </div>
        </Card>

        <Card title="Modal and toast">
          <div className="ui-demo-row">
            <Button onClick={() => setIsModalOpen(true)}>Open modal</Button>
            <Button onClick={() => showToast('Your changes have been saved.', 'success')}>Show success toast</Button>
            <Button variant="secondary" onClick={() => showToast('The request could not be completed.', 'error')}>Show error toast</Button>
          </div>
          <Modal
            open={isModalOpen}
            onClose={closeModal}
            title="Confirm this action"
            footer={<><Button variant="secondary" onClick={closeModal}>Cancel</Button><Button variant="danger" onClick={closeModal}>Continue</Button></>}
          >
            This dialog traps keyboard focus and closes with Escape.
          </Modal>
        </Card>
      </div>
    </main>
  )
}
