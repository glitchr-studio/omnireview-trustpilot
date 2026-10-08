# omnireview/trustpilot

**Trustpilot** for [glitchr/omnireview](https://github.com/glitchr-studio/omnireview): a business
unit's TrustScore, its number of reviews, its reviews and the links to its profile and to write
one; with a business user's access, replying to a review and inviting a customer to write one.

```php
use Omnireview\Trustpilot\TrustpilotGatewayFactory;

$gateway = (new TrustpilotGatewayFactory($httpClient))->create(['api_key' => getenv('TRUSTPILOT_API_KEY')]);

$unit = $gateway->find('www.maison-erable.example');  // once: keep its ID
$gateway->rating($unit);                              // the TrustScore, the number of reviews, the profile
$gateway->reviews($unit, 10);
$gateway->writeUrl($unit);                            // the profile's evaluate link
```

Written from Trustpilot's developer documentation, read on 2026-10-08. **Not verified in real:
no key.**

[Documentation](docs/index.md): the options, the calls, Trustpilot's terms, what was verified.

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
