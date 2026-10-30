# Release Notes for Link Checker

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
