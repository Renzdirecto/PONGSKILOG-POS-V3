---
paths:
  - '{app/Models/CustomerReview.php,app/Policies/CustomerReviewPolicy.php,app/Http/Controllers/CustomerReviewController.php,resources/js/components/customer-review*.tsx}'
---

# Controllers Js Components

## Private customer feedback cannot be published
Reviews are branch-owned and manageable only by active Owner users with settings.manage. Visibility and consent are fixed at guest submission: private feedback has no approval path; new public permission requires a new submission. Store all review photos privately and authorize every image response; never expose object paths, sample testimonials or customer text in audit records.
