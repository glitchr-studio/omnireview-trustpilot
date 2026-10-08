<?php

namespace Omnireview\Trustpilot\Tests;

use Omnireview\Exception\NotSupportedException;
use Omnireview\Model\Invitation;
use Omnireview\Model\Place;
use Omnireview\Model\Review;
use Omnireview\Trustpilot\TrustpilotGateway;
use Omnireview\Trustpilot\TrustpilotGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers written from Trustpilot's developer documentation (Data
 * Solutions, Business Units, Service Reviews, Invitations, client
 * credentials): no key was used.
 */
final class TrustpilotGatewayTest extends TestCase
{
    private const UNIT = '46d6a890000064000500e0c3';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $calls = [];

    /** @param list<string|MockResponse> $answers */
    private function gateway(array $answers, array $options = []): TrustpilotGateway
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->calls[] = [$method, $url, $options];
            $answer = array_shift($answers) ?? throw new \LogicException('No answer left for '.$url);
            if ($answer instanceof MockResponse) {
                return $answer;
            }

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$answer.'.json'));
        });
        $gateway = (new TrustpilotGatewayFactory($http))->create($options + ['api_key' => 'tp-key']);
        self::assertInstanceOf(TrustpilotGateway::class, $gateway);

        return $gateway;
    }

    public function testTheTrustScoreTheReviewsAndTheLinks(): void
    {
        $gateway = $this->gateway(['find', 'business-unit', 'web-links', 'reviews', 'web-links']);
        $place = $gateway->find('www.maison-erable.example');
        self::assertSame([self::UNIT, 'Maison Érable'], [$place->id, $place->name]);
        self::assertSame('https://api.trustpilot.com/v1/business-units/find?name=www.maison-erable.example', $this->calls[0][1]);

        $rating = $gateway->rating($place);
        self::assertSame([4.4, 469, 'https://fr.trustpilot.com/review/www.maison-erable.example'], [$rating->value, $rating->count, $rating->url]);
        self::assertSame('https://datasolutions.trustpilot.com/v1/business-units/'.self::UNIT, $this->calls[1][1]);
        self::assertStringContainsString('apikey: tp-key', implode("\n", $this->calls[1][2]['headers']));

        $review = $gateway->reviews($place, 3, 'fr')[0];
        self::assertSame(['507f191e810c19729de860ea', 5.0, 'Parfait', 'Camille R.', 'Merci Camille !'], [$review->id, $review->rating, $review->title, $review->author, $review->reply?->text]);
        self::assertSame('https://www.trustpilot.com/reviews/507f191e810c19729de860ea', $review->url, 'the public page, not the API');
        self::assertSame('https://api.trustpilot.com/v1/business-units/'.self::UNIT.'/reviews?perPage=3&language=fr&orderBy=createdat.desc', $this->calls[3][1]);
        self::assertSame('https://fr.trustpilot.com/evaluate/www.maison-erable.example', $gateway->writeUrl($place));
        self::assertSame(86400, $gateway->terms()->cacheFor);
    }

    public function testRepliesAndInvitationsWithTheBusinessUsersToken(): void
    {
        $gateway = $this->gateway(['token', new MockResponse('', ['http_code' => 201]), 'invitation'], ['api_secret' => 'tp-secret', 'business_user_id' => 'bu-1', 'template_id' => 'tpl-1', 'sender_name' => 'Maison Érable', 'reply_to' => 'contact@maison-erable.example']);
        $place = new Place(self::UNIT);

        $gateway->reply($place, new Review('trustpilot', '507f191e810c19729de860ea', 5), 'Merci !');
        self::assertSame('https://api.trustpilot.com/v1/oauth/oauth-business-users-for-applications/accesstoken', $this->calls[0][1]);
        self::assertStringContainsString('Authorization: Basic '.base64_encode('tp-key:tp-secret'), implode("\n", $this->calls[0][2]['headers']));
        self::assertSame(['authorBusinessUserId' => 'bu-1', 'message' => 'Merci !'], json_decode((string) $this->calls[1][2]['body'], true));

        $id = $gateway->invite($place, new Invitation('camille@example.org', 'Camille R.', 'ORDER-42', 'fr-FR', new \DateTimeImmutable('2026-10-10 10:00', new \DateTimeZone('Europe/Paris'))));
        self::assertSame('f4c3b2a1-0000-4000-8000-000000000001', $id);
        self::assertSame('https://invitations-api.trustpilot.com/v1/private/business-units/'.self::UNIT.'/email-invitations', $this->calls[2][1]);
        self::assertStringContainsString('x-business-user-id: bu-1', implode("\n", $this->calls[2][2]['headers']));
        self::assertSame(['replyTo' => 'contact@maison-erable.example', 'locale' => 'fr-FR', 'senderName' => 'Maison Érable', 'referenceNumber' => 'ORDER-42', 'consumerName' => 'Camille R.', 'consumerEmail' => 'camille@example.org', 'type' => 'email', 'serviceReviewInvitation' => ['templateId' => 'tpl-1', 'preferredSendTime' => '2026-10-10T08:00:00']], json_decode((string) $this->calls[2][2]['body'], true));
        self::assertSame(['rating', 'reviews', 'reply', 'invite', 'write-url'], $gateway->capabilities()->gives());
    }

    public function testWithoutTheBusinessUserNoReplyNorInvitation(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->gateway([])->invite(new Place(self::UNIT), new Invitation('a@example.org', 'A'));
    }
}
