export function Card({ title, description, header, footer, children, className = '', ...props }) {
  return (
    <section className={`ui-card ${className}`.trim()} {...props}>
      {(header || title || description) && (
        <header className="ui-card-header">
          {header ?? (
            <>
              {title && <h2>{title}</h2>}
              {description && <p>{description}</p>}
            </>
          )}
        </header>
      )}
      <div className="ui-card-body">{children}</div>
      {footer && <footer className="ui-card-footer">{footer}</footer>}
    </section>
  )
}
