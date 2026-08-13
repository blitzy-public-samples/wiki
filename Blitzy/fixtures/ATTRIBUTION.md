# Fixture content attribution

This file records the provenance and licensing of the **imported Wikipedia content** committed in
`Blitzy/fixtures/*.xml`. It exists for two reasons. The first is legal: that text is reused under a
Creative Commons Attribution-ShareAlike licence, which obliges the reuser to credit the authors,
point at the licence, and say what was changed. The second is technical: pinning each article to one
exact upstream revision is what stops fixture content from silently changing underneath the
verification captures, whose value depends on being reproducible.

**This file is not the licence of the Blitzy skin.** The skin itself — every PHP class, Mustache
template, Less stylesheet, client script, message file, tool and harness file in this package — is
licensed **GPL-2.0-or-later**, and that licence text lives at [`../COPYING`](../COPYING). The two
records are separate and must not be conflated: `../COPYING` governs the software, this file governs
the encyclopedia text that the verification fixtures carry. Nothing recorded here relicenses the
skin, and nothing in `../COPYING` applies to the imported article text.

## 1. What this record covers

| Files | Content | Licence | Where stated |
| --- | --- | --- | --- |
| `*.xml` (4 files) | Text derived from English Wikipedia articles, plus the `Template:` pages they transclude | CC BY-SA 4.0 (co-licensed GFDL) | Sections [2](#2-licence-of-the-imported-text)–[6](#6-no-external-media-is-reused) |
| `*.wikitext` (4 files) | Original wikitext authored for this package | GPL-2.0-or-later | Section [7](#7-original-content-in-this-directory) |
| Everything else in this package | The Blitzy skin and its harness | GPL-2.0-or-later | [`../COPYING`](../COPYING) |

## 2. Licence of the imported text

The text in the four XML exports is reused under the
**[Creative Commons Attribution-ShareAlike 4.0 International licence](https://creativecommons.org/licenses/by-sa/4.0/)**
(CC BY-SA 4.0). That is the licence the English Wikipedia declares for its article text, as published
at [Wikipedia:Copyrights](https://en.wikipedia.org/wiki/Wikipedia:Copyrights); the full legal code is
at <https://creativecommons.org/licenses/by-sa/4.0/legalcode>. The version was verified against that
policy page and against the Creative Commons deed rather than assumed, and each export repeats it
in-band: every article revision's `<comment>` element names the licence alongside the upstream
revision it was taken from.

Three points of the licensing position are recorded deliberately, because a reader checking the
history of a long-lived article will encounter all three:

- **Co-licensing.** Most English Wikipedia text is additionally available under the GNU Free
  Documentation License (unversioned, with no invariant sections and no cover texts). This record
  relies on the CC BY-SA grant and makes no claim under the GFDL.
- **Version history.** The Wikimedia projects moved the default licence for new edits to version 4.0
  in a 2023 revision of their Terms of Use; edits made before that remain under version 3.0, which
  is upward-compatible with 4.0. A present-day article revision is therefore an accumulation whose
  older contributions arrived under 3.0, and reusing the whole under 4.0 is the position the projects
  themselves describe.
- **Pre-2009 text.** Text published on Wikipedia before 15 June 2009 was released under the GFDL.
  Where such text survives in a pinned revision it is reachable through the article history URLs
  recorded in section [4](#4-attributed-articles).

The obligations CC BY-SA 4.0 places on this package, and where each is discharged:

| Obligation | Discharged by |
| --- | --- |
| Credit the authors and identify the material | The canonical, permanent-link and **history** URLs in section [4](#4-attributed-articles). The history URL is the conventional way to credit an article's authors, who are too numerous to list. |
| Link to the licence | This section, and the per-article licence line in section [4](#4-attributed-articles). |
| State that changes were made, and what they were | Section [8](#8-disclosure-of-deviations-from-as-exported), which discloses every deviation from the upstream revision. |
| Licence adaptations under the same terms | The imported text and its derived exports remain under CC BY-SA 4.0. Applying the skin's GPL to them is neither claimed nor intended. |

## 3. How to read the two revision identifiers

Each export carries **two** different numbers that both look like a revision identifier, and
confusing them defeats the purpose of this record. They are recorded separately for every article, so
a reviewer cross-checking this record against a fixture knows exactly where to look: the
fixture-local value is the export's `<revision><id>` element, and the upstream `oldid` is inside the
same revision's `<comment>` element. Section [9](#9-auditing-this-record) gives the commands.

| Identifier | Where it appears | What it means |
| --- | --- | --- |
| **Upstream `oldid`** | The `<comment>` element of the article revision — line 40 in each fixture as committed | The English Wikipedia revision the fixture text was taken from. This is the CC BY-SA pin: it identifies the exact upstream text and, with the history URL, the authors being credited. |
| **Fixture-local `<revision><id>`** | The `<revision><id>` element — line 35 in each fixture as committed | Local bookkeeping inside the export. It is a small synthetic number, **not** an upstream identifier, and the export schema requires the element to be present — `mediawiki/docs/export-0.11.xsd:L184`. |
| **Revision content hash `<sha1>`** | The `<revision><sha1>` element | The base-36 SHA-1 of the committed wikitext. It is an integrity anchor: recompute it from the committed text and it must match, which detects any edit to a fixture whether or not this record was updated alongside it. |

Two consequences follow, and both are the reason the distinction is spelled out rather than left to
inference:

- The local identifiers are **not preserved on import**. MediaWiki assigns fresh local identifiers
  when it imports an export, and core's own importer carries a standing annotation at
  `mediawiki/includes/Import/ImportableOldRevisionImporter.php:L128` recording that reusing the
  original revision id is optional behaviour it does not implement. So the identifier a reviewer sees
  in a rendered page's history is neither the upstream `oldid` nor the value in the export, and
  neither of those is a defect.
- Only the upstream `oldid` should ever be quoted when attributing this content to Wikipedia. The
  fixture-local value has no meaning outside this directory.

## 4. Attributed articles

Four articles, one per XML export. Every value recorded below is verifiable inside this repository —
the titles, identifiers, timestamps and hashes are all read back from the named fixture. Following
the upstream URLs is the one step that needs network access; see section
[9](#9-auditing-this-record).

| Article | Fixture | Upstream `oldid` | Fixture-local `<revision><id>` | Retrieved (UTC) |
| --- | --- | --- | --- | --- |
| Ada Lovelace | `ada-lovelace.xml` | 1360639004 | 2001 | 2026-06-22 |
| Python (programming language) | `python-programming-language.xml` | 1368824895 | 2101 | 2026-06-22 |
| List of countries by GDP (nominal) | `list-of-countries-by-gdp-nominal.xml` | 1229115239 | 4001 | 2026-06-22 |
| Photosynthesis | `photosynthesis.xml` | 1368470526 | 5001 | 2026-06-22 |

All four exports were taken from the English Wikipedia (`<dbname>enwiki</dbname>`,
`<base>https://en.wikipedia.org/wiki/Main_Page</base>`). The retrieval date is the
`<revision><timestamp>` recorded in each export; the exact per-article timestamps are given below.

### 4.1 Ada Lovelace

The most important entry in this file. This article is the subject of the end-to-end boundary
requirement, and it is the article rendered in the two committed reading captures
(`reading-article-1440-light-anon.png` and `reading-article-390-light-anon.png`), so a reviewer must
be able to reach the exact upstream text those captures were produced from. These URLs do that.

- **Page title** (`<title>`): `Ada Lovelace`
- **Fixture**: `ada-lovelace.xml`
- **Article**: <https://en.wikipedia.org/wiki/Ada_Lovelace>
- **Pinned revision** (`oldid` 1360639004):
  <https://en.wikipedia.org/w/index.php?oldid=1360639004>
- **Author credit — page history**:
  <https://en.wikipedia.org/w/index.php?title=Ada_Lovelace&action=history>
- **Retrieved**: 2026-06-22T18:42:17Z
- **Licence**: CC BY-SA 4.0 — <https://creativecommons.org/licenses/by-sa/4.0/>
- **Fixture-local revision id**: 2001 (local page id 1001)
- **Revision content hash**: `tf1162coui5k9tavjdkrac2m26gm5x1` (47,879 bytes of wikitext)
- **Transcluded pages included**: 32 `Template:` pages
- **Deviations**: the common baseline only — see section
  [8.1](#81-baseline-common-to-all-four-exports)

### 4.2 Python (programming language)

- **Page title** (`<title>`): `Python (programming language)`
- **Fixture**: `python-programming-language.xml`
- **Article**: <https://en.wikipedia.org/wiki/Python_%28programming_language%29>
- **Pinned revision** (`oldid` 1368824895):
  <https://en.wikipedia.org/w/index.php?oldid=1368824895>
- **Author credit — page history**:
  <https://en.wikipedia.org/w/index.php?title=Python_%28programming_language%29&action=history>
- **Retrieved**: 2026-06-22T18:44:09Z
- **Licence**: CC BY-SA 4.0 — <https://creativecommons.org/licenses/by-sa/4.0/>
- **Fixture-local revision id**: 2101 (local page id 1101)
- **Revision content hash**: `906yx6f7b6fvjrkq41jlryyye68rqrg` (141,839 bytes of wikitext)
- **Transcluded pages included**: 41 `Template:` pages
- **Deviations**: baseline, plus three recorded in section
  [8.2](#82-per-export-deviations)

### 4.3 List of countries by GDP (nominal)

- **Page title** (`<title>`): `List of countries by GDP (nominal)`
- **Fixture**: `list-of-countries-by-gdp-nominal.xml`
- **Article**: <https://en.wikipedia.org/wiki/List_of_countries_by_GDP_%28nominal%29>
- **Pinned revision** (`oldid` 1229115239):
  <https://en.wikipedia.org/w/index.php?oldid=1229115239>
- **Author credit — page history**:
  <https://en.wikipedia.org/w/index.php?title=List_of_countries_by_GDP_%28nominal%29&action=history>
- **Retrieved**: 2026-06-22T18:44:03Z
- **Licence**: CC BY-SA 4.0 — <https://creativecommons.org/licenses/by-sa/4.0/>
- **Fixture-local revision id**: 4001 (local page id 3001)
- **Revision content hash**: `1q9j7kgolpasq5syimelpja05zg0syc` (50,279 bytes of wikitext)
- **Transcluded pages included**: 16 `Template:` pages
- **Deviations**: baseline, plus one recorded in section
  [8.2](#82-per-export-deviations)

### 4.4 Photosynthesis

- **Page title** (`<title>`): `Photosynthesis`
- **Fixture**: `photosynthesis.xml`
- **Article**: <https://en.wikipedia.org/wiki/Photosynthesis>
- **Pinned revision** (`oldid` 1368470526):
  <https://en.wikipedia.org/w/index.php?oldid=1368470526>
- **Author credit — page history**:
  <https://en.wikipedia.org/w/index.php?title=Photosynthesis&action=history>
- **Retrieved**: 2026-06-22T18:44:09Z
- **Licence**: CC BY-SA 4.0 — <https://creativecommons.org/licenses/by-sa/4.0/>
- **Fixture-local revision id**: 5001 (local page id 4001)
- **Revision content hash**: `olmwcw1bavs9dn856v5zib52smcfx6m` (110,386 bytes of wikitext)
- **Transcluded pages included**: 41 `Template:` pages
- **Deviations**: baseline, plus three recorded in section
  [8.2](#82-per-export-deviations)

## 5. How the exports were produced and imported

Each fixture was produced with **`Special:Export` with template inclusion enabled**. That matters for
attribution as much as for rendering: with template inclusion, an export is **closed under
transclusion** — it contains not only the article but every `Template:` page the article transcludes,
so the article renders with no unexpanded transclusions and no red template links on a wiki that has
never seen Wikipedia. Those included template pages are Wikipedia content in their own right, and
they are covered by the same attribution and the same licence as the article text. The counts are
recorded per article in section [4](#4-attributed-articles); in total the four exports carry 4
articles and 130 transcluded pages.

Every one of those 130 pages is in the `Template:` namespace (`<ns>10</ns>`). **No `Module:` page is
included in any export**, because the included templates are wikitext-only implementations that
invoke no Lua — see section [8.1](#81-baseline-common-to-all-four-exports). Article text and template
pages are therefore the only two classes of Wikipedia content this package carries, and both are
attributed here.

The files are consumed by MediaWiki's own import maintenance script,
`mediawiki/maintenance/importDump.php`, which reads an XML file "as produced from Special:Export or
dumpBackup.php" and takes the file as a positional argument. Nothing else transforms them, which
makes the chain from upstream text to rendered evidence short enough to audit end to end:

```text
upstream revision (oldid, section 4)
  -> Special:Export with template inclusion  ->  fixtures/<article>.xml  (committed here)
  -> importDump.php <file>                   ->  pages on the verification wiki
  -> page render                             ->  the committed captures
```

Every link in that chain is either committed in this repository or a documented core script, so a
reviewer never has to trust an undocumented step. The provisioning sequence itself — which script
runs when, with which arguments — is documented in `../README.md` and its results in `../DELIVERY.md`;
this file deliberately stops at provenance and licensing.

## 6. No external media is reused

**No image, font or other media file is reused from Wikipedia or Wikimedia Commons, and no media
licence is therefore recorded here.** A reader would reasonably expect image attributions in a file
like this one; there are none to make, and the reason is worth stating plainly.

The exports retain the upstream media syntax exactly as exported — both `[[File:…]]` links (11 in
`ada-lovelace.xml`, 15 in `photosynthesis.xml`, 3 in `python-programming-language.xml` and none in
`list-of-countries-by-gdp-nominal.xml`) and the bare filename parameters that infoboxes take. On the
verification wiki every one of them resolves to a **local red file link**, because no foreign file
repository is configured. MediaWiki consults a foreign repository only when one is declared and the
file is absent locally, and both relevant settings are left at their defaults:
`$wgForeignFileRepos` defaults to the empty list and `$wgUseInstantCommons` defaults to `false`
(`mediawiki/includes/MainConfigSchema.php`, lines 1090-1092, 1100-1103 and 1114-1116). Neither is
enabled for the verification instance.

Two consequences, both verifiable in this directory:

- **No media bytes are committed.** The exports contain zero `<upload>` elements — the XML export
  schema's carrier for file data — so no image file is redistributed by this package and no image
  licence is exercised. Only wikitext is committed.
- **No request leaves the wiki's own origin.** Because the file references resolve locally, rendering
  an imported article fetches nothing from `upload.wikimedia.org` or any other host. This is what
  keeps the imported fixtures compatible with the package's zero-external-origin requirement.
  External hyperlinks *inside* article prose — citation targets in `<ref>` elements — are editorial
  content, are not subresource requests, and are retained as exported.

## 7. Original content in this directory

`main-page.wikitext`, `talk-page.wikitext`, `category-page.wikitext` and `history-seed.wikitext` are
**original work authored for this package**. They contain no Wikipedia text, carry no CC BY-SA
obligation, and are licensed under the package licence in [`../COPYING`](../COPYING)
(GPL-2.0-or-later). They are listed here only so that the licensing of this directory is unambiguous;
what they contain and why is not this file's subject.

## 8. Disclosure of deviations from "as exported"

The exports are not byte-for-byte copies of the upstream revisions, and every difference is recorded
below. This section is not a formality: an undisclosed adjustment is indistinguishable from fixture
drift, so a deviation that is not written down here would undermine the very pinning that section
[4](#4-attributed-articles) exists to provide. Each export also states its own deviations in-band, in
the `<comment>` element of the revision it applies to, so the record travels with the file.

### 8.1 Baseline common to all four exports

| Deviation | What it means | Why |
| --- | --- | --- |
| The article wikitext is a **subset** of the pinned upstream revision | Sections and furniture not needed by the capture matrix were omitted. Retained prose, tables, infoboxes, references and headings are as they stand in the pinned revision — **no article sentence was rewritten, and no article text was authored.** | Keeps the fixtures small and their rendering deterministic without editing content |
| Every included `Template:` page is a **local wikitext-only implementation** of the upstream template of the same name | The included pages reproduce the upstream *rendered shape* using core wikitext and parser functions, with no Scribunto module, no Wikidata access and no date-dependent output. Each carries this statement in a `<noinclude>` note on the page itself. | The upstream implementations depend on facilities the verification instance does not have, and on values that change over time |
| `<page><id>`, `<revision><id>` and `<origin>` are small synthetic numbers, and the contributor is `Blitzy Fixture Import` | Fixture-local bookkeeping, explained in section [3](#3-how-to-read-the-two-revision-identifiers). Upstream authorship is credited through the history URLs, not through this field. | An export must carry these elements; using upstream values would misrepresent a subset as an upstream revision |
| 13 of the 130 included template pages render nothing at all | They expand to an empty string in article context, carrying only a `<noinclude>` note. They are present so the export stays closed under transclusion. | Their upstream counterparts emit either invisible metadata or styling that needs a facility the instance does not have |

The render-nothing pages are `Cbignore`, `Pp-semi-indef`, `Use British English`, `Use dmy dates`
(`ada-lovelace.xml`); `Use American English`, `Use dmy dates`, `Wikidata`
(`python-programming-language.xml`); `Pp-protect`, `Static row numbers`, `Sticky header`,
`Table alignment`, `Use dmy dates` (`list-of-countries-by-gdp-nominal.xml`); and `Pp-vandalism`
(`photosynthesis.xml`).

### 8.2 Per-export deviations

| Fixture | Construct | What changed | Forced by |
| --- | --- | --- | --- |
| `ada-lovelace.xml` | — | **None** beyond the baseline in section [8.1](#81-baseline-common-to-all-four-exports). | — |
| `python-programming-language.xml` | Infobox parameters sourced from Wikidata, and preview-release version parameters | Omitted from the article text. | Deterministic captures: their values are fetched or change between releases, so the rendered infobox would not be reproducible |
| `python-programming-language.xml` | `Template:Start date and age` (fixture-local revision 2135) | The included implementation renders the fixed date only; the upstream elapsed-age suffix is omitted. | Deterministic captures: the suffix is recomputed on every parse and would change the rendered article at the next calendar boundary. Neutralising the *template page* is the narrowest possible remedy — the article text is untouched |
| `python-programming-language.xml` | `Template:Wikidata` (fixture-local revision 2142) | Included as a deliberate no-op. The only upstream invocation retained in this export sits inside an HTML comment and is never expanded. | No Wikibase client is installed, and installing a non-bundled extension is not permitted |
| `list-of-countries-by-gdp-nominal.xml` | Sortable-table furniture: `Static row numbers`, `Sticky header`, `Table alignment`, `Pp-protect` | Included as no-ops. The wide sortable wikitable itself — the reason this fixture exists — is retained verbatim. | Their upstream implementations need facilities that are not bundled with the release, so the markup requiring them was replaced rather than an extension installed |
| `photosynthesis.xml` | Scribunto-backed furniture | Omitted. | The upstream implementations are Lua modules; the fixtures are wikitext-only |
| `photosynthesis.xml` | Chemical and mathematical notation | Kept in the native subscript and superscript markup that MediaWiki renders on its own. No `<math>` markup appears anywhere in any fixture. | The Math extension is not bundled with the release and installing a non-bundled extension is not permitted |
| `photosynthesis.xml` | Displayed equations, and captions that carried meaning through colour alone | Equations re-indented with block indent; the colour-only caption styling was dropped. | The accessibility gate is blocking. Choosing fixture content that does not trip it is permitted; excluding a rule or an element from the audit is not, so the content was adjusted and disclosed rather than the audit narrowed |

### 8.3 What was deliberately not changed

Recorded so the boundary of section [8.2](#82-per-export-deviations) is unambiguous, and verifiable by
searching the four exports:

- **No article prose was edited or authored.** Retained text is upstream text.
- **No time-varying construct was left in, and none was papered over in article text.** The exports
  contain no `{{CURRENTDAY}}`-family or `{{NUMBEROFARTICLES}}`-family magic word, no `#time` parser
  function and no `{{REVISIONTIMESTAMP}}`, so nothing in a rendered fixture depends on when it is
  parsed.
- **No markup requiring a non-bundled extension survives.** There is no `<math>`, no
  `{{#invoke:}}`, no `<templatestyles>`, and no `<graph>`, `<maplink>`, `<imagemap>`, `<score>`,
  `<timeline>` or `<chem>` element in any fixture.
- **Markup from bundled extensions is retained and exercised**, so the fixtures still represent real
  Wikipedia articles rather than a simplified imitation: `<ref>` citations (45, 285, 54 and 132
  respectively, in the order the articles are listed in section [4](#4-attributed-articles)),
  `{{#if:}}`-style parser functions throughout, and four `<syntaxhighlight>` blocks in
  `python-programming-language.xml`.

## 9. Auditing this record

Every claim in sections [3](#3-how-to-read-the-two-revision-identifiers),
[4](#4-attributed-articles) and [8](#8-disclosure-of-deviations-from-as-exported) is checkable
without network access, from this directory:

```sh
# What each export declares in-band: upstream oldid, licence, and its own deviations.
for f in ./*.xml; do printf '%s: ' "$f"; grep -m1 '<comment>' "$f"; done

# The fixture-local revision id of the article in each export (section 3 explains the difference).
for f in ./*.xml; do printf '%s: ' "$f"; grep -m1 -A1 '<revision>' "$f" | tail -1; done

# The article revision content hash, which must match the value recorded in section 4.
for f in ./*.xml; do printf '%s: ' "$f"; grep -m1 '<sha1>' "$f"; done
```

Only the first `<comment>`, `<revision><id>` and `<sha1>` in each file are read above, because the
article is the first page in every export; the pages that follow are its transcluded templates. The
hash is a base-36 SHA-1 of the committed wikitext, so it can also be recomputed rather than merely
compared, which is what makes it an anti-drift check and not just a label.

The one check that does require network access is resolving the permanent-link URLs in section
[4](#4-attributed-articles) against the live English Wikipedia. The `oldid` values recorded here are
the pins the exports themselves declare; confirming that each still resolves to the expected article
is a network-only step and is deliberately not asserted offline.

## 10. Notices

- This package is not affiliated with, endorsed by, or sponsored by the Wikimedia Foundation. Naming
  the English Wikipedia here identifies the source of the reused text, as the licence requires, and
  implies no endorsement.
- The imported content is a small, dated subset kept for the sole purpose of verifying a skin's
  rendering. It is not a mirror of Wikipedia, is not maintained, and should not be read as a current
  or authoritative version of any article. Follow the URLs in section
  [4](#4-attributed-articles) for the live text.
- This file is a licensing and provenance record, not legal advice.
