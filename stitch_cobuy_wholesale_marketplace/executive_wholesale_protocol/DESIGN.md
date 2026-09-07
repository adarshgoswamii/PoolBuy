---
name: Executive Wholesale Protocol
colors:
  surface: '#faf8ff'
  surface-dim: '#d9d9e5'
  surface-bright: '#faf8ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f3f3fe'
  surface-container: '#ededf9'
  surface-container-high: '#e7e7f3'
  surface-container-highest: '#e1e2ed'
  on-surface: '#191b23'
  on-surface-variant: '#434655'
  inverse-surface: '#2e3039'
  inverse-on-surface: '#f0f0fb'
  outline: '#737686'
  outline-variant: '#c3c6d7'
  surface-tint: '#0053db'
  primary: '#004ac6'
  on-primary: '#ffffff'
  primary-container: '#2563eb'
  on-primary-container: '#eeefff'
  inverse-primary: '#b4c5ff'
  secondary: '#565e74'
  on-secondary: '#ffffff'
  secondary-container: '#dae2fd'
  on-secondary-container: '#5c647a'
  tertiary: '#943700'
  on-tertiary: '#ffffff'
  tertiary-container: '#bc4800'
  on-tertiary-container: '#ffede6'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dbe1ff'
  primary-fixed-dim: '#b4c5ff'
  on-primary-fixed: '#00174b'
  on-primary-fixed-variant: '#003ea8'
  secondary-fixed: '#dae2fd'
  secondary-fixed-dim: '#bec6e0'
  on-secondary-fixed: '#131b2e'
  on-secondary-fixed-variant: '#3f465c'
  tertiary-fixed: '#ffdbcd'
  tertiary-fixed-dim: '#ffb596'
  on-tertiary-fixed: '#360f00'
  on-tertiary-fixed-variant: '#7d2d00'
  background: '#faf8ff'
  on-background: '#191b23'
  surface-variant: '#e1e2ed'
typography:
  display-lg:
    fontFamily: Inter
    fontSize: 36px
    fontWeight: '700'
    lineHeight: '1.2'
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: '1.3'
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '600'
    lineHeight: '1.4'
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: '1.6'
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: '1.5'
  label-md:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: '1'
    letterSpacing: 0.05em
  mono-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '500'
    lineHeight: '1.4'
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 4px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  2xl: 48px
  gutter: 24px
  margin: 32px
---

## Brand & Style
The design system is engineered for a premium B2B wholesale environment, blending the systematic efficiency of enterprise SaaS with the urgency of a high-stakes procurement marketplace. The brand personality is authoritative yet accessible, designed to evoke confidence in high-volume transactions.

The visual style is **Corporate / Modern**, heavily influenced by the high-density utility of the Stripe Dashboard and the refined minimalism of Linear. It prioritizes clarity over decoration, using ample whitespace to reduce cognitive load during complex inventory management. The emotional response is one of institutional trust and technological sophistication, specifically tailored for the "pool buying" experience—where collective procurement meets industrial scale.

## Colors
The palette is built on a foundation of "Trust Blue" and "Deep Slate" to ground the interface in professionalism. 

- **Primary (Royal Blue):** Used for primary actions, active states, and brand-critical touchpoints.
- **Secondary (Deep Slate):** Reserved for high-level navigation, headings, and complex data visualization to provide strong contrast.
- **Accent Green:** Signals "Verified" statuses and successful transaction completion.
- **Accent Orange:** High-visibility indicator for "Pool Status," indicating urgency in time-sensitive wholesale deals.
- **Background/Surface:** A soft Slate-tinted grey background creates a subtle separation from the pure white surfaces of cards and modal containers.

## Typography
The system utilizes **Inter** exclusively to achieve a functional, systematic aesthetic. The hierarchy is defined by tight tracking in large headlines and generous leading in body copy to ensure legibility during long sessions.

For data-heavy tables and procurement lists, the system employs tabular numbers (`tnum`) to ensure columns of figures align vertically, maintaining the "professional ledger" feel required for B2B transactions.

## Layout & Spacing
The layout follows a **Fixed-Fluid Hybrid** model. On desktop, content is constrained to a 1280px max-width container to prevent line-lengths from becoming unreadable on ultra-wide monitors. 

A 12-column grid is used for the main dashboard, with a 24px gutter. For the "Pool Buying" marketplace, cards should span 3 columns on desktop (4 per row) and reflow to 2 columns on tablet. Margins are generous (32px+) to create a "premium" sense of space, avoiding the cluttered feel of traditional wholesale catalogs.

## Elevation & Depth
Depth is communicated through **Tonal Layers** and **Ambient Shadows**. 
- **Level 0 (Background):** #F8FAFC.
- **Level 1 (Surface/Cards):** White background with a 1px border (#E2E8F0) and a very soft, diffused shadow (0px 4px 6px -1px rgba(0,0,0,0.05)).
- **Level 2 (Modals/Popovers):** Slightly more pronounced shadow with an 8% opacity to simulate physical lift.

Glassmorphism is used sparingly, only for sticky header navigations to maintain context of the scroll position without obscuring the content entirely.

## Shapes
The shape language is consistently **Rounded** to soften the industrial nature of B2B procurement. 
- All primary containers and cards use a 16px (1rem) corner radius.
- Buttons and input fields use a 8px (0.5rem) radius to feel precise but modern.
- Progress bars and trust badges use a fully "pill" (9999px) radius to differentiate them as status indicators rather than structural containers.

## Components
### Buttons
Primary buttons feature a subtle vertical gradient (from Primary to a slightly darker shade) to provide a tactile "pressable" feel. Secondary buttons use a "Ghost" style: a transparent background with a 1px slate border.

### Pool Status Bars
Animated progress bars indicate the "filling" of a wholesale pool. The background is a light tint of the primary color, while the progress indicator uses the Primary Blue or Accent Orange for high-urgency deals. Use a subtle pulse animation on the "leading edge" of the progress bar to indicate active growth.

### Trust Badges
Small, pill-shaped indicators with a subtle background tint and 1px border.
- **GST Verified:** Deep Slate text on a light Slate background.
- **Verified Seller:** Accent Green text on a 10% opacity Green background.

### Input Fields
Inputs should have a 1px border (#E2E8F0) that transitions to Primary Blue on focus. Labels should use the `label-md` typography style, positioned strictly above the field for maximum clarity.

### Cards
Cards are the primary vessel for products. They must include a white surface, 16px rounded corners, and a 1px border. Inside, the "Pool Buy" CTA should always be anchored to the bottom right, with the "Remaining Time" or "Stock Left" highlighted in Accent Orange.