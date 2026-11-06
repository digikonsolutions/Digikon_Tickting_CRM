import { EmptyState } from './EmptyState'
import { Spinner } from './Spinner'

export function Table({ columns, rows = [], loading = false, error, emptyTitle = 'No results', emptyText = 'There is nothing to show yet.' }) {
  if (loading) {
    return <div className="ui-table-state"><Spinner label="Loading rows" /><span>Loading…</span></div>
  }
  if (error) return <div className="ui-table-state ui-table-error" role="alert">{error}</div>
  if (!rows.length) {
    return <EmptyState title={emptyTitle}>{emptyText}</EmptyState>
  }

  return (
    <div className="ui-table-scroll">
      <table className="ui-table">
        <thead>
          <tr>{columns.map((column) => <th key={column.key} scope="col">{column.label}</th>)}</tr>
        </thead>
        <tbody>
          {rows.map((row, rowIndex) => (
            <tr key={row.id ?? row.key ?? rowIndex}>
              {columns.map((column) => (
                <td key={column.key}>
                  {column.render ? column.render(row[column.key], row) : row[column.key]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
