export function DataList({ items = [], className = '' }) {
  return (
    <dl className={`ui-datalist ${className}`.trim()}>
      {items.map((item, index) => (
        <div className="ui-datalist-row" key={item.label ?? index}>
          <dt className="ui-datalist-label">{item.label}</dt>
          <dd className="ui-datalist-value">{item.value}</dd>
        </div>
      ))}
    </dl>
  )
}
