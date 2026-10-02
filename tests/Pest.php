<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use XerAds\Laravel\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit', 'Feature');

/*
 * Test site keys. The secret is the one in the v2 signature vectors, so a key
 * built from these verifies the fixture signatures.
 */
const TEST_SITE_ID = 'site_01jb2m8q4z7x3k9v5t1r6w0y2h';
const TEST_KEY_ID = 'sk_01jb2m8q4z7x3k9v5t1r6w0y2h';
const TEST_SECRET = 'q8xN3vV0b2cY5mT9wR1uE7aL4kZ6sD0fH2jG8pX3nB5';
const TEST_OTHER_KEY_ID = 'sk_01jb2n0a1b2c3d4e5f6g7h8j9k';
const TEST_OTHER_SECRET = 'Zr7_kLm2-Qp9Ws4Xc1Vb8Nh3Jt6Yu0Ig5Oa';

function testSiteKey(string $keyId = TEST_KEY_ID, string $secret = TEST_SECRET, string $siteId = TEST_SITE_ID): string
{
    return 'xsk_'.$siteId.'.'.$keyId.'.'.$secret;
}
