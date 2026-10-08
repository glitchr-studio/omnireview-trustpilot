<?php

namespace Omnireview\Trustpilot;

use Omnireview\Exception\InvalidKeyException;
use Omnireview\Exception\NotSupportedException;
use Omnireview\Exception\ProviderException;
use Omnireview\Http\Answer;
use Omnireview\InviteInterface;
use Omnireview\Model\Capabilities;
use Omnireview\Model\Invitation;
use Omnireview\Model\Place;
use Omnireview\Model\Rating;
use Omnireview\Model\Reply;
use Omnireview\Model\Review;
use Omnireview\Model\Terms;
use Omnireview\RatingInterface;
use Omnireview\ReplyInterface;
use Omnireview\ReviewsInterface;
use Omnireview\WriteUrlInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Trustpilot: the TrustScore (Data Solutions API), the reviews and the
 * profile's links (public API, the API key), replying and inviting (the
 * business user's OAuth token: client credentials, the business user's id).
 * A Place is a business unit ID - found once by the site's domain, and kept.
 */
final class TrustpilotGateway implements RatingInterface, ReviewsInterface, ReplyInterface, InviteInterface, WriteUrlInterface
{
    public const API = 'https://api.trustpilot.com/v1/';
    public const DATA = 'https://datasolutions.trustpilot.com/v1/';
    public const INVITATIONS = 'https://invitations-api.trustpilot.com/v1/';

    private ?string $token = null;
    private int $expires = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[\SensitiveParameter] private readonly string $apiKey,
        #[\SensitiveParameter] private readonly ?string $apiSecret = null,
        private readonly ?string $businessUserId = null,
        private readonly string $locale = 'fr-FR',
        private readonly ?string $templateId = null,
        private readonly ?string $replyTo = null,
        private readonly ?string $senderName = null,
        private readonly ?string $senderEmail = null,
    ) {
    }

    public function getName(): string
    {
        return 'trustpilot';
    }

    public function getTitle(): string
    {
        return 'Trustpilot';
    }

    public function capabilities(): Capabilities
    {
        $business = null !== $this->apiSecret && null !== $this->businessUserId;

        return new Capabilities(rating: true, reviews: true, reply: $business, invite: $business, writeUrl: true);
    }

    public function terms(): Terms
    {
        return new Terms(
            cacheFor: 86400,
            maxReviews: 100,
            attribution: 'Trustpilot',
            authors: true,
            link: true,
            noIndex: false,
            rules: [
                'Refresh what is shown every 24 hours at the latest (Content Refresh Guidelines); the business unit ID may be kept.',
                'Keep under 833 calls in 5 minutes, 10,000 in an hour; keep the data in the back end rather than calling from every page view.',
            ],
            source: 'https://developers.trustpilot.com/ds-caching-best-practices/',
        );
    }

    /** The business unit of a domain: once, then keep its ID. */
    public function find(string $domain): Place
    {
        $data = $this->call('GET', self::API.'business-units/find', ['query' => ['name' => $domain], 'headers' => ['apikey' => $this->apiKey]]);

        return new Place((string) ($data['id'] ?? throw new ProviderException('trustpilot', \sprintf('No business unit for "%s".', $domain))), $data['displayName'] ?? $domain);
    }

    public function rating(Place $place): Rating
    {
        $data = $this->call('GET', self::DATA.'business-units/'.rawurlencode($place->id), ['headers' => ['apikey' => $this->apiKey]]);
        $count = $data['numberOfReviews'] ?? 0;

        return new Rating('trustpilot', (float) ($data['score']['trustScore'] ?? 0), (int) (\is_array($count) ? ($count['total'] ?? 0) : $count), url: $this->links($place)['profileUrl'] ?? null);
    }

    public function reviews(Place $place, int $limit = 5, ?string $language = null): array
    {
        $data = $this->call('GET', self::API.'business-units/'.rawurlencode($place->id).'/reviews', ['headers' => ['apikey' => $this->apiKey], 'query' => array_filter(['perPage' => max(1, min(100, $limit)), 'language' => $language, 'orderBy' => 'createdat.desc'])]);

        return array_slice(array_map(static fn (array $r) => new Review(
            'trustpilot',
            (string) ($r['id'] ?? ''),
            (float) ($r['stars'] ?? 0),
            isset($r['text']) ? (string) $r['text'] : null,
            isset($r['title']) ? (string) $r['title'] : null,
            $r['language'] ?? null,
            $r['consumer']['displayName'] ?? null,
            publishedAt: self::date($r['createdAt'] ?? null),
            // The review's public page: its "links" point to the API, not to a page a visitor can open.
            url: isset($r['id']) ? 'https://www.trustpilot.com/reviews/'.rawurlencode((string) $r['id']) : null,
            reply: isset($r['companyReply']['text']) ? new Reply((string) $r['companyReply']['text'], self::date($r['companyReply']['createdAt'] ?? null)) : null,
        ), (array) ($data['reviews'] ?? [])), 0, $limit);
    }

    public function reply(Place $place, Review $review, string $text): Reply
    {
        $this->business('reply');
        $this->call('POST', self::API.'private/reviews/'.rawurlencode($review->id).'/reply', ['headers' => ['Authorization' => 'Bearer '.$this->token()], 'json' => ['authorBusinessUserId' => $this->businessUserId, 'message' => $text]]);

        return new Reply($text, new \DateTimeImmutable());
    }

    public function invite(Place $place, Invitation $invitation): ?string
    {
        $this->business('invite');
        $data = $this->call('POST', self::INVITATIONS.'private/business-units/'.rawurlencode($place->id).'/email-invitations', [
            'headers' => ['Authorization' => 'Bearer '.$this->token(), 'x-business-user-id' => (string) $this->businessUserId],
            'json' => array_filter([
                'replyTo' => $this->replyTo,
                'locale' => $invitation->locale,
                'senderName' => $this->senderName,
                'senderEmail' => $this->senderEmail,
                'referenceNumber' => $invitation->reference,
                'consumerName' => $invitation->name,
                'consumerEmail' => $invitation->email,
                'type' => 'email',
                'serviceReviewInvitation' => array_filter([
                    'templateId' => $this->templateId,
                    'preferredSendTime' => $invitation->sendAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'),
                    'redirectUri' => $invitation->redirectUrl,
                ]),
            ], static fn ($v) => null !== $v && [] !== $v),
        ]);

        return isset($data['id']) ? (string) $data['id'] : null;
    }

    public function writeUrl(Place $place): string
    {
        return (string) ($this->links($place)['evaluateUrl'] ?? throw new ProviderException('trustpilot', 'The business unit gives no evaluateUrl.'));
    }

    /** @return array<string, mixed> profileUrl, evaluateUrl, evaluateEmbedUrl */
    private function links(Place $place): array
    {
        return $this->call('GET', self::API.'business-units/'.rawurlencode($place->id).'/web-links', ['headers' => ['apikey' => $this->apiKey], 'query' => ['locale' => $this->locale]]);
    }

    private function business(string $operation): void
    {
        if (null === $this->apiSecret || null === $this->businessUserId) {
            throw NotSupportedException::operation('trustpilot', $operation, 'it takes the business user\'s token: api_secret and business_user_id');
        }
    }

    /** The client credentials grant: Basic base64(key:secret), no refresh - asked again once lapsed. */
    private function token(): string
    {
        if (null !== $this->token && time() < $this->expires) {
            return $this->token;
        }
        $answer = Answer::send($this->http, 'trustpilot', 'POST', self::API.'oauth/oauth-business-users-for-applications/accesstoken', ['headers' => ['Authorization' => 'Basic '.base64_encode($this->apiKey.':'.$this->apiSecret)], 'body' => ['grant_type' => 'client_credentials']]);
        $data = json_decode($answer->body, true);
        if ($answer->status >= 400 || !isset($data['access_token'])) {
            throw new InvalidKeyException('trustpilot', 'The API key and secret were refused for a token.', (string) $answer->status);
        }
        $this->token = (string) $data['access_token'];
        $this->expires = time() + max(0, (int) ($data['expires_in'] ?? 3600) - 60);

        return $this->token;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $url, array $options): array
    {
        $answer = Answer::send($this->http, 'trustpilot', $method, $url, $options);
        if (\in_array($answer->status, [401, 403], true)) {
            throw new InvalidKeyException('trustpilot', \sprintf('Refused (HTTP %d): %s', $answer->status, mb_substr(trim($answer->body), 0, 200)), (string) $answer->status);
        }
        if ($answer->status >= 400) {
            $error = json_decode($answer->body, true);
            throw new ProviderException('trustpilot', \sprintf('%s %s: HTTP %d, %s', $method, parse_url($url, \PHP_URL_PATH), $answer->status, \is_array($error) ? ($error['message'] ?? $error['details'] ?? json_encode($error)) : mb_substr(trim($answer->body), 0, 200)), (string) $answer->status);
        }

        return '' === trim($answer->body) ? [] : $answer->json();
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        try {
            return \is_string($value) && '' !== $value ? new \DateTimeImmutable($value) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
