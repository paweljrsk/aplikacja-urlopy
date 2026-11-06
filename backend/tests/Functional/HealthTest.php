<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testKernelAndApiRoutingRespondWithoutLeakingConfiguration(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame('{"status":"ok"}', $client->getResponse()->getContent());
    }

    public function testHealthDoesNotAcceptMutationRequests(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/health');

        self::assertResponseStatusCodeSame(405);
    }
}
