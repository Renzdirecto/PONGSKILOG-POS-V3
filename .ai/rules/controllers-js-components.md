---
paths:
  - '{app/Models/CustomerReview.php,app/Policies/CustomerReviewPolicy.php,app/Http/Controllers/CustomerReviewController.php,resources/js/components/customer-review*.tsx}'
---

# Controllers Js Components

## Private customer feedback cannot be published
Reviews are branch-owned and manageable only by active Owner and Super Admin users with settings.manage (v1.2.1 Manual QA: Super Admin added), from the Reviews tab of Settings (`branches/index`). Visibility and consent are fixed at guest submission: private feedback has no approval path; new public permission requires a new submission. Store all review photos privately and authorize every image response; never expose object paths, sample testimonials or customer text in audit records. The optional `customer_email` is encrypted at rest, hidden from every projection (public and management) and reserved for future moderation notices; no email is sent yet. Changing what is collected or who can read it bumps `CustomerReview::NOTICE_VERSION`.
