import { EmptyState, Pagination, Skeleton } from '../../../components/ui'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { Button } from '../../../components/ui/Button'

export function ReportTable({
  columns = [],
  rows = [],
  pagination,
  sort,
  direction = 'asc',
  onSort,
  onPageChange,
  totals = null,
  renderTotals,
  isLoading = false,
  error = null,
  onRetry,
  emptyTitle = 'No data found',
  emptyText = 'There are no records matching the current filters.',
  noun = 'record',
}) {
  if (isLoading && (!rows || rows.length === 0)) {
    return (
      <div className="rp-table-tab">
        <div className="ui-table-scroll">
          <table className="ui-table">
            <thead>
              <tr>
                {columns.map((col) => (
                  <th key={col.key} scope="col">
                    {col.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {[...Array(5)].map((_, idx) => (
                <tr key={idx}>
                  {columns.map((col) => (
                    <td key={col.key}>
                      <Skeleton style={{ height: '18px', width: '70%', borderRadius: '4px' }} />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    )
  }

  if (error && (!rows || rows.length === 0)) {
    return (
      <div className="rp-table-tab">
        <ApiErrorMessage error={error} />
        {onRetry && (
          <div style={{ marginTop: '0.75rem' }}>
            <Button type="button" variant="secondary" onClick={onRetry}>
              Retry
            </Button>
          </div>
        )}
      </div>
    )
  }

  if (!rows.length) {
    return (
      <div className="rp-table-tab">
        <EmptyState title={emptyTitle}>
          <p>{emptyText}</p>
        </EmptyState>
      </div>
    )
  }

  function handleHeaderClick(col) {
    if (!col.sortKey || !onSort) return
    const isCurrent = sort === col.sortKey
    const nextDir = isCurrent && direction === 'asc' ? 'desc' : 'asc'
    onSort(col.sortKey, nextDir)
  }

  return (
    <div className="rp-table-tab">
      <div className="ui-table-scroll">
        <table className="ui-table">
          <thead>
            <tr>
              {columns.map((col) => {
                const isSortable = Boolean(col.sortKey && onSort)
                const isCurrent = sort === col.sortKey
                const ariaSort = isSortable ? (isCurrent ? (direction === 'asc' ? 'ascending' : 'descending') : 'none') : undefined

                return (
                  <th
                    key={col.key}
                    scope="col"
                    aria-sort={ariaSort}
                    style={col.align ? { textAlign: col.align } : undefined}
                  >
                    {isSortable ? (
                      <button
                        type="button"
                        className="rp-sort-btn"
                        onClick={() => handleHeaderClick(col)}
                        aria-label={`Sort by ${col.label}${isCurrent ? ` (${direction === 'asc' ? 'descending' : 'ascending'})` : ''}`}
                      >
                        <span>{col.label}</span>
                        <span className={`rp-sort-indicator${!isCurrent ? ' is-idle' : ''}`} aria-hidden="true">
                          {isCurrent ? (direction === 'asc' ? '▲' : '▼') : '↕'}
                        </span>
                      </button>
                    ) : (
                      col.label
                    )}
                  </th>
                )
              })}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, rowIndex) => (
              <tr key={row.id ?? row.key ?? rowIndex}>
                {columns.map((col) => (
                  <td
                    key={col.key}
                    data-label={col.label}
                    style={col.align ? { textAlign: col.align } : undefined}
                  >
                    {col.render ? col.render(row[col.key], row) : row[col.key]}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
          {totals && renderTotals && (
            <tfoot className="rp-tfoot">
              {renderTotals(totals)}
            </tfoot>
          )}
        </table>
      </div>

      {pagination && pagination.total > 0 && (
        <Pagination
          page={pagination.current_page}
          lastPage={pagination.last_page}
          total={pagination.total}
          noun={noun}
          loading={isLoading}
          onPrevious={() => onPageChange?.(pagination.current_page - 1)}
          onNext={() => onPageChange?.(pagination.current_page + 1)}
        />
      )}
    </div>
  )
}
