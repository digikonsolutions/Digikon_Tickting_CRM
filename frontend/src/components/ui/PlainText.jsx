import { Fragment } from 'react'

// Matches http(s) URLs; trailing punctuation is left out of the link.
const URL_PATTERN = /(https?:\/\/[^\s<>"']*[^\s<>"'.,;:!?)\]])/g

function isSafeUrl(value) {
  try {
    const url = new URL(value)
    return url.protocol === 'http:' || url.protocol === 'https:'
  } catch {
    return false
  }
}

function linkify(line) {
  // split() with a capture group puts the URLs at the odd indexes.
  return line.split(URL_PATTERN).map((part, index) => (
    index % 2 === 1 && isSafeUrl(part)
      ? <a key={index} href={part} target="_blank" rel="noopener noreferrer">{part}</a>
      : <Fragment key={index}>{part}</Fragment>
  ))
}

// Renders user text as text (never HTML), keeping line breaks and auto-linking http(s) URLs.
export function PlainText({ text, className = '' }) {
  const lines = String(text ?? '').split(/\r?\n/)
  return (
    <p className={`ui-plain-text ${className}`.trim()}>
      {lines.map((line, index) => (
        <Fragment key={index}>
          {index > 0 && <br />}
          {linkify(line)}
        </Fragment>
      ))}
    </p>
  )
}
