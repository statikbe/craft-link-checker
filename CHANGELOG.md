# Release Notes for Link Checker

## 1.4.0 - 2026-10-31

> [!IMPORTANT]
> Run `php craft up` after updating: a migration adds the pending pages table and new columns, and the rows of the domains
> and URLs that weren't checked yet.

### Changed
- The report is cached once for everyone (filtered per user when it's read) and built by one request at a time; others get the previous report meanwhile
- The report is only cleared when something it shows changes, at most once per request or queue job: not for every element save (drafts, nested entries, saved assets, users, form submissions, resaving and propagation don't clear it), and after external checks only when a link becomes or stops being broken
- The report reads fewer rows: only links whose anchor can be checked, no routes or invalid URLs, and the columns that are only shown are read for the problems only
- Saved pages are added to a pending table and read by one queue job at a time, in batches of 20; saving a page again while it waits adds no work, and pages saved in a queue job (an import) are read after that job
- Reading a page loads its nested entries a level at a time for all their siblings (eager loading): about 10× fewer queries for a page with many blocks, also for the check on publish and the Links panel
- Queue jobs carry no lists: each batch takes the next rows from the tables (a few bytes instead of up to megabytes per batch), only one job of a kind runs at a time, and every job has a TTR and a lower priority than Craft's own jobs
- Whether domains and URLs are due is an indexed lookup instead of a scan of every link
- An unknown external result is checked again after 1, 3, 7, then 14 days; a host that refuses link checkers (401, 403, 429) five times in a row is left alone for two weeks
- No `hostDelay` between the steps of one check, DNS answers are reused within a job, and one check takes at most twice the timeout
- The Links panel loads its list after the page, and reloads once the editor pauses instead of after every autosave (a minute while collapsed)
- In block mode, the check on publish reads the page once instead of twice
- The CSV export is written to a temporary file and fetches edit URLs in chunks
- Domains and URLs no page links to any more are removed after a month
- `link-checker/rebuild` no longer clears Craft's element caches (and with them the site's `{% cache %}` tags) while it runs
- Updating from 1.2 reads only the pages with protocol-relative links again, not every page

### Removed
- The unused index on the links' kind

## 1.3.0 - 2026-10-31

### Changed
- Saved pages are read in a queue job after the save, not before the editor's response is sent; the domain and URL checks are queued from that job
- The report is faster and uses less memory on large sites: links to live pages of the site itself are filtered out in the database, only the columns that are shown are read, exact redirects are looked up instead of compared one by one, and edit URLs are fetched in a few queries for the shown pages only
- The report is cached for up to 10 minutes per user, and cleared when an element is saved, deleted or restored, pages are read, or links are checked or ignored
- The widget only lists other people's entries to users who may save them (`savePeerEntries`), and only in the sites the user may edit

### Fixed
- Protocol-relative links (`//example.org/…`) are checked over https (their pages are read again after the update)
- The Links panel endpoint checks the user may edit the entry's site, and doesn't show someone else's unsaved changes
- The Links panel in a slideout (an entry opened from another page) works: collapse, refresh and Ignore

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
