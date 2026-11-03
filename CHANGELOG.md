# Changelog

## 0.1.1 — 2026-11-03

Security hardening. Updating is recommended.

### Security
- **No requests to internal addresses.** The on-save check verifies that a page's canonical URL answers; a canonical
  pointing at another host (which an editor can set) is now fetched with WordPress's safe HTTP API, which refuses
  private and reserved addresses (such as a cloud metadata service), so a check can't reach the server's internal
  network. Child sitemaps are only read from this site.
- **Schema can't break out of its script tag.** The JSON-LD Greenlight SEO prints itself is encoded with `<`, `>` and
  `&` escaped, and its text is plain (no tags, entities decoded).
- **Rate and size limits.** "Check now" runs at most once per page every 15 seconds (each check requests the site
  itself), and the link-suggestion endpoint accepts at most 500 KB of unsaved content.
- **llms.txt** is sent with `X-Content-Type-Options: nosniff`.
- **Input limits:** up to 25 locations, 30 profile URLs (http and https only), and length limits on every text
  setting and on the service-page fields. The editor box ignores autosaves.
- Empty `index.php` files keep the plugin's folders from being listed on servers that allow directory listing.

## 0.1.0 — 2026-11-03

First version. Works with Yoast SEO (tested with 28.8 on WordPress 7.1), Rank Math (tested with 1.0.280) or
neither, and steps aside wherever the site's SEO plugin already does the job.

### Added
- **LocalBusiness schema**: business type, address, phone, opening hours, coordinates, price range, areas
  served and profiles, plus one LocalBusiness per other location on its page, linked to the main business.
  Merged into Yoast's or Rank Math's Organization when the graph has one (one graph per page, as Yoast Local
  SEO does it); otherwise added to their graph, or printed as a graph of its own. Steps aside for Yoast
  Local SEO, Rank Math's Local SEO module, AIOSEO Local Business and SEOPress Pro.
- **Service schema** on pages marked as service pages in the new Greenlight SEO box.
- **Indexing and on-page checks on save**, read from the page as search engines see it: noindex pages in
  the sitemap, theme or plugin noindex and canonical overrides, multiple canonicals, canonicals pointing
  elsewhere or at a 404, redirecting pages, indexable pages missing from the sitemap, missing/multiple/
  duplicate titles, H1 count, H1 equal to the SEO title, content images without alt text, and an optional
  "| Site name" title suffix check. Site-wide list under Checks, and "Check all pages now".
- **Internal links**: an index of the links stored in every page's content (and Elementor data), orphan
  pages, dead ends, links to missing or redirected pages, the most-linked pages, and link suggestions in
  the editor (service and location pages first). Suggestions step aside for Yoast SEO Premium and Rank Math.
- **`[greenlight_nap]` shortcode**: name, address, phone, hours or email of the business or any location,
  from the same settings as the schema.
- **Optional llms.txt**, off by default; can't be switched on while Yoast's or Rank Math's llms.txt is on or
  an llms.txt file is on the server.
- **Business checks**: missing address or phone, placeholder values, coordinates under 5 decimals,
  opening-hours lines it can't read, locations without a page.
- Admin page (Overview, Business, Schema, Checks, Links, llms.txt) with overlap warnings.
- WP-CLI: `wp greenlight-seo status [--format=json] | check | links | scan | llms | set`. The status JSON is
  a contract (`schema: 1`).

### Not included
- An FAQ block: Yoast SEO and Rank Math have one.
- HowTo schema (Google stopped showing HowTo rich results in 2023).
- A redirect manager (the Redirection plugin, managed by the QA app, does that).
