<?php

namespace Omnireview\Trustpilot;

use Omnireview\Config;
use Omnireview\GatewayFactory;
use Omnireview\GatewayInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Trustpilot.
 *
 *   options:
 *     api_key: '%env(TRUSTPILOT_API_KEY)%'           # required: the TrustScore, the reviews, the links
 *     locale: fr-FR                                  # the profile's links, the invitations
 *     # replying and inviting: the business user's token
 *     api_secret: '%env(default::TRUSTPILOT_API_SECRET)%'
 *     business_user_id: '%env(default::TRUSTPILOT_BUSINESS_USER_ID)%'
 *     template_id: ~                                 # the invitation's template
 *     reply_to: ~
 *     sender_name: ~
 *     sender_email: ~
 */
final class TrustpilotGatewayFactory extends GatewayFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnireview.factory_name' => 'trustpilot',
            'omnireview.factory_title' => 'Trustpilot',
            'omnireview.required_options' => ['api_key'],
            'locale' => 'fr-FR',
            'api_secret' => null,
            'business_user_id' => null,
            'template_id' => null,
            'reply_to' => null,
            'sender_name' => null,
            'sender_email' => null,
        ]);
    }

    protected function build(Config $c): GatewayInterface
    {
        return new TrustpilotGateway($this->http ?? HttpClient::create(), (string) $c['api_key'], $c->string('api_secret'), $c->string('business_user_id'), $c->string('locale') ?? 'fr-FR', $c->string('template_id'), $c->string('reply_to'), $c->string('sender_name'), $c->string('sender_email'));
    }
}
