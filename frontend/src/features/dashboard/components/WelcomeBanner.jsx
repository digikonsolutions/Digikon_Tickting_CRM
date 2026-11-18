import { useBranding } from '../../../branding/useBranding'

export function WelcomeBanner({ eyebrow, title, subtitle, children }) {
  // Agency users see their agency's tagline and welcome message when the owner has set them.
  const { tagline, welcomeMessage } = useBranding()
  const eyebrowText = tagline || eyebrow
  const subtitleText = welcomeMessage || subtitle

  return (
    <section className="welcome-banner">
      <div>
        {eyebrowText && <p className="welcome-eyebrow">{eyebrowText}</p>}
        <h1>{title}</h1>
        {subtitleText && <p>{subtitleText}</p>}
      </div>
      {children && <div className="welcome-actions">{children}</div>}
    </section>
  )
}
