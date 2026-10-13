---
paths:
    - '{app/Support/LandingLove.php,app/Models/LandingTeamMember.php,app/Policies/LandingTeamMemberPolicy.php,app/Http/Controllers/{LandingPageController,LandingLoveController,LandingTeamController,WebsiteAnalyticsController}.php,app/Http/Requests/{StoreLandingLoveRequest,UpdateLandingTeamMemberRequest,WebsiteAnalyticsRequest}.php}'
    - '{resources/js/pages/welcome.tsx,resources/js/pages/workspaces/website-analytics.tsx,resources/js/components/{landing-experience.*,landing-team.tsx,landing-love.tsx,website-team-settings.tsx},resources/js/lib/{landing,landing-love,website-team,website-analytics}.ts,resources/css/landing.css}'
---

# Website

## Landing V6 is the reference; its sample content is never published

v1.3.0: `context/design/PONGSKILOG Landing Page v6.html` supersedes V2 where they differ. Ported from it: the Reviews rating summary, the yellow Three's Lemonade heading, the Drinks filter in Pics, Meet the Team after Visit, and tap-to-love. Not ported: the Shippy chat, V6's extra menu cards (Lomi, Siopao, Beef broccoli, Beef ampalaya), its sample reviews and its sample team names (Marco Villanueva, Jun Dela Rosa, Bea Mendoza are not staff). The Drinks cards reuse the four `CUPS` photos and names; a `drink` card is shown whole (`object-fit: contain`) over a blurred copy of itself. The rating summary is `CustomerReview::publishedSummary()` over every published review (not the 60 listed), absent until one is published, and never becomes JSON-LD rating markup.

## The highlighted menu card is centred

User request (v1.3.0): each carousel stop puts its card in the middle (`stop = card centre - half the carousel`), first and last included, so bounds are `min`/`max` stops, not `0`/`scrollWidth`. At rest `slideFrame` re-targets the current card (`_si`), which re-centres after a resize; `_snap` jumps without easing on first paint, a new filter, a lens switch or a resize. A hidden carousel (Prices view) has no bounds and keeps its place.

## New landing widgets are function components

`LandingExperience` stays a class whose unmount test pins its exact listeners; Meet the Team (`landing-team.tsx`) and tap-to-love (`landing-love.tsx`) are separate function components with their own effects. Phone rules for the landing page go inside the final `@media (max-width: 699px)` block of `landing.css` (tests read the last one).

## Tap-to-love is one hourly count, shared by the page and the Owner

`landing_loves` holds taps per Asia/Manila date and clock hour, nothing about the visitor. The landing total is the sum of all rows and Website → Analytics → Heart Taps is the sum of a period, so they always agree; never add a second counter. `LandingLove::add()` is one atomic upsert and ignores a batch key seen in the last ten minutes. The page sends taps as batches (at most `MAX_TAPS`, about one request per two seconds, same key on retry) through the `landing-love` limiter, reads the total with the page and each saved batch, and never polls. The "Tap tap!" invitation is not remembered: it returns on every load.

## Website is Analytics, Reviews and Team

The Owner workspace is labelled Website (route and component names stay `workspaces.website-analytics`). It needs a business-wide scope and either `reports.view` (Analytics) or website content access (Reviews, Team: active Owner or Super Admin with `settings.manage`); the server sends each tab's data only to accounts that may use it. Review moderation moved here from Settings and must not be duplicated there. Heart taps ride in the existing `report` prop and its existing refresh: no new chart, poll or channel.

## The landing team is six database cards

`landing_team_members` has exactly the six rows its migration creates; cards are edited, cleared or hidden, never added or deleted. An empty name, bio or photo renders a marked placeholder ("Name to follow", "Details to follow.", "Photo to follow"): never invent a person. `image_path` is either an upload under `landing-team/` on the `s3` disk (re-encoded by `ProductImageProcessor`, served by the versioned `landing-team.photo` route, public only while the card is visible) or a portrait bundled in `public/images`, which is never deleted from storage. Edits are audited under `settings` and are live on the next landing visit.
