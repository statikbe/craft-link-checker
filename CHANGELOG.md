# Release Notes for Link Checker

## 1.2.1 - 2026-10-30

### Fixed
- Updating from 1.0.0 or 1.1.0 broke the dashboard and entry saves: a migration now adds the missing tables and columns (`php craft up`) and reads the links again
- External pages: every request goes through curl and is pinned to the checked address (the GET fallback could connect elsewhere); IPv6 addresses are checked too, and carrier-grade NAT, multicast and other non-public ranges are refused, also on redirects; a request through a proxy or to another address isn't used
- "The website’s domain doesn’t exist" never showed in the Links panel and the check on publish
- Two checks close together (two queue jobs, a deploy) could make an external link broken within minutes: a failure only counts an hour after the previous check, a URL another job checked is skipped, and only one job waits at a time
- A domain only counts as not existing after two failed lookups an hour apart; domain lookups run in batches
- With `checkDomains` off, external pages weren't checked at all
- Links in disabled blocks were checked on publish (and could block it)
- A link can only be ignored from the Links panel when it's on the page; ignoring from a page in another site than the primary one failed
- The check on publish only runs in the control panel (not for front-end entry forms or GraphQL)
- The CSV export no longer lets a spreadsheet run link text as a formula
- One page that can't be read no longer stops a rebuild

## 1.2.0 - 2026-10-30

### Added
- External pages are checked over HTTP in a queue job (`checkExternal`): 404 or 410 twice in a row is broken, a permanent redirect is a warning (not for http→https, www. or a trailing slash). Throttled per host (`hostDelay`), never to private or reserved addresses, hosts that block link checkers are skipped (`skipHosts`)
- Utilities → Link Checker: every problem with filters and a CSV export
- Ignore a link (in the utility or the Links panel), and stop ignoring it in the utility
- `link-checker/check-urls` console command; `rebuild --skip-external`

## 1.1.0 - 2026-10-30

### Added
- Links panel in the entry sidebar: the problems of the page as it's edited, reloaded after an autosave (`panel`)
- Check on publish (`mode`: `'warn'`, `'block'` or `false`): broken links as a warning, or as errors on the row, block, column and field
- Global sets are checked too (`checkGlobals`)
- The domain of external links is checked: a link to a domain that doesn't exist is broken (`checkDomains`; a DNS lookup in a queue job, cached per domain)

### Changed
- A link without a label shows the title of its target in the widget

## 1.0.0 - 2026-10-30

### Added
- Finds the links in Link, rich text (CKEditor, Redactor) and Hyper fields, also in Matrix entries, Content Blocks and entries in CKEditor
- Broken: links to deleted, trashed, disabled, expired or URL-less entries, entries not in the page's site, deleted assets and own-site URLs that no longer exist
- Warnings: entries that aren't published yet, redirected URLs (SEO Fields, Retour), anchors that aren't on the target page, invalid email addresses and phone numbers, deleted entries whose saved URL still works
- Broken links dashboard widget, only with the pages the user may edit
- `link-checker/rebuild` and `link-checker/report` console commands
