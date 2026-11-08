<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Separate namespace for committed multi-process fixtures; existing public data is never reset. */
class CookingReleaseEnvironment
{
    public static function useSchema(string $schema): void
    {
        if (! preg_match('/^cooking_release_[a-f0-9]{16}$/D', $schema)
            || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
            throw new InvalidArgumentException('Cooking release checks require a dedicated schema in a test database.');
        }

        config(['database.connections.pgsql.search_path' => [$schema, 'public'],
            'nested-recipes.enabled' => true, 'cooking.sessions_enabled' => true, 'cooking.step_groups_enabled' => true]);
        DB::purge('pgsql');
    }

    public static function publicFingerprints(): array
    {
        $result = [];
        foreach (DB::select("select tablename from pg_tables where schemaname = 'public' order by tablename") as $table) {
            $name = str_replace('"', '""', $table->tablename);
            $result[$table->tablename] = (array) DB::selectOne('select count(*) as rows, md5(coalesce(string_agg(row_to_json(t)::text, chr(10) order by row_to_json(t)::text), \'\')) as hash from public."'.$name.'" t');
        }

        return $result;
    }
}
