---
title: omnireview/trustpilot
order: 1
---

# omnireview/trustpilot

## Installation

```sh
composer require omnireview/trustpilot
```

PHP 8.2 or later, `glitchr/omnireview` and `symfony/http-client`. A Trustpilot application (its
API key; its secret and a business user for replying and inviting) - the APIs come with
Trustpilot's paid plans.

## Options

| Option | Default | |
|---|---|---|
| `api_key` | required | |
| `locale` | `fr-FR` | the profile's links, the invitations |
| `api_secret`, `business_user_id` | | replying and inviting: the business user's token |
| `template_id`, `reply_to`, `sender_name`, `sender_email` | | the invitation e-mail's |

## Calls

| | Route | |
|---|---|---|
| `find($domain)` | `GET api.trustpilot.com/v1/business-units/find?name=` | the business unit, as a `Place`: keep its ID |
| `rating()` | `GET datasolutions.trustpilot.com/v1/business-units/{id}`, `apikey` | `score.trustScore`, `numberOfReviews.total`; the link: `profileUrl` of `/web-links` |
| `reviews()` | `GET api.trustpilot.com/v1/business-units/{id}/reviews?perPage=&language=&orderBy=createdat.desc` | up to 100; each with its reply; its link is its public page `www.trustpilot.com/reviews/{id}` (the API's own links point to the API) |
| `writeUrl()` | `GET .../business-units/{id}/web-links?locale=` | `evaluateUrl` |
| `reply()` | `POST api.trustpilot.com/v1/private/reviews/{id}/reply`, `{authorBusinessUserId, message}` | the business user's token |
| `invite()` | `POST invitations-api.trustpilot.com/v1/private/business-units/{id}/email-invitations` | the business user's token; the invitation's id |

The token: `POST api.trustpilot.com/v1/oauth/oauth-business-users-for-applications/accesstoken`,
`grant_type=client_credentials`, Basic `api_key:api_secret`; kept until a minute before it lapses.
Without the secret and the business user, `reply()` and `invite()` throw `NotSupportedException`
and `capabilities()` says so.

## Trustpilot's terms, as declared (`terms()`)

From Trustpilot's caching best practices
([developers.trustpilot.com/ds-caching-best-practices](https://developers.trustpilot.com/ds-caching-best-practices/))
and rate limits, read on 2026-10-08:

- What is shown refreshed **every 24 hours at the latest** (`cacheFor: 86400`): the Twig functions
  keep it no longer. The business unit ID may be kept.
- At most 833 calls in 5 minutes and 10,000 in an hour: keep the data in the back end rather than
  calling from every page view.
- Invite every customer alike: Trustpilot forbids inviting only those likely to be pleased.

## Verified, and not

| | |
|---|---|
| Against Trustpilot | **not verified in real: no key.** Every answer in `Tests/Fixtures` is written from Trustpilot's API reference (business units, reviews, web links, the token, the invitation) |
| The headers (`apikey`, Bearer, `x-business-user-id`), the bodies, the token's grant | by the tests |
| That the Data Solutions API answers with the plain API key | unknown until tried: Trustpilot's plans decide which APIs a key opens |
