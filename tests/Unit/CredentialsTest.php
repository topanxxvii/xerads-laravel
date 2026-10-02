<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use XerAds\Laravel\Facades\Xerads;
use XerAds\Laravel\Support\Credentials;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\InvalidSiteKey;
use XerAds\Laravel\Support\StateStore;

function freshResolver(): CredentialsResolver
{
    app()->forgetScopedInstances();

    return app(CredentialsResolver::class);
}

describe('parsing', function () {
    it('reads the site id, key id and secret', function () {
        $credentials = Credentials::parse(testSiteKey());

        expect($credentials->siteId)->toBe(TEST_SITE_ID)
            ->and($credentials->keyId)->toBe(TEST_KEY_ID)
            ->and($credentials->secret())->toBe(TEST_SECRET)
            ->and($credentials->siteKey())->toBe(testSiteKey());
    });

    it('refuses a malformed key without repeating it', function (string $key) {
        try {
            Credentials::parse($key);
        } catch (InvalidSiteKey $exception) {
            expect($exception->getMessage())->not->toContain(TEST_SECRET)
                ->and($exception->getMessage())->not->toContain('kL0ng');

            return;
        }

        $this->fail('A malformed site key was accepted.');
    })->with([
        'no prefix' => [TEST_SITE_ID.'.'.TEST_KEY_ID.'.'.TEST_SECRET],
        'wrong prefix' => ['xak_'.TEST_SITE_ID.'.'.TEST_KEY_ID.'.'.TEST_SECRET],
        'two parts' => ['xsk_'.TEST_SITE_ID.'.'.TEST_SECRET],
        'four parts' => [testSiteKey().'.extra'],
        'uppercase site id' => [testSiteKey(siteId: 'site_01JB2M8Q4Z7X3K9V5T1R6W0Y2H')],
        'short site id' => [testSiteKey(siteId: 'site_01jb2m8q')],
        'key id without prefix' => [testSiteKey(keyId: '01jb2m8q4z7x3k9v5t1r6w0y2h')],
        'secret too short' => [testSiteKey(secret: 'kL0ng-but-not-enough')],
        'secret too long' => [testSiteKey(secret: str_repeat('a', 129))],
        'secret with a quote' => [testSiteKey(secret: TEST_SECRET.'"')],
        'trailing space' => [testSiteKey().' '],
        'empty' => [''],
    ]);

    it('treats an empty value as absent but still refuses a malformed one', function () {
        expect(Credentials::parseOrNull(null))->toBeNull()
            ->and(Credentials::parseOrNull('   '))->toBeNull()
            ->and(Credentials::parseOrNull(' '.testSiteKey().' '))->toBeInstanceOf(Credentials::class);

        Credentials::parseOrNull('xsk_nope');
    })->throws(InvalidSiteKey::class);
});

describe('masking', function () {
    it('never shows the secret when cast, dumped or encoded', function () {
        $credentials = Credentials::parse(testSiteKey());

        ob_start();
        var_dump($credentials);
        $dumped = (string) ob_get_clean();

        expect((string) $credentials)->toBe('xsk_'.TEST_SITE_ID.'.'.TEST_KEY_ID.'.********')
            ->and($credentials->masked())->toBe((string) $credentials)
            ->and(json_encode($credentials))->not->toContain(TEST_SECRET)
            ->and(json_encode($credentials))->toContain(TEST_KEY_ID)
            ->and(print_r($credentials, true))->not->toContain(TEST_SECRET)
            ->and($dumped)->not->toContain(TEST_SECRET)
            ->and($dumped)->toContain(TEST_SITE_ID);
    });

    it('keeps the secret out of tools that read private properties', function () {
        $credentials = Credentials::parse(testSiteKey());

        // What dd(), dump(), Tinker and debug toolbars print.
        $varDumper = (new CliDumper)->dump((new VarCloner)->cloneVar($credentials), true);

        expect($varDumper)->not->toContain(TEST_SECRET)
            ->and($varDumper)->toContain(TEST_KEY_ID)
            ->and(var_export($credentials, true))->not->toContain(TEST_SECRET)
            ->and(json_encode((array) $credentials))->not->toContain(TEST_SECRET)
            ->and(get_object_vars($credentials))->not->toContain(TEST_SECRET)
            // The secret is still there for the code that asks by name.
            ->and($credentials->secret())->toBe(TEST_SECRET);
    });

    it('keeps the secrets of separate instances apart', function () {
        $first = Credentials::parse(testSiteKey());
        $second = Credentials::parse(testSiteKey(TEST_OTHER_KEY_ID, TEST_OTHER_SECRET));

        expect($first->secret())->toBe(TEST_SECRET)
            ->and($second->secret())->toBe(TEST_OTHER_SECRET);
    });

    it('cannot be cloned into a copy without a secret', function () {
        $credentials = Credentials::parse(testSiteKey());

        clone $credentials;
    })->throws(Error::class);

    it('refuses to be serialised', function () {
        serialize(Credentials::parse(testSiteKey()));
    })->throws(LogicException::class);

    it('keeps the key out of stack traces', function () {
        try {
            Credentials::parse(testSiteKey(secret: 'kL0ng-but-not-enough'));
        } catch (InvalidSiteKey $exception) {
            $frame = collect($exception->getTrace())->firstWhere('function', 'parse');

            expect($frame['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class);

            return;
        }

        $this->fail('A malformed site key was accepted.');
    })->skip((bool) ini_get('zend.exception_ignore_args'), 'This PHP build records no arguments in traces at all.');
});

describe('resolving', function () {
    it('has no credentials on an unpaired site', function () {
        expect(freshResolver()->current())->toBeNull()
            ->and(freshResolver()->previous())->toBeNull()
            ->and(freshResolver()->configured())->toBeFalse()
            ->and(Xerads::credentials())->toBeNull();
    });

    it('reads the encrypted key pair from the database', function () {
        freshResolver()->store(Credentials::parse(testSiteKey()), Credentials::parse(testSiteKey(TEST_OTHER_KEY_ID, TEST_OTHER_SECRET)), Carbon::now()->addHour());

        $stored = (string) DB::table('xerads_state')->where('key', 'credentials')->value('value');

        // Nothing readable at rest: not the secrets, not even the ids.
        expect($stored)->not->toContain(TEST_SECRET)
            ->and($stored)->not->toContain(TEST_SITE_ID);

        $resolver = freshResolver();

        expect($resolver->current()?->keyId)->toBe(TEST_KEY_ID)
            ->and($resolver->previous()?->keyId)->toBe(TEST_OTHER_KEY_ID)
            ->and($resolver->byKeyId(TEST_OTHER_KEY_ID)?->secret())->toBe(TEST_OTHER_SECRET)
            ->and($resolver->byKeyId('sk_00000000000000000000000000'))->toBeNull()
            ->and(Xerads::credentials()?->keyId)->toBe(TEST_KEY_ID);
    });

    it('lets the environment override the stored key, previous key included', function () {
        freshResolver()->store(Credentials::parse(testSiteKey()), Credentials::parse(testSiteKey(TEST_OTHER_KEY_ID, TEST_OTHER_SECRET)), Carbon::now()->addHour());

        config(['xerads.credentials.key' => testSiteKey(TEST_OTHER_KEY_ID, TEST_OTHER_SECRET)]);

        $resolver = freshResolver();

        expect($resolver->current()?->keyId)->toBe(TEST_OTHER_KEY_ID)
            ->and($resolver->previous())->toBeNull();

        config(['xerads.credentials.previous' => testSiteKey()]);

        expect(freshResolver()->previous()?->keyId)->toBe(TEST_KEY_ID);
    });

    it('stops accepting the previous key once it expires', function () {
        freshResolver()->store(Credentials::parse(testSiteKey()), Credentials::parse(testSiteKey(TEST_OTHER_KEY_ID, TEST_OTHER_SECRET)), Carbon::now()->addHour());

        $resolver = freshResolver();

        expect($resolver->previous())->not->toBeNull();

        Carbon::setTestNow(Carbon::now()->addHour()->addSecond());

        expect($resolver->previous())->toBeNull()
            ->and($resolver->byKeyId(TEST_OTHER_KEY_ID))->toBeNull()
            ->and($resolver->current()?->keyId)->toBe(TEST_KEY_ID);

        Carbon::setTestNow();
    });

    it('remembers what it read until told to forget', function () {
        $resolver = freshResolver();

        expect($resolver->current())->toBeNull();

        config(['xerads.credentials.key' => testSiteKey()]);

        expect($resolver->current())->toBeNull();

        $resolver->forget();

        expect($resolver->current()?->keyId)->toBe(TEST_KEY_ID);
    });

    it('treats a row it cannot decrypt as unpaired, and says so', function () {
        DB::table('xerads_state')->insert([
            'key' => 'credentials',
            'value' => json_encode('not-an-encrypted-payload'),
            'updated_at' => Carbon::now(),
        ]);

        $warnings = $this->captureWarnings();

        expect(freshResolver()->current())->toBeNull()
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('could not decrypt');
    });

    it('ignores a malformed previous key, and says so, without blocking the current one', function () {
        config([
            'xerads.credentials.key' => testSiteKey(),
            'xerads.credentials.previous' => 'xsk_not-a-key',
        ]);

        $warnings = $this->captureWarnings();
        $resolver = freshResolver();

        expect($resolver->current()?->keyId)->toBe(TEST_KEY_ID)
            ->and($resolver->previous())->toBeNull()
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('XERADS_SITE_KEY_PREVIOUS');
    });

    it('keeps refusing a malformed current key instead of reading it as unpaired', function () {
        config(['xerads.credentials.key' => 'xsk_not-a-key']);

        $resolver = freshResolver();
        $failures = 0;

        foreach ([1, 2] as $attempt) {
            try {
                $resolver->current();
            } catch (InvalidSiteKey) {
                $failures++;
            }
        }

        expect($failures)->toBe(2);
    });

    it('uses a configured resolver subclass', function () {
        $custom = new class(app('config'), app(StateStore::class), app('encrypter')) extends CredentialsResolver
        {
            protected function readFromDatabase(): array
            {
                return [Credentials::parse(testSiteKey(TEST_OTHER_KEY_ID, TEST_OTHER_SECRET)), null, null];
            }
        };

        app()->instance($custom::class, $custom);
        config(['xerads.credentials.resolver' => $custom::class]);

        expect(freshResolver()->current()?->keyId)->toBe(TEST_OTHER_KEY_ID);
    });
});
