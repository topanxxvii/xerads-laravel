<?php

namespace XerAds\Laravel\Compat;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;

/**
 * Keep a site configured for the original receiver working unchanged.
 *
 * That receiver was configured by `config/xerads-cms.php` and `XERADS_CMS_*`
 * variables. Both keep working: the variables are read by `config/xerads.php`
 * directly, and a published `config/xerads-cms.php` is copied onto the new
 * keys here, so swapping the package needs no config edits at all.
 *
 * Either one also selects the mapped content mode, because that is the only
 * mode the original receiver had; a site that set it up expects articles to
 * keep landing in its own model.
 *
 * A published legacy file wins over `config/xerads.php` for the keys it
 * contains. It is the file the site owner actually edited; delete it once its
 * values have moved to `config/xerads.php`.
 */
final class LegacyConfig
{
    /** Legacy key => new key. */
    private const MAP = [
        'secret' => 'xerads.legacy.secret',
        'route' => 'xerads.legacy.route',
        'middleware' => 'xerads.legacy.middleware',
        'timestamp_tolerance' => 'xerads.legacy.timestamp_tolerance',
        'model' => 'xerads.content.mapped.model',
        'status_map' => 'xerads.content.mapped.status_map',
        'public_route' => 'xerads.content.mapped.public_route',
        'public_route_parameter' => 'xerads.content.mapped.public_route_parameter',
    ];

    /** Marks a `xerads-cms` array this class mirrored rather than one the site published. */
    public const MIRROR_MARKER = '_mirrored_from_xerads';

    /** Any of these set means the site was installed for the original receiver. */
    private const ENVIRONMENT = [
        'XERADS_CMS_SECRET',
        'XERADS_CMS_MODEL',
        'XERADS_CMS_ROUTE',
        'XERADS_CMS_PUBLIC_ROUTE',
    ];

    /**
     * @param  bool  $readEnvironment  false once config is cached: the cached
     *                                 values already include what this did, and
     *                                 the environment file is not loaded then
     */
    public static function apply(Repository $config, bool $readEnvironment = true): void
    {
        $legacy = $config->get('xerads-cms');
        $hasLegacyFile = is_array($legacy) && $legacy !== [] && ! isset($legacy[self::MIRROR_MARKER]);

        if ($hasLegacyFile) {
            self::copy($config, $legacy);
        }

        if ($hasLegacyFile || ($readEnvironment && self::environmentIsSet())) {
            $config->set('xerads.content.mode', 'mapped');
        }
    }

    /**
     * Expose the effective legacy values under `xerads-cms.*` again.
     *
     * Code written against the original receiver reads `config('xerads-cms.secret')`
     * and friends. With a published legacy file those keys exist anyway; with
     * environment variables only, they would be empty, so they are mirrored
     * from the new keys. The marker keeps a mirrored copy (which `config:cache`
     * stores like any other value) from being mistaken for a published file
     * on the next boot.
     */
    public static function mirror(Repository $config): void
    {
        $legacy = $config->get('xerads-cms');

        if (is_array($legacy) && $legacy !== [] && ! isset($legacy[self::MIRROR_MARKER])) {
            return;
        }

        $mirror = [self::MIRROR_MARKER => true];

        foreach (self::MAP as $legacyKey => $key) {
            $mirror[$legacyKey] = $config->get($key);
        }

        $mirror['fields'] = $config->get('xerads.content.mapped.fields');

        $config->set('xerads-cms', $mirror);
    }

    public static function environmentIsSet(): bool
    {
        foreach (self::ENVIRONMENT as $name) {
            $value = Env::get($name);

            if (is_string($value) ? trim($value) !== '' : $value !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<mixed>  $legacy */
    private static function copy(Repository $config, array $legacy): void
    {
        foreach (self::MAP as $from => $to) {
            if (array_key_exists($from, $legacy)) {
                $config->set($to, $legacy[$from]);
            }
        }

        if (isset($legacy['fields']) && is_array($legacy['fields'])) {
            /*
             * The legacy file's map is the complete map. The original receiver
             * replaced its defaults with the published `fields` wholesale, so a
             * field the file leaves out was never written; it must not come
             * back now under a default column name the site may not have.
             */
            $config->set('xerads.content.mapped.fields', array_merge(
                array_fill_keys(array_keys((array) $config->get('xerads.content.mapped.fields', [])), null),
                $legacy['fields'],
            ));
        }
    }
}
