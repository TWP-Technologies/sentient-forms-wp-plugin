# WordPress.org Asset Placement Checklist

This checklist covers `MKT-020` and `MKT-011`. It is a repo-ready planning
artifact only; `MKT-012`, screenshot capture, Google Stitch generation, Product
Design critique, SVN asset commits, release tagging, and WordPress.org
publication remain separate follow-up work.

## Placement Rules

- WordPress.org listing media belongs in the top-level SVN `assets/` directory.
- Do not place listing media in `trunk/assets`, in a release tag, or in the
  plugin ZIP package.
- Existing icon assets are already a separate listing-media lane. Do not add
  protected artwork to Git unless that exposure is explicitly approved.
- Update `readme-byte-budget-ledger.md` before adding screenshot captions to
  `readme.txt`.

## Required Filenames

| Asset | Filename | Requirement |
|---|---|---|
| Normal banner | `banner-772x250.png` | Required for the first banner pass. |
| High-DPI banner | `banner-1544x500.png` | Required with the normal banner. |
| Screenshots | `screenshot-N.png` | Future numbered screenshots; each needs a matching `== Screenshots ==` caption line in `readme.txt`. |

## Locked Banner Direction

- Creative direction: product workflow.
- Story: Gravity Forms submission -> AI action review -> visible WordPress
  result.
- Visual content: Sentient Forms brand mark plus polished, generated UI elements
  derived from real product screenshots: simplified form card, action chips for
  Lead Scoring A/B/C/Reject, Spam Review, Entry Summary, and Action Log.
- Source references: `docs/brand/` assets.
- Avoid abstract AI art, chatbot framing, unsupported automation claims, tiny UI
  text, and screenshots that imply behavior not available in the current release.

## Banner Production Brief For MKT-012

- Reserve literal product screenshots for the WordPress.org screenshots tab.
- Capture real product screenshots first so the banner is grounded in shipped UI,
  then use Google Stitch MCP to generate a high-resolution polished UI/banner
  composition rather than placing raw screenshots directly in the banner.
- Iterate the Stitch prompt multiple times as needed, using Product Design review
  after each candidate. Accept the banner only when there are no unresolved
  material Product Design critiques around hierarchy, polish, spacing, legibility,
  brand fit, product truth, or WordPress.org constraints.
- Export or crop from the high-resolution generated composition into
  `banner-1544x500.png`, then produce `banner-772x250.png` from that approved
  source so the two files match.
- Keep visible banner text minimal. The banner should communicate the workflow
  visually; detailed proof belongs in the screenshots tab and captions.

## Screenshot Caption Gate

- Add `== Screenshots ==` only when screenshot files are ready for the same
  publication lane.
- Keep captions short enough to preserve the `9700` byte readme target.
- Captions must stay within current product truth: Gravity Forms only, no numeric
  lead scores, no guaranteed spam accuracy, no automatic CRM routing, and no
  auto-sent replies.
