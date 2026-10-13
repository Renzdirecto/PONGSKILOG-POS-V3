---
paths:
    - '{app/Support/WebsiteAnalytics.php,app/Models/WebsiteEvent.php,app/Http/Controllers/{WebsiteEventController,WebsiteAnalyticsController}.php,app/Http/Requests/{StoreWebsiteEventRequest,WebsiteAnalyticsRequest}.php,resources/js/pages/workspaces/website-analytics.tsx,resources/js/lib/website-analytics.ts,resources/js/components/landing-experience.*}'
---

# Website Analytics

## Landing counters are first-party, anonymous and business-wide

`WebsiteAnalytics` is the only tracker (v1.2.1 Manual QA 2): no third-party script, heatmap or session recording. `website_events` stores only an event name, its Manila date and a daily-rotating HMAC of the session id; never an IP address, user agent, URL or account. Only the public landing page reports (`page_view`, `facebook_click`, `directions_click`, `menu_click`, at most one per second per kind through `websiteEventDue`); `review_submission` is recorded by the server after a review is really saved, outside the transaction and rescued so a counter failure never blocks a guest. Signed-in accounts and recognizable bots are answered 204 but not counted; workspace pages never track. The Owner page (`workspaces.website-analytics`) needs reports.view AND a business-wide scope (the website is not Branch data; `requiresBusinessWide` hides it from Branch roles), reads three bounded aggregate queries per period (Today by Manila hour; 7 Days, 30 Days and Custom of at most 31 days by Manila date) and must label unique visitors as an estimate. Events are pruned after `WebsiteEvent::RETENTION_DAYS`; changing what is collected updates the Privacy Notice and bumps `CustomerReview::NOTICE_VERSION`.
